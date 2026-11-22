<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use Closure;
use Fiber;
use Generator;
use LogicException;
use SorgeIt\HttpServer\Request;
use Throwable;

/**
 * The state of one client connection while the server reads from it and writes to it. Only its
 * own methods change its fields; its times are in `deadlines`, which the exchange also changes.
 *
 * @internal
 */
final class Connection
{
    /**
     * @var list<string|FileBody|Generator<int, list<string>, mixed, bool>> what is to be written
     */
    public private(set) array $outgoing = [];

    /**
     * The request whose streamed body is still to be written. As in Go, the server reads and
     * answers no more meanwhile.
     */
    public private(set) ?Request $streaming = null;

    /**
     * The connection closes once its bytes are written.
     */
    public private(set) bool $closing = false;

    /**
     * Before it closes, the connection lingers, as Go waits where the client may still send.
     */
    public private(set) bool $lingers = false;

    /**
     * The client sent its end; it may still read an answer.
     */
    public private(set) bool $ended = false;

    /**
     * The fiber of the handler while it runs. It waits in a read of the body until more bytes come,
     * as Go waits in its goroutine; the other connections go on.
     *
     * @var Fiber<null, null, array{Request, Answer, Throwable|null}, null>|null
     */
    public private(set) ?Fiber $handler = null;

    /**
     * The request of the fiber in `handler`.
     */
    private ?Request $handling = null;

    /**
     * Throwables the SAPI of PHP turned into output, for `onError` once the server is past them.
     *
     * @var list<Throwable>
     */
    private array $failures = [];

    /**
     * The chunk of the body that the generator first in `outgoing` gave last is queued.
     */
    private bool $taken = false;

    /**
     * The request that `receive()` read and `next()` did not give yet.
     */
    private ?Request $read = null;

    /**
     * Bytes of the first part that are written; copying the rest after each write would make a
     * large response quadratic.
     */
    private int $written = 0;

    /**
     * @param  resource  $stream
     * @param  string  $local  the address of the server end, as PHP gives it: on `127.0.0.1` and
     *                         `::1` the form of Go
     * @param  string  $remote  the address of the client, in the same form
     */
    public function __construct(public readonly mixed $stream, public readonly RequestParser $parser, public readonly Deadlines $deadlines, private readonly string $local, private readonly string $remote) {}

    /**
     * Whether the connection is open. The server closes it as it forgets it, also in
     * `Server::close()`.
     */
    public function open(): bool
    {
        return is_resource($this->stream);
    }

    /**
     * One request at most: the server answers it before the parser reads the next, so the requests
     * after it stay bytes, which take far less memory.
     */
    public function receive(string $bytes): void
    {
        $this->read = $this->feed($bytes);
        $this->deadlines->request($this->parser->reading(), $this->parser->readingHead());
    }

    /**
     * Feeds no bytes when no request was read: a pipelined request is in the buffer of the parser
     * already, and only a call to `feed()` parses it. Taken off here, so it is answered once.
     */
    public function next(): ?Request
    {
        $request = $this->read ?? $this->feed('');
        $this->read = null;

        return $request;
    }

    /**
     * Closes the body of the request answered last, as `finishRequest()` of Go does before the
     * connection closes; the parser reads no request after it.
     */
    public function finish(): void
    {
        $this->feed('');
    }

    public function queue(string|FileBody $part): void
    {
        $this->outgoing[] = $part;
    }

    public function fail(Throwable $throwable): void
    {
        $this->failures[] = $throwable;
    }

    /**
     * The throwables of `fail()` since the last call.
     *
     * @return list<Throwable>
     */
    public function failures(): array
    {
        $failures = $this->failures;
        $this->failures = [];

        return $failures;
    }

    /**
     * @param  Closure(): array{Request, Answer, Throwable|null}  $respond
     */
    public function handle(Closure $respond, Request $request): void
    {
        $this->handler = new Fiber($respond);
        $this->handling = $request;
        $this->parser->handledIn($this->handler);
    }

    public function handled(): void
    {
        $this->handler = null;
        // No behavior: the request and its body go now, not with the next request.
        $this->handling = null;
        $this->parser->handledIn(null);
    }

    /**
     * Queues the streamed body of the request, as `Answer::body()` makes it.
     *
     * @param  Generator<int, list<string>, mixed, bool>  $body
     */
    public function stream(Generator $body, Request $request): void
    {
        $this->outgoing[] = $body;
        $this->streaming = $request;
    }

