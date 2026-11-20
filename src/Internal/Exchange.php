<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use Closure;
use Fiber;
use Generator;
use LogicException;
use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use Throwable;

/**
 * What happens on the connections of a server: it reads their requests, answers them with the
 * handler in their order, writes the answers, and ends a connection past its time. It closes a
 * connection that ends; the server then forgets it.
 *
 * @internal
 */
final readonly class Exchange
{
    /**
     * Bytes the server reads of a connection at once. With its read buffer, PHP gives at most its
     * chunk size of 8 KB for each `fread()`.
     */
    private const int READ = 1 << 16;

    /**
     * Nanoseconds one call takes chunks of a streamed body, so a slow or endless one holds no
     * other connection, as the goroutines of Go do not.
     */
    private const int SLICE = 1_000_000;

    /**
     * @param  Closure(Request): mixed  $handler
     * @param  Closure(Throwable, Request): void  $onError
     */
    public function __construct(private Closure $handler, private Closure $onError, private Limits $limits) {}

    /**
     * Whether the server reads from the connection. A client that sent its end stays readable
     * without end; waiting on it would spin. While a streamed body is written, the server reads
     * nothing, as the next request waits for its end.
     */
    public function reads(Connection $connection): bool
    {
        return $connection->open() && ! $connection->ended && ! $connection->streaming instanceof Request;
    }

    /**
     * When the connection is past its time, or null.
     */
    public function due(Connection $connection): ?int
    {
        return $connection->deadlines->due($this->readsRequest($connection), $connection->outgoing !== []);
    }

    /**
     * Ends the connection if it is past its time, as Go does once a read fails with its timeout.
     * A lingering connection closes.
     */
    public function expire(Connection $connection, int $now): void
    {
        if (! $this->past($connection, $now)) {
            return;
        }

        if ($connection->deadlines->lingering()) {
            $connection->close();

            return;
        }

        // The bytes that wait in the socket came in time, as from a process paused in a debugger.
        // The reads end once the head is complete or refused, as a body has no time.
        while ($this->past($connection, $now) && $this->read($connection)) {
        }

        // Past its time, a head is cut, as at the end of the client.
        if ($connection->open() && $this->past($connection, $now)) {
            $connection->parser->end();
            $this->serve($connection);
            $connection->closeOnceWritten();
            $this->write($connection);
        }
    }

    /**
     * Reads what the client sent, and gives whether it fed bytes to the parser.
     */
    public function read(Connection $connection): bool
    {
        $stream = $connection->stream;

        if ($connection->deadlines->lingering()) {
            if ($connection->drain()) {
                $connection->close();
            }

            return false;
        }

        // A client that reset the connection makes the read fail with a notice. Go reads the body
        // only as its handler reads, so a fast client waits in TCP; the handler takes these bytes
        // before the next read.
        $bytes = Quietly::run(static fn (): string|false => fread($stream, self::READ));

        while ($bytes !== false && $bytes !== '' && strlen($bytes) < self::READ && ($more = Quietly::run(static fn (): string|false => fread($stream, self::READ - strlen($bytes)))) !== false && $more !== '') {
            $bytes .= $more;
        }

        if ($bytes === false || ($bytes === '' && feof($stream))) {
            // As Go, the server answers a request that is not complete at the end of the client.
            if (! $connection->closing) {
                $connection->parser->end();
                $this->serve($connection);
            }

            // The client sent its end; it may still read what is to be written (a half-close).
            if ($connection->outgoing !== []) {
                $connection->end();
            } else {
                $connection->close();
            }

            return false;
        }

        if ($bytes === '' || $connection->closing) {
            return false;
        }

        $connection->receive($bytes);
        $this->write($connection);

        return true;
    }

    /**
     * Answers what waits and writes until the socket takes no more. A file is read only as far as
     * the socket takes it.
     */
    public function write(Connection $connection): void
    {
        $until = null;

        while (true) {
            $this->serve($connection);

            if (! $connection->open()) {
                return;
            }

            if ($connection->outgoing === []) {
                break;
            }

            if ($connection->outgoing[0] instanceof Generator) {
                $now = hrtime(true);
                $until ??= $now + self::SLICE;

                if ($now > $until) {
                    return;
                }
            }

            $request = $connection->streaming;

            try {
                $written = $connection->writePart();
            } catch (Throwable $throwable) {
                // The head is out, so no other answer can follow: the connection ends, as Go ends
                // one whose handler panics.
                $connection->close();
                assert($request instanceof Request);
                $this->report($throwable, $request);

                return;
            }

            // A chunk PHP turned into output threw; the body goes on.
            foreach ($connection->failures() as $failure) {
                assert($request instanceof Request);
                $this->report($failure, $request);
            }

            // A write that failed, or a file that shrank, is gone or cannot be read, ends the
            // connection, and with it a pipelined request whose body the server waits for.
            if ($written === null) {
                foreach ($connection->close() as [$error, $ended]) {
                    $this->report($error, $ended);
                }

                return;
            }

            if (! $written) {
                return;
            }
        }

        // A read and a write in one tick both end here; a second shutdown fails on macOS.
        if ($connection->closing && ! $connection->deadlines->lingering()) {
            $connection->lingers ? $this->linger($connection) : $connection->close();
        }
    }

    public function report(Throwable $throwable, Request $request): void
    {
        ($this->onError)($throwable, $request);
    }

    /**
     * Whether the server reads the bytes of a request: it reads from the connection, and does not
     * close it once its answers are written. A lingering connection is kept out by `Deadlines`.
     */
    private function readsRequest(Connection $connection): bool
    {
        return ! $connection->closing && $this->reads($connection);
    }

    /**
     * @phpstan-impure as a read changes the time of the connection
     */
    private function past(Connection $connection, int $now): bool
    {
        $due = $this->due($connection);

        return $due !== null && $due <= $now;
    }

    /**
     * Answers the requests read, in order; the parser reads each next request only then. With all
     * answered, it sends the fault of the parser.
     */
    private function serve(Connection $connection): void
    {
        // `next()` takes a request off before it is answered, so what `onError` throws does not
        // leave it to be answered again.
        while (! $connection->closing && ! $connection->streaming instanceof Request) {
            if (! $connection->handler instanceof Fiber) {
                $request = $connection->next();

                if (! $request instanceof Request) {
                    break;
                }

                $connection->handle(fn (): array => $this->respond($connection, $request), $request);
            }

            $fiber = $connection->handler;
            assert($fiber instanceof Fiber);

            try {
                $fiber->isStarted() ? $fiber->resume() : $fiber->start();

                // Only a read of the body waits in the fiber; one the handler suspends itself would
                // wait for nothing, so it gets the exception, as a throw of the handler.
                if ($fiber->isSuspended() && ! $connection->parser->waits()) {
                    $fiber->throw(new LogicException('The handler suspended the fiber the server runs it in. Only a read of the body may wait there.'));
                }
            } catch (Throwable $throwable) {
                // A bug of the server, as `respond()` takes each throwable of the handler.
                $connection->close();

                throw $throwable;
            }

            if ($fiber->isSuspended() && ! $connection->parser->waits()) {
                // The handler suspended the fiber again: the request ends without an answer.
                $connection->close();

                return;
            }

            if (! $fiber->isTerminated()) {
                break;
            }

            $connection->handled();
            $this->answer($connection, ...$fiber->getReturn());
        }

        $connection->deadlines->request($connection->parser->reading(), $connection->parser->readingHead());

        if ($connection->closing) {
            return;
        }

        $error = $connection->parser->error();

        // The client may still send after a 431, so the server lingers.
        if ($error instanceof BadRequest) {
            $connection->queue($error->answer());
            $connection->closeOnceWritten($error->status === 431);
        }
    }

    /**
     * The answer of the handler, in its fiber: a read of the body waits there for more bytes, and
     * so does the drop of the rest of the body.
     *
     * @return array{Request, Answer, Throwable|null}
     */
    private function respond(Connection $connection, Request $request): array
    {
        $settle = $connection->parser->settle(...);

        try {
            return [$request, Answer::of($this->response($connection, $request), $request, $settle), null];
        } catch (Throwable $throwable) {
            return [$request, Answer::of(Caddy::withServer(Php::failure($throwable, $request)), $request, $settle), $throwable];
        }
    }

    private function answer(Connection $connection, Request $request, Answer $answer, ?Throwable $failed): void
    {
        // A body that ended before its length makes the request no good one, whatever the handler
        // answered.
        if ($connection->parser->cut()) {
            $answer->discard();
            $answer = Answer::refusal(new BadRequest(400));
        }

        $connection->parser->done(! $answer->closes);
        $this->send($connection, $answer, $request);

        // Last, so the connection is complete when what it throws leaves `tick()`.
        foreach ([...$connection->failures(), ...($failed instanceof Throwable ? [$failed] : [])] as $failure) {
            $this->report($failure, $request);
        }
    }

    /**
     * The response of the handler, under Caddy and PHP. `maxBody` acts as a handler of Go before
     * PHP.
     */
    private function response(Connection $connection, Request $request): Response
    {
        $maxBody = $this->limits->maxBody;
        $handler = fn (Request $request): Response => Answer::check(($this->handler)($request));

        return Caddy::serve($request, static fn (Request $request): Response => Go::limited($request, static fn (Request $request): Response => Php::serve($request, $handler, $connection->fail(...)), $maxBody));
    }

    /**
     * Queues the answer. A connection that closes after it lingers after a body over its limit.
     */
    private function send(Connection $connection, Answer $answer, Request $request): void
    {
        $connection->queue($answer->head);

        foreach ($answer->parts as $part) {
            $part instanceof Generator ? $connection->stream($part, $request) : $connection->queue($part);
        }

        if ($answer->closes) {
            $connection->finish();
            $connection->closeOnceWritten($connection->parser->lingers());
        }
    }

    /**
     * Shuts the writing end once the answer is written; what the client still sends is dropped
     * until it closes or `Go::LINGER` has passed. A client that sent its end sends nothing more.
     * The server waits after a 431 and a body over its limit.
     */
    private function linger(Connection $connection): void
    {
        $stream = $connection->stream;

        if ($connection->ended || ! Quietly::run(static fn (): bool => stream_socket_shutdown($stream, STREAM_SHUT_WR))) {
            $connection->close();

            return;
        }

        $connection->linger();
    }
}
