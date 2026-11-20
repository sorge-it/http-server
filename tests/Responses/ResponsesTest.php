<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;

it('answers with the headers of the echo server as FrankenPHP answers with the same headers and body, and keeps the connection open without a Connection', function (): void {
    $request = "GET /a/b?x=1 HTTP/1.1\r\nHost: localhost\r\nX-Probe: yes\r\n\r\n";
    $body = '{"method":"GET","path":"\/a\/b","query":"x=1","probe":"yes","body":""}';
    $model = 'GET /respond?h=Content-Type=text%2Fplain&h=X-Path=%2Fa%2Fb&body='.rawurlencode($body)." HTTP/1.1\r\nHost: localhost\r\n\r\n";

    expect(answers(echoHandler(...), $request.$request))->toBe(modelAnswers($model.$model));
});

it('writes a field line for each value of a header, and takes names as Go makes them canonical, from each form of headers a Response takes', function (): void {
    $response = static fn (): Response => new Response(200, ['set-cookie' => ['a=1', 'b=2'], 'x-fOO-bar' => '1', 'a_b' => '3']);

    expect(answers($response, "GET / HTTP/1.1\r\nHost: a\r\n\r\n"))
        ->toBe(modelAnswers("GET /respond?h=set-cookie=a%3D1&h=set-cookie=b%3D2&h=x-fOO-bar=1&h=a_b=3 HTTP/1.1\r\nHost: a\r\n\r\n"))
        ->toContain("\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\n");
});

it('sends a Date with every answer', function (string $request, array $thrown): void {
    [$answer, $errors] = failed(static fn (Request $request): Response => $request->path() === '/fail' ? throw new LogicException('broken') : new Response($request->path() === '/missing' ? 404 : 200), $request);

    expect(preg_match_all('/\r\nDate: (Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d\d (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d\d:\d\d:\d\d GMT\r\n/', $answer))->toBe(1)
        ->and($errors)->toBe($thrown);
})->with([
    '200' => ["GET / HTTP/1.1\r\nHost: a\r\n\r\n", []],
    '404' => ["GET /missing HTTP/1.1\r\nHost: a\r\n\r\n", []],
    '500 for a handler that throws' => ["GET /fail HTTP/1.1\r\nHost: a\r\n\r\n", ['LogicException: broken']],
    'HEAD' => ["HEAD / HTTP/1.1\r\nHost: a\r\n\r\n", []],
]);

it('sends each string of a streamed body as one chunk, as PHP sends an echo and a flush()', function (iterable $body, string $chunks): void {
    $request = static fn (string $target): string => 'GET '.$target." HTTP/1.1\r\nHost: a\r\n\r\n";

    // @phpstan-ignore argument.type (the body comes from the dataset, whose types PHPStan does not see)
    expect(answers(static fn (): Response => new Response(200, [], $body), $request('/')))->toBe(modelAnswers($request('/respond?stream'.$chunks)));
})->with([
    'a list' => [['ab', '', 'c'], '&chunk=ab&chunk=&chunk=c'],
    'a generator' => [(static fn (): Generator => yield from ['ab', 'c'])(), '&chunk=ab&chunk=c'],
    'an empty list, as an empty body' => [[], ''],
    'an aggregate' => [new ArrayObject(['<p>', 'x']), '&chunk=%3Cp%3E&chunk=x'],
]);