    /**
     * Closes the connection once its bytes are written, after it lingers if asked; the server
     * answers no request after it.
     */
    public function closeOnceWritten(bool $linger = false): void
    {
        $this->closing = true;
        $this->lingers = $this->lingers || $linger;
    }

    /**
     * The client sent its end, and gets what is still to be written (a half-close).
     */
    public function end(): void
    {
        $this->ended = true;
        $this->closeOnceWritten();
    }

    /**
     * Its answer is written and its writing end shut: it drops what the client still sends until
     * `Go::LINGER` has passed. If the connection closes at once, unread bytes reset it.
     */
    public function linger(): void
    {
        $this->deadlines->linger();
    }

    /**
     * Writes the first part, in pieces of 64 KB, or takes the next piece of a file or the next chunk
     * of a streamed body that is the first part. Gives true once that is done, false when the socket
     * takes no more, and null when the connection cannot go on. What the chunks throw leaves it.
     */
    public function writePart(): ?bool
    {
        $part = $this->outgoing[0];

        if ($part instanceof Generator) {
            // The chunk taken before is written, so the next is taken only now.
            if ($this->taken) {
                $part->next();
            }

            $this->taken = $part->valid();

            if ($this->taken) {
                array_unshift($this->outgoing, ...$part->current());

                return true;
            }

            array_shift($this->outgoing);
            $this->streaming = null;

            if ($part->getReturn()) {
                $this->closeOnceWritten($this->parser->lingers());
            }

            return true;
        }

        if ($part instanceof FileBody) {
            $piece = $part->next();

            // The length is sent: a file that shrank or cannot be read any more ends the
            // connection, which the client sees, instead of leaving it waiting for bytes.
            if ($piece === null) {
                return null;
            }

            if ($part->done()) {
                $this->outgoing[0] = $piece;
            } else {
                array_unshift($this->outgoing, $piece);
            }

            return true;
        }

        $stream = $this->stream;

        while ($this->written < strlen($part)) {
            $piece = substr($part, $this->written, 1 << 16);
            $written = Quietly::run(static fn (): int|false => fwrite($stream, $piece));

            if ($written === false) {
                return null;
            }

            if ($written === 0) {
                return false;
            }

            $this->written += $written;
            $this->deadlines->wrote();
        }

        array_shift($this->outgoing);
        $this->written = 0;

        return true;
    }

    /**
     * Reads and drops what the client still sends to a lingering connection, and gives whether the
     * client closed it.
     */
    public function drain(): bool
    {
        $stream = $this->stream;

        while (($bytes = Quietly::run(static fn (): string|false => fread($stream, 1 << 16))) !== false && $bytes !== '') {
            // Dropped.
        }

        return $bytes === false || feof($stream);
    }

    /**
     * Closes the socket and each file that is still to be written. Gives the errors of a request
     * that the close ended before its body was complete. `Server::close()` and a write that ends
     * the connection report them. In each other close, the server waits for no body.
     *
     * @return list<array{Throwable, Request}>
     */
    public function close(): array
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $fiber = $this->handler;
        $request = $this->handling;
        $this->handled();
        $errors = [];

        // The handler ends now, as in Go its read fails on the closed connection; a fiber left
        // waiting would hold the connection until the collector of cycles runs. A fiber the
        // handler suspended itself waits for no read, so it is dropped.
        if ($fiber instanceof Fiber && $request instanceof Request && $fiber->isSuspended() && $this->parser->waits()) {
            $length = (int) $request->header('Content-Length');
            $errors[] = [new LogicException(sprintf('The server closed before the body was complete, with %d of %d bytes read.', $length - $this->parser->left(), $length)), $request];
            $this->parser->end('read tcp '.$this->local.'->'.$this->remote.': '.Go::NET_CLOSING);
            $fiber->resume();

            if ($fiber->isTerminated()) {
                [, $answer, $failed] = $fiber->getReturn();
                array_push($this->outgoing, ...$answer->parts);

                foreach ([...$this->failures(), ...($failed instanceof Throwable ? [$failed] : [])] as $failure) {
                    $errors[] = [$failure, $request];
                }
            }
        }

        foreach ($this->outgoing as $part) {
            if ($part instanceof FileBody) {
                $part->close();
            }
        }

        $this->outgoing = [];

        return $errors;
    }

    /**
     * A throwable of the parser other than a fault of the request is a bug: it closes the
     * connection, as Go closes one whose goroutine panics, and leaves `tick()`, so the bug is seen.
     */
    private function feed(string $bytes): ?Request
    {
        try {
            return $this->parser->feed($bytes, 1)[0] ?? null;
        } catch (Throwable $throwable) {
            $this->close();

            throw $throwable;
        }
    }
}
