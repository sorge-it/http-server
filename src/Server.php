<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer;

use ArrayObject;
use LogicException;
use RuntimeException;
use SorgeIt\HttpServer\Internal\Clock;
use SorgeIt\HttpServer\Internal\Connection;
use SorgeIt\HttpServer\Internal\Deadlines;
use SorgeIt\HttpServer\Internal\Exchange;
use SorgeIt\HttpServer\Internal\Quietly;
use SorgeIt\HttpServer\Internal\RequestParser;
use Throwable;
use ValueError;

/**
 * An in-process HTTP/1.1 server for browser tests on loopback, without an event loop: the
 * caller's wait loop calls `tick()`. It handles the requests on each connection one at a time,
 * with keep-alive enabled. Not for production.
 *
 * @api
 */
final class Server
{
    /**
     * Connections the system holds until they are accepted; with PHP's default of 32, more
     * clients that connect between two ticks wait for seconds.
     */
    private const int BACKLOG = 128;

    /**
     * @var resource|null null once closed
     */
    private $listener;

    private readonly Exchange $exchange;

    private readonly Clock $clock;

    private readonly string $address;

    /**
     * @var array<int, Connection> by the id of the socket
     */
    private array $connections = [];

    private bool $ticking = false;

    private bool $closing = false;

    /**
     * Errors that no `onError` received, with their requests, for `ClosedWithErrors`. Not the
     * server itself, so the closure that fills it holds no reference to the server.
     *
     * @var ArrayObject<int, array{Throwable, Request}>
     */
    private readonly ArrayObject $uncollected;

    /**
     * Errors of the requests that `close()` ended, for `onError` once the close is complete.
     *
     * @var list<array{Throwable, Request}>
     */
    private array $ended = [];

