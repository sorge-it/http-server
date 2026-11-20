<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

/**
 * Reads the head of a request as `readRequest()` of Go does with `net/textproto`, over the read
 * buffer, and takes only a good request: one that Chrome sends over HTTP/1.1 to `http://` on
 * loopback, see the scope in the README.
 *
 * @internal
 */
final readonly class HeadReader
{
    /**
     * The methods of a token that a browser does not send, in upper case.
     */
    private const array METHODS = ['CONNECT', 'TRACE', 'TRACK'];

    /**
     * The fields that a browser does not send: they ask for a body in chunks, trailers or a 100
     * (Continue).
     */
    private const array FIELDS = ['transfer-encoding' => true, 'expect' => true, 'trailer' => true, 'te' => true];

    /**
     * @param  int  $maxHead  bytes of a head, 0 for no limit
     * @param  int  $maxValues  field values of a head, 0 for no limit
     */
    public function __construct(private ReadBuffer $buffer, private int $maxHead, private int $maxValues) {}

    /**
     * Method, target, fields by lower-case name, and the length of the body; null where the client
     * ended before the first byte.
     *
     * @return array{string, string, array<string, list<string>>, int}|null
     *
     * @throws BadRequest 400 for a request that is not good, also for a head that the end of
     *                    the client or a timeout cut; 431 for a head over `maxHead` bytes or
     *                    over `maxValues` field values
     */
    public function read(): ?array
    {
        $left = $this->maxHead === 0 ? PHP_INT_MAX : $this->maxHead;
        $line = $this->line($left);

        // Go answers the end of the client before a request with nothing.
        if ($line === null) {
            return null;
        }

        [$method, $target] = $this->requestLine($line);
        $headers = $this->fields($left);
        $length = $this->check($headers);

        if (($headers['pragma'][0] ?? null) === 'no-cache' && ! isset($headers['cache-control'])) {
            $headers['cache-control'] = ['no-cache'];
        }

        return [$method, $target, $headers, $length];
    }

    /**
     * A line without its CRLF, within the bytes left of the head, which it takes off; null where
     * the client ended before its first byte.
     *
     * @throws BadRequest 400 for a line that ends with a bare LF, or that has no end; 431 for a
     *                    line past the bytes left
     */
    private function line(int &$left): ?string
    {
        $line = $this->buffer->line($left) ?? throw new BadRequest(431);
        $left -= strlen($line);

        if ($line === '') {
            return null;
        }

        return str_ends_with($line, "\r\n") ? substr($line, 0, -2) : throw new BadRequest(400);
    }

    /**
     * Method and target of `METHOD SP target SP HTTP/1.1`. The target is in origin form, of visible
     * ASCII without a fragment, and Go can decode its path, `url.ParseRequestURI()`.
     *
     * @return array{string, string}
     */
    private function requestLine(string $line): array
    {
        $parts = explode(' ', $line);
        [$method, $target] = $parts + ['', ''];

        if (count($parts) !== 3 || $parts[2] !== 'HTTP/1.1' || $method === '' || strspn($method, Grammar::TOKEN) !== strlen($method)
            || in_array(strtoupper($method), self::METHODS, true) || ! str_starts_with($target, '/')
            || preg_match('/[^\x21\x22\x24-\x7E]/', $target) === 1 || preg_match('/%(?![0-9A-Fa-f]{2})/', explode('?', $target, 2)[0]) === 1) {
            throw new BadRequest(400);
        }

        return [$method, $target];
    }

    /**
     * The field lines up to the empty line, by lower-case name. Each is a token, a colon and a
     * value. The value has no control byte but the tab, once the spaces and tabs around it are
     * removed. A line that starts with a space or a tab (obs-fold) has no token.
     *
     * @return array<string, list<string>>
     */
    private function fields(int &$left): array
    {
        $fields = [];
        $values = $this->maxValues === 0 ? PHP_INT_MAX : $this->maxValues;

        while (($line = $this->line($left) ?? throw new BadRequest(400)) !== '') {
            $colon = strspn($line, Grammar::TOKEN);
            $value = trim(substr($line, $colon + 1), " \t");

            if ($colon === 0 || ($line[$colon] ?? '') !== ':' || strpbrk($value, Grammar::CONTROL) !== false) {
                throw new BadRequest(400);
            }

            if (--$values < 0) {
                throw new BadRequest(431);
            }

            $fields[strtolower(substr($line, 0, $colon))][] = $value;
        }

        return $fields;
    }

    /**
     * The fields of a good request: one `Host` of a name or an address with an optional port, at
     * most one `Content-Length` of digits, and none of `FIELDS`. Gives the length of the body.
     *
     * @param  array<string, list<string>>  $headers
     */
    private function check(array $headers): int
    {
        $hosts = $headers['host'] ?? [];
        $lengths = $headers['content-length'] ?? ['0'];

        // Go takes a length of at most 2^63 - 1, `strconv.ParseUint(cl, 10, 63)`.
        if (count($hosts) !== 1 || preg_match('/^(?:\[([0-9A-Fa-f:.]++)\]|[A-Za-z0-9._-]++)(?::[0-9]{1,5})?$/D', $hosts[0], $host) !== 1
            || isset($host[1]) && filter_var($host[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
            || count($lengths) !== 1 || preg_match('/^(0|[1-9][0-9]{0,18})$/D', $lengths[0]) !== 1 || strcmp(str_pad($lengths[0], 19, '0', STR_PAD_LEFT), (string) PHP_INT_MAX) > 0
            || array_intersect_key($headers, self::FIELDS) !== []) {
            throw new BadRequest(400);
        }

        return (int) $lengths[0];
    }
}
