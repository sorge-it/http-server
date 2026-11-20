<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;

/**
 * Moves the time of the servers ahead by the seconds, and gives what the client got after it, as
 * `converse()` does. The timeouts of these tests are seconds long, so the time a test takes itself
 * does not move a connection past its time.
 *
 * @param  resource  $client
 */
function after(float $seconds, Server $server, mixed $client): string
{
    advance($server, $seconds);

    return converse($server, $client);
}

/**
 * Ticks until the server read what the client sent, and the time of its head runs.
 */
function untilRequesting(Server $server, float $headerTimeout): void
{
    for ($i = 0; $i < 100 && ($server->timeout() ?? INF) > $headerTimeout; $i++) {
        $server->tick(0.01);
    }
}

/**
 * Writes the bytes, as far as the socket takes them, and gives the rest.
 *
 * @param  resource  $client
 */
function writeAll(mixed $client, string $bytes): string
{
    for ($i = 0; $bytes !== '' && $i < 1000; $i++) {
        $bytes = substr($bytes, (int) fwrite($client, $bytes));
    }

    return $bytes;
}

/**
 * Fills a head field to the byte, so a head reaches `maxHead` exactly.
 */
function filledLine(string $line, int $length, string $byte): string
{
    return $line.str_repeat($byte, $length - strlen($line))."\r\n";
}

/**
 * Waits until the system moved the bytes a client wrote into the socket of the server; a test that
 * moves the clock ahead at once would find them still on their way.
 */
function waitForDelivery(): void
{
    usleep(100_000);
}

// FrankenPHP runs on the clock of the wall, with 0.2 seconds for the 10 here and 5 for the 60.
// Within two seconds only the time that ends the connection here ends it in FrankenPHP.
it('closes a connection once it was idle for its time, as FrankenPHP', function (string $bytes, Limits $limits, Limits $model): void {
    $server = new Server(goHandler(...), limits: $limits);
    $client = connect($server);

    try {
        $first = samePhp(converse($server, $client, $bytes));

        expect(after(9.0, $server, $client))->toBe('');

        $answer = $first.after(1.1, $server, $client);

        expect($answer)->toBe(scopeAnswer($answer, $bytes, static fn (string $sent): string => modelAnswers($sent, $model, 2.0)))
            ->and(readStreamsOnceForgotten($server))->toBe(1);
    } finally {
        fclose($client);
        $server->close();
    }
})->with([
    'a client that sends nothing, after the headerTimeout' => ['', new Limits(idleTimeout: 60.0, headerTimeout: 10.0), new Limits(idleTimeout: 5.0, headerTimeout: 0.2)],
    'a client after an answer, after its idleTimeout' => ["GET / HTTP/1.1\r\nHost: a\r\n\r\n", new Limits(idleTimeout: 10.0, headerTimeout: 60.0), new Limits(idleTimeout: 0.2, headerTimeout: 5.0)],
]);

it('answers a head not complete within its time with 400, and closes the connection', function (string $bytes): void {
    $server = new Server(goHandler(...), limits: new Limits(idleTimeout: 60.0, headerTimeout: 10.0));
    $client = connect($server);

    try {
        converse($server, $client, $bytes);

        expect(after(9.0, $server, $client))->toBe('')
            ->and(after(1.1, $server, $client))->toBe(BAD_REQUEST."\n<closed>");
    } finally {
        fclose($client);
        $server->close();
    }
})->with([
    'one byte' => ['G'],
    'half a request line that is valid' => ['GET / HTTP/1.1'],
    'a request line' => ["GET / HTTP/1.1\r\n"],
    'half a field line' => ["GET / HTTP/1.1\r\nHost"],
    'a head without its end' => ["GET / HTTP/1.1\r\nHost: a\r\n"],
]);

