<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Internal\Caddy;
use SorgeIt\HttpServer\Internal\Php;
use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;
use SorgeIt\HttpServer\Tests\FrankenPhp\Cases;
use SorgeIt\HttpServer\Tests\FrankenPhp\FrankenPhpReference;

const FRANKENPHP_FIXTURE = __DIR__.'/../Fixtures/frankenphp';

const CADDY_FIXTURE = __DIR__.'/../Fixtures/caddy';

/**
 * The bytes as `samePhp()` gives them, and, in the page of an error, the same file and line, and so
 * the same length: the throw is in `app/index.php` there, in `Cases.php` here.
 */
function samePage(string $bytes): string
{
    $bytes = samePhp($bytes);

    if (! str_contains($bytes, '<b>Fatal error</b>')) {
        return $bytes;
    }

    return (string) preg_replace(
        ['/ in \/\S+:\d+\nStack trace:/', '/thrown in <b>[^<]*<\/b> on line <b>\d+<\/b>/', '/\r\nContent-Length: \d+\r\n/', '/\r\n[0-9a-f]+\r\n<br \/>\n<b>Fatal/'],
        [' in <file>:<line>'."\nStack trace:", 'thrown in <b><file></b> on line <b><line></b>', "\r\nContent-Length: <n>\r\n", "\r\n<n>\r\n<br />\n<b>Fatal"],
        $bytes,
    );
}

/**
 * The probes of Caddy, by number and name: the bytes, and the limits of the probe.
 *
 * @return array<string, array{string, Limits}>
 */
function caddyProbes(): array
{
    /** @var list<array<string, mixed>> $cases */
    $cases = json_decode((string) file_get_contents(CADDY_FIXTURE.'/cases.json'), true, flags: JSON_THROW_ON_ERROR);
    $probes = [];

    foreach ($cases as $number => $case) {
        $maxBody = $case['maxBody'] ?? null;
        assert(is_string($case['name']) && (is_int($maxBody) || $maxBody === null));
        $probes[sprintf('#%d %s', $number + 1, addcslashes($case['name'], "\0..\37\177..\377"))] = [goBytes($case['request'] ?? ''), new Limits(maxBody: $maxBody)];
    }

    return $probes;
}

/**
 * The line of the throw of the case in the file: the first, or the next for a streamed body.
 */
function throwLine(string $file, int $nth): int
{
    $lines = array_keys(array_filter(file($file, FILE_IGNORE_NEW_LINES) ?: [], static fn (string $line): bool => str_contains($line, 'throw new RuntimeException($throws);')));

    return 1 + ($lines[$nth] ?? $lines[0]);
}

function frankenPhpAnswer(int $number): string
{
    return sameDate(FrankenPhpReference::get()->exchange(Cases::request($number), Cases::ini($number)));
}

/**
 * What this server sends for the case, and what `onError` got, as `failed()` gives it: here with
 * `ini_set()`, or in a process with the php.ini, which alone sets `expose_php`. That process gives
 * each throwable on STDOUT after the address.
 *
 * @return array{string, list<string>}
 */
function ownAnswer(int $number): array
{
    $ini = Cases::ini($number);

    if (ini_get('expose_php') !== $ini['expose_php']) {
        $process = proc_open([PHP_BINARY, '-c', FrankenPhpReference::get()->iniFile($ini), __DIR__.'/serve.php'], [['pipe', 'r'], ['pipe', 'w'], STDERR], $pipes, null, [...getenv(), 'PHP_INI_SCAN_DIR' => '']);
        assert(is_resource($process));

        try {
            $read = [$pipes[1]];
            $none = null;

            if (stream_select($read, $none, $none, 10) !== 1) {
                throw new RuntimeException('tests/FrankenPhp/serve.php named no address within 10 seconds; its errors are on STDERR.');
            }

            $address = trim((string) fgets($pipes[1]));
            $answer = sameDate(FrankenPhpReference::talk((int) substr($address, strrpos($address, ':') + 1), Cases::request($number)));
        } finally {
            // The end of its input ends the server, so the rest of its output is its errors.
            fclose($pipes[0]);
            $errors = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exit = proc_close($process);
        }

        expect($exit)->toBe(0);

        return [$answer, array_values(array_filter(explode(PHP_EOL, $errors)))];
    }

    $before = [];

    foreach (array_diff_key($ini, ['expose_php' => true]) as $name => $value) {
        $before[$name] = (string) ini_get($name);
        ini_set($name, $value);
    }

    try {
        return failedAnswers(Cases::handler(...), Cases::request($number), within: 5.0);
    } finally {
        foreach ($before as $name => $value) {
            ini_set($name, $value);
        }
    }
}

