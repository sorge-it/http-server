<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Internal\Clock;
use SorgeIt\HttpServer\Internal\Deadlines;
use SorgeIt\HttpServer\Internal\Go;
use SorgeIt\HttpServer\Internal\RequestParser;
use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;

/**
 * The count of requests, the fault and the error of the last body the parser gives for the bytes
 * fed whole, and fed in two pieces cut where the second begins.
 *
 * @return array{array{int, int|null, string|null}, array{int, int|null, string|null}}
 */
function wholeAndCut(string $first, string $second): array
{
    [$whole, $error] = parse($first.$second);
    $parser = new RequestParser;
    $cut = [...$parser->feed($first), ...$parser->feed($second)];

    return [[count($whole), $error, end($whole) ? readBody(end($whole))[1] : null], [count($cut), $parser->error()?->status, end($cut) ? readBody(end($cut))[1] : null]];
}

/**
 * The values of the limits, in the order of the constructor.
 *
 * @return list<int|float>
 */
function limitValues(Limits $limits): array
{
    return [$limits->maxHead, $limits->maxBody, $limits->idleTimeout, $limits->headerTimeout];
}

it('takes the default for null: the limits of FrankenPHP in the production', function (): void {
    expect(limitValues(new Limits))->toBe([16 << 10, 0, 120.0, 10.0]);
});

it('keeps 0 as off and each other value', function (): void {
    expect(limitValues(new Limits(0, 0, 0.0, 0.0)))->toBe([0, 0, 0.0, 0.0])
        ->and(limitValues(new Limits(1, 2, 3.0, 4.0)))->toBe([1, 2, 3.0, 4.0]);
});

it('reads a body of any length by default, as Go has no limit for it, and a body of exactly the limit', function (Limits $limits, int $length): void {
    $server = new Server(static fn (Request $request): Response => new Response(200, [], bodyDigest($request->body)), limits: $limits);
    $body = str_repeat('x', $length);

    try {
        expect(exchange($server, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body))->toStartWith('HTTP/1.1 200 OK')->toEndWith("\r\n\r\n".strlen($body).' '.md5($body));
    } finally {
        $server->close();
    }
})->with([
    'no limit' => [new Limits, (8 << 20) + 1],
    'the limit' => [new Limits(maxBody: 8 << 20), 8 << 20],
]);

it('reads a head of exactly maxHead bytes, and answers one byte more with 431, whole and when its end comes in two pieces', function (int $over, array $expected): void {
    $head = "GET / HTTP/1.1\r\nHost: a\r\nX: ";
    $head .= str_repeat('a', (1 << 20) - 4 - strlen($head) + $over);
    [$whole] = wholeAndCut($head."\r\n\r", "\n");

    expect($whole)->toBe($expected)
        ->and(wholeAndCut($head."\r\n\r", "\n"))->toBe([$whole, $whole])
        ->and(wholeAndCut($head."\r", "\n\r\n"))->toBe([$whole, $whole]);
})->with([
    'at the limit' => [0, [1, null, null]],
    'one byte over' => [1, [0, 431, null]],
]);

it('refuses a head over its limit before an end that does not come', function (string $bytes, ?int $status): void {
    $parser = new RequestParser(maxHead: 100);

    expect($parser->feed($bytes))->toBe([])
        ->and($parser->error()?->status)->toBe($status);
})->with([
    'a line of 100 bytes without its end' => ['GET /'.str_repeat('a', 95), 431],
    'a line of 99 bytes without its end, which may still end' => ['GET /'.str_repeat('a', 94), null],
    'a field after a request line' => ["GET / HTTP/1.1\r\nX: ".str_repeat('a', 100), 431],
    'a head with its end one byte over' => ["GET / HTTP/1.1\r\nX: ".str_repeat('a', 78)."\r\n\r\n", 431],
]);

