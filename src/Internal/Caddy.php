<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use Closure;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;

/**
 * The defaults and checks of Caddy 2.11.9 that FrankenPHP 1.14.0 serves with. Each names its place
 * in the source of Caddy, `modules/caddyhttp/`, so a new Caddy is compared place by place.
 *
 * @internal
 */
final class Caddy
{
    /**
     * app.go:274, `MaxHeaderBytes` for a server that sets none.
     */
    public const int MAX_HEADER_BYTES = 16 << 10;

    /**
     * The timeouts of the header section and of an idle connection, in seconds, measured on a
     * production FrankenPHP site behind a TLS proxy on 2026-11-02. Only the timeouts come from
     * there; the rest of this class follows the official build over HTTP.
     */
    public const float HEADER_TIMEOUT = 10.0;

    public const float IDLE_TIMEOUT = 120.0;

    /**
     * server.go:798: a longer method gets 405.
     */
    public const int MAX_METHOD = 32;

    /**
     * `ServerHeader`, server.go:549, as the official build of FrankenPHP sets it with
     * `-ldflags`, Dockerfile:145 of FrankenPHP. The production sends `Caddy` twice, from its proxy.
     */
    public const string SERVER = 'FrankenPHP Caddy';

    /**
     * `Server.serveHTTP()`, server.go:796: 405 for a long method, 400 for a host of dots, else the
     * handler without the fields whose names hold `_` or `.`. A good request has a host, so the 400
     * of server.go:814 does not come.
     *
     * @param  Closure(Request): Response  $handler
     */
    public static function serve(Request $request, Closure $handler): Response
    {
        return self::withServer(match (true) {
            strlen($request->method) > self::MAX_METHOD => new Response(405),
            // server.go:823-832. `HeadReader` takes only a good host, so of these checks only the
            // one for dots can refuse it.
            preg_match('/^\.++(:|$)/D', (string) $request->header('Host')) === 1 => new Response(400),
            default => $handler(new Request($request->method, $request->target, array_filter($request->headers, static fn (string $name): bool => strpbrk($name, '_.') === false, ARRAY_FILTER_USE_KEY), $request->body, $request->remoteAddress)),
        });
    }

    /**
     * The response with `Server` first, server.go:631, as the handler adds its own to it.
     */
    public static function withServer(Response $response): Response
    {
        $headers = ['Server' => [self::SERVER]];

        foreach ($response->headers as $name => $values) {
            $headers[$name] = $name === 'Server' ? [self::SERVER, ...is_string($values) ? [$values] : $values] : $values;
        }

        return new Response($response->status, $headers, $response->body, $response->file, $response->deleteFile);
    }
}
