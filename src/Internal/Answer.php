<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use Closure;
use Generator;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use UnexpectedValueException;

/**
 * The bytes of one answer as Go `net/http` 1.27.4 writes them for a handler that adds the headers,
 * calls `WriteHeader()` and writes the body. Unlike Go, it sends no trailers, and a chunked file
 * has chunks of 64 KB. Head and body stay apart, so a large body is not in memory twice.
 *
 * @internal
 */
final readonly class Answer
{
    /**
     * `StatusText()` of Go. Go writes `status code` and the number for a status not named here.
     */
    public const array TEXTS = [
        100 => 'Continue', 101 => 'Switching Protocols', 102 => 'Processing', 103 => 'Early Hints',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content',
        205 => 'Reset Content', 206 => 'Partial Content', 207 => 'Multi-Status', 208 => 'Already Reported', 226 => 'IM Used',
        300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        305 => 'Use Proxy', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Timeout',
        409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Request Entity Too Large',
        414 => 'Request URI Too Long', 415 => 'Unsupported Media Type', 416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed', 418 => "I'm a teapot", 421 => 'Misdirected Request', 422 => 'Unprocessable Entity',
        423 => 'Locked', 424 => 'Failed Dependency', 425 => 'Too Early', 426 => 'Upgrade Required', 428 => 'Precondition Required',
        429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
        504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported', 506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage', 508 => 'Loop Detected', 510 => 'Not Extended', 511 => 'Network Authentication Required',
    ];

    /**
     * `suppressedHeadersNoBody` of Go, and with `Content-Type` that of a 304.
     */
    private const array NO_BODY = ['Content-Length' => true, 'Transfer-Encoding' => true];

    private const array NOT_MODIFIED = self::NO_BODY + ['Content-Type' => true];

    /**
     * @param  list<string|FileBody|Generator<int, list<string>, mixed, bool>>  $parts
     * @param  bool  $closes  Go closes the connection after the answer
     */
    private function __construct(public string $head, public array $parts, public bool $closes) {}

    /**
     * The handler's result, if it is a response the server can send. The handler is the user's
     * code, so its type is not trusted: what fails here would fail in `tick()`.
     *
     * @throws UnexpectedValueException
     */
    public static function check(mixed $response): Response
    {
        if (! $response instanceof Response) {
            throw new UnexpectedValueException('The handler gave '.get_debug_type($response).', not a '.Response::class.'. A handler for a framework converts its response; see "Works with Symfony, Laravel and PSR-15" in the README.');
        }

        if ($response->file !== null && (! is_file($response->file) || ! is_readable($response->file))) {
            // A file made for the response is removed with it.
            FileBody::discard($response->file, $response->deleteFile);

            throw new UnexpectedValueException('The file '.$response->file.' could not be read.');
        }

        return $response;
    }

    /**
     * The refusal in place of the answer of the handler; the connection closes after it.
     */
    public static function refusal(BadRequest $refused): self
    {
        return new self($refused->answer(), [], true);
    }

    /**
     * The answer as `(*chunkWriter).writeHeader()` of Go makes the head. A file goes as from a
     * handler that sets its length and copies it, but without that length after a 1xx. A chunk goes
     * as a `Write()` with a `Flush()`.
     *
     * @param  (Closure(): bool)|null  $settle  `BodyReader::settle()` for the body of the request,
     *                                          before the head; none for a body read
     *
     * @throws UnexpectedValueException for a first chunk that is not a string, or a file that the server
     *                                  cannot read any more
     */
    public static function of(Response $response, Request $request, ?Closure $settle = null): self
    {
        $header = Header::of($response->headers);
        $status = $response->status;
        $head = '';

        // After a 1xx, the final answer has no header of the handler, frankenphp.go:696.
        if ($status < 200 && $status !== 101) {
            $head = self::line($status).Header::lines(array_diff_key($header, self::NO_BODY))."\r\n";
            $status = 200;
            $header = [];
        }

        $body = $response->body;
        $chunks = is_string($body) ? null : (static fn (): Generator => yield from $body)();
        $data = is_string($body) ? $body : '';
        $file = $response->file;
        $size = 0;

        if ($file !== null) {
            $size = Quietly::run(static fn (): int|false => filesize($file));
            $start = Quietly::run(static fn (): string|false => file_get_contents($file, length: Go::SNIFF));

            if ($size === false || $start === false) {
                // Untested: no code runs between `check()` and here, so only another process
                // removes the file.
                // @codeCoverageIgnoreStart
                FileBody::discard($file, $response->deleteFile);

                throw new UnexpectedValueException('The file '.$file.' could not be read.');
                // @codeCoverageIgnoreEnd
            }

            // After a 1xx, the length that the script set went with its other headers.
            if ($head === '') {
                $header['Content-Length'] = [(string) $size];
            }

            $data = $start;
        } elseif ($chunks instanceof Generator && $chunks->valid()) {
            $data = self::chunk($chunks->current());
        } else {
            $chunks = null;
        }

        // Only the first write decides the head.
        $allowed = $status >= 200 && $status !== 204 && $status !== 304;
        $length = self::length($header['Content-Length'][0] ?? '');
        $first = $data !== '' && $allowed && ($length === -1 || strlen($data) <= $length) ? $data : '';
        $isHead = $request->method === 'HEAD';
        $te = $header['Transfer-Encoding'][0] ?? '';
        $trailers = array_any(array_keys($header), static fn (int|string $name): bool => $name === 'Trailer' || str_starts_with((string) $name, 'Trailer:'));
        $extra = ['Date' => isset($header['Date']) ? null : gmdate('D, d M Y H:i:s').' GMT', 'Content-Length' => null, 'Content-Type' => null, 'Connection' => null, 'Transfer-Encoding' => null];

        // Go knows the length only when the handler returned before the head went out.
        $written = $file === null ? strlen($first) : $size;

        if (! $chunks instanceof Generator && $written <= Go::BEFORE_CHUNKING && ! $trailers && $te === '' && $allowed && ! isset($header['Content-Length']) && (! $isHead || $first !== '')) {
            $length = $written;
            $extra['Content-Length'] = (string) $length;
        }

        $connection = $request->headers['connection'] ?? [];
        $close = ($header['Connection'][0] ?? '') === 'close' || Grammar::listHas($connection, 'close') || Grammar::hasToken($connection[0] ?? '', 'close');

        // `requestTooLarge()` only marks the limit, net/http/server.go:567. `writeHeader()` then
        // closes and adds `Connection: close` after the headers of the handler, 1438 and 1580.
        if ($settle instanceof Closure && $settle()) {
            $close = true;
        }

        if (! $allowed) {
            $header = array_diff_key($header, $status === 304 ? self::NOT_MODIFIED : self::NO_BODY);
        } elseif (! isset($header['Content-Type']) && ($header['Content-Encoding'][0] ?? '') === '' && $te === '' && $first !== '') {
            $extra['Content-Type'] = Sniff::type($first);
        }

        $hasLength = $length !== -1;

        if ($hasLength && $te !== '' && $te !== 'identity') {
            unset($header['Content-Length']);
            $hasLength = false;
        }

        $chunked = ! $isHead && $allowed && ! $hasLength && $te !== 'identity';

        if ($chunked) {
            unset($header['Content-Length']);
            $extra['Transfer-Encoding'] = 'chunked';
        } elseif (! $isHead && $allowed && ! $hasLength) {
            // Without a length, only the close ends the body.
            $close = true;
        }

        if (! $chunked || $te === 'chunked') {
            unset($header['Transfer-Encoding']);
        }

        $switch = $status === 101 && ($header['Upgrade'][0] ?? '') !== '' && Grammar::listHas($header['Connection'] ?? [], 'upgrade');

        if ($close && ! $switch && ! Grammar::hasToken($header['Connection'][0] ?? '', 'close')) {
            unset($header['Connection']);
            $extra['Connection'] = 'close';
        }

        $head .= self::line($status).Header::lines($header);

        foreach (array_filter($extra, is_string(...)) as $name => $value) {
            $head .= $name.': '.$value."\r\n";
        }

        $head .= "\r\n";

        $last = $chunked ? "0\r\n\r\n" : '';

        if ($file !== null) {
            $parts = [];

            // `Php` gives HEAD the length of a file in place of the file.
            if (! $allowed || $size === 0) {
                FileBody::discard($file, $response->deleteFile);
            } else {
                $parts[] = new FileBody($file, $size, $response->deleteFile, $chunked);
            }

            return new self($head, $last === '' ? $parts : [...$parts, $last], $close);
        }

        $writes = self::body($chunks ?? [$data], $allowed && ! $isHead, $allowed, $chunked, $length, $last);

        if ($chunks instanceof Generator) {
            return new self($head, [$writes], $close);
        }

        $parts = array_merge(...iterator_to_array($writes, false));

        return new self($head, $parts, $close || $writes->getReturn());
    }

    /**
     * Drops an answer that is not sent, and removes a file made for it.
     */
    public function discard(): void
    {
        foreach ($this->parts as $part) {
            if ($part instanceof FileBody) {
                $part->close();
            }
        }
    }

    /**
     * One list for each chunk, empty where Go drops it, so the server can stop after any chunk.
     * Returns whether the body fell short of its length.
     *
     * @param  iterable<mixed>  $chunks
     * @return Generator<int, list<string>, mixed, bool>
     */
    private static function body(iterable $chunks, bool $sent, bool $counted, bool $chunked, int $length, string $last): Generator
    {
        $written = 0;

        foreach ($chunks as $chunk) {
            $chunk = self::chunk($chunk);
            $size = strlen($chunk);
            $written += $counted ? $size : 0;

            // Go refuses each write past the length, and each later one.
            if ($size === 0 || ! $sent || ($length !== -1 && $written > $length)) {
                yield [];

                continue;
            }

            yield $chunked ? [dechex($size)."\r\n", $chunk, "\r\n"] : [$chunk];
        }

        if ($last !== '') {
            yield [$last];
        }

        return $sent && $length !== -1 && $written !== $length;
    }

    /**
     * Checked when it is taken, not in `Response`: a generator gives its chunks only then. A bad
     * chunk after the head ends the connection, as a Go handler that panics does.
     *
     * @throws UnexpectedValueException
     */
    private static function chunk(mixed $chunk): string
    {
        if (! is_string($chunk)) {
            throw new UnexpectedValueException('The body gave '.get_debug_type($chunk).', not a string.');
        }

        return $chunk;
    }

    /**
     * The status line, with the text of Go.
     */
    private static function line(int $status): string
    {
        return 'HTTP/1.1 '.$status.' '.(self::TEXTS[$status] ?? 'status code '.$status)."\r\n";
    }

    /**
     * The `Content-Length` of the handler as `strconv.ParseInt()` of Go reads it, or -1: Go takes
     * a sign and leading zeros, and drops a negative length.
     */
    private static function length(string $value): int
    {
        if (preg_match('/^(?:\+?0*(\d{1,19})|-0+)$/D', $value, $match) !== 1) {
            return -1;
        }

        $digits = $match[1] ?? '';

        return strlen($digits) === 19 && strcmp($digits, (string) PHP_INT_MAX) > 0 ? -1 : (int) $digits;
    }
}