/**
 * The cases by number and name.
 *
 * @return array<string, array{int}>
 */
function frankenPhpCases(): array
{
    $cases = [];

    foreach (Cases::all() as $number => $case) {
        assert(is_string($case['name']));
        $cases[sprintf('#%d %s', $number, $case['name'])] = [$number];
    }

    return $cases;
}

// The weekly job of frankenphp.yml runs with the newest FrankenPHP, so it leaves out this group.
// The Go of the binary is not part of the pin: upstream builds its newest release again each night,
// with a new Go at times. composer check:go compares it with go.lock every week.
it('names in each place the FrankenPHP of release.lock and its PHP', function (): void {
    $version = strtok((string) preg_replace('/^#.*\n/m', '', (string) file_get_contents(FRANKENPHP_FIXTURE.'/release.lock')), "\n");

    expect($version)->toBe('FrankenPHP v1.13.6 PHP 8.5.13 Caddy v2.11.9')
        ->and(FrankenPhpReference::get()->version())->toStartWith($version.' ')
        ->and(file_get_contents(FRANKENPHP_FIXTURE.'/frankenphp.lock'))->toContain('blobs at v1.13.6')
        ->and(file_get_contents(FRANKENPHP_FIXTURE.'/php-src.lock'))->toContain('blobs at php-8.5.13')
        ->and(file_get_contents(__DIR__.'/../../src/Internal/Php.php'))->toContain('PHP 8.5.13 in FrankenPHP 1.13.6')
        ->and(file_get_contents(CADDY_FIXTURE.'/caddy.lock'))->toContain('blobs at v2.11.9 (')
        ->and(file_get_contents(CADDY_FIXTURE.'/frankenphp.lock'))->toContain('blobs at v1.13.6 (')
        ->and(file_get_contents(__DIR__.'/../../src/Internal/Caddy.php'))->toContain('Caddy 2.11.9 ')->toContain('FrankenPHP 1.13.6 ');
})->group('pins');

// The suites give FrankenPHP the `maxHead` of the server, so this test alone sees the default of
// Caddy. With a `max_header_size` of 16 KB, FrankenPHP 1.13.6 takes a head of 20494 bytes and
// refuses one byte more: Go reads 4096 bytes more, net/http/server.go:957.
it('has the MaxHeaderBytes of Caddy::MAX_HEADER_BYTES in FrankenPHP without max_header_size', function (): void {
    $status = static function (int $length, bool $set): string {
        $start = "GET /?case=4 HTTP/1.1\r\nHost: a\r\nX: ";
        $bytes = $start.str_repeat('a', $length - strlen($start) - 4)."\r\n\r\n";

        return substr($set ? FrankenPhpReference::get()->answers($bytes, new Limits(maxHead: Caddy::MAX_HEADER_BYTES)) : FrankenPhpReference::get()->exchange($bytes, Cases::INI), 9, 3);
    };
    $edge = Caddy::MAX_HEADER_BYTES + 4096 + 14;

    expect([$status($edge, true), $status($edge + 1, true)])->toBe(['200', '431'])
        ->and([$status($edge, false), $status($edge + 1, false)])->toBe(['200', '431']);
});

it('answers each good probe of Caddy with the bytes of FrankenPHP, with the test handler and the limits of the probe, and each other with 400', function (string $bytes, Limits $limits): void {
    // The page of an error names the script; the test of the cases above compares it.
    [$answer, $errors] = withDisplayErrors('0', static fn (): array => failedAnswers(goHandler(...), $bytes, $limits));
    $model = withDisplayErrors('0', static fn (): string => scopeAnswer($answer, $bytes, static fn (string $sent): string => modelAnswers($sent, $limits), $limits->maxHead));

    expect(visible($answer))->toBe(visible($model))
        ->and($errors)->toBe(goPanics($bytes));
})->with(caddyProbes());