// The model runs after the measure, so its start does not count in the memory of the server.
it('answers a header that never ends as FrankenPHP, and holds no more than the limit of it', function (): void {
    $server = new Server(static fn (): Response => new Response(200), limits: new Limits(maxHead: 64 << 10));
    $client = connect($server);
    $received = '';
    $pieces = 0;

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\nEndless: ");
        $before = memory_get_usage();
        memory_reset_peak_usage();

        while ($received === '' && $pieces < 200) {
            withoutWarnings(static fn (): int|false => fwrite($client, str_repeat('a', 1024)));
            $pieces++;
            $server->tick(0.01);
            $received .= (string) fread($client, 1 << 16);
        }

        $peak = memory_get_peak_usage() - $before;
        $model = strtok(modelAnswers("GET / HTTP/1.1\r\nHost: a\r\nEndless: ".str_repeat('a', 70 << 10), new Limits(maxHead: 64 << 10)), "\r");

        expect(strtok($received, "\r"))->toBe($model)
            ->and($pieces)->toBeGreaterThanOrEqual(64)->toBeLessThan(70)
            ->and($peak)->toBeLessThan(1 << 20);
    } finally {
        fclose($client);
        $server->close();
    }
});

// The client sends no end, so the test sees whether the server shuts its end within 0.3 seconds.
// It does not wait for the rest of the body; the linger drops what comes. The model gets 2
// seconds, so a slow start of FrankenPHP does not cut its 413, and its close is not compared.
it('answers a body over its limit with the 413 of FrankenPHP, and shuts its end without a wait for the rest', function (string $request): void {
    $limits = new Limits(maxBody: 10);
    $model = (string) preg_replace('/\n<closed>$/', '', modelAnswers($request, $limits, 2.0));

    expect(answers(goHandler(...), $request, $limits, 0.3))->toBe($model."\n<closed>");
})->with([
    'a length with 9 bytes left' => ["POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 20\r\n\r\n01234567890"],
    'a length whose rest came' => ["POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 20\r\n\r\n01234567890123456789"],
]);

it('keeps the limits it is given, with the answers of FrankenPHP within them', function (): void {
    $limits = new Limits(maxHead: 1024, maxBody: 10);
    $parser = static fn (): RequestParser => new RequestParser(maxHead: 1024);
    $head = "GET / HTTP/1.1\r\nHost: a\r\nX: ";
    $within = $head.str_repeat('a', 1024 - 4 - strlen($head))."\r\n\r\n";
    $over = $head.str_repeat('a', 1025 - 4 - strlen($head))."\r\n\r\n";
    $long = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 11\r\n\r\n01234567890";
    $fits = "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\n0123456789";

    foreach ([0, 1] as $piece) {
        expect(parse($over, $piece, $parser())[1])->toBe(431)
            ->and(parse($within, $piece, $parser())[0])->toHaveCount(1);
    }

    foreach ([$within, $long, $fits] as $request) {
        expect(answers(goHandler(...), $request, $limits))->toBe(modelAnswers($request, $limits));
    }
});

it('takes no limit for 0', function (): void {
    $parser = new RequestParser(0, 0);
    $request = "POST / HTTP/1.1\r\nHost: a\r\n".str_repeat("X: a\r\n", 600).'X: '.str_repeat('a', 2 << 20)."\r\nContent-Length: 11\r\n\r\n01234567890";

    [$read, $error] = parse($request, parser: $parser);

    expect($read)->toHaveCount(1)
        ->and($error)->toBeNull()
        ->and((string) $read[0]->body)->toBe('01234567890');
});

it('refuses a negative count with a ValueError', function (string $name, int $position): void {
    expect(static fn (): Limits => new Limits(...[$name => -1]))
        ->toThrow(ValueError::class, Limits::class.'::__construct(): Argument #'.$position.' ($'.$name.') must be greater than or equal to 0');
})->with([['maxHead', 1], ['maxBody', 2]]);

it('refuses a time that is negative, not finite or more than 1e8 seconds, with a ValueError', function (string $name, int $position, float $seconds): void {
    // @phpstan-ignore argument.type (PHPStan takes a spread with a string key for the first argument)
    expect(static fn (): Limits => new Limits(...[$name => $seconds]))
        ->toThrow(ValueError::class, Limits::class.'::__construct(): Argument #'.$position.' ($'.$name.') must be between 0 and 100000000')
        // @phpstan-ignore argument.type (PHPStan takes a spread with a string key for the first argument)
        ->and((new Limits(...[$name => 1e8]))->{$name})->toBe(1e8);
})->with([['idleTimeout', 3], ['headerTimeout', 4]])
    ->with(['negative' => [-0.001], 'INF' => [INF], 'NAN' => [NAN], 'above 1e8' => [1e8 + 1]]);

