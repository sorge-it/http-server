<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Body;
use SorgeIt\HttpServer\Internal\RequestParser;
use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;
use SorgeIt\HttpServer\StringBody;

// The bytes Go writes for a handler that reads none, a part or all of the body are cases of
// tests/Fixtures/go-net-http/cases.json, group `handler`. Here is when things happen.

it('calls the handler once the head came, as Go, and a read waits for the body while other connections go on', function (): void {
    $events = [];
    $server = new Server(static function (Request $request) use (&$events): Response {
        $events[] = 'handler '.$request->path();
        $body = (string) $request->body;
        $events[] = 'read '.$body;

        return new Response(200, [], $body);
    });
    $slow = connect($server);

    try {
        expect(converse($server, $slow, "POST /slow HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nhe"))->toBe('')
            ->and($events)->toBe(['handler /slow'])
            ->and(exchange($server, "GET /other HTTP/1.1\r\nHost: a\r\n\r\n"))->toStartWith("HTTP/1.1 200 OK\r\n")
            ->and($events)->toBe(['handler /slow', 'handler /other', 'read '])
            ->and(converse($server, $slow, 'llo'))->toEndWith("\r\n\r\nhello")
            ->and($events)->toBe(['handler /slow', 'handler /other', 'read ', 'read hello']);
    } finally {
        fclose($slow);
        $server->close();
    }
});

