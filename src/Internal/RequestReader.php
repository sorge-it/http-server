<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use Fiber;
use SorgeIt\HttpServer\Request;

/**
 * Reads the requests of a connection in the fiber of the parser: the head with `HeadReader`, the
 * body with `BodyReader`.
 *
 * @internal
 */
final class RequestReader
{
    /**
     * A request is in progress, and `$mark` is the offset of the buffer when it began.
     */
    private bool $reading = false;

    private int $mark = 0;

    private bool $inHead = false;

    /**
     * Whether the connection goes on after the answer of the request given last.
     */
    private bool $reuse = true;

    /**
     * @param  bool  $streams  the handler reads the body; else the reader reads it before
     */
    public function __construct(
        private readonly ReadBuffer $buffer,
        private readonly HeadReader $head,
        private readonly BodyReader $body,
        private readonly string $remoteAddress,
        private readonly bool $streams,
    ) {}

    /**
     * Each request, until the client ends or a fault ends the connection. The parser gets each
     * request from `Fiber::suspend()`.
     *
     * @throws BadRequest
     */
    public function run(): void
    {
        $first = true;

        while (true) {
            // A later request begins with its first byte. An end before it closes the connection
            // without an answer.
            if (! $first && ! $this->buffer->next()) {
                return;
            }

            $first = false;
            $this->reading = true;
            $this->inHead = true;
            $this->mark = $this->buffer->offset();
            $head = $this->head->read();
            $this->inHead = false;

            if ($head === null) {
                return;
            }

            [$method, $target, $headers, $length] = $head;
            $body = $this->body->begin($length);

            if (! $this->streams) {
                $this->body->hold();
            }

            $this->reading = false;
            Fiber::suspend(new Request($method, $target, $headers, $body, $this->remoteAddress));
            $this->reading = true;
            $this->body->finish();
            $this->reading = false;
            $reuse = $this->reuse;
            $this->reuse = true;

            // After a cut body, no byte that may come can be framed as the start of a request.
            if (! $reuse || $this->body->cut()) {
                return;
            }
        }
    }

    /**
     * The answer of the request given last is queued; the next resume closes its body.
     */
    public function done(bool $reuse): void
    {
        $this->reuse = $reuse;
    }

    /**
     * Whether bytes of a request in progress were read.
     */
    public function begun(): bool
    {
        return $this->reading && $this->buffer->offset() > $this->mark;
    }

    public function reading(): bool
    {
        return $this->reading;
    }

    public function inHead(): bool
    {
        return $this->inHead;
    }
}
