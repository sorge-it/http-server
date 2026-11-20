<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use Closure;
use Generator;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use Throwable;

/**
 * The SAPI of PHP 8.5.13 in FrankenPHP 1.13.6, with the php.ini of this process: what they do to
 * the response of a script that calls `header()` for each value, then `http_response_code()`,
 * then echoes the body. Each rule names its place in the source.
 *
 * @internal
 */
final class Php
{
    /**
     * The ini directives the rules read. CLI fixes `output_buffering`, `implicit_flush` and
     * `html_errors`, so the rules take 0, 0 and 1, as FrankenPHP has them.
     */
    private const array INI = ['expose_php', 'default_mimetype', 'default_charset', 'display_errors'];

    /**
     * What `isspace()` of C finds; `sapi_header_op()` cuts it off the end of a header,
     * main/SAPI.c:740.
     */
    private const string SPACE = " \t\n\r\v\f";

    /**
     * The ini of `INI` is read after the handler and its first chunk, as PHP sends the headers
     * then. `zend_deactivate()` resets an ini the script set at the end of the request,
     * main/main.c:2055; the server sets its own back after the handler and after each later chunk.
     *
     * @param  Closure(Request): Response  $handler
     * @param  Closure(Throwable): void  $failed  takes each throwable PHP turns into output
     */
    public static function serve(Request $request, Closure $handler, Closure $failed): Response
    {
        $before = self::ini();
        $thrown = false;

        try {
            try {
                $response = $handler($request);
            } catch (Throwable $throwable) {
                $response = self::error($throwable, new Response, $failed);
                $thrown = true;
            }

            // Status and headers of the handler are set when its first chunk throws.
            try {
                $response = self::started($response);
            } catch (Throwable $throwable) {
                $response = self::error($throwable, $response, $failed);
                $thrown = true;
            }

            $ini = self::ini();
        } finally {
            self::restore($before);
        }

        if ($response->body instanceof Generator) {
            $response = new Response($response->status, $response->headers, self::caught($response->body, $ini, $failed));
        }

        return self::response($response, $request, $ini, $thrown);
    }

    /**
     * The answer of PHP to a throw before any output, where the server finds it after the handler.
     */
    public static function failure(Throwable $throwable, Request $request): Response
    {
        return self::response(self::error($throwable, new Response, static function (): void {}), $request, self::ini(), true);
    }