it('answers each case of a good request with the bytes of FrankenPHP, with the php.ini of the case, and each other with 400', function (int $number): void {
    [$answer, $errors] = ownAnswer($number);
    $throws = Cases::throws(Cases::get($number));

    // A case is one request, so only the request of the case goes to FrankenPHP. A request that is
    // not good gets 400, and its handler does not run.
    expect(visible(samePage($answer)))->toBe(visible(samePage(scopeAnswer($answer, Cases::request($number), static fn (): string => frankenPhpAnswer($number)))))
        ->and($errors)->toBe($throws === null || browserSorted(Cases::request($number))[0] !== 'good' ? [] : ['RuntimeException: '.$throws]);
})->with(frankenPhpCases());

it('gives FrankenPHP and this server the same request, the same case and the php.ini of the case', function (int $number): void {
    $body = substr(frankenPhpAnswer($number), (int) strpos(frankenPhpAnswer($number), "\r\n\r\n") + 4, -strlen("\n<closed>"));
    /** @var array{method: string, target: string, case: array<string, mixed>, ini: array<string, string>} $seen */
    $seen = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

    ksort($seen['ini']);
    $ini = Cases::ini($number);
    ksort($ini);

    [$answer, $errors] = ownAnswer($number);

    expect($seen)->toBe(['method' => 'GET', 'target' => '/?case='.$number, 'case' => Cases::get($number), 'ini' => $ini])
        ->and(visible(samePage($answer)))->toBe(visible(samePage(frankenPhpAnswer($number))))
        ->and($errors)->toBe([]);
})->with(array_filter(frankenPhpCases(), static fn (array $case): bool => (Cases::get($case[0])['echo'] ?? false) === true));

// The main test masks file and line of the page; here each side names its own throw.
it('names in the page of the error the file and line of the throw, and the trace of the handler only, as PHP names those of the script', function (string $name, int $nth): void {
    $number = (int) array_search($name, array_column(Cases::all(), 'name'), true);
    $page = static fn (string $file, int $line): string => 'Uncaught RuntimeException: boom in '.$file.':'.$line."\nStack trace:\n#0 {main}\n  thrown in <b>".$file.'</b> on line <b>'.$line.'</b><br />';
    [$answer, $errors] = ownAnswer($number);

    expect(frankenPhpAnswer($number))->toContain($page(__DIR__.'/app/index.php', throwLine(__DIR__.'/app/index.php', 0)))
        ->and($answer)->toContain($page(__DIR__.'/Cases.php', throwLine(__DIR__.'/Cases.php', $nth)))
        ->and($errors)->toBe(['RuntimeException: boom']);
})->with([
    'a throw of the handler' => ['a handler that throws, display_errors 1', 0],
    'a throw of a chunk' => ['two chunks, then a throw, display_errors 1', 1],
]);

it('names in the trace of the page the calls of the handler, and the call of the handler as {main}', function (): void {
    $throws = static fn (): never => throw new LogicException('deep');
    $line = __LINE__ - 1;
    [$answer, $errors] = withDisplayErrors('1', static fn (): array => failed(static fn (): Response => $throws(), "GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n"));

    expect($answer)->toContain('Uncaught LogicException: deep in '.__FILE__.':'.$line."\nStack trace:\n#0 ".__FILE__.'('.($line + 2).'): ')
        ->toContain("\n#1 {main}\n  thrown in <b>".__FILE__.'</b> on line <b>'.$line.'</b>')
        ->and($errors)->toBe(['LogicException: deep']);
});

it('answers as PHP for a first chunk that is not a string, which the server finds after the handler', function (): void {
    // @phpstan-ignore argument.type (a body the server cannot send)
    [$answer, $errors] = withDisplayErrors('0', static fn (): array => failed(static fn (): Response => new Response(body: new ArrayIterator([1])), "GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n"));

    expect($answer)->toStartWith("HTTP/1.1 500 Internal Server Error\r\n")->toContain("\r\nContent-Length: 0\r\n")
        ->and($errors)->toBe(['UnexpectedValueException: The body gave int, not a string.']);
});