it('gives a read after the handler returned the error of Go, also of a body read before the handler for maxBody', function (?int $maxBody): void {
    $kept = null;
    $server = new Server(static function (Request $request) use (&$kept): Response {
        $kept = $request->body;

        return new Response(204);
    }, limits: new Limits(maxBody: $maxBody));

    try {
        exchange($server, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nhello");
    } finally {
        $server->close();
    }

    assert($kept instanceof Body);

    expect([$kept->read(5), $kept->error(), (string) $kept])->toBe(['', 'http: invalid Read on closed Body', '']);
})->with(['no maxBody' => [null], 'a maxBody' => [10]]);

it('refuses a read of the body in a fiber of its own with a LogicException, which gets the 500 of a handler that throws', function (): void {
    [$answer, $errors] = withDisplayErrors('0', static fn (): array => failedAnswers(static function (Request $request): Response {
        new Fiber(static fn (): string => $request->body->read(5))->start();

        return new Response(200);
    }, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nhello"));

    expect($answer)->toBe(thrownAnswer())
        ->and($errors[0] ?? '')->toStartWith('LogicException: Body::read() was called outside the fiber in which the server runs the handler.');
});

it('throws a LogicException into a handler that suspends its fiber itself, which gets the 500 of a handler that throws', function (): void {
    [$answer, $errors] = withDisplayErrors('0', static fn (): array => failedAnswers(static function (): Response {
        Fiber::suspend();

        return new Response(200);
    }, "GET / HTTP/1.1\r\nHost: a\r\n\r\n"));

    expect($answer)->toBe(thrownAnswer())
        ->and($errors[0] ?? '')->toStartWith('LogicException: The handler suspended the fiber');
});

it('closes the connection without an answer, and continues the fiber no more, when the handler suspends it again after that exception', function (): void {
    $events = [];
    $answer = answer(static function () use (&$events): Response {
        for ($i = 0; $i < 3; $i++) {
            try {
                Fiber::suspend();
                $events[] = 'continued';
            } catch (LogicException) {
                $events[] = 'caught';
            }
        }

        return new Response(200);
    }, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");

    expect($answer)->toBe("\n<closed>")
        ->and($events)->toBe(['caught']);
});

it('ends a handler that waits for its body when the server closes, as its read gets the error of Go on the closed connection', function (Limits $limits): void {
    $seen = null;
    $errors = [];
    $server = new Server(static function (Request $request) use (&$seen): Response {
        try {
            return new Response(200, [], $request->body->read(10));
        } finally {
            $seen = [$request->body->read(10), $request->body->error()];
        }
    }, limits: $limits, onError: static function (Throwable $throwable) use (&$errors): void {
        $errors[] = $throwable::class.': '.$throwable->getMessage();
    });
    $client = connect($server);

    try {
        expect(converse($server, $client, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\n"))->toBe('')
            ->and($seen)->toBeNull();

        $server->close();

        expect($seen)->toBeArray()
            ->and($seen[0] ?? null)->toBe('')
            ->and($seen[1] ?? null)->toMatch('/^read tcp \S+->\S+: use of closed network connection$/')
            ->and($errors)->toBe(['LogicException: The server closed before the body was complete, with 0 of 5 bytes read']);
    } finally {
        fclose($client);
    }
})->with([
    'no maxBody' => [new Limits],
    // The handler reads through `MaxBytesReader` first, which gets the error.
    'a maxBody' => [new Limits(maxBody: 10)],
]);

it('gives onError a LogicException with the bytes read and the length for a body that the close cut, then the throw of its handler, once all is closed', function (): void {
    $calls = [];
    $server = new Server(static fn (Request $request): never => throw new RuntimeException('read '.$request->body), onError: static function (Throwable $throwable, Request $request) use (&$calls, &$server): void {
        assert($server instanceof Server);
        $calls[] = [$throwable::class.': '.$throwable->getMessage(), $request->target, $server->streams()];
    });
    $client = connect($server);

    try {
        expect(converse($server, $client, "PUT /upload?x=1 HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\nabc"))->toBe('')
            ->and($calls)->toBe([]);

        $server->close();

        expect($calls)->toBe([
            ['LogicException: The server closed before the body was complete, with 3 of 10 bytes read', '/upload?x=1', [[], []]],
            ['RuntimeException: read abc', '/upload?x=1', [[], []]],
        ])
            ->and(converse($server, $client))->toBe("\n<closed>");
    } finally {
        fclose($client);
    }
});

it('throws ClosedWithErrors from close() without onError for a body that the close cut, with each error in order, once each connection and the listener are closed', function (): void {
    $server = new Server(static fn (Request $request): never => throw new RuntimeException('read '.$request->body));
    $address = $server->address();
    $client = connect($server);

    try {
        expect(converse($server, $client, "PUT /upload?x=1 HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\nabc"))->toBe('')
            ->and(closedWithErrors($server))->toBe([
                ['LogicException: The server closed before the body was complete, with 3 of 10 bytes read', 'RuntimeException: read abc'],
                'The server closed with 2 errors that no onError callback received; ClosedWithErrors::$errors lists each one. Fix the handler; only a test that expects the error passes onError to the Server. The first, from PUT /upload?x=1: The server closed before the body was complete, with 3 of 10 bytes read',
                true,
            ])
            ->and($server->streams())->toBe([[], []])
            ->and(converse($server, $client))->toBe("\n<closed>")
            ->and(withoutWarnings(static fn (): mixed => stream_socket_client('tcp://'.$address, timeout: 1)))->toBeFalse()
            ->and(closedWithErrors($server))->toBeNull();
    } finally {
        fclose($client);
    }
});

it('throws ClosedWithErrors from close() for a body that the server drops after the handler returned, because it waits for the rest', function (): void {
    $server = new Server(static fn (): Response => new Response(200, [], 'early'));
    $client = connect($server);

    try {
        expect(converse($server, $client, "POST /early HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\nabc"))->toBe('')
            ->and(closedWithErrors($server))->toBe([
                ['LogicException: The server closed before the body was complete, with 3 of 10 bytes read'],
                'The server closed with 1 error that no onError callback received; ClosedWithErrors::$errors lists each one. Fix the handler; only a test that expects the error passes onError to the Server. The first, from POST /early: The server closed before the body was complete, with 3 of 10 bytes read',
                true,
            ]);
    } finally {
        fclose($client);
    }
});

it('gives a LogicException to a handler that a close() resumes and that calls tick() or close(), as to each handler', function (Closure $call, string $message): void {
    $caught = '';
    $server = new Server(static function (Request $request) use (&$server, &$caught, $call): Response {
        assert($server instanceof Server);
        $body = (string) $request->body;

        try {
            $call($server);
        } catch (LogicException $logicException) {
            $caught = $logicException::class.': '.$logicException->getMessage();
        }

        return new Response(200, [], $body);
    });
    $client = connect($server);

    try {
        expect(converse($server, $client, "PUT /upload HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\nabc"))->toBe('')
            ->and(closedWithErrors($server))->toBe([
                ['LogicException: The server closed before the body was complete, with 3 of 10 bytes read'],
                'The server closed with 1 error that no onError callback received; ClosedWithErrors::$errors lists each one. Fix the handler; only a test that expects the error passes onError to the Server. The first, from PUT /upload: The server closed before the body was complete, with 3 of 10 bytes read',
                true,
            ])
            ->and($caught)->toStartWith('LogicException: '.$message);
    } finally {
        fclose($client);
    }
})->with([
    'tick()' => [static function (Server $server): void {
        $server->tick(0);
    }, 'tick() is already running, or close() resumes a handler.'],
    'close()' => [static function (Server $server): void {
        $server->close();
    }, 'close() was called during tick(), or from a handler that close() resumes.'],
]);

it('gives a body read none of the bytes of the next request, once the parser closed it', function (): void {
    $parser = new RequestParser(streams: true);
    $first = $parser->feed("POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 2\r\n\r\nhiPOST / HTTP/1.1\r\nHost: a\r\nContent-Length: 2\r\n\r\nho")[0]->body;
    $parser->done(true);
    $second = $parser->feed('')[0]->body;

    expect([$first->read(2), $first->error(), $second->read(2)])->toBe(['', 'http: invalid Read on closed Body', 'ho']);
});

it('keeps the first error of a read, as Go gives EOF to a read after unexpected EOF', function (): void {
    $parser = new RequestParser(streams: true);
    $body = $parser->feed("POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\nhello")[0]->body;
    $parser->end();

    expect([$body->read(10), $body->read(10), $body->error(), $body->read(10), $body->error()])->toBe(['hello', '', 'unexpected EOF', '', 'unexpected EOF']);
});

it('gives the handler a body that the end of the client cut with unexpected EOF, then answers 400 in place of its answer, and closes the connection', function (): void {
    $read = null;
    $answer = answers(static function (Request $request) use (&$read): Response {
        $read = [(string) $request->body, $request->body->error()];

        return new Response(200, [], 'handler');
    }, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nab");

    expect($answer)->toBe(BAD_REQUEST."\n<closed>")
        ->and($read)->toBe(['ab', 'unexpected EOF']);
});

// Go drops no rest of 256 KB or more, and none of a request that closes; it would send the answer.
it('drops the rest of a body that the handler did not read before its answer, so a cut gets 400', function (string $request): void {
    expect(answers(static fn (): Response => new Response(200, [], 'handler'), $request))->toBe(BAD_REQUEST."\n<closed>");
})->with([
    'a rest of 3 bytes' => ["POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nab"],
    'a rest of 256 KB or more' => ["POST / HTTP/1.1\r\nHost: a\r\nContent-Length: ".(1 << 20)."\r\n\r\n".str_repeat('x', 768 << 10)],
    'a request that closes' => ["POST / HTTP/1.1\r\nHost: a\r\nConnection: close\r\nContent-Length: 5\r\n\r\nab"],
]);

it('answers 400 for a cut body also where the handler throws', function (bool $reads): void {
    [$answer, $errors] = failedAnswers(static function (Request $request) use ($reads): Response {
        if ($reads) {
            $request->body->read(1);
        }

        throw new LogicException('broken');
    }, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\nab");

    expect($answer)->toBe(BAD_REQUEST."\n<closed>")
        ->and($errors)->toBe(['LogicException: broken']);
})->with(['before a read' => [false], 'after a read' => [true]]);

it('drops the rest of a body that the handler did not read, and answers the next request', function (): void {
    $answer = answers(static fn (Request $request): Response => new Response(200, [], $request->path()), "POST /a HTTP/1.1\r\nHost: a\r\nContent-Length: ".(1 << 20)."\r\n\r\n".str_repeat('x', 1 << 20)."GET /b HTTP/1.1\r\nHost: a\r\n\r\n");

    expect(substr_count($answer, "HTTP/1.1 200 OK\r\n"))->toBe(2)
        ->and($answer)->not->toContain('Connection: close')
        ->and($answer)->toEndWith("\r\n\r\n/b\n<closed>");
});

it('gives a request that no server read an empty body, as `http.NoBody` of Go', function (): void {
    $body = new Request('GET', '/', [])->body;

    expect([(string) $body, $body->read(1), $body->error()])->toBe(['', '', null]);
});

it('reads a StringBody as a body that arrived whole: a cast gives the rest, a later cast the same, a later read nothing', function (): void {
    $body = new StringBody('abcde');

    expect([$body->read(2), (string) $body, (string) $body, $body->read(1), $body->error()])->toBe(['ab', 'cde', 'cde', '', null])
        ->and([($long = new StringBody('abc'))->read(10), $long->read(1)])->toBe(['abc', ''])
        // @phpstan-ignore argument.type (a test gives the length the type refuses)
        ->and(static fn (): string => new StringBody('abc')->read(0))->toThrow(ValueError::class, Body::class.'::read(): Argument #1 ($length) must be greater than 0');
});

it('refuses a read of less than one byte with a ValueError', function (string $request): void {
    $body = $request === '' ? new Request('GET', '/', [])->body : new RequestParser()->feed($request)[0]->body;

    // @phpstan-ignore argument.type (a test gives the length the type refuses)
    expect(static fn (): string => $body->read(0))->toThrow(ValueError::class, Body::class.'::read(): Argument #1 ($length) must be greater than 0');
})->with([
    'a body the server read' => ["POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 1\r\n\r\na"],
    'the body of a request no server read' => [''],
]);

it('holds no more of the body than the handler reads, as Go, also from a client that sends without a pause', function (): void {
    $server = new Server(static function (Request $request): Response {
        $read = 0;

        while (($piece = $request->body->read(1 << 16)) !== '') {
            $read += strlen($piece);
        }

        return new Response(200, [], (string) $read);
    });
    // A client in its own process sends as fast as the socket takes the bytes. Go reads only as the
    // handler reads, so TCP stops the client; a server that read all that came held megabytes.
    $client = <<<'PHP'
        [, $address] = $argv;
        $client = stream_socket_client('tcp://'.$address);
        $send = static function (string $bytes) use ($client): void {
            while ($bytes !== '' && ($written = fwrite($client, $bytes)) > 0) {
                $bytes = substr($bytes, $written);
            }
        };
        $send("POST / HTTP/1.1\r\nHost: a\r\nConnection: close\r\nContent-Length: ".(64 << 20)."\r\n\r\n");
        $piece = str_repeat('x', 1 << 20);

        for ($i = 0; $i < 64; $i++) {
            $send($piece);
        }

        echo stream_get_contents($client);
        PHP;
    // The first request of the process loads the classes of the server, which take memory too.
    exchange($server, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 1\r\n\r\nx");
    $process = proc_open([PHP_BINARY, '-r', $client, $server->address()], [['file', '/dev/null', 'r'], ['pipe', 'w'], STDERR], $pipes);
    assert(is_resource($process));
    stream_set_blocking($pipes[1], false);
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $answer = '';
    $start = hrtime(true) / 1e9;

    try {
        while (! feof($pipes[1]) && hrtime(true) / 1e9 - $start < 30) {
            $server->tick(0.01);
            $answer .= (string) fread($pipes[1], 1 << 16);
        }

        expect($answer)->toStartWith("HTTP/1.1 200 OK\r\n")->toEndWith("\r\n\r\n".(64 << 20))
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(1 << 20);
    } finally {
        fclose($pipes[1]);
        proc_close($process);
        $server->close();
    }
});

// No live suite has it: PHP parses such a body itself, so FrankenPHP gives `goHandler()` none.
it('gives the handler the multipart body of a FormData from Chrome as it came, and none of the next request, by read() and by the cast, whole and one byte at a time', function (bool $cast, bool $bytewise): void {
    $boundary = '----WebKitFormBoundaryutIejmH8YLFdz1D9';
    // The body that Chrome 147 sent for a `fetch` of a FormData with a field and a file; the head
    // has some of its fields.
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nb\r\n"
        ."--{$boundary}\r\nContent-Disposition: form-data; name=\"f\"; filename=\"f.txt\"\r\nContent-Type: text/plain\r\n\r\nfile \xC3\xA4\r\n"
        ."--{$boundary}--\r\n";
    $request = "POST /x HTTP/1.1\r\nHost: 127.0.0.1:61488\r\nConnection: keep-alive\r\nContent-Length: ".strlen($body)."\r\n"
        ."Content-Type: multipart/form-data; boundary={$boundary}\r\nOrigin: http://127.0.0.1:61488\r\n"
        ."Sec-Fetch-Mode: cors\r\nAccept-Encoding: gzip, deflate, br, zstd\r\n\r\n".$body
        ."GET /next HTTP/1.1\r\nHost: 127.0.0.1:61488\r\n\r\n";
    $got = [];

    $answer = answer(static function (Request $request) use ($cast, &$got): Response {
        $read = '';

        if ($cast) {
            $read = (string) $request->body;
        } else {
            while (($piece = $request->body->read(7)) !== '') {
                $read .= $piece;
            }
        }

        $got[] = [$request->path(), $read, $request->body->error()];

        return new Response(200, [], 'ok');
    }, $bytewise ? str_split($request) : $request);

    expect(strlen($body))->toBe(272)
        ->and($got)->toBe([['/x', $body, null], ['/next', '', null]])
        ->and(substr_count($answer, "HTTP/1.1 200 OK\r\n"))->toBe(2);
})->with(['by read()' => [false], 'by the cast' => [true]])->with(['whole' => [false], 'one byte at a time' => [true]]);
