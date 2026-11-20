<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use Closure;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;

/**
 * The sizes, defaults, error texts and handler steps of Go 1.27.4 that decide which bytes the
 * server reads and writes. Each names its place in `.go/go/src`, so a new Go is compared place by
 * place.
 *
 * @internal
 */
final class Go
{
    /**
     * `DefaultMaxHeaderBytes`, net/http/server.go:935: the default of `RequestParser`. The server
     * gives the parser `maxHead`.
     */
    public const int MAX_HEADER_BYTES = 1 << 20;

    /**
     * `DefaultMaxHeaderValueCount`, net/http/server.go:940.
     */
    public const int MAX_HEADER_VALUES = 500;

    /**
     * `rstAvoidanceDelay`, net/http/server.go:1830, in nanoseconds: how long a connection reads and
     * drops what the client still sends before it closes after a 413 or a 431. If the connection
     * closes at once, unread bytes reset it, and the client can lose the answer.
     */
    public const int LINGER = 500_000_000;

    /**
     * net/http/request.go:1223: the error of `MaxBytesReader`.
     */
    public const string TOO_LARGE = 'http: request body too large';

    /**
     * `ErrBodyReadAfterClose`, net/http/transfer.go:845.
     */
    public const string READ_AFTER_CLOSE = 'http: invalid Read on closed Body';

    /**
     * `errNetClosing`, internal/poll/fd.go:24: a read of a connection the server closed, after
     * `read tcp <server>-><client>: `.
     */
    public const string NET_CLOSING = 'use of closed network connection';

    /**
     * `bufferBeforeChunkingSize`, net/http/server.go:342: a longer write goes out before Go knows
     * the length of the body.
     */
    public const int BEFORE_CHUNKING = 2048;

    /**
     * `SniffLen`, net/http/internal/sniff.go:13: the bytes that decide the `Content-Type`.
     */
    public const int SNIFF = 512;

    /**
     * io/io.go:48.
     */
    public const string UNEXPECTED_EOF = 'unexpected EOF';

    /**
     * A handler that reads the body through `http.MaxBytesReader()` first,
     * net/http/request.go:1209, and calls `http.Error()` with 413 for a body over the limit.
     * `requestTooLarge()` marks the limit, net/http/server.go:566, and the connection closes;
     * `Answer::of()` adds the `Connection: close` through `settle`.
     *
     * @param  Closure(Request): Response  $handler
     * @param  int  $maxBody  0 for no limit
     */
    public static function limited(Request $request, Closure $handler, int $maxBody): Response
    {
        return $maxBody > 0 && $request->body instanceof RequestBody && ! $request->body->readUpTo($maxBody) ? self::error(413) : $handler($request);
    }

    /**
     * The response of `http.Error()`, net/http/server.go:2377.
     */
    public static function error(int $status): Response
    {
        return new Response($status, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff'], Answer::TEXTS[$status]."\n");
    }
}
