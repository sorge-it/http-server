<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer;

use SorgeIt\HttpServer\Internal\NoBody;
use ValueError;

/**
 * One HTTP/1.1 request as it arrived, with lowercase header names. The handler gets no header field
 * whose name contains `_` or `.`, as in Caddy.
 *
 * @api
 */
final readonly class Request
{
    /**
     * The request body. A body that the server read is readable only while the handler runs, as
     * in Go.
     */
    public Body $body;

    /**
     * @param  array<string, list<string>>  $headers  lowercase name => values in their order
     * @param  Body|null  $body  null for an empty body, like Go's `http.NoBody`
     * @param  string  $remoteAddress  the client's IP and port, like Go's `RemoteAddr`, such as
     *                                 `127.0.0.1:54321` or `[::1]:54321`; empty, as in Go, for a
     *                                 request that no server read
     *
     * @throws ValueError for a header name that is not lowercase, or values that are not a list
     */
    public function __construct(
        public string $method,
        public string $target,
        public array $headers = [],
        ?Body $body = null,
        public string $remoteAddress = '',
    ) {
        $fault = $this->fault($headers);

        if ($fault !== null) {
            throw new ValueError(sprintf('%s::__construct(): Argument #3 ($headers) %s', self::class, $fault));
        }

        $this->body = $body ?? new NoBody;
    }

    /**
     * The values of the header joined by `, `; null if absent.
     */
    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;

        return $values === null ? null : implode(', ', $values);
    }

    /**
     * The path of the request target, not decoded: the part before the first `?`. Go decodes it
     * into `URL.Path`.
     */
    public function path(): string
    {
        return explode('?', $this->target, 2)[0];
    }

    /**
     * The query of the request target, not decoded: Go's `URL.RawQuery`.
     */
    public function query(): string
    {
        return explode('?', $this->target, 2)[1] ?? '';
    }

    /**
     * What makes the headers ones that `header()` cannot find, if anything.
     *
     * @param  array<mixed>  $headers  as the caller gave them, whatever their documented type
     */
    private function fault(array $headers): ?string
    {
        foreach ($headers as $name => $values) {
            // PHP makes a name of digits an integer key.
            $name = (string) $name;

            if ($name !== strtolower($name)) {
                return 'has the name '.addcslashes($name, "\0..\37\177..\377").', which is not lowercase';
            }

            if (! is_array($values) || ! array_is_list($values)) {
                return 'has values of '.addcslashes($name, "\0..\37\177..\377").' that are not a list';
            }
        }

        return null;
    }
}
