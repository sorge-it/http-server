<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use Fiber;
use LogicException;
use SorgeIt\HttpServer\Request;
use Throwable;

/**
 * The body of the request read last, of the length of its `Content-Length`.
 *
 * @internal
 */
final class BodyReader
{
    /**
     * Bytes the server reads of a body at once, where the handler does not say how many.
     */
    private const int PIECE = 1 << 16;

    /**
     * Bytes of the body still to read.
     */
    private int $left = 0;

    /**
     * The body was read to its length, or its read ended with an error.
     */
    private bool $sawEof = true;

    /**
     * The handler can read the body no more.
     */
    private bool $closed = false;

    /**
     * The end of the client ended the body before its length, so the request is not valid.
     */
    private bool $cut = false;

    /**
     * The body was over `maxBody`, so the connection lingers.
     */
    private bool $limitHit = false;

    /**
     * The body the handler reads; null once its answer is decided, as Go then closes it.
     */
    private ?RequestBody $body = null;

    /**
     * The fiber the handler runs in: only the server continues it, so only a read there may wait.
     *
     * @var Fiber<null, null, array{Request, Answer, Throwable|null}, null>|null
     */
    private ?Fiber $handler = null;

    public function __construct(private readonly ReadBuffer $buffer) {}

    /**
     * @param  Fiber<null, null, array{Request, Answer, Throwable|null}, null>|null  $fiber
     */
    public function handledIn(?Fiber $fiber): void
    {
        $this->handler = $fiber;
    }

    /**
     * The body of the request with the head.
     */
    public function begin(int $length): RequestBody
    {
        $this->left = $length;
        $this->sawEof = $length === 0;
        $this->closed = false;
        $this->cut = false;
        $this->limitHit = false;

        return $this->body = new RequestBody($this);
    }

    /**
     * Reads the body before the handler runs and holds it.
     */
    public function hold(): void
    {
        $body = $this->body;
        assert($body instanceof RequestBody);
        $body->hold(...$this->readAll($body));
    }

    /**
     * One `Read()` of the handler: the bytes and the error, EOF at the end.
     *
     * @return array{string, string|null}
     */
    public function read(RequestBody $for, int $size): array
    {
        if ($for !== $this->body) {
            return ['', Go::READ_AFTER_CLOSE];
        }

        if ($this->handler instanceof Fiber && Fiber::getCurrent() !== $this->handler) {
            throw new LogicException('Body::read() was called outside the fiber in which the server runs the handler. Read the body in the handler itself: the server resumes only that fiber when more bytes arrive.');
        }

        return $this->closed ? ['', Go::READ_AFTER_CLOSE] : $this->readLocked($size);
    }

    /**
     * The handler returned. The server reads and drops the rest of the body before it writes the
     * answer, so a body that the client cut gets the 400 in place of the answer. Gives whether the
     * answer closes for a body over its limit, as `requestTooLarge()` of Go.
     */
    public function settle(): bool
    {
        $this->release();
        $this->close();

        return $this->limitHit;
    }

    /**
     * The answer is out: the body closes, and its rest is dropped.
     */
    public function finish(): void
    {
        $this->body = null;
        $this->close();
    }

    /**
     * Whether the connection waits before it closes, so the client gets the answer: the body was
     * over a limit. A cut body came with the end of the client, which sends nothing more.
     */
    public function lingers(): bool
    {
        return $this->limitHit;
    }

    public function cut(): bool
    {
        return $this->cut;
    }

    /**
     * Bytes of the body that the server did not read yet.
     */
    public function left(): int
    {
        return $this->left;
    }

    /**
     * The rest of the body as `io.ReadAll()` reads it, and its error. A limit reads at most a byte
     * over it, as `http.MaxBytesReader()` does, net/http/request.go:1245.
     *
     * @param  int  $limit  0 for none
     * @return array{string, string|null}
     */
    public function readAll(RequestBody $for, int $limit = 0): array
    {
        $body = '';

        while (true) {
            [$bytes, $err] = $this->read($for, $limit === 0 ? self::PIECE : min(self::PIECE, $limit - strlen($body) + 1));
            $body .= $bytes;

            if ($limit !== 0 && strlen($body) > $limit) {
                $this->limitHit = true;

                return [$body, Go::TOO_LARGE];
            }

            if ($err !== null) {
                return [$body, $err === ReadBuffer::EOF ? null : $err];
            }
        }
    }

    /**
     * The handler returned: as in Go, its body is closed to it, also one read before it.
     */
    private function release(): void
    {
        $this->body?->close();
        $this->body = null;
    }

    /**
     * Reads and drops the rest of the body, to its length or to its error. The rest of a body over
     * its limit is not waited for: the linger drops what comes of it.
     */
    private function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        while (! $this->limitHit && ! $this->sawEof) {
            $this->readLocked(self::PIECE);
        }
    }

    /**
     * One read of the body: the bytes and EOF at its length, or the error of an end before it.
     *
     * @return array{string, string|null}
     */
    private function readLocked(int $size): array
    {
        if ($this->sawEof) {
            return ['', ReadBuffer::EOF];
        }

        $bytes = $this->buffer->read(min($size, $this->left));

        if ($bytes === false) {
            $this->sawEof = true;
            $this->cut = true;

            return ['', $this->buffer->unexpectedEnd()];
        }

        $this->left -= strlen($bytes);
        $this->sawEof = $this->left === 0;

        return [$bytes, $this->sawEof ? ReadBuffer::EOF : null];
    }
}