it('gives onError each throwable PHP turns into output, once, and answers the next request', function (string $target, string $start): void {
    $errors = [];
    $handler = static fn (Request $request): Response => match ($request->path()) {
        '/throws' => throw new LogicException('broken'),
        '/chunks' => new Response(body: (static function (): Generator {
            yield 'a';

            throw new LogicException('broken');
        })()),
        default => new Response(body: 'next'),
    };
    $server = new Server($handler, onError: static function (Throwable $throwable, Request $request) use (&$errors): void {
        $errors[] = $throwable->getMessage().' '.$request->path();
    });
    $display = (string) ini_get('display_errors');
    ini_set('display_errors', '0');
    assert($start !== '');

    try {
        expect(exchange($server, 'GET '.$target." HTTP/1.1\r\nHost: a\r\n\r\nGET /next HTTP/1.1\r\nHost: a\r\n\r\n"))->toStartWith($start)->toEndWith("\r\n\r\nnext")
            ->and($errors)->toBe(['broken '.$target]);
    } finally {
        ini_set('display_errors', $display);
        $server->close();
    }
})->with([
    'a throw of the handler' => ['/throws', "HTTP/1.1 500 Internal Server Error\r\n"],
    'a throw of a chunk' => ['/chunks', "HTTP/1.1 200 OK\r\n"],
]);

it('reads expose_php as PHP reads a boolean of its ini, and names the PHP of this process', function (string $value, bool $exposed): void {
    $response = Php::response(new Response, new Request('GET', '/', []), ['expose_php' => $value, 'default_mimetype' => '', 'default_charset' => '']);

    expect($response->headers)->toBe($exposed ? ['X-Powered-By' => ['PHP/'.PHP_VERSION]] : []);
})->with([
    '1' => ['1', true],
    'On' => ['On', true],
    'yes' => ['yes', true],
    'TRUE' => ['TRUE', true],
    '2' => ['2', true],
    '0' => ['0', false],
    'empty' => ['', false],
    'off' => ['off', false],
    'no' => ['no', false],
]);

// The suite sets the ini back after each case, so only this test sees whether the server does.
it('sets back the ini that the rules read, after the answer of a handler, as PHP does at the end of a request', function (bool $throws): void {
    $before = [(string) ini_get('default_charset'), (string) ini_get('default_mimetype')];
    $handler = static function (Request $request) use ($throws): Response {
        if ($request->path() === '/set') {
            ini_set('default_charset', 'ISO-8859-1');
            ini_set('default_mimetype', 'text/plain');

            return $throws ? throw new RuntimeException('boom') : new Response(body: 'x');
        }

        return new Response(body: 'x');
    };

    try {
        [$answers, $errors] = failedAnswers($handler, "GET /set HTTP/1.1\r\nHost: a\r\n\r\nGET / HTTP/1.1\r\nHost: a\r\n\r\n");

        // The answer to the throw has the ini of the handler too, as PHP writes the error before the end.
        expect(substr_count($answers, "\r\nContent-Type: text/plain; charset=ISO-8859-1\r\n"))->toBe(1)
            ->and(substr_count($answers, "\r\nContent-Type: ".$before[1].'; charset='.$before[0]."\r\n"))->toBe(1)
            ->and([(string) ini_get('default_charset'), (string) ini_get('default_mimetype')])->toBe($before)
            ->and($errors)->toBe($throws ? ['RuntimeException: boom'] : []);
    } finally {
        ini_set('default_charset', $before[0]);
        ini_set('default_mimetype', $before[1]);
    }
})->with(['an answer' => [false], 'a throw' => [true]]);

it('runs each chunk with the ini the chunks before set, and sets the ini of the server back after each, as PHP keeps it to the end of the request', function (): void {
    $charset = (string) ini_get('default_charset');
    $chunks = static function (): Generator {
        yield 'a';
        ini_set('default_charset', 'ISO-8859-1');
        ini_set('display_errors', '1');

        yield 'b';

        throw new LogicException('later');
    };
    [$answer, $errors, $display] = withDisplayErrors('0', static fn (): array => [...failed(static fn (): Response => new Response(body: $chunks()), "GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n"), (string) ini_get('display_errors')]);

    expect($answer)->toContain("\r\nb\r\n")->toContain('<b>Fatal error</b>:  Uncaught LogicException: later')
        ->and($errors)->toBe(['LogicException: later'])
        ->and([(string) ini_get('default_charset'), $display])->toBe([$charset, '0']);
});