    /**
     * @param  array<string, string>  $ini  the values of `INI`, as `ini_get()` gives them
     * @param  bool  $thrown  a throw before any output: a status of 200 becomes 500 where
     *                        `display_errors` is off, main/main.c:1541-1549
     */
    public static function response(Response $response, Request $request, array $ini, bool $thrown = false): Response
    {
        $charset = $ini['default_charset'] ?? '';
        $lines = self::bool($ini['expose_php'] ?? '') ? ['X-Powered-By: PHP/'.PHP_VERSION] : [];
        $typed = false;
        // The status PHP has before the script sets one; a status of 0 sets none.
        $status = 200;

        foreach ($response->headers as $name => $values) {
            $first = true;

            foreach (is_array($values) ? $values : [$values] as $value) {
                $replaces = $first;
                $first = false;
                $line = rtrim($name.': '.$value, self::SPACE);

                // PHP refuses the header with a warning, main/SAPI.c:763.
                if (strpbrk($line, "\r\n\0") !== false) {
                    continue;
                }

                $key = (string) strstr($line, ':', true);

                if (strcasecmp($key, 'Content-Type') === 0) {
                    $line = self::type($line, strlen($key), $charset);
                    $typed = true;
                }

                $status = self::status($status, $key);

                // `header()` replaces each header of the name, main/SAPI.c:666.
                if ($replaces) {
                    $lines = array_filter($lines, static fn (string $kept): bool => strcasecmp((string) strstr($kept, ':', true), $key) !== 0);
                }

                $lines[] = $line;
            }
        }

        $mimetype = $ini['default_mimetype'] ?? '';

        // `sapi_send_headers()`, main/SAPI.c:878, and `get_default_content_type()`, 303.
        if (! $typed && $mimetype !== '') {
            $lines[] = 'Content-type: '.$mimetype.($charset !== '' && strncasecmp($mimetype, 'text/', 5) === 0 ? '; charset='.$charset : '');
        }

        $headers = [];

        // `splitRawHeader()` and `Header().Add()` of FrankenPHP, frankenphp.go:618: the name up to
        // the colon, the value after its spaces.
        foreach ($lines as $line) {
            [$key, $value] = explode(':', $line, 2);

            if ($key !== '') {
                $headers[Header::canonical($key)][] = ltrim($value, ' ');
            }
        }

        // `http_response_code(0)` sets none, ext/standard/head.c:387; a status Go refuses gets
        // 500, frankenphp.go:684.
        $status = $response->status === 0 ? $status : $response->status;
        $status = $thrown && $status === 200 && ! self::displays($ini['display_errors'] ?? '') ? 500 : $status;
        $status = $status < 100 || $status > 999 ? 500 : $status;

        if ($request->method !== 'HEAD') {
            return new Response($status, $headers, $response->body, $response->file, $response->deleteFile);
        }

        // For HEAD, PHP sends no output, main/output.c:121. The length of a file is a header of
        // the script.
        if ($response->file !== null) {
            $headers['Content-Length'] = [(string) filesize($response->file)];
            FileBody::discard($response->file, $response->deleteFile);
        }

        return new Response($status, $headers, is_string($response->body) ? '' : self::silent($response->body));
    }

    /**
     * @return array<string, string>
     */
    private static function ini(): array
    {
        $ini = [];

        foreach (self::INI as $name) {
            $ini[$name] = (string) ini_get($name);
        }

        return $ini;
    }

    /**
     * The status after a header, main/SAPI.c:834-852: `Location` gives 302 unless the status is
     * 201 or 3xx, and `WWW-Authenticate` 401. The 303 for a POST never comes: `sapi_activate()`
     * sets the version to 1.0, main/SAPI.c:452, after cgi.go:464 set it, frankenphp.c:1951.
     */
    private static function status(int $status, string $name): int
    {
        if (strcasecmp($name, 'Location') === 0 && ($status < 300 || $status > 399) && $status !== 201) {
            return 302;
        }

        return strcasecmp($name, 'WWW-Authenticate') === 0 ? 401 : $status;
    }

    /**
     * A streamed body, started: a throw before the first chunk is one before any output. Each
     * chunk is an echo with a `flush()`, which sends the headers.
     */
    private static function started(Response $response): Response
    {
        if (is_string($response->body) || is_array($response->body)) {
            return $response;
        }

        $chunks = (static fn (): Generator => yield from $response->body)();
        $chunks->valid();

        return new Response($response->status, $response->headers, $chunks);
    }

    /**
     * The chunks up to a throw, then the page of the error where it is displayed. The script ends,
     * and FrankenPHP ends the body as for any script. Each chunk runs with the ini of the script
     * so far, and the server sets its own back after it.
     *
     * @param  Generator<mixed>  $chunks  started
     * @param  array<string, string>  $ini  the ini of the script after its first chunk
     * @param  Closure(Throwable): void  $failed
     * @return Generator<int, mixed>
     */
    private static function caught(Generator $chunks, array $ini, Closure $failed): Generator
    {
        while ($chunks->valid()) {
            yield $chunks->current();
            $before = self::ini();
            self::restore($ini);
            $page = null;

            try {
                $chunks->next();
            } catch (Throwable $throwable) {
                $failed($throwable);
                $page = self::displays((string) ini_get('display_errors')) ? self::page($throwable) : '';
            } finally {
                $ini = self::ini();
                self::restore($before);
            }

            if ($page !== null) {
                yield $page;

                return;
            }
        }
    }

