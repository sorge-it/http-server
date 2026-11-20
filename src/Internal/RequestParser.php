<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use Fiber;
use SorgeIt\HttpServer\Request;
use Throwable;

/**
 * Cuts HTTP/1.1 requests out of the bytes of one connection, in the pieces they arrive in:
 * `RequestReader` reads them in a fiber, which waits for more bytes of the client.
 *
 * @internal
 */
final class RequestParser
{
    private readonly ReadBuffer $buffer;

    private readonly BodyReader $body;

    private readonly RequestReader $reader;

    /**
     * @var Fiber<null, null, void, Request|null>
     */
    private readonly Fiber $fiber;

    private ?BadRequest $error = null;

    /**
     * A handler reads the body of the request given last, so the reader waits until `done()`.
     */
    private bool $out = false;

    /**
     * @param  int  $maxHead  bytes of a head; 0 for no limit
     * @param  int  $maxValues  field values of a head; 0 for no limit
     * @param  string  $remoteAddress  the `Request::$remoteAddress` of each request
     * @param  bool  $streams  a request comes with its head, and the handler reads the body until
     *                         `done()`; else with its body, read as `io.ReadAll()` does
     */
    public function __construct(int $maxHead = Go::MAX_HEADER_BYTES, int $maxValues = Go::MAX_HEADER_VALUES, string $remoteAddress = '', private readonly bool $streams = false)
    {
        $this->buffer = new ReadBuffer;
        $this->body = new BodyReader($this->buffer);
        $this->reader = new RequestReader($this->buffer, new HeadReader($this->buffer, $maxHead, $maxValues), $this->body, $remoteAddress, $streams);
        $this->fiber = new Fiber($this->reader->run(...));
    }

    /**
     * Takes the bytes and gives the requests they complete, at most `$most`; the rest stays
     * bytes, which take far less memory than a request read, until a later call. After a fault it
     * gives the requests before it, `error()` names it, and nothing more is read.
     *
     * @return list<Request>
     */
    public function feed(string $bytes, int $most = PHP_INT_MAX): array
    {
        // A fault ends the fiber, so a parser that found one reads nothing more.
        if ($this->fiber->isTerminated()) {
            return [];
        }

        $this->buffer->give($bytes);
        $requests = [];

        try {
            while (! $this->out && count($requests) < $most && ($request = $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start()) instanceof Request) {
                $requests[] = $request;
                $this->out = $this->streams;
            }
        } catch (BadRequest $badRequest) {
            // The reader stopped at the fault, so the offset of the buffer is where it found it.
            $this->error = $badRequest->at($this->buffer->offset());
        }

        return $requests;
    }

    /**
     * The client sent its end, or the head is past its time, or the server closes the connection
     * with the error. The next `feed()` reads what is left: a part of a head gets 400, and a part
     * of a body gets `unexpected EOF` or the error.
     */
    public function end(?string $error = null): void
    {
        $this->buffer->end($error);
    }

    /**
     * Whether the connection waits before it closes after the request read last: its body was
     * over a limit.
     */
    public function lingers(): bool
    {
        return $this->body->lingers();
    }

    /**
     * Whether a request is in progress: from its first byte, the first from the accept; also while
     * a handler may read its body.
     */
    public function reading(): bool
    {
        return ! $this->fiber->isTerminated() && ($this->reader->reading() || $this->out);
    }

    /**
     * Whether the head of a request is in progress; after it, its body is.
     */
    public function readingHead(): bool
    {
        return ! $this->fiber->isTerminated() && $this->reader->inHead();
    }

    public function error(): ?BadRequest
    {
        return $this->error;
    }

    /**
     * Whether part of a request waits for more bytes.
     */
    public function pending(): bool
    {
        if ($this->fiber->isTerminated()) {
            return false;
        }

        return $this->buffer->holds() || $this->reader->begun();
    }

    /**
     * Whether the end of the client ended the body of the request given last before its length.
     * Such a request is not valid.
     */
    public function cut(): bool
    {
        return $this->body->cut();
    }

    /**
     * Bytes of the body of the request given last that the server did not read yet.
     */
    public function left(): int
    {
        return $this->body->left();
    }

    /**
     * The handler of the request given last returned: the rest of its body is dropped before the
     * answer, see `BodyReader::settle()`. Gives whether the answer closes for a body too large.
     */
    public function settle(): bool
    {
        return $this->body->settle();
    }

    /**
     * The fiber the handler of the request given last runs in, or null once it returned.
     *
     * @param  Fiber<null, null, array{Request, Answer, Throwable|null}, null>|null  $fiber
     */
    public function handledIn(?Fiber $fiber): void
    {
        $this->body->handledIn($fiber);
    }

    /**
     * Whether a read waits for more bytes of the client in its fiber.
     */
    public function waits(): bool
    {
        return $this->buffer->waiting();
    }

    /**
     * The answer of the request given last is queued. The next `feed()` closes its body as Go
     * does, then reads the next request if the connection goes on.
     */
    public function done(bool $reuse): void
    {
        $this->out = false;
        $this->reader->done($reuse);
    }
}