it('runs the time of a head from the accept, none while answers are to be written, and the idle time after the last write', function (): void {
    $clock = new Clock;
    $start = $clock->now();
    $deadlines = new Deadlines($clock, new Limits(idleTimeout: 20.0, headerTimeout: 10.0));
    $seconds = static fn (?int $due): ?float => $due === null ? null : round(($due - $start) / 1e9);
    $times = [$seconds($deadlines->due(true, false)), $seconds($deadlines->due(true, true)), $seconds($deadlines->due(false, false))];

    $clock->advance(5.0);
    $deadlines->request(false, false);
    $deadlines->wrote();
    $times[] = $seconds($deadlines->due(true, false));

    // The time of a later head runs from its start; a later write does not move it.
    $clock->advance(1.0);
    $deadlines->request(true, true);
    $times[] = $seconds($deadlines->due(true, false));
    $clock->advance(2.0);
    $deadlines->wrote();
    $times[] = $seconds($deadlines->due(true, false));
    $before = $clock->now();
    $deadlines->linger();
    $after = $clock->now();

    // The linger is 0.5 seconds, as `rstAvoidanceDelay` of Go, from a time within the call.
    expect($times)->toBe([10.0, null, 20.0, 25.0, 16.0, 16.0])
        ->and(Go::LINGER)->toBe(500_000_000)
        ->and($deadlines->due(true, true))->toBeGreaterThanOrEqual($before + Go::LINGER)->toBeLessThanOrEqual($after + Go::LINGER);
});

it('runs the time of a head and the idle time, and none for a body; 0 is none', function (float $header, float $idle, array $expected): void {
    $clock = new Clock;
    $start = $clock->now();
    $deadlines = new Deadlines($clock, new Limits(idleTimeout: $idle, headerTimeout: $header));
    $seconds = static fn (?int $due): ?float => $due === null ? null : round(($due - $start) / 1e9);
    $head = $seconds($deadlines->due(true, false));
    $deadlines->request(true, false);
    $body = $seconds($deadlines->due(true, false));
    $deadlines->request(false, false);

    expect([$head, $body, $seconds($deadlines->due(true, false))])->toBe($expected);
})->with([
    'each time' => [5.0, 20.0, [5.0, null, 20.0]],
    'each time 0' => [0.0, 0.0, [null, null, null]],
]);

it('keeps each time within the time of the call, also at the most seconds a limit takes', function (): void {
    // At 1e8 seconds, a factor of nanoseconds one off moves a time by 0.1 seconds. Each time must
    // lie between the clock before and after the call that sets it.
    $clock = new Clock;
    $deadlines = new Deadlines($clock, new Limits(headerTimeout: 1e8));
    $seconds = 100_000_000_000_000_000;

    $before = hrtime(true);
    $clock->advance(1e8);
    $moved = $clock->now() - $seconds;
    $after = hrtime(true);

    $start = $clock->now();
    $idling = new Deadlines($clock, new Limits(idleTimeout: 1e8));
    $idling->request(false, false);

    $idle = (int) $idling->due(true, false) - $seconds;
    $deadlines->request(false, false);
    $deadlines->request(true, true);

    $request = (int) $deadlines->due(true, false) - $seconds;
    $end = $clock->now();

    expect($moved)->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual($after);

    foreach ([$idle, $request] as $time) {
        expect($time)->toBeGreaterThanOrEqual($start)->toBeLessThanOrEqual($end);
    }
});

it('drops the bytes it holds once it found a fault', function (string $bytes): void {
    $parser = new RequestParser;
    $parser->feed($bytes);

    expect($parser->error())->not->toBeNull()
        ->and($parser->pending())->toBeFalse();
})->with([
    'a transfer coding after a body of 64 KB' => ["POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 65536\r\n\r\n".str_repeat('x', 1 << 16)."POST / HTTP/1.1\r\nHost: a\r\nTransfer-Encoding: gzip\r\n\r\nabc"],
    'a bad request after a request begun' => ["GET / HTTP/1.1\r\nHost: a\r\n\r\nNOPE\r\n\r\nGET / HTTP/1.1\r\n"],
]);
