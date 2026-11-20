<?php

declare(strict_types=1);

use Random\Randomizer;
use SorgeIt\HttpServer\Body;
use SorgeIt\HttpServer\ClosedWithErrors;
use SorgeIt\HttpServer\Internal\Clock;
use SorgeIt\HttpServer\Internal\RequestParser;
use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;
use SorgeIt\HttpServer\Tests\FrankenPhp\FrankenPhpReference;

/**
 * The answer to each request that is not good: see `browserSorted()`.
 */
const BAD_REQUEST = "HTTP/1.1 400 Bad Request\r\nContent-Type: text/plain; charset=utf-8\r\nConnection: close\r\n\r\n400 Bad Request";

/**
 * The answer to a head over `maxHead`: see `browserSorted()`.
 */
const HEAD_TOO_LARGE = "HTTP/1.1 431 Request Header Fields Too Large\r\nContent-Type: text/plain; charset=utf-8\r\nConnection: close\r\n\r\n431 Request Header Fields Too Large";

/**
 * Good requests the parser reads without a fault. Each one leads to another part of the parser, so
 * the requests mutated from all of them reach most of its paths.
 *
 * @return array<string, string>
 */
function fuzzSeeds(): array
{
    return [
        'GET with a query and a field' => "GET /a?b=c HTTP/1.1\r\nHost: a\r\nX-A: b\r\n\r\n",
        'POST with a length' => "POST /a HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nhello",
        'two requests with lengths' => "PUT /a HTTP/1.1\r\nHost: a\r\nContent-Length: 2\r\n\r\nhiDELETE /b HTTP/1.1\r\nHost: a\r\nContent-Length: 0\r\n\r\n",
        'a method in lower case and a field twice' => "patch /a HTTP/1.1\r\nHost: a:8080\r\nX: 1\r\nX: 2\r\nContent-Length: 2\r\n\r\nhi",
        'Connection: close, then a request' => "GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\nGET /b HTTP/1.1\r\nHost: a\r\n\r\n",
        'Upgrade to a WebSocket' => "GET /ws HTTP/1.1\r\nHost: 127.0.0.1:80\r\nConnection: Upgrade\r\nUpgrade: websocket\r\n\r\n",
        'IPv6 host' => "GET / HTTP/1.1\r\nHost: [::1]:80\r\n\r\n",
        'bytes over 0x7F in a value, and a query that Go does not decode' => "GET /a?%zz HTTP/1.1\r\nHost: a\r\nX: \xC3\xA4\r\n\r\n",
        'whitespace around field values' => "GET / HTTP/1.1\r\nHost: a\r\nX: \t b \t\r\nY:\r\n\r\n",
    ];
}

/**
 * Sorts the bytes of a client by the rule of the scope, without a class of the server: `good`, also
 * after a request that closes; `bad` at the first other request; `cut` for a cut body; `large` for
 * a head over `maxHead`. Also the good requests before, whole and each.
 *
 * @return array{'good'|'bad'|'cut'|'large', string, list<string>}
 */