it('takes each chunk only once the socket took the one before, as Go waits in Write()', function (): void {
    $taken = 0;
    $server = new Server(static function () use (&$taken): Response {
        return new Response(200, [], (static function () use (&$taken): Generator {
            for ($i = 0; $i < 64; $i++) {
                $taken++;

                yield str_repeat('z', 1 << 20);
            }
        })());
    });
    $client = connect($server);
    $received = 0;

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");

        for ($i = 0; $i < 20; $i++) {
            $server->tick(0.001);
        }

        expect($taken)->toBeLessThan(32);
        $start = hrtime(true) / 1e9;

        while ($received < 64 << 20 && hrtime(true) / 1e9 - $start < 10) {
            $server->tick(0.001);

            while (($bytes = (string) fread($client, 1 << 20)) !== '') {
                $received += substr_count($bytes, 'z');
            }
        }

        expect($taken)->toBe(64)->and($received)->toBe(64 << 20);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('answers the requests after a stream only once it is written, in their order, also those that come meanwhile', function (): void {
    $log = [];
    $server = new Server(static function (Request $request) use (&$log): Response {
        $log[] = 'handler '.$request->path();

        return new Response(200, [], $request->path() !== '/1' ? $request->path() : (static function () use (&$log): Generator {
            for ($i = 0; $i < 16; $i++) {
                $log[] = 'chunk '.$i;

                yield str_repeat('z', 1 << 20);
            }
        })());
    });
    $client = connect($server);
    $received = '';
    $zBytes = 0;

    try {
        foreach (['/1', '/2', '/3'] as $path) {
            fwrite($client, 'GET '.$path." HTTP/1.1\r\nHost: a\r\n\r\n");

            for ($i = 0; $i < 5; $i++) {
                $server->tick(0.001);
            }
        }

        expect($log)->not->toContain('handler /2');
        $start = hrtime(true) / 1e9;

        while (! str_ends_with($received, '/3') && hrtime(true) / 1e9 - $start < 10) {
            $server->tick(0.001);

            while (($bytes = (string) fread($client, 1 << 20)) !== '') {
                $zBytes += substr_count($bytes, 'z');
                $received .= str_replace('z', '', $bytes);
            }
        }

        // The same requests to FrankenPHP, with chunks of `y` for the chunks of `z`.
        $model = modelAnswers("GET /respond?stream&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576&cfill=1048576 HTTP/1.1\r\nHost: a\r\n\r\n"
            ."GET /respond?body=%2F2 HTTP/1.1\r\nHost: a\r\n\r\nGET /respond?body=%2F3 HTTP/1.1\r\nHost: a\r\n\r\n");

        // Without the bytes and the sizes of the chunks. The sizes differ, because PHP writes its
        // output in parts of 64 KB; the count of the bytes and the last chunk are compared.
        $framed = static fn (string $answer): string => (string) preg_replace(['/[yz]{2,}/', '/(?<=\n)[0-9a-f]+\r\n(\r\n)?/'], '', $answer);

        expect($framed(samePhp($received)."\n<closed>"))->toBe($framed($model))
            ->and($zBytes)->toBe(16 << 20)
            ->and($received)->toContain("\r\n0\r\n\r\nHTTP/1.1 200 OK\r\n")
            ->and(array_slice($log, -3))->toBe(['chunk 15', 'handler /2', 'handler /3']);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('answers the 500 of a handler that throws for a body whose first chunk fails or is not a string, as FrankenPHP for a throw', function (Closure $body, string $message): void {
    // @phpstan-ignore argument.type (the body comes from the dataset, whose types PHPStan does not see)
    [$answer, $errors] = withDisplayErrors('0', static fn (): array => failedAnswers(static fn (): Response => new Response(200, [], $body()), "GET / HTTP/1.1\r\nHost: a\r\n\r\nGET /b HTTP/1.1\r\nHost: a\r\n\r\n"));

    expect($answer)->toBe(thrownAnswer(after: "GET /respond?panic HTTP/1.1\r\nHost: a\r\n\r\n"))
        ->and($errors)->toBe([$message, $message]);
})->with([
    'a throw' => [static fn (): Generator => yield throw new RuntimeException('first'), 'RuntimeException: first'],
    'an integer' => [static fn (): array => [1], 'UnexpectedValueException: The body gave int, not a string.'],
]);

it('ends the body for a later chunk that throws, as FrankenPHP for a script that throws, and gives onError the throwable', function (): void {
    $answer = withDisplayErrors('0', static fn (): array => failedAnswers(static fn (): Response => new Response(200, [], (static function (): Generator {
        yield 'ok';

        throw new RuntimeException('later');
    })()), "GET /a HTTP/1.1\r\nHost: a\r\n\r\nGET /b HTTP/1.1\r\nHost: a\r\n\r\n"));
    $model = withDisplayErrors('0', static fn (): string => modelAnswers(str_repeat("GET /respond?chunk=ok&panic HTTP/1.1\r\nHost: a\r\n\r\n", 2)));

    expect(visible($answer[0]))->toBe(visible($model))
        ->and($answer[1])->toBe(['RuntimeException: later', 'RuntimeException: later']);
});

// PHP has no such chunk: its echo takes each scalar.
it('ends the connection for a later chunk that is not a string, and gives onError the throwable', function (): void {
    // @phpstan-ignore argument.type (a body the server cannot send)
    [$answer, $errors] = failedAnswers(static fn (): Response => new Response(200, [], ['ok', 1]), "GET /a HTTP/1.1\r\nHost: a\r\n\r\nGET /b HTTP/1.1\r\nHost: a\r\n\r\n");

    expect(substr_count($answer, 'HTTP/1.1 '))->toBe(1)
        ->and($answer)->toEndWith("\r\n\r\n2\r\nok\r\n\n<closed>")
        ->and($errors)->toBe(['UnexpectedValueException: The body gave int, not a string.']);
});

it('gives onError the LogicException of a close() from a later chunk, and stays open', function (): void {
    $errors = [];
    $server = new Server(static function () use (&$server): Response {
        return new Response(200, [], (static function () use (&$server): Generator {
            yield 'ok';

            assert($server instanceof Server);
            $server->close();
        })());
    }, onError: static function (Throwable $throwable) use (&$errors): void {
        $errors[] = $throwable::class.': '.$throwable->getMessage();
    });

    try {
        expect(withDisplayErrors('0', static fn (): string => exchange($server, "GET / HTTP/1.1\r\nHost: a\r\n\r\n")))->toStartWith('HTTP/1.1 200 OK')
            ->and($errors[0] ?? '')->toStartWith('LogicException: close() was called during tick()')
            ->and($server->streams()[0])->not->toBe([]);
    } finally {
        $server->close();
    }
});

it('refuses a body that is not a string together with a file, with a ValueError', function (): void {
    expect(static fn (): Response => new Response(200, [], ['a'], temporaryFile('b')))->toThrow(ValueError::class, 'Argument #3 ($body) must be empty when Argument #4 ($file) is given');
});

it('takes chunks for about a millisecond in each tick, so a slow or long streamed body holds no other connection, as the goroutines of Go do not', function (string $method, array $headers): void {
    $taken = 0;
    $server = new Server(static function (Request $request) use (&$taken, $headers): Response {
        // @phpstan-ignore argument.type (the headers come from the dataset, whose types PHPStan does not see)
        return new Response(200, $request->path() === '/slow' ? $headers : [], $request->path() !== '/slow' ? 'fast' : (static function () use (&$taken): Generator {
            // A second at most, so a server that takes all chunks at once fails instead of hanging.
            while ($taken < 1000) {
                $taken++;
                usleep(1000);

                yield 'z';
            }
        })());
    });
    $slow = connect($server);
    $fast = connect($server);
    $received = '';

    try {
        fwrite($slow, $method." /slow HTTP/1.1\r\nHost: a\r\n\r\n");

        for ($i = 0; $i < 5; $i++) {
            $server->tick(0.001);
        }

        fwrite($fast, "GET /fast HTTP/1.1\r\nHost: a\r\n\r\n");
        $start = hrtime(true) / 1e9;

        while (! str_ends_with($received, 'fast') && hrtime(true) / 1e9 - $start < 2) {
            $server->tick(0.001);
            $received .= (string) fread($fast, 1 << 16);
        }

        expect($received)->toEndWith("\r\n\r\nfast")->and($taken)->toBeLessThan(100);
    } finally {
        fclose($slow);
        fclose($fast);
        $server->close();
    }
})->with([
    'GET' => ['GET', []],
    'HEAD, whose chunks Go drops' => ['HEAD', []],
    'chunks past the length, which Go drops' => ['GET', ['Content-Length' => '1']],
]);

it('answers each request a client sent, also those after a streamed body, as FrankenPHP', function (): void {
    $handler = static fn (Request $request): Response => new Response(200, [], $request->path() === '/1' ? ['a', 'b'] : $request->path());

    expect(answers($handler, "GET /1 HTTP/1.1\r\nHost: a\r\n\r\nGET /2 HTTP/1.1\r\nHost: a\r\n\r\nGET /3 HTTP/1.1\r\nHost: a\r\n\r\n"))
        ->toBe(modelAnswers("GET /respond?stream&chunk=a&chunk=b HTTP/1.1\r\nHost: a\r\n\r\nGET /respond?body=%2F2 HTTP/1.1\r\nHost: a\r\n\r\nGET /respond?body=%2F3 HTTP/1.1\r\nHost: a\r\n\r\n"));
});