it('gives the next request its time from its first byte after the answer before', function (): void {
    $server = new Server(goHandler(...), limits: new Limits(idleTimeout: 60.0, headerTimeout: 10.0));
    $client = connect($server);

    try {
        // The `G` comes 5 s after the accept and the answer. A time from either of them would end
        // at 10 s, before the 400 at 15 s here.
        expect(converse($server, $client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n"))->toStartWith("HTTP/1.1 200 OK\r\n")
            ->and(after(5.0, $server, $client))->toBe('')
            ->and(converse($server, $client, 'G'))->toBe('')
            ->and(after(9.0, $server, $client))->toBe('')
            ->and(after(1.1, $server, $client))->toBe(BAD_REQUEST."\n<closed>");
    } finally {
        fclose($client);
        $server->close();
    }
});

it('names the end of the time of a head, or of an idle connection, as its timeout', function (): void {
    $server = new Server(static fn (): Response => new Response(200), limits: new Limits(idleTimeout: 10.0, headerTimeout: 5.0));
    $client = connect($server);

    try {
        expect($server->timeout())->toBeNull();

        $server->tick(0.01);
        $accepted = $server->timeout();
        converse($server, $client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");
        $idle = $server->timeout();
        advance($server, 3.0);
        converse($server, $client, 'G');
        $request = $server->timeout();

        expect($accepted)->toBeGreaterThan(4.0)->toBeLessThanOrEqual(5.0)
            ->and($idle)->toBeGreaterThan(9.0)->toBeLessThanOrEqual(10.0)
            ->and($request)->toBeGreaterThan(4.0)->toBeLessThanOrEqual(5.0);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('names its timeout within the time of the call, also at the most seconds a limit takes', function (): void {
    // At 1e8 seconds, a divisor one off from 1e9 moves the timeout by 0.1 seconds. The time of the
    // first request runs from the accept, which lies between the clock before and after the ticks.
    $server = new Server(static fn (): Response => new Response(200), limits: new Limits(headerTimeout: 1e8));
    $client = connect($server);

    try {
        $accepting = hrtime(true);

        for ($i = 0; $i < 1000 && $server->timeout() === null; $i++) {
            $server->tick(0);
        }

        $timeout = (int) round((float) $server->timeout() * 1e9);
        $asked = hrtime(true);
        // Nanoseconds of 1e8 seconds, and the rounding of a float of that size.
        $seconds = 100_000_000_000_000_000;
        $rounding = 100;

        expect($timeout)->toBeGreaterThanOrEqual($seconds + $accepting - $asked - $rounding)->toBeLessThanOrEqual($seconds + $rounding);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('answers a head of maxHead whose bytes came in time, also when the server reads them after its time', function (): void {
    // As from a process paused in a debugger.
    $server = new Server(static fn (Request $request): Response => new Response(200, [], 'ok'), limits: new Limits(maxHead: 8192, headerTimeout: 30.0));
    $client = connect($server);

    try {
        // The accept starts the time of the head; all bytes but the first come after its time.
        fwrite($client, 'G');
        untilRequesting($server, 30.0);

        expect(writeAll($client, substr(filledLine("GET / HTTP/1.1\r\nHost: a\r\nX: ", 8192 - 4, 'h')."\r\n", 1)))->toBe('');

        waitForDelivery();
        advance($server, 31.0);
        $server->tick(0);

        expect(converse($server, $client))->toStartWith("HTTP/1.1 200 OK\r\n")->toEndWith("\r\n\r\nok");
    } finally {
        fclose($client);
        $server->close();
    }
});

it('runs no time of a head once it answered the requests of one read, and serves the next', function (): void {
    $server = new Server(static fn (Request $request): Response => new Response(200, [], $request->path()), limits: new Limits(idleTimeout: 60.0, headerTimeout: 10.0));
    $client = connect($server);

    try {
        // The parser reads the second request only to answer it; then no request is in progress.
        $answers = converse($server, $client, "GET /a HTTP/1.1\r\nHost: a\r\n\r\nGET /b HTTP/1.1\r\nHost: a\r\n\r\n");

        expect($answers)->toEndWith("\r\n\r\n/b")
            ->and(after(11.0, $server, $client))->toBe('')
            ->and($server->timeout())->toBeGreaterThan(30.0)
            ->and(converse($server, $client, "GET /c HTTP/1.1\r\nHost: a\r\n\r\n"))->toStartWith("HTTP/1.1 200 OK\r\n")->toEndWith("\r\n\r\n/c");
    } finally {
        fclose($client);
        $server->close();
    }
});

it('keeps a connection open without end where its timeout has no end', function (string $bytes, string $answer, string $rest, Limits $limits): void {
    $server = new Server(static fn (): Response => new Response(200, [], 'ok'), limits: $limits);
    $client = connect($server);

    try {
        expect(converse($server, $client, $bytes))->toMatch($answer)
            ->and(after(1e7, $server, $client))->toBe('')
            ->and($server->timeout())->toBeNull()
            ->and(converse($server, $client, $rest))->toStartWith('HTTP/1.1 200 OK')->toEndWith("\r\n\r\nok");
    } finally {
        fclose($client);
        $server->close();
    }
})->with([
    'a client that sends nothing, with each time 0' => ['', '/^$/', "GET / HTTP/1.1\r\nHost: a\r\n\r\n", new Limits(idleTimeout: 0.0, headerTimeout: 0.0)],
    'a client that sends nothing, because an idleTimeout is not for the first request' => ['', '/^$/', "GET / HTTP/1.1\r\nHost: a\r\n\r\n", new Limits(idleTimeout: 10.0, headerTimeout: 0.0)],
    'a client that sends half a head' => ['GET / HTTP/1.1', '/^$/', "\r\nHost: a\r\n\r\n", new Limits(idleTimeout: 10.0, headerTimeout: 0.0)],
    'a client after an answer, with an idleTimeout of 0' => ["GET / HTTP/1.1\r\nHost: a\r\n\r\n", '/^HTTP\/1\.1 200 OK\r\n.*\r\n\r\nok$/s', "GET / HTTP/1.1\r\nHost: a\r\n\r\n", new Limits(idleTimeout: 0.0, headerTimeout: 10.0)],
]);

it('moves the time of one server only, so a test of one server leaves the others as they were', function (): void {
    $limits = new Limits(headerTimeout: 60.0);
    $servers = [new Server(static fn (): Response => new Response(200), limits: $limits), new Server(static fn (): Response => new Response(200), limits: $limits)];
    $clients = [connect($servers[0]), connect($servers[1])];

    try {
        $servers[0]->tick(0.01);
        $servers[1]->tick(0.01);
        advance($servers[0], 5.0);

        // Each has a client that sent nothing, which closes after its `headerTimeout`.
        expect($servers[0]->timeout())->toBeGreaterThan(54.0)->toBeLessThanOrEqual(55.0)
            ->and($servers[1]->timeout())->toBeGreaterThan(59.0)->toBeLessThanOrEqual(60.0);
    } finally {
        array_map(fclose(...), $clients);
        array_map(static fn (Server $server): null => $server->close(), $servers);
    }
});

it('runs no time while an answer is to be written, because the server has no write timeout, also with a request after it', function (string $close): void {
    // More than the buffers of a system take.
    $server = new Server(static fn (): Response => new Response(200, [], str_repeat('x', 32 << 20)), limits: new Limits(idleTimeout: 30.0, headerTimeout: 5.0));
    $client = connect($server);

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n".$close."\r\nGET / HTTP/1.1");

        for ($i = 0; $i < 5; $i++) {
            $server->tick(0.01);
        }

        // The answer is not written yet, and the second request has begun.
        expect($server->streams()[1])->toHaveCount(1)
            ->and($server->timeout())->toBeNull();
    } finally {
        fclose($client);
        $server->close();
    }
})->with(['a connection that stays' => [''], 'a connection that closes after the answer' => ["Connection: close\r\n"]]);

it('runs the idle time from the last byte it wrote, so a client that takes its answer slowly keeps the connection', function (): void {
    // More than the buffers of a system take.
    $server = new Server(static fn (): Response => new Response(200, [], str_repeat('x', 32 << 20)), limits: new Limits(idleTimeout: 10.0));
    $client = connect($server);

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");

        for ($i = 0; $i < 5; $i++) {
            $server->tick(0.01);
        }

        advance($server, 8.0);
        $read = 0;

        // A read of a socket gives at most 8 KB.
        for ($i = 0; $i < 100_000 && $server->streams()[1] !== []; $i++) {
            while (($bytes = (string) fread($client, 1 << 16)) !== '') {
                $read += strlen($bytes);
            }

            $server->tick(0.0001);
        }

        expect($read)->toBeGreaterThan(0)
            ->and($server->streams()[1])->toBe([])
            ->and($server->timeout())->toBeGreaterThan(9.0)->toBeLessThanOrEqual(10.0);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('keeps a connection whose client takes no byte of its answers, because the server has no write timeout', function (string $requests): void {
    // More than the buffers of a system take.
    $server = new Server(static fn (Request $request): Response => new Response(200, [], $request->path() === '/big' ? str_repeat('x', 32 << 20) : 'small'), limits: new Limits(idleTimeout: 10.0, headerTimeout: 10.0));
    $client = connect($server);

    try {
        fwrite($client, $requests);

        for ($i = 0; $i < 5; $i++) {
            $server->tick(0.01);
        }

        advance($server, 1e7);
        $server->tick(0.01);

        expect($server->streams()[1])->toHaveCount(1)
            ->and($server->timeout())->toBeNull();

        // Another client is served meanwhile. `converse()` waits until the server has nothing to
        // write, which it does not reach here.
        $other = connect($server);
        fwrite($other, "GET /small HTTP/1.1\r\nHost: a\r\n\r\n");
        $answer = '';

        for ($i = 0; $i < 1000 && ! str_ends_with($answer, "\r\n\r\nsmall"); $i++) {
            $server->tick(0.001);
            $answer .= (string) fread($other, 1 << 16);
        }

        fclose($other);

        expect($answer)->toStartWith("HTTP/1.1 200 OK\r\n")->toEndWith("\r\n\r\nsmall");
    } finally {
        fclose($client);
        $server->close();
    }
})->with([
    'an answer it does not take' => ["GET /big HTTP/1.1\r\nHost: a\r\n\r\n"],
    'and a request after it that waits' => ["GET /big HTTP/1.1\r\nHost: a\r\n\r\nGET /small HTTP/1.1\r\nHost: a\r\n\r\n"],
]);

it('ends a head that comes byte by byte slower than its time, as its time runs from the accept', function (): void {
    $server = new Server(goHandler(...), limits: new Limits(headerTimeout: 3.0));
    $client = connect($server);
    // One byte each 50 ms. After 3 seconds the request line is in progress; FrankenPHP, on the
    // clock of the wall with a `read_header` of 0.2 seconds, gets the bytes that came until then.
    $bytes = 'GET /'.str_repeat('a', 65)." HTTP/1.1\r\nHost: a\r\n\r\n";
    $received = '';

    try {
        for ($i = 0; $i < strlen($bytes) && $received === ''; $i++) {
            $received = converse($server, $client, $bytes[$i]);
            advance($server, 0.05);
        }

        expect($received)->toBe(modelAnswers(substr($bytes, 0, $i), new Limits(headerTimeout: 0.2), 2.0))
            ->and($i)->toBeLessThan(strlen($bytes));
    } finally {
        fclose($client);
        $server->close();
    }
});

it('waits in a tick no longer than until the next connection is past its time', function (): void {
    // The one test of the wait itself, so it runs on the clock of the wall.
    $server = new Server(static fn (): Response => new Response(200, [], 'ok'), limits: new Limits(headerTimeout: 0.2));
    $client = connect($server);

    try {
        $server->tick(0.05);
        $start = hrtime(true) / 1e9;
        $server->tick(10.0);

        expect(hrtime(true) / 1e9 - $start)->toBeGreaterThan(0.1)->toBeLessThan(5.0)
            ->and($server->streams()[0])->toHaveCount(1)
            ->and(converse($server, $client))->toBe("\n<closed>");
    } finally {
        fclose($client);
        $server->close();
    }
});