function browserSorted(string $bytes, int $maxHead = 0): array
{
    $token = '[!#$%&\'*+\-.^_`|~0-9A-Za-z]++';
    $requests = [];

    while ($bytes !== '') {
        $before = implode('', $requests);
        $left = $maxHead === 0 ? PHP_INT_MAX : $maxHead;
        $end = 0;
        $fields = [];

        // Line by line, as the server checks each line once it ends: one that does not end within
        // the bytes left of `maxHead` makes the head too large, also before its end came.
        while (true) {
            $lf = strpos($bytes, "\n", $end);

            if ($lf === false || $lf - $end >= $left) {
                return [strlen($bytes) - $end >= $left ? 'large' : 'bad', $before, $requests];
            }

            $first = $end === 0;
            $line = substr($bytes, $end, $lf - $end);
            $left -= $lf + 1 - $end;
            $end = $lf + 1;

            // A bare LF, a bare CR, an obs-fold, a space before the colon, or a control byte.
            if (! str_ends_with($line, "\r")) {
                return ['bad', $before, $requests];
            }

            $line = substr($line, 0, -1);

            if ($first) {
                if (! goodRequestLine($line, $token)) {
                    return ['bad', $before, $requests];
                }

                continue;
            }

            if ($line === '') {
                break;
            }

            if (preg_match('/^('.$token.'):(.*)$/sD', $line, $match) !== 1 || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value = trim($match[2], " \t")) === 1) {
                return ['bad', $before, $requests];
            }

            $fields[strtolower($match[1])][] = $value;
        }

        $end -= 4;

        $hosts = $fields['host'] ?? [];
        $lengths = $fields['content-length'] ?? ['0'];

        if (count($hosts) !== 1 || preg_match('/^(?:\[([0-9A-Fa-f:.]++)\]|[A-Za-z0-9.\-_]++)(?::[0-9]{1,5})?$/D', $hosts[0], $host) !== 1
            || isset($host[1]) && filter_var($host[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
            || count($lengths) !== 1 || preg_match('/^(0|[1-9][0-9]*)$/D', $lengths[0]) !== 1
            || array_intersect(array_keys($fields), ['transfer-encoding', 'expect', 'trailer', 'te']) !== []) {
            return ['bad', $before, $requests];
        }

        // A length over PHP_INT_MAX counts as PHP_INT_MAX.
        $length = (int) $lengths[0];
        $closes = preg_match('/(^|,)[ \t]*close[ \t]*(,|$)/i', implode(',', $fields['connection'] ?? [])) === 1;

        if (strlen($bytes) - $end - 4 < $length) {
            return ['cut', $before, $requests];
        }

        $requests[] = substr($bytes, 0, $end + 4 + $length);
        $bytes = substr($bytes, $end + 4 + $length);

        if ($closes) {
            break;
        }
    }

    return ['good', implode('', $requests), $requests];
}

/**
 * Whether the request line is `METHOD SP target SP HTTP/1.1` of a good request.
 */
function goodRequestLine(string $line, string $token): bool
{
    $parts = explode(' ', $line);

    return count($parts) === 3 && $parts[2] === 'HTTP/1.1' && preg_match('/^'.$token.'$/D', $parts[0]) === 1
        && ! in_array(strtoupper($parts[0]), ['CONNECT', 'TRACE', 'TRACK'], true) && preg_match('/^\/[\x21\x22\x24-\x7E]*+$/D', $parts[1]) === 1
        && preg_match('/%(?![0-9A-Fa-f]{2})/', explode('?', $parts[1], 2)[0]) !== 1;
}

/**
 * Whether a list of the head names the token, as `close` in `Connection`.
 */
function namesToken(string $head, string $field, string $token): bool
{
    preg_match_all('/\r\n'.$field.':([^\r]*)/i', $head, $values);

    return preg_match('/(^|,)[ \t]*'.$token.'[ \t]*(,|$)/i', implode(',', $values[1])) === 1;
}

/**
 * The offset of the last response in the answers to the requests, found by the framing of each
 * response before it: no body for HEAD, its length, its chunks, or no body at all.
 *
 * @param  list<string>  $requests
 */
function lastResponseAt(string $answers, array $requests): int
{
    $last = 0;

    for ($at = 0; $at < strlen($answers) && ($end = strpos($answers, "\r\n\r\n", $at)) !== false;) {
        $last = $at;
        $head = substr($answers, $at, $end + 2 - $at);
        $at = $end + 4;
        $status = (int) substr($head, 9, 3);

        if ($status < 200) {
            continue;
        }

        if (str_starts_with((string) array_shift($requests), 'HEAD ') || $status === 204 || $status === 304) {
            continue;
        }

        if (preg_match('/\r\nContent-Length: (\d+)\r\n/i', $head, $match) === 1) {
            $at += (int) $match[1];
        } elseif (namesToken($head, 'Transfer-Encoding', 'chunked')) {
            while (preg_match('/\G([0-9a-f]+)\r\n/i', $answers, $chunk, 0, $at) === 1 && ($size = (int) hexdec($chunk[1])) > 0) {
                $at += strlen($chunk[0]) + $size + 2;
            }

            // The last chunk, the trailers, and the empty line; a cut body has no more answer.
            $tail = $at <= strlen($answers) ? strpos($answers, "\r\n\r\n", $at) : false;

            if ($tail === false) {
                break;
            }

            $at = $tail + 4;
        } else {
            break;
        }
    }

    return $last;
}

/**
 * The answer where the model closes after each body of 256 KB or more that its handler did not
 * read. The server drops each rest and goes on, so the requests after it get the answers of the
 * model alone. Null where no such body is; a request that asks for the close has none.
 *
 * @param  list<string>  $requests
 * @param  Closure(string): string  $model
 */
function restDropped(array $requests, Closure $model): ?string
{
    $answer = null;
    $from = 0;

    foreach ($requests as $k => $request) {
        $head = substr($request, 0, (int) strpos($request, "\r\n\r\n") + 2);

        if (preg_match('/\r\ncontent-length:[ \t]*(\d+)\r\n/i', $head, $match) !== 1 || (int) $match[1] < 256 << 10 || namesToken($head, 'connection', 'close')) {
            continue;
        }

        $sent = array_slice($requests, $from, $k + 1 - $from);
        $answers = substr($model(implode('', $sent)), 0, -strlen("\n<closed>"));
        $last = lastResponseAt($answers, $sent);

        // A 413 closes in both.
        if (str_starts_with(substr($answers, $last), 'HTTP/1.1 413 ')) {
            return $answer.$answers."\n<closed>";
        }

        $answer .= substr($answers, 0, $last).preg_replace('/\r\nConnection: close(?=\r\n)/', '', substr($answers, $last), 1);
        $from = $k + 1;
    }

    $rest = implode('', array_slice($requests, $from));

    return $answer === null ? null : $answer.($rest === '' ? "\n<closed>" : $model($rest));
}

/**
 * What the server must answer: to good requests the model; else the model up to the first other
 * request, then the 400 unless the model closed. The model holds too for its 413 or 431. A head
 * over `maxHead` gets 431 after the answers of the model to the requests before it.
 *
 * @param  Closure(string): string  $model  the answer to the bytes and the end, with `<closed>`
 */
function scopeAnswer(string $answer, string $bytes, Closure $model, int $maxHead = 0): string
{
    [$verdict, $before, $requests] = browserSorted($bytes, $maxHead);

    if ($verdict === 'good') {
        return restDropped($requests, $model) ?? $model($bytes);
    }

    $answers = $before === '' ? '' : substr($model($before), 0, -strlen("\n<closed>"));
    $refused = $answers.(str_contains($answers, "\r\nConnection: close\r\n") ? '' : ($verdict === 'large' ? HEAD_TOO_LARGE : BAD_REQUEST))."\n<closed>";

    if ($answer === $refused || $verdict === 'large') {
        return $refused;
    }

    $whole = $model($bytes);

    return preg_match('/^HTTP\/1\.[01] 4(13|31) /m', $whole) === 1 ? $whole : $refused;
}

/**
 * The seed with one to four mutations. Small mutations keep most of a valid request, so the parser
 * reads past the request line; a second seed before or after it tests the bytes between requests.
 *
 * @param  list<string>  $seeds
 */
function mutate(Randomizer $random, string $seed, array $seeds): string
{
    // Bytes and words where a parser decides how a request is framed.
    $tokens = [
        "\r\n", "\n", "\r", "\r\n\r\n", ' ', "\t", ':', ';', ',', '=', '"', '\\', "\0", "\x0b", "\x7f", "\xff",
        '0', '1', 'f', '-', '+', '#', '?', '*', '@', '[', ']', '%', 'chunked', ' chunked', 'gzip, ',
        "Transfer-Encoding: chunked\r\n", "Content-Length: 0\r\n", "Content-Length: 5\r\n", "Host: a\r\n",
        "Expect: 100-continue\r\n", "0\r\n\r\n", ';a="b"', 'ffffffffffffffff', '99999999999999999999',
        'HTTP/1.0', 'HTTP/1.1', 'HTTP/2.0', 'CONNECT', 'OPTIONS', 'http://a/', 'a:1', str_repeat('a', 200),
    ];
    $bytes = $seed;

    for ($mutations = $random->getInt(1, 4); $mutations > 0; $mutations--) {
        $length = strlen($bytes);
        $at = $random->getInt(0, $length);
        $bytes = match ($random->getInt(0, 6)) {
            0 => substr_replace($bytes, $random->getInt(0, 1) === 0 ? $random->getBytes(1) : $tokens[$random->getInt(0, 15)], $at, 1),
            1 => substr_replace($bytes, $tokens[$random->getInt(0, count($tokens) - 1)], $at, $random->getInt(0, 8)),
            2 => substr_replace($bytes, '', $at, $random->getInt(1, 16)),
            3 => substr_replace($bytes, substr($bytes, $random->getInt(0, $length), $random->getInt(1, 64)), $at, 0),
            4 => substr($bytes, 0, $at),
            // About as long as the read limit of a small head, 4160 to 4352 bytes.
            5 => substr_replace($bytes, str_repeat('a', $random->getInt(3900, 4400)), $at, 0),
            default => $random->getInt(0, 1) === 0
                ? $bytes.$seeds[$random->getInt(0, count($seeds) - 1)]
                : $seeds[$random->getInt(0, count($seeds) - 1)].$bytes,
        };
    }

    return $bytes;
}

/**
 * Moves the time of the server by the seconds: ahead, as if they had passed, or back for a
 * negative value.
 */
function advance(Server $server, float $seconds): void
{
    $clock = new ReflectionProperty(Server::class, 'clock')->getValue($server);
    assert($clock instanceof Clock);
    $clock->advance($seconds);
}

/**
 * A path for a new file below `.tmp/tests`, with the content if one is given; the file is removed
 * after the run.
 */
function temporaryFile(?string $content = null): string
{
    $directory = __DIR__.'/../.tmp/tests';

    if (! is_dir($directory)) {
        mkdir($directory, 0o755, true);
    }

    $file = $directory.'/file-'.bin2hex(random_bytes(4));
    register_shutdown_function(static fn (): bool => ! is_file($file) || unlink($file));

    if ($content !== null) {
        file_put_contents($file, $content);
    }

    return $file;
}

/**
 * Cuts the file to the given bytes.
 *
 * @param  int<0, max>  $bytes
 */
function truncate(string $file, int $bytes): void
{
    $handle = fopen($file, 'r+b');
    assert(is_resource($handle));
    ftruncate($handle, $bytes);
    fclose($handle);
}

/**
 * Runs a call whose warning is an expected answer, such as a write to a connection the server
 * closed. Test runners report a warning even under `@`.
 *
 * @template T
 *
 * @param  Closure(): T  $call
 * @return T
 */
function withoutWarnings(Closure $call): mixed
{
    set_error_handler(static fn (): bool => true);

    try {
        return $call();
    } finally {
        restore_error_handler();
    }
}

/**
 * Runs the call under an error handler that turns a warning into an exception, as Laravel does in
 * a test process.
 *
 * @template T
 *
 * @param  Closure(): T  $call
 * @return T
 */
function whereWarningsThrow(Closure $call): mixed
{
    set_error_handler(static fn (int $level, string $message): never => throw new ErrorException($message, 0, $level));

    try {
        return $call();
    } finally {
        restore_error_handler();
    }
}

/**
 * The bytes with the time of each `Date` as in the fixture of Go: its reference time.
 */
function sameDate(string $bytes): string
{
    return (string) preg_replace('/\r\nDate: [A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT\r\n/', "\r\nDate: Mon, 02 Jan 2006 15:04:05 GMT\r\n", $bytes);
}

/**
 * A server that answers each request with its method, path, query, a header and its body.
 */
function echoServer(Limits $limits = new Limits): Server
{
    return new Server(echoHandler(...), limits: $limits);
}

/**
 * A function of its own, so a test can compare the answers of this handler with those of
 * FrankenPHP.
 */
function echoHandler(Request $request): Response
{
    return new Response(200, ['Content-Type' => 'text/plain', 'X-Path' => $request->path()], json_encode([
        'method' => $request->method,
        'path' => $request->path(),
        'query' => $request->query(),
        'probe' => $request->header('X-Probe'),
        'body' => (string) $request->body,
    ], JSON_THROW_ON_ERROR));
}

/**
 * A client of the server whose reads and writes do not block.
 *
 * @return resource
 */
function connect(Server $server): mixed
{
    $client = stream_socket_client('tcp://'.$server->address());
    assert(is_resource($client));
    stream_set_blocking($client, false);

    return $client;
}

/**
 * Sends the pieces with a tick after each, and gives what the server wrote until it closed the
 * connection, marked by `<closed>`, or was idle for 10 ms. On loopback a byte arrives in far less.
 *
 * @param  resource  $client
 * @param  list<string>|string  $pieces
 */
function converse(Server $server, mixed $client, array|string $pieces = []): string
{
    $received = '';
    $take = static function () use ($client, &$received): bool {
        $before = strlen($received);

        while (($bytes = withoutWarnings(static fn (): string|false => fread($client, 1 << 20))) !== '' && $bytes !== false) {
            $received .= $bytes;
        }

        return strlen($received) > $before;
    };

    foreach ((array) $pieces as $piece) {
        $start = hrtime(true) / 1e9;

        // The socket may take a large piece in parts; a server that closed takes nothing more.
        while ($piece !== '' && hrtime(true) / 1e9 - $start < 10) {
            $written = withoutWarnings(static fn (): int|false => fwrite($client, $piece));

            if ($written === false) {
                break 2;
            }

            $piece = substr($piece, $written);
            $server->tick(0.01);
            $take();
        }
    }

    $idle = 0;
    $start = hrtime(true) / 1e9;

    while (! feof($client) && $idle < 5 && hrtime(true) / 1e9 - $start < 10) {
        $server->tick(0.002);
        // A closed server returns from the tick at once; the client still waits for its end.
        $read = [$client];
        $none = null;
        withoutWarnings(static fn (): int|false => stream_select($read, $none, $none, 0, 2_000));
        $idle = $take() || ! idle($server) ? 0 : $idle + 1;
    }

    return $received.(feof($client) ? "\n<closed>" : '');
}

/**
 * Whether the server has nothing to write and no socket with bytes to read.
 */
function idle(Server $server): bool
{
    [$read, $write] = $server->streams();

    if ($write !== [] || $read === []) {
        return $write === [];
    }

    $none = null;
    $except = null;

    return withoutWarnings(static fn (): int|false => stream_select($read, $none, $except, 0)) === 0;
}

/**
 * Ticks until the server forgot its clients, for at most a second, and gives the streams it still
 * reads, the listener included. A client that closed with bytes in flight is seen to end a little
 * later than its last byte.
 */
function readStreamsOnceForgotten(Server $server): int
{
    $start = hrtime(true) / 1e9;

    while ((count($server->streams()[0]) > 1 || $server->streams()[1] !== []) && hrtime(true) / 1e9 - $start < 1) {
        $server->tick(0.01);
    }

    return count($server->streams()[0]);
}

/**
 * Sends the bytes in the given pieces over a new connection, gives what the server wrote as
 * `converse()` does, and closes the connection.
 *
 * @param  list<string>|string  $pieces
 */
function exchange(Server $server, array|string $pieces): string
{
    $client = connect($server);
    $received = converse($server, $client, $pieces);
    fclose($client);
    // The server sees the end of the connection at its next tick.
    $server->tick(0.01);

    return $received;
}

/**
 * What a server with the handler answers to the bytes.
 *
 * @param  Closure(Request): mixed  $handler
 * @param  list<string>|string  $pieces
 */
function answer(Closure $handler, array|string $pieces): string
{
    // @phpstan-ignore argument.type (the handler's result is not trusted, so a test gives what no type allows)
    $server = new Server($handler);

    try {
        return exchange($server, $pieces);
    } finally {
        $server->close();
    }
}

/**
 * What the call gives with `display_errors` of the value, which decides the answer of FrankenPHP to
 * a throw; the php.ini of the test process may have either.
 *
 * @template T
 *
 * @param  Closure(): T  $call
 * @return T
 */
function withDisplayErrors(string $value, Closure $call): mixed
{
    $before = (string) ini_get('display_errors');
    ini_set('display_errors', $value);

    try {
        return $call();
    } finally {
        ini_set('display_errors', $before);
    }
}

/**
 * What a server with the handler answers to the bytes, and each throwable `onError` got, as its
 * class and message.
 *
 * @param  Closure(Request): mixed  $handler
 * @param  list<string>|string  $pieces
 * @return array{string, list<string>}
 */
function failed(Closure $handler, array|string $pieces): array
{
    $errors = [];
    // @phpstan-ignore argument.type (the handler's result is not trusted, so a test gives what no type allows)
    $server = new Server($handler, onError: static function (Throwable $throwable) use (&$errors): void {
        $errors[] = $throwable::class.': '.$throwable->getMessage();
    });

    try {
        $answer = exchange($server, $pieces);
    } finally {
        $server->close();
    }

    // After the close, as `onError` gets the requests that the close ended.
    return [$answer, $errors];
}

/**
 * What `close()` throws as `ClosedWithErrors`: each error as `failed()` gives it, the message, and
 * whether the first error is the previous exception. Null when it throws nothing.
 *
 * @return array{list<string>, string, bool}|null
 */
function closedWithErrors(Server $server): ?array
{
    try {
        $server->close();
    } catch (ClosedWithErrors $closedWithErrors) {
        return [array_map(static fn (Throwable $error): string => $error::class.': '.$error->getMessage(), $closedWithErrors->errors), $closedWithErrors->getMessage(), $closedWithErrors->getPrevious() === $closedWithErrors->errors[0]];
    }

    return null;
}

/**
 * What the echo server answers to the bytes.
 *
 * @param  list<string>|string  $pieces
 */
function echoed(array|string $pieces): string
{
    $server = echoServer();

    try {
        return exchange($server, $pieces);
    } finally {
        $server->close();
    }
}

/**
 * The test handler of the fixture, which FrankenPHP runs as `FrankenPhp/go/index.php`: an echo of
 * method, target and body; for `/read?` of what `goRead()` reads; for `/headers` of the fields;
 * for `/respond?` the response its pairs name.
 */
function goHandler(Request $request): Response
{
    $seen = $request->method.' '.requestUri($request->target)."\n";

    if (str_starts_with($request->target, '/read?')) {
        return new Response(body: $seen.goRead($request->body, substr($request->target, 6)));
    }

    // As `$_SERVER` of FrankenPHP has the fields: one line for each name, with its values joined by
    // a comma, and no `Proxy`, which FrankenPHP drops against httpoxy.
    if ($request->target === '/headers') {
        $names = array_diff(array_keys($request->headers), ['host', 'proxy']);
        sort($names, SORT_STRING);
        $lines = '';

        foreach ($names as $name) {
            $lines .= $name.': '.$request->header($name)."\n";
        }

        return new Response(body: $seen.$lines);
    }

    // First the whole body.
    $read = (string) $request->body;

    if (! str_starts_with($request->target, '/respond?')) {
        return new Response(body: $seen.$read);
    }

    $status = 200;
    $headers = [];
    $body = '';
    $chunks = null;
    $file = null;
    $panics = false;
    // A `%` without two hexadecimal digits leaves the value as it is.
    $unescape = static fn (string $value): string => preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1 ? $value : rawurldecode($value);

    foreach (explode('&', substr($request->target, 9)) as $pair) {
        [$key, $value] = explode('=', $pair, 2) + [1 => ''];
        [$name, $field] = explode('=', $value, 2) + [1 => ''];
        $count = goCount($value);

        match ($key) {
            'status' => $status = $count >= 100 && $count <= 999 ? $count : $status,
            'h' => $headers[$unescape($name)][] = $unescape($field),
            'body' => $body .= $unescape($value),
            'fill' => $body .= str_repeat('x', $count),
            'stream' => $chunks ??= [],
            'panic' => [$chunks, $panics] = [$chunks ?? [], true],
            'chunk' => $chunks[] = $unescape($value),
            'cfill' => $chunks[] = str_repeat('y', $count),
            'file' => $file .= $unescape($value),
            'ffill' => $file .= str_repeat('z', $count),
            default => null,
        };
    }

    if ($file !== null) {
        $path = (string) tempnam(sys_get_temp_dir(), 'go');
        file_put_contents($path, $file);

        return new Response($status, $headers, file: $path, deleteFile: true);
    }

    // A generator, so the server takes each chunk only as it writes it; `panic` throws after them.
    return new Response($status, $headers, $chunks === null ? $body : (static function () use ($chunks, $panics): Generator {
        yield from $chunks;

        if ($panics) {
            throw new RuntimeException('panic');
        }
    })());
}

/**
 * The target as PHP gets it in `REQUEST_URI` of FrankenPHP, from `URL.RequestURI()` of Go: a path
 * with a byte that `validEncoded()` refuses is escaped again, net/url/url.go:735.
 */
function requestUri(string $target): string
{
    $at = strpos($target, '?');
    $path = $at === false ? $target : substr($target, 0, $at);

    if (preg_match('/[^A-Za-z0-9\-_.~$&+,\/:;=@!\'()*\[\]%]/', $path) !== 1) {
        return $target;
    }

    $escaped = (string) preg_replace_callback('/[^A-Za-z0-9\-_.~$&+,\/:;=@]/', static fn (array $byte): string => sprintf('%%%02X', ord($byte[0])), rawurldecode($path));

    return $escaped.($at === false ? '' : substr($target, $at));
}

/**
 * What `goHandler()` throws for the bytes, as `failedAnswers()` gives it: a `panic` for each
 * request of `/respond?` with the key `panic`.
 *
 * @return list<string>
 */
function goPanics(string $bytes): array
{
    return array_fill(0, (int) preg_match_all('/^\S+ \/respond\?(?:\S*&)?panic[=& ]/m', $bytes), 'RuntimeException: panic');
}

/**
 * The value as a count of the test handler: the digits up to `PHP_INT_MAX`, and 0 for each other
 * value and for a number below 0.
 */
function goCount(string $value): int
{
    $digits = ltrim(ltrim($value, '+'), '0');

    return preg_match('/^\+?\d+$/', $value) === 1 && (strlen($digits) < 19 || (strlen($digits) === 19 && strcmp($digits, '9223372036854775807') <= 0)) ? (int) $digits : 0;
}

/**
 * The body read as the query names it: none for `none`, one read of n bytes for `one=n`, reads of
 * n bytes up to '' for `each=n`, and one more for `again=n`; then the error on a line of its own.
 */
function goRead(Body $body, string $query): string
{
    [$key, $value] = explode('=', $query, 2) + [1 => ''];
    $size = max(1, goCount($value));
    $read = '';

    if ($key === 'one') {
        $read = $body->read($size);
    } elseif ($key === 'each' || $key === 'again') {
        while (($piece = $body->read($size)) !== '') {
            $read .= $piece;
        }

        $read .= $key === 'again' ? $body->read($size) : '';
    }

    return $read.($body->error() === null ? '' : "\n".$body->error());
}

/**
 * What a server with the handler and the limits writes for the bytes and then the end of the
 * client, as `samePhp()` gives it, and `<closed>` once it closed, within 10 seconds; with
 * `within`, what it wrote in that many seconds without the end.
 *
 * @param  Closure(Request): mixed  $handler
 * @param  (Closure(Throwable): void)|null  $onError  without it, a throw of the handler makes the
 *                                                    close of the server throw
 */
function answers(Closure $handler, string $bytes, Limits $limits = new Limits, ?float $within = null, ?Closure $onError = null): string
{
    // @phpstan-ignore argument.type (the handler's result is not trusted, so a test gives what no type allows)
    $server = new Server($handler, limits: $limits, onError: $onError);
    $client = connect($server);
    $received = '';
    $start = hrtime(true) / 1e9;

    try {
        while ($bytes !== '' && hrtime(true) / 1e9 - $start < 10) {
            $written = withoutWarnings(static fn (): int|false => fwrite($client, $bytes));
            $bytes = $written === false ? '' : substr($bytes, $written);
            $server->tick(0.001);
            $received .= (string) withoutWarnings(static fn (): string|false => fread($client, 1 << 20));
        }

        if ($within !== null) {
            while (! feof($client) && hrtime(true) / 1e9 - $start < $within) {
                $server->tick(0.001);
                $received .= (string) withoutWarnings(static fn (): string|false => fread($client, 1 << 20));
            }

            return samePhp($received).(feof($client) ? "\n<closed>" : '');
        }

        withoutWarnings(static fn (): bool => stream_socket_shutdown($client, STREAM_SHUT_WR));

        while (! feof($client) && hrtime(true) / 1e9 - $start < 10) {
            $server->tick(0.001);
            $received .= (string) withoutWarnings(static fn (): string|false => fread($client, 1 << 20));
        }

        return samePhp($received).(feof($client) ? "\n<closed>" : '');
    } finally {
        fclose($client);
        $server->close();
    }
}

/**
 * What `answers()` gives, and each throwable `onError` got, as its class and message, as `failed()`
 * gives them.
 *
 * @param  Closure(Request): mixed  $handler
 * @return array{string, list<string>}
 */
function failedAnswers(Closure $handler, string $bytes, Limits $limits = new Limits, ?float $within = null): array
{
    $errors = [];
    $answer = answers($handler, $bytes, $limits, $within, static function (Throwable $throwable) use (&$errors): void {
        $errors[] = $throwable::class.': '.$throwable->getMessage();
    });

    return [$answer, $errors];
}

/**
 * What FrankenPHP with the test handler and the limits writes for the bytes, as `answers()` gives
 * it for this server: the model of the suites.
 */
function modelAnswers(string $bytes, Limits $limits = new Limits, ?float $within = null): string
{
    return samePhp(FrankenPhpReference::get()->answers($bytes, $limits, $within));
}

/**
 * What FrankenPHP writes for a handler that throws before the head, then for the bytes after it,
 * without `display_errors`: the 500 of PHP. A test runs the server without it as well.
 */
function thrownAnswer(string $after = ''): string
{
    return withDisplayErrors('0', static fn (): string => modelAnswers("GET /respond?panic HTTP/1.1\r\nHost: a\r\n\r\n".$after));
}

/**
 * The bytes with the same `Date` and the same version of PHP in `X-Powered-By`: FrankenPHP has its
 * own PHP.
 */
function samePhp(string $bytes): string
{
    return (string) preg_replace('/\r\nX-Powered-By: PHP\/\d+\.\d+\.\d+[^\r]*/', "\r\nX-Powered-By: PHP/<version>", sameDate($bytes));
}

/**
 * The bytes of a value of the fixture: a string whose code points are its bytes, as JSON holds no
 * other byte, or a list of such strings and of bytes repeated.
 */
function goBytes(mixed $value): string
{
    $bytes = '';

    foreach (is_array($value) ? $value : [$value] as $part) {
        if (is_array($part)) {
            $repeat = $part['repeat'] ?? null;
            $times = $part['times'] ?? null;
            assert(is_string($repeat) && is_int($times) && $times >= 0);
            $part = str_repeat($repeat, $times);
        }

        assert(is_string($part));
        $bytes .= preg_replace_callback('/[\xC2\xC3][\x80-\xBF]/', static fn (array $pair): string => chr(((ord($pair[0][0]) & 0x03) << 6) | (ord($pair[0][1]) & 0x3F)), $part);
    }

    return $bytes;
}

/**
 * What Go did, from the bytes of its server with the handler `show`: a request for the handler,
 * with the error of its body, if any; a 100; an answer of the server itself; or a refusal, which
 * is the last.
 *
 * @return list<string|array<string, mixed>>
 */
function goEvents(string $answer): array
{
    $events = [];

    while ($answer !== '') {
        $head = strstr($answer, "\r\n\r\n", true);
        $status = (int) substr($answer, 9, 3);
        $length = is_string($head) && preg_match('/\r\nContent-Length: (\d+)(\r\n|$)/', $head, $match) === 1 ? (int) $match[1] : null;
        $seen = is_string($head) && preg_match('/\r\nX-Seen: ([^\r]*)/', $head, $field) === 1 ? json_decode(base64_decode($field[1]), true, flags: JSON_THROW_ON_ERROR) : null;

        // Go gives no length with its answer to CONNECT.
        if (! is_string($head) || (! is_array($seen) && ($status >= 300 || ($status > 100 && $length === null)))) {
            $events[] = ['refused' => withoutDate($answer)];

            break;
        }

        $answer = substr($answer, strlen($head) + 4 + (int) $length);
        $events[] = match (true) {
            is_array($seen) => ['request' => goRequest($seen)],
            $status === 100 => 'continue',
            default => ['answered' => (string) $status],
        };
    }

    return $events;
}

/**
 * The request as Go read it, from the field `X-Seen` of its answer.
 *
 * @param  array<mixed>  $request
 * @return array<string, mixed>
 */
function goRequest(array $request): array
{
    $headers = [];

    foreach (is_array($request['header']) ? $request['header'] : [] as $name => $values) {
        $headers[strtolower(goBytes((string) $name))] = array_map(goBytes(...), is_array($values) ? $values : []);
    }

    ksort($headers);

    return [
        'method' => goBytes($request['method']),
        'target' => goBytes($request['target']),
        'proto' => goBytes($request['proto']),
        'host' => goBytes($request['host']),
        'path' => goBytes($request['path']),
        'rawPath' => isset($request['rawPath']) ? goBytes($request['rawPath']) : null,
        'rawQuery' => goBytes($request['rawQuery']),
        'header' => $headers,
        'body' => goBytes($request['body']),
        'bodyError' => isset($request['bodyError']) ? goBytes($request['bodyError']) : null,
    ];
}

function withoutDate(string $answer): string
{
    return (string) preg_replace('/\r\nDate: [^\r]*/', '', $answer);
}

/**
 * The answers as Chrome sees them: each body in chunks as one chunk, without trailers. The sizes
 * of the chunks and the trailers are out of Scope, so a live suite compares this form. A body cut
 * before its last chunk keeps the rest of its bytes.
 */
function visible(string $answers): string
{
    $visible = '';

    while (preg_match('/HTTP\/1\.[01] \d{3}[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n/', $answers, $head, PREG_OFFSET_CAPTURE) === 1) {
        $end = $head[0][1] + strlen($head[0][0]);
        $visible .= substr($answers, 0, $end);
        $answers = substr($answers, $end);

        // A head without chunks keeps its body: the search for the next head goes over it.
        if (! str_contains($head[0][0], "\r\nTransfer-Encoding: chunked\r\n")) {
            continue;
        }

        $data = '';

        while (preg_match('/\A([0-9a-f]+)\r\n/', $answers, $line) === 1 && ($size = (int) hexdec($line[1])) > 0 && substr($answers, strlen($line[0]) + $size, 2) === "\r\n") {
            $data .= substr($answers, strlen($line[0]), $size);
            $answers = substr($answers, strlen($line[0]) + $size + 2);
        }

        $visible .= $data === '' ? '' : dechex(strlen($data))."\r\n".$data."\r\n";

        if (preg_match('/\A0\r\n(?:[^\r\n]+\r\n)*\r\n/', $answers, $last) === 1) {
            $visible .= "0\r\n\r\n";
            $answers = substr($answers, strlen($last[0]));
        }
    }

    return $visible.$answers;
}

/**
 * Milliseconds of CPU time the process used. Other processes on a busy host make the time of the
 * wall longer, but not this time. In a virtual machine, such as that of Docker Desktop, they make
 * both longer.
 */
function cpuMilliseconds(): float
{
    $usage = getrusage();
    assert(is_array($usage));
    /** @var array{'ru_utime.tv_sec': int, 'ru_utime.tv_usec': int, 'ru_stime.tv_sec': int, 'ru_stime.tv_usec': int} $usage */

    return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1e3 + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e3;
}

/**
 * Milliseconds of CPU time to feed the bytes in pieces of the size, and the requests they gave.
 *
 * @param  positive-int  $size
 * @return array{float, int}
 */
function feedInPieces(RequestParser $parser, string $bytes, int $size = 4096): array
{
    $start = cpuMilliseconds();
    $requests = 0;

    for ($offset = 0; $offset < strlen($bytes); $offset += $size) {
        $requests += count($parser->feed(substr($bytes, $offset, $size)));
    }

    return [cpuMilliseconds() - $start, $requests];
}

/**
 * Whether the feed takes under 2 s or 12 times a quarter of the size. Quadratic time breaks both
 * limits at the sizes of the tests.
 *
 * @param  Closure(int): string  $bytes
 * @param  Closure(): RequestParser  $parser
 * @param  positive-int  $piece
 * @return array{bool, int, string}
 */
function inLinearTime(Closure $bytes, int $size, Closure $parser, int $piece): array
{
    [$quarter] = feedInPieces($parser(), $bytes(intdiv($size, 4)), $piece);
    [$whole, $requests] = feedInPieces($parser(), $bytes($size), $piece);
    // Below 5 ms the time is mostly the resolution of the clock.
    $growth = $whole / max($quarter, 5.0);

    return [$whole < 2_000 || $growth < 12.0, $requests, sprintf('%.0f ms of CPU time, %.1f times that of a quarter', $whole, $growth)];
}

/**
 * The requests the parser reads from the bytes fed in pieces of the given size, or whole for 0,
 * and the status of the fault it found.
 *
 * @param  int<0, max>  $piece
 * @return array{list<Request>, int|null}
 */
function parse(string $bytes, int $piece = 0, ?RequestParser $parser = null): array
{
    $parser ??= new RequestParser;
    $requests = [];

    foreach ($piece === 0 ? [$bytes] : str_split($bytes, $piece) as $part) {
        array_push($requests, ...$parser->feed($part));
    }

    return [$requests, $parser->error()?->status];
}

/**
 * The length and the MD5 of the rest of the body, read in pieces of 64 KB as a handler of Go reads
 * `Request.Body`, so no test holds the whole body.
 */
function bodyDigest(Body $body): string
{
    $length = 0;
    $context = hash_init('md5');

    while (($piece = $body->read(1 << 16)) !== '') {
        $length += strlen($piece);
        hash_update($context, $piece);
    }

    return $length.' '.hash_final($context);
}

/**
 * The rest of the body and the error of Go that ended it.
 *
 * @return array{string, string|null}
 */
function readBody(Request $request): array
{
    $body = (string) $request->body;

    return [$body, $request->body->error()];
}

/**
 * The requests read from the bytes, which must be the same whole and one byte at a time, with no
 * fault.
 *
 * @return list<Request>
 */
function requests(string $bytes, bool $bytewise = true): array
{
    [$requests, $error] = parse($bytes);
    expect($error)->toBeNull();

    if ($bytewise) {
        expect(parse($bytes, 1))->toEqual([$requests, null]);
    }

    return $requests;
}

/**
 * Calls the callback while the process may open only 64 descriptors more than the highest it holds,
 * and restores the limit after.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function withFewDescriptors(Closure $callback): mixed
{
    $limits = posix_getrlimit();
    assert(is_array($limits));
    $limit = static fn (int|string $value): int => $value === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $value;
    $soft = $limit($limits['soft openfiles']);
    $hard = $limit($limits['hard openfiles']);
    $open = array_filter((array) scandir(is_dir('/proc/self/fd') ? '/proc/self/fd' : '/dev/fd'), is_numeric(...));

    if (! posix_setrlimit(POSIX_RLIMIT_NOFILE, max([0, ...array_map(intval(...), $open)]) + 65, $hard)) {
        throw new RuntimeException('The limit of descriptors could not be lowered.');
    }

    try {
        return $callback();
    } finally {
        // Kept low, it would fail each later test that opens a file.
        if (! posix_setrlimit(POSIX_RLIMIT_NOFILE, $soft, $hard)) {
            throw new RuntimeException('The limit of descriptors could not be restored to '.$soft.'.');
        }
    }
}

/**
 * Runs the callback while the process holds so many descriptors that the next one opened is at or
 * above `FD_SETSIZE`, 1024, and closes them after. It raises the limit of descriptors to 2048 for
 * that time where it is lower.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function withHighDescriptors(Closure $callback): mixed
{
    $limits = posix_getrlimit();
    assert(is_array($limits));
    $limit = static fn (int|string $value): int => $value === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $value;
    $soft = $limit($limits['soft openfiles']);
    $hard = $limit($limits['hard openfiles']);

    if ($soft !== POSIX_RLIMIT_INFINITY && $soft < 2048 && ! posix_setrlimit(POSIX_RLIMIT_NOFILE, 2048, $hard)) {
        throw new RuntimeException('The limit of descriptors could not be raised to 2048.');
    }

    $files = [];

    try {
        while (count($files) < 1024) {
            $file = fopen('/dev/null', 'r');
            assert(is_resource($file));
            $files[] = $file;
        }

        return $callback();
    } finally {
        closeEach($files);

        if (! posix_setrlimit(POSIX_RLIMIT_NOFILE, $soft, $hard)) {
            throw new RuntimeException('The limit of descriptors could not be restored to '.$soft.'.');
        }
    }
}

/**
 * Opens what the callback gives until it fails, into the list, and throws when it did not fail
 * within 200: then descriptors were left.
 *
 * @param  list<resource>  $opened
 * @param  Closure(): (resource|false)  $open
 */
function takeEachDescriptor(array &$opened, Closure $open): void
{
    withoutWarnings(static function () use (&$opened, $open): void {
        while (count($opened) < 200 && ($stream = $open()) !== false) {
            $opened[] = $stream;
        }
    });

    if (count($opened) === 200) {
        throw new RuntimeException('200 descriptors were opened, and more were left.');
    }
}

/**
 * Closes each stream of the list that is still open.
 *
 * @param  list<mixed>  $streams
 */
function closeEach(array $streams): void
{
    foreach ($streams as $stream) {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
}

/**
 * Ticks until the client has the whole answer, for at most two seconds.
 *
 * @param  resource  $client
 */
function ask(Server $server, mixed $client): string
{
    fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");
    stream_set_blocking($client, false);
    $received = '';
    $start = hrtime(true) / 1e9;

    while (! str_ends_with($received, "\r\n\r\nok") && hrtime(true) / 1e9 - $start < 2) {
        $server->tick(0.05);
        $received .= (string) fread($client, 1 << 16);
    }

    return $received;
}