    /**
     * @param  callable(Request): Response  $handler
     * @param  string  $host  a name or an IP address on loopback, 127.0.0.0/8 or ::1; an IPv6
     *                        address with or without brackets
     * @param  (callable(Throwable, Request): void)|null  $onError  receives each error: a handler
     *                                                              exception, once its response
     *                                                              is queued or its connection
     *                                                              closed, and a request whose
     *                                                              body the server is still
     *                                                              waiting for when it closes the
     *                                                              connection. Exceptions it
     *                                                              throws propagate out of
     *                                                              `tick()` or `close()`
     *
     * @throws ListenFailed
     */
    public function __construct(callable $handler, string $host = '127.0.0.1', int $port = 0, private readonly Limits $limits = new Limits, ?callable $onError = null)
    {
        /** @var ArrayObject<int, array{Throwable, Request}> $uncollected */
        $uncollected = new ArrayObject;
        $this->uncollected = $uncollected;
        $this->exchange = new Exchange($handler(...), $onError === null ? static function (Throwable $throwable, Request $request) use ($uncollected): void {
            $uncollected->append([$throwable, $request]);
        } : $onError(...), $limits);
        $this->clock = new Clock;

        // A colon is part of an IPv6 address, which may name its zone after `%`, or of a port or a
        // scheme, which the resolver would report as an unknown name.
        if (str_contains($host, '/') || (str_contains($host, ':') && filter_var((string) preg_replace('/%.*$/sD', '', trim($host, '[]')), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)) {
            throw new ListenFailed(sprintf('The server takes a name or an IP address as $host, without a scheme or a port, not %s. Pass the port as $port.', $host));
        }

        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        // A socket that is bound but does not listen takes no connection. Its name is the address
        // that the resolver makes of `$host`, also of `localhost`, `0` or `0.0`; only on that
        // address, and only if it is loopback, does the server listen.
        $probe = Quietly::run(static function () use ($host, &$code, &$message): mixed {
            return stream_socket_server(sprintf('tcp://%s:0', $host), $code, $message, STREAM_SERVER_BIND);
        });

        if ($probe === false) {
            throw new ListenFailed(sprintf('The server could not bind %s: %s.', $host, $message));
        }

        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $host = substr($name, 0, (int) strrpos($name, ':'));

        if (! $this->onLoopback(trim($host, '[]'))) {
            throw new ListenFailed(sprintf('The server listens on loopback only, not on %s.', $host));
        }

        // Without `tcp_nodelay`, Linux holds the body back until the client acknowledges the head,
        // and a client delays that ACK.
        $context = stream_context_create(['socket' => ['backlog' => self::BACKLOG, 'tcp_nodelay' => true]]);
        $listener = Quietly::run(static function () use ($host, $port, $context, &$code, &$message): mixed {
            return stream_socket_server(sprintf('tcp://%s:%d', $host, $port), $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        });

        if ($listener === false) {
            throw new ListenFailed(sprintf('The server could not listen on %s:%d: %s. Port 0 picks a free port.', $host, $port, $message));
        }

        stream_set_blocking($listener, false);
        $this->listener = $listener;
        $this->address = (string) stream_socket_get_name($listener, false);
    }

    /**
     * Host and port, such as `127.0.0.1:49152` or `[::1]:49152`.
     */
    public function address(): string
    {
        return $this->address;
    }

    /**
     * The base URL for the browser, such as `http://127.0.0.1:49152`.
     */
    public function url(): string
    {
        return 'http://'.$this->address;
    }

    /**
     * The sockets to pass to `stream_select()`, which returns once the server has work; two empty
     * lists once it is closed. The server reads, writes and closes them; the caller only waits.
     *
     * @return array{list<resource>, list<resource>} for reading and for writing
     */
    public function streams(): array
    {
        if ($this->listener === null) {
            return [[], []];
        }

        $read = [$this->listener];
        $write = [];

        foreach ($this->connections as $connection) {
            if ($this->exchange->reads($connection)) {
                $read[] = $connection->stream;
            }

            if ($connection->outgoing !== []) {
                $write[] = $connection->stream;
            }
        }

        return [$read, $write];
    }

    /**
     * Seconds until work is due that no socket signals: a connection past its timeout. Null when
     * there is none. A caller that waits on `streams()` waits no longer than this, then calls
     * `tick(0)`.
     */
    public function timeout(): ?float
    {
        $due = array_filter(array_map($this->exchange->due(...), array_values($this->connections)), is_int(...));

        return $due === [] ? null : max(0.0, (min($due) - $this->clock->now()) / 1e9);
    }

    /**
     * Handles the work that is ready, after a wait of at most `$seconds` and `timeout()`. A call
     * from code in a tick would answer a later request before the one in progress, so it throws.
     *
     * @throws ValueError for seconds that are negative, not finite or above 1e8
     * @throws RuntimeException when it cannot wait on its sockets or accept
     * @throws LogicException in a call from code that runs in a tick, or from a handler that
     *                        `close()` resumes
     */
    public function tick(float $seconds): void
    {
        if (is_nan($seconds) || $seconds < 0 || $seconds > Clock::MAX_SECONDS) {
            throw new ValueError(sprintf('%s::tick(): Argument #1 ($seconds) must be between 0 and %d', self::class, Clock::MAX_SECONDS));
        }

        if ($this->busy()) {
            throw new LogicException('tick() is already running, or close() resumes a handler. A tick now would answer later requests before the one in progress.');
        }

        $this->ticking = true;

        try {
            $this->work($seconds);
        } finally {
            $this->ticking = false;
        }
    }

    /**
     * Closes every connection and the listener, reports the errors of the requests it ended, and
     * throws for errors that no `onError` received; exceptions that `onError` throws propagate too.
     * A second call closes nothing more and only passes on the errors that an exception from
     * `onError` held back.
     *
     * @throws ClosedWithErrors
     * @throws LogicException in a call from code that runs in a tick, or from a handler that
     *                        `close()` resumes
     */
    public function close(): void
    {
        if ($this->busy()) {
            throw new LogicException('close() was called during tick(), or from a handler that close() resumes. A close now would end the request in progress.');
        }

        $this->closing = true;

        // Only a bug of the server throws here, and no test reaches it. Without `finally`, each
        // later close() and tick() would throw.
        try {
            foreach ($this->connections as $connection) {
                array_push($this->ended, ...$connection->close());
                $this->forget($connection);
            }
        } finally {
            $this->closing = false;
        }

        if (is_resource($this->listener)) {
            fclose($this->listener);
        }

        $this->listener = null;
        $this->reportEnded();
        $errors = array_values($this->uncollected->getArrayCopy());

        if ($errors !== []) {
            $this->uncollected->exchangeArray([]);

            throw new ClosedWithErrors(array_column($errors, 0), $errors[0][1]);
        }
    }

    /**
     * Whether an IP address without brackets, such as `127.0.0.1` or `::1`, is in 127.0.0.0/8 or
     * is ::1.
     */
    private function onLoopback(string $address): bool
    {
        $ip = inet_pton($address);

        return $ip === str_repeat("\0", 15)."\1" || (is_string($ip) && strlen($ip) === 4 && $ip[0] === "\x7F");
    }

    /**
     * Whether a tick runs, or `close()` closes the connections. A handler that `close()` resumes
     * runs outside a tick, but it is a handler too.
     */
    private function busy(): bool
    {
        return $this->ticking || $this->closing;
    }

    /**
     * Each error is taken off before `onError` gets it, so what `onError` throws leaves the rest
     * for the next report.
     */
    private function reportEnded(): void
    {
        while ($this->ended !== []) {
            [$throwable, $request] = array_shift($this->ended);
            $this->exchange->report($throwable, $request);
        }
    }

    private function work(float $seconds): void
    {
        if ($this->listener === null) {
            return;
        }

        $seconds = min($seconds, $this->timeout() ?? $seconds);
        [$read, $write] = $this->streams();
        $except = null;
        $whole = (int) $seconds;
        $ready = Quietly::run(static function () use (&$read, &$write, &$except, $whole, $seconds): int|false {
            return stream_select($read, $write, $except, $whole, (int) (($seconds - $whole) * 1_000_000));
        }, $warning);

        // A signal ends the wait early, with EINTR, which is 4 on Linux and macOS. Any other fault
        // would make each later wait fail too, so no client would get an answer anymore.
        if ($ready === false && ! str_contains((string) $warning, '[4]')) {
            throw new RuntimeException('The server cannot wait on its streams: '.$warning);
        }

        if ($ready === false || $ready === 0) {
            $this->expire();

            return;
        }

        foreach ($read as $stream) {
            if ($stream === $this->listener) {
                $this->accept();
            } else {
                $this->read($this->connections[(int) $stream]);
            }
        }

        // A read that ended a connection leaves its stream in the list.
        foreach ($write as $stream) {
            if (isset($this->connections[(int) $stream])) {
                $this->write($this->connections[(int) $stream]);
            }
        }

        // After the reads: bytes that arrived in time count, also when the process was paused.
        $this->expire();
    }

    /**
     * Ends each connection past its time.
     */
    private function expire(): void
    {
        $now = $this->clock->now();

        foreach ($this->connections as $connection) {
            try {
                $this->exchange->expire($connection, $now);
            } finally {
                $this->forget($connection);
            }
        }
    }

    /**
     * Accepts the connections that wait. A listener that is readable and gives no connection, as
     * when the process has no descriptor left, would end each later wait at once, so the method
     * throws.
     */
    private function accept(): void
    {
        $listener = $this->listener;
        assert($listener !== null);
        $accepted = false;

        while (($next = Quietly::run(static function () use ($listener): ?array {
            $client = stream_socket_accept($listener, 0, $peer);

            // The stubs of PHPStan give the address no type; PHP sets a string.
            return $client === false ? null : [$client, is_string($peer) ? $peer : ''];
        }, $warning)) !== null) {
            [$client, $peer] = $next;
            stream_set_blocking($client, false);

            // The address of `accept()`, as in Go: a client that resets the connection before has
            // no address for `stream_socket_get_name()`, but its bytes are still read.
            $parser = new RequestParser($this->limits->maxHead, remoteAddress: $peer, streams: true);
            $this->connections[(int) $client] = new Connection($client, $parser, new Deadlines($this->clock, $this->limits), (string) stream_socket_get_name($client, false), $peer);
            $accepted = true;
        }

        if (! $accepted) {
            throw new RuntimeException('The server cannot accept a connection: '.$warning);
        }
    }

    private function read(Connection $connection): void
    {
        try {
            $this->exchange->read($connection);
        } finally {
            $this->forget($connection);
        }
    }

    private function write(Connection $connection): void
    {
        try {
            $this->exchange->write($connection);
        } finally {
            $this->forget($connection);
        }
    }

    private function forget(Connection $connection): void
    {
        if (! $connection->open()) {
            unset($this->connections[(int) $connection->stream]);
        }
    }
}
