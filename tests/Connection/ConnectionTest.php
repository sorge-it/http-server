<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Internal\Clock;
use SorgeIt\HttpServer\Internal\Connection;
use SorgeIt\HttpServer\Internal\Deadlines;
use SorgeIt\HttpServer\Internal\Exchange;
use SorgeIt\HttpServer\Internal\RequestParser;
use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;
use SorgeIt\HttpServer\Tests\GoNetHttp\GoReference;

beforeEach(function (): void {
    $this->server = echoServer();
});

afterEach(function (): void {
    $this->server->close();
});

it('answers requests in the order they came, also several in one piece', function (): void {
    $answer = exchange($this->server, "GET /1 HTTP/1.1\r\nHost: a\r\n\r\nGET /2 HTTP/1.1\r\nHost: a\r\n\r\n");

    expect(substr_count($answer, 'HTTP/1.1 200 OK'))->toBe(2)
        ->and($answer)->toMatch('#"path":"\\\\/1".*"path":"\\\\/2"#s');
});

it('reads and drops the rest of a body too long, so the client gets the 413, then closes', function (): void {
    $this->server->close();
    $this->server = echoServer(new Limits(maxBody: 8 << 20));

    $client = stream_socket_client('tcp://'.$this->server->address());
    assert(is_resource($client));
    $chunk = str_repeat('x', 1 << 16);
    $total = 20 << 20;

    try {
        fwrite($client, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: ".$total."\r\n\r\n");
        stream_set_blocking($client, false);
        $sent = 0;
        $received = '';
        $start = hrtime(true) / 1e9;

        // As a browser does: it sends the whole body, and reads the answer after.
        while ($sent < $total && hrtime(true) / 1e9 - $start < 10) {
            $written = fwrite($client, $chunk);

            if ($written === false) {
                break;
            }

            $sent += $written;
            $this->server->tick(0.001);
        }

        while (! feof($client) && hrtime(true) / 1e9 - $start < 10) {
            $this->server->tick(0.01);
            $received .= (string) fread($client, 1 << 16);
        }

        expect($sent)->toBeGreaterThanOrEqual($total)
            ->and(samePhp($received).(feof($client) ? "\n<closed>" : ''))->toBe(modelAnswers("POST / HTTP/1.1\r\nHost: a\r\nContent-Length: ".$total."\r\n\r\n".str_repeat('x', $total), new Limits(maxBody: 8 << 20)));

        fclose($client);

        expect(readStreamsOnceForgotten($this->server))->toBe(1);
    } finally {
        if (is_resource($client)) {
            fclose($client);
        }
    }
});

// The server does not wait for the rest of the body; what comes within the linger is dropped.
it('closes a connection after its 413 when the client sends nothing more, after a linger of 0.5 seconds, and names that time as its timeout', function (): void {
    $this->server->close();
    $this->server = echoServer(new Limits(maxBody: 2));

    $client = connect($this->server);

    try {
        expect($this->server->timeout())->toBeNull()
            ->and(converse($this->server, $client, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 999999999\r\n\r\nabc"))->toStartWith('HTTP/1.1 413 ')->toEndWith("\n<closed>")
            ->and($this->server->streams()[0])->toHaveCount(2)
            ->and($this->server->timeout())->toBeGreaterThan(0.2)->toBeLessThanOrEqual(0.5);

        // Not before the time it named.
        advance($this->server, (float) $this->server->timeout() - 0.2);
        $this->server->tick(0.01);

        expect($this->server->streams()[0])->toHaveCount(2);

        advance($this->server, 0.3);

        expect(readStreamsOnceForgotten($this->server))->toBe(1)
            ->and($this->server->timeout())->toBeNull();
    } finally {
        fclose($client);
    }
});

it('forgets a lingering connection as soon as its client closes it, and drops what the client sent before', function (): void {
    $server = new Server(static fn (): Response => new Response(200), limits: new Limits(maxBody: 10));
    $client = connect($server);

    try {
        expect(converse($server, $client, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 11\r\n\r\n01234567890"))->toStartWith('HTTP/1.1 413 ');

        // More than one read, then the end; one tick reads them all, and the end.
        $rest = str_repeat('x', 200_000);

        for ($i = 0; $rest !== '' && $i < 1000; $i++) {
            $rest = substr($rest, (int) fwrite($client, $rest));
        }

        // The clock goes back, so the linger has 60 seconds more: only the end of the client can
        // close the connection.
        advance($server, -60.0);

        expect($server->timeout())->toBeGreaterThan(59.0);
        fclose($client);
        // Until the system moved the bytes into the socket of the server.
        usleep(100_000);
        $server->tick(0.01);

        expect($server->streams()[0])->toHaveCount(1);
    } finally {
        $server->close();
    }
});

it('writes the whole answer to a client that sent its end, and does not spin on it', function (): void {
    $body = str_repeat('x', 16 << 20);
    $server = new Server(static fn (): Response => new Response(200, [], $body));
    $client = stream_socket_client('tcp://'.$server->address());
    assert(is_resource($client));

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        stream_set_blocking($client, false);
        $ticks = 0;
        $start = hrtime(true) / 1e9;

        // The client reads nothing, so once the buffers of the system are full the server can do
        // nothing: each tick waits its 100 ms, and half a second holds a few. A tick that waits on
        // the ended client returns at once, as its end stays readable, and counts thousands.
        while (hrtime(true) / 1e9 - $start < 0.5) {
            $server->tick(0.1);
            $ticks++;
        }

        $received = '';

        while (! feof($client) && hrtime(true) / 1e9 - $start < 10) {
            $server->tick(0.05);

            while (($bytes = fread($client, 1 << 20)) !== '' && $bytes !== false) {
                $received .= $bytes;
            }
        }

        expect($ticks)->toBeLessThan(50)
            ->and(str_contains($received, $body))->toBeTrue()
            ->and(readStreamsOnceForgotten($server))->toBe(1);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('writes the whole of a large file to a client that sent its end while it was written', function (): void {
    // The server reads the end of the client while most of the file is still to be written. 16 MB,
    // as the buffers of the system take several of them before the client reads.
    $file = temporaryFile(str_repeat('z', 16 << 20));
    $server = new Server(static fn (): Response => new Response(200, [], file: $file));
    $client = connect($server);

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");

        for ($i = 0; $i < 5; $i++) {
            $server->tick(0.01);
        }

        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $received = 0;
        $start = hrtime(true) / 1e9;

        while (! feof($client) && hrtime(true) / 1e9 - $start < 10) {
            $server->tick(0.01);

            // Counted and dropped: kept, 16 MB would lie in memory.
            while (($bytes = fread($client, 1 << 20)) !== '' && $bytes !== false) {
                $received += substr_count($bytes, 'z');
            }
        }

        expect($received)->toBe(16 << 20)
            ->and(feof($client))->toBeTrue()
            ->and(readStreamsOnceForgotten($server))->toBe(1);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('answers no request after one that closes the connection, also when it writes in the same tick, and lingers only where Go waits', function (string $request, array $paths, string $after): void {
    $limits = new Limits(maxHead: 1024, maxBody: 10);
    $lingers = GoReference::get()->lingers($request.$after, $limits);
    $handled = [];
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert(is_array($pair));
    [$server, $client] = $pair;
    stream_set_blocking($server, false);
    $clock = new Clock;
    $connection = new Connection($server, new RequestParser($limits->maxHead, streams: true), new Deadlines($clock, $limits), '', '');
    $exchange = new Exchange(static function (Request $request) use (&$handled): Response {
        $handled[] = $request->path();

        return new Response(200, [], 'ok');
    }, static fn (Throwable $throwable): never => throw $throwable, $limits);

    try {
        fwrite($client, $request.$after);
        // As a tick does for a stream that is readable and writable. Only macOS refuses a second
        // shutdown, so a second linger fails this test there only.
        $exchange->read($connection);
        $exchange->write($connection);

        expect($handled)->toBe($paths)
            ->and($connection->open())->toBe($lingers)
            ->and($connection->deadlines->lingering())->toBe($lingers);
    } finally {
        fclose($client);
        $connection->close();
    }
})->with([
    'Connection: close' => ["GET /c HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n", ['/c']],
    'a head of 6 KB over a maxHead of 1 KB, as server.go:2099' => ["GET / HTTP/1.1\r\nHost: a\r\nX: ".str_repeat('a', 6000)."\r\n\r\n", []],
    'a body over maxBody, as a requestBodyLimitHit of Go' => ["POST /c HTTP/1.1\r\nHost: a\r\nContent-Length: 11\r\n\r\n".str_repeat('x', 11), []],
])->with([
    'a request after it' => ["GET /d HTTP/1.1\r\nHost: a\r\n\r\n"],
    'nothing after it' => [''],
]);

// The client sends its end while the answer before the 413 is still to be written, so it sends
// nothing more; a linger would only hold the connection.
it('closes at once after a 413 to a client that sent its end, and does not linger', function (): void {
    $limits = new Limits(maxBody: 10);
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert(is_array($pair));
    [$server, $client] = $pair;
    stream_set_blocking($server, false);
    $connection = new Connection($server, new RequestParser(streams: true), new Deadlines(new Clock, $limits), '', '');
    $exchange = new Exchange(static fn (): Response => new Response(200, [], str_repeat('x', 4 << 20)), static fn (Throwable $throwable): never => throw $throwable, $limits);
    $received = '';

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\nPOST / HTTP/1.1\r\nHost: a\r\nContent-Length: 11\r\n\r\n01234567890");
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        stream_set_blocking($client, false);
        $exchange->read($connection);
        $exchange->read($connection);

        for ($i = 0; $i < 100_000 && $connection->open(); $i++) {
            $received .= (string) fread($client, 1 << 20);
            $exchange->write($connection);
        }

        // The system still holds the bytes written last.
        while (($bytes = (string) fread($client, 1 << 20)) !== '') {
            $received .= $bytes;
        }

        expect($connection->ended)->toBeTrue()
            ->and($connection->open())->toBeFalse()
            ->and($connection->deadlines->lingering())->toBeFalse()
            ->and($received)->toContain(str_repeat('x', 4 << 20)."\r\n0\r\n\r\nHTTP/1.1 413 ");
    } finally {
        fclose($client);
        $connection->close();
    }
});

// A browser sends a body in one go; a test that hangs ends on the side of the client.
it('waits for the rest of a body without a time', function (): void {
    $server = new Server(static fn (Request $request): Response => new Response(200, [], (string) $request->body));
    $client = connect($server);

    try {
        expect(converse($server, $client, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\n01"))->toBe('')
            ->and($server->timeout())->toBeNull();

        advance($server, 1e7);

        expect(converse($server, $client, '234'))->toStartWith("HTTP/1.1 200 OK\r\n")->toEndWith("\r\n\r\n01234");
    } finally {
        fclose($client);
        $server->close();
    }
});

it('drops what a client sends while the answer it asked to close after is written', function (): void {
    $server = new Server(static fn (Request $request): Response => new Response(200, [], str_repeat('x', 4 << 20)));
    $client = connect($server);

    try {
        fwrite($client, "GET /1 HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n");
        $server->tick(0.01);
        fwrite($client, "GET /2 HTTP/1.1\r\nHost: a\r\n\r\n");
        $answer = converse($server, $client);

        expect(substr_count($answer, 'HTTP/1.1 '))->toBe(1)
            ->and(strlen($answer))->toBeGreaterThan(4 << 20)
            ->and($answer)->toEndWith("\n<closed>");
    } finally {
        fclose($client);
        $server->close();
    }
});

it('forgets a client that leaves in the middle of a request', function (): void {
    exchange($this->server, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 100\r\n\r\nshort");

    expect(readStreamsOnceForgotten($this->server))->toBe(1)
        ->and(exchange($this->server, "GET /after HTTP/1.1\r\nHost: a\r\n\r\n"))->toContain('"path":"\/after"');
});

it('closes the connection only when the first Connection of the handler is close, and sends the Connection of the handler, as FrankenPHP with the same headers', function (array $headers, string $query): void {
    $request = static fn (string $target): string => 'GET '.$target." HTTP/1.1\r\nHost: a\r\n\r\n";

    // @phpstan-ignore argument.type (the keys are names, as the dataset gives them)
    expect(answers(static fn (): Response => new Response(200, $headers, 'one'), $request('/').$request('/')))->toBe(modelAnswers($request('/respond?'.$query.'&body=one').$request('/respond?'.$query.'&body=one')));
})->with([
    'close' => [['Connection' => 'close'], 'h=Connection=close'],
    'Close, which is not close' => [['Connection' => 'Close'], 'h=Connection=Close'],
    'close in a list, in another case' => [['CONNECTION' => 'Keep-Alive, Close'], 'h=CONNECTION=Keep-Alive%2C%20Close'],
    'close as the second value' => [['connection' => ['keep-alive', 'close']], 'h=connection=keep-alive&h=connection=close'],
    'a token that holds close' => [['Connection' => 'x-closer'], 'h=Connection=x-closer'],
]);

it('closes the connection when the Connection of the handler is close, also for a HEAD of a file, as FrankenPHP', function (?string $file, string $request, string $go): void {
    expect(answers(static fn (): Response => new Response(200, ['Connection' => 'close'], $file === null ? 'one' : '', $file), $request.$request))->toBe(modelAnswers($go.$go));
})->with([
    'a HEAD of a file' => [__FILE__, "HEAD / HTTP/1.1\r\nHost: a\r\n\r\n", 'HEAD /respond?h=Connection=close&ffill='.filesize(__FILE__)." HTTP/1.1\r\nHost: a\r\n\r\n"],
]);

it('serves a thousand requests one after the other on one connection, with flat memory', function (): void {
    $client = connect($this->server);
    $answered = 0;

    try {
        $before = memory_get_usage();

        for ($i = 0; $i < 1000; $i++) {
            fwrite($client, 'GET /'.$i." HTTP/1.1\r\nHost: a\r\n\r\n");
            $received = '';
            $start = hrtime(true) / 1e9;

            while (! str_ends_with($received, '"path":"\/'.$i.'","query":"","probe":null,"body":""}') && hrtime(true) / 1e9 - $start < 1) {
                $this->server->tick(0.001);
                $received .= (string) fread($client, 1 << 16);
            }

            $answered += substr_count($received, 'HTTP/1.1 200 OK');
        }

        expect($answered)->toBe(1000)
            ->and(memory_get_usage() - $before)->toBeLessThan(256 << 10);
    } finally {
        fclose($client);
    }
});

it('closes its listener and its connections when the caller drops it without close(), as a failed test does', function (): void {
    $serve = static function (): array {
        $server = echoServer();
        $client = connect($server);
        $server->tick(0.01);

        return [$server->address(), $client];
    };
    [$address, $client] = $serve();
    gc_collect_cycles();

    try {
        $listener = withoutWarnings(static fn (): mixed => stream_socket_server('tcp://'.$address));
        $closed = fread($client, 1) === '' && feof($client);
    } finally {
        fclose($client);
    }

    expect($listener)->toBeResource()
        ->and($closed)->toBeTrue();
    assert(is_resource($listener));
    fclose($listener);
});