    /**
     * A throw before any output, with the status and headers the script set: with
     * `display_errors`, the page of the error, else no body; `response()` makes a 200 a 500 then.
     *
     * @param  Closure(Throwable): void  $failed
     */
    private static function error(Throwable $throwable, Response $set, Closure $failed): Response
    {
        $failed($throwable);

        return new Response($set->status, $set->headers, self::displays((string) ini_get('display_errors')) ? self::page($throwable) : '');
    }

    /**
     * @param  array<string, string>  $before
     */
    private static function restore(array $before): void
    {
        foreach ($before as $name => $value) {
            if (ini_get($name) !== $value) {
                ini_set($name, $value);
            }
        }
    }

    /**
     * The fatal error of an uncaught throwable with `html_errors`, as `php_error_cb()` writes it,
     * main/main.c:1497-1499, of the message of `zend_exception_error()`.
     */
    private static function page(Throwable $throwable): string
    {
        $message = 'Uncaught '.self::described($throwable)."\n  thrown";
        // `escape_html()`, main/main.c:1055: a second try replaces bytes the charset does not take.
        $escaped = htmlspecialchars($message, ENT_COMPAT);
        $escaped = $escaped === '' ? htmlspecialchars($message, ENT_COMPAT | ENT_SUBSTITUTE) : $escaped;

        return "<br />\n<b>Fatal error</b>:  ".$escaped.' in <b>'.$throwable->getFile().'</b> on line <b>'.$throwable->getLine()."</b><br />\n";
    }

    /**
     * The throwable as `Exception::__toString()` gives it, with a trace that ends where the server
     * called the handler: that call is the `{main}` of a script.
     */
    private static function described(Throwable $throwable): string
    {
        $described = '';

        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            $frames = 0;

            foreach ($current->getTrace() as $frame) {
                if (str_starts_with($frame['file'] ?? '', dirname(__DIR__).DIRECTORY_SEPARATOR)) {
                    break;
                }

                $frames++;
            }

            $trace = implode("\n", [...array_slice(explode("\n", $current->getTraceAsString()), 0, $frames), '#'.$frames.' {main}']);
            $head = $current::class.($current->getMessage() === '' ? '' : ': '.$current->getMessage());
            $own = $head.' in '.$current->getFile().':'.$current->getLine()."\nStack trace:\n".$trace;
            $described = $described === '' ? $own : $own."\n\nNext ".$described;
        }

        return $described;
    }

    /**
     * The value as `php_get_display_errors_mode()` reads it, main/main.c:514: `stderr` writes to
     * the output too, as FrankenPHP is no CLI.
     */
    private static function displays(string $value): bool
    {
        return in_array(strtolower($value), ['on', 'yes', 'true', 'stderr', 'stdout'], true) || ((int) $value & 0xFF) !== 0;
    }

    /**
     * The value as `zend_ini_parse_bool()` reads it.
     */
    private static function bool(string $value): bool
    {
        return in_array(strtolower($value), ['true', 'yes', 'on'], true) || (int) $value !== 0;
    }

    /**
     * `sapi_apply_default_charset()`, main/SAPI.c:369: a type that starts with `text/`, in that
     * case, and holds no `charset=` gets `default_charset`.
     */
    private static function type(string $line, int $colon, string $charset): string
    {
        $type = ltrim(substr($line, $colon + 1), ' ');

        return $charset !== '' && str_starts_with($type, 'text/') && ! str_contains($type, 'charset=') ? 'Content-type: '.$type.';charset='.$charset : $line;
    }

    /**
     * The chunks without a byte, as the output for HEAD. They still run, as the script does; one
     * that is not a string stays, so the server refuses it as before.
     *
     * @param  iterable<mixed>  $chunks
     * @return Generator<int, mixed>
     */
    private static function silent(iterable $chunks): Generator
    {
        foreach ($chunks as $chunk) {
            yield is_string($chunk) ? '' : $chunk;
        }
    }
}
