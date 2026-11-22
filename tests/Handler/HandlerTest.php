<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;

it('answers the 500 of a handler that throws for a handler that gives no valid response, and gives the throwable to onError', function (Closure $handler, string $message): void {
    [$answer, $errors] = withDisplayErrors('0', static fn (): array => failedAnswers($handler, "GET /bad HTTP/1.1\r\nHost: a\r\n\r\nGET / HTTP/1.1\r\nHost: a\r\n\r\n"));

    expect($answer)->toBe(thrownAnswer(after: "GET /respond?body=ok HTTP/1.1\r\nHost: a\r\n\r\n"))
        ->and($errors)->toHaveCount(1)
        ->and($errors[0] ?? '')->toContain($message);
})->with([
    'null' => [static fn (Request $request): mixed => $request->path() === '/bad' ? null : new Response(200, [], 'ok'), 'The handler returned null, not a SorgeIt\\HttpServer\\Response. Return a Response from every path of the handler.'],
    'a string' => [static fn (Request $request): mixed => $request->path() === '/bad' ? 'text' : new Response(200, [], 'ok'), 'The handler returned string, not a SorgeIt\\HttpServer\\Response. Wrap it: new Response(200, [], $body).'],
    'an array' => [static fn (Request $request): mixed => $request->path() === '/bad' ? ['ok' => true] : new Response(200, [], 'ok'), 'The handler returned array, not a SorgeIt\\HttpServer\\Response. For JSON, return new Response(200, [\'Content-Type\' => \'application/json\'], json_encode($data)).'],
    'an object' => [static fn (Request $request): mixed => $request->path() === '/bad' ? new stdClass : new Response(200, [], 'ok'), 'The handler returned stdClass, not a SorgeIt\\HttpServer\\Response. A handler for a framework converts its response; see "Works with Symfony, Laravel and PSR-15" in the README of sorge-it/http-server.'],
    'a body that is an array with keys' => [static fn (Request $request): Response => $request->path() === '/bad' ? new Response(200, [], ['ok' => 'yes']) : new Response(200, [], 'ok'), 'ValueError: '.Response::class.'::__construct(): Argument #3 ($body) must be a list of strings, not an array with keys; for JSON, pass json_encode($data)'],
    // @phpstan-ignore argument.type (a handler result the server cannot send)
    'a header value that is a number' => [static fn (Request $request): Response => $request->path() === '/bad' ? new Response(200, ['X-Count' => 5]) : new Response(200, [], 'ok'), 'ValueError: '.Response::class.'::__construct(): Argument #2 ($headers) has a value of X-Count that is not a string'],
    // @phpstan-ignore argument.type (a handler result the server cannot send)
    'header values that are numbers' => [static fn (Request $request): Response => $request->path() === '/bad' ? new Response(200, ['X-Count' => [5, 6]]) : new Response(200, [], 'ok'), 'Argument #2 ($headers) has a value of X-Count that is not a string'],
    // @phpstan-ignore argument.type (a handler result the server cannot send)
    'a Content-Length that is an object' => [static fn (Request $request): Response => $request->path() === '/bad' ? new Response(200, ['Content-Length' => new stdClass]) : new Response(200, [], 'ok'), 'Argument #2 ($headers) has a value of Content-Length that is not a string'],
    // @phpstan-ignore argument.type (a handler result the server cannot send)
    'a header value that is an object with a property' => [static fn (Request $request): Response => $request->path() === '/bad' ? new Response(200, ['X-Arr' => (object) ['v']]) : new Response(200, [], 'ok'), 'Argument #2 ($headers) has a value of X-Arr that is not a string'],
    // @phpstan-ignore argument.type (a handler result the server cannot send)
    'a header without a name' => [static fn (Request $request): Response => $request->path() === '/bad' ? new Response(200, ['X: y']) : new Response(200, [], 'ok'), 'Argument #2 ($headers) has the key 0, which is not a header name; map each name to its value, such as [\'X-Foo\' => \'bar\']'],
]);

it('takes header values with keys in their order, also where a warning throws', function (): void {
    // Laravel turns each warning into an exception in a test process; one in the server would make
    // `tick()` throw.
    set_error_handler(static fn (int $level, string $message): never => throw new ErrorException($message, 0, $level));

    try {
        // @phpstan-ignore argument.type (a handler result the documented type does not allow)
        $answer = answers(static fn (Request $request): Response => $request->path() === '/bad' ? new Response(200, ['Content-Length' => ['x' => '5']], 'hello') : new Response(200, [], 'ok'), "GET /bad HTTP/1.1\r\nHost: a\r\n\r\nGET / HTTP/1.1\r\nHost: a\r\n\r\n");
    } finally {
        restore_error_handler();
    }

    expect($answer)->toBe(modelAnswers("GET /respond?h=Content-Length=5&body=hello HTTP/1.1\r\nHost: a\r\n\r\nGET /respond?body=ok HTTP/1.1\r\nHost: a\r\n\r\n"));
});

it('gives a handler that calls tick() or close() a LogicException, and the server stays open', function (Closure $call, string $message): void {
    $errors = [];
    $server = new Server(static function () use (&$server, $call): Response {
        assert($server instanceof Server);
        $call($server);

        return new Response(200, [], 'answer');
    }, onError: static function (Throwable $throwable) use (&$errors): void {
        $errors[] = $throwable::class.': '.$throwable->getMessage();
    });

    try {
        $answer = withDisplayErrors('0', static fn (): string => exchange($server, "GET / HTTP/1.1\r\nHost: a\r\n\r\n"));
        $listens = $server->streams()[0] !== [];
    } finally {
        $server->close();
    }

    // The connection stays open after the 500, so the client sends no end.
    expect(samePhp($answer)."\n<closed>")->toBe(thrownAnswer())
        ->and($errors[0] ?? '')->toStartWith('LogicException: '.$message)
        ->and($listens)->toBeTrue();
})->with([
    'tick()' => [static function (Server $server): void {
        $server->tick(0);
    }, 'tick() is already running'],
    'close()' => [static function (Server $server): void {
        $server->close();
    }, 'close() was called during tick()'],
]);

it('lets the LogicException of a close() from onError in a tick leave the tick, and the server stays open', function (): void {
    $server = new Server(static fn (): never => throw new RuntimeException('broken'), onError: static function () use (&$server): void {
        assert($server instanceof Server);
        $server->close();
    });
    $client = connect($server);

    try {
        expect(static fn (): string => converse($server, $client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n"))->toThrow(LogicException::class, 'close() was called during tick()')
            ->and($server->streams()[0])->not->toBe([]);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('refuses a response with a header value that is not a string with a ValueError, where it is made', function (mixed $headers, string $fault): void {
    // @phpstan-ignore argument.type (headers the server cannot send)
    expect(static fn (): Response => new Response(200, $headers))
        ->toThrow(ValueError::class, Response::class.'::__construct(): Argument #2 ($headers) '.$fault);
})->with([
    'a value that is an object' => [['X' => new stdClass], 'has a value of X that is not a string'],
    'a value that is a number, of a name with a line break' => [["X\nY" => 1], 'has a value of X\nY that is not a string'],
    'values with keys, one an integer' => [['X' => ['a' => 'b', 'c' => 2]], 'has a value of X that is not a string'],
    'an integer key, as of a list' => [['X: y'], "has the key 0, which is not a header name; map each name to its value, such as ['X-Foo' => 'bar']"],
    'a name of digits, which PHP makes an integer key' => [['123' => 'a'], "has the key 123, which is not a header name; map each name to its value, such as ['X-Foo' => 'bar']"],
]);

it('refuses a request with a header name that is not lowercase, or values that are not a list, with a ValueError', function (array $headers, string $fault): void {
    // @phpstan-ignore argument.type (headers that header() cannot find)
    expect(static fn (): Request => new Request('GET', '/', $headers))
        ->toThrow(ValueError::class, Request::class.'::__construct(): Argument #3 ($headers) '.$fault);
})->with([
    'a name with an uppercase letter' => [['Content-Type' => ['a']], "has the name Content-Type, which is not lowercase; map each lowercase name to a list of values, such as ['content-type' => ['text/plain']]"],
    'a name with a line break' => [["x\nY" => ['a']], 'has the name x\nY, which is not lowercase; map each lowercase name to a list of values, such as [\'content-type\' => [\'text/plain\']]'],
    'values that are a string' => [['x-a' => 'a'], "has values of x-a that are not a list; map each lowercase name to a list of values, such as ['content-type' => ['text/plain']]"],
    'values with keys' => [['x-a' => ['k' => 'a']], "has values of x-a that are not a list; map each lowercase name to a list of values, such as ['content-type' => ['text/plain']]"],
]);

it('takes a request without headers, and a name of digits, which PHP makes an integer key', function (): void {
    expect(new Request('GET', '/')->headers)->toBe([])
        // @phpstan-ignore argument.type (the integer key that PHP makes of the name)
        ->and(new Request('GET', '/', ['123' => ['a']])->header('123'))->toBe('a');
});

// The SAPI of PHP takes each status, and FrankenPHP sends 500 for one Go refuses.
it('takes each status, as the SAPI of PHP does', function (int $status): void {
    expect((new Response($status))->status)->toBe($status);
})->with([PHP_INT_MIN, -1, 0, 99, 100, 101, 199, 600, 999, 1000, PHP_INT_MAX]);

it('refuses a response with a body and a file with a ValueError', function (): void {
    expect(static fn (): Response => new Response(200, [], 'body', __FILE__))
        ->toThrow(ValueError::class, Response::class.'::__construct(): Argument #3 ($body) must be empty when Argument #4 ($file) is given')
        ->and((new Response(200, [], '', __FILE__))->file)->toBe(__FILE__);
});

it('takes any callable as the handler', function (callable $handler): void {
    $server = new Server($handler);

    try {
        expect(exchange($server, "GET /x HTTP/1.1\r\nHost: a\r\n\r\n"))->toEndWith("\r\n\r\npath /x");
    } finally {
        $server->close();
    }
})->with([
    'an invokable object' => [new class
    {
        public function __invoke(Request $request): Response
        {
            return new Response(200, [], 'path '.$request->path());
        }
    }],
    'an object and a method' => [[new class
    {
        public function handle(Request $request): Response
        {
            return new Response(200, [], 'path '.$request->path());
        }
    }, 'handle']],
]);

it('calls onError with each throwable that becomes a 500 of PHP and its request, once the 500 is queued', function (): void {
    $calls = [];
    $server = new Server(
        // @phpstan-ignore argument.type (a handler result the server cannot send)
        static fn (Request $request): mixed => match ($request->path()) {
            '/throws' => throw new LogicException('broken'),
            '/null' => null,
            default => new Response(200, [], 'ok'),
        },
        onError: static function (Throwable $throwable, Request $request) use (&$calls, &$server): void {
            assert($server instanceof Server);
            // The 500 waits to be written.
            $calls[] = [$throwable::class, $throwable->getMessage(), $request->path(), count($server->streams()[1])];
        },
    );

    try {
        $answer = withDisplayErrors('0', static fn (): string => exchange($server, "GET /throws HTTP/1.1\r\nHost: a\r\n\r\nGET /ok HTTP/1.1\r\nHost: a\r\n\r\nGET /null HTTP/1.1\r\nHost: a\r\n\r\n"));
    } finally {
        $server->close();
    }

    expect(substr_count($answer, 'HTTP/1.1 500 '))->toBe(2)
        ->and($calls)->toBe([
            [LogicException::class, 'broken', '/throws', 1],
            [UnexpectedValueException::class, 'The handler returned null, not a SorgeIt\\HttpServer\\Response. Return a Response from every path of the handler.', '/null', 1],
        ]);
});

it('lets what onError throws leave tick(), and answers the 500 and the next requests after it', function (): void {
    $server = new Server(
        static fn (Request $request): Response => $request->path() === '/throws' ? throw new LogicException('broken') : new Response(200, [], 'answer '.$request->path()),
        onError: static fn (Throwable $throwable): never => throw new RuntimeException('onError: '.$throwable->getMessage()),
    );
    $client = connect($server);
    $received = '';
    $thrown = [];
    $display = (string) ini_get('display_errors');
    ini_set('display_errors', '0');

    try {
        fwrite($client, "GET /throws HTTP/1.1\r\nHost: a\r\n\r\nGET /next HTTP/1.1\r\nHost: a\r\n\r\n");
        $start = hrtime(true) / 1e9;

        while (! str_ends_with($received, 'answer /next') && hrtime(true) / 1e9 - $start < 2) {
            try {
                $server->tick(0.01);
            } catch (RuntimeException $runtimeException) {
                $thrown[] = $runtimeException->getMessage();
            }

            $received .= (string) fread($client, 1 << 16);
        }

        expect($thrown)->toBe(['onError: broken'])
            ->and($received)->toStartWith("HTTP/1.1 500 Internal Server Error\r\n")->toEndWith("\r\n\r\nanswer /next");
    } finally {
        ini_set('display_errors', $display);
        fclose($client);
        $server->close();
    }
});

it('calls onError for a file it cannot read', function (): void {
    $calls = [];
    $server = new Server(
        static fn (): Response => new Response(200, [], file: __DIR__.'/missing'),
        onError: static function (Throwable $throwable) use (&$calls): void {
            $calls[] = [$throwable::class, $throwable->getMessage()];
        },
    );

    try {
        expect(withDisplayErrors('0', static fn (): string => exchange($server, "GET / HTTP/1.1\r\nHost: a\r\n\r\n")))->toStartWith("HTTP/1.1 500 Internal Server Error\r\n")
            ->and($calls)->toBe([[UnexpectedValueException::class, 'The file '.__DIR__.'/missing could not be read.']]);
    } finally {
        $server->close();
    }
});

it('takes any callable as onError', function (): void {
    $errors = new class
    {
        /** @var list<string> */
        public array $messages = [];

        public function record(Throwable $throwable): void
        {
            $this->messages[] = $throwable->getMessage();
        }
    };
    $server = new Server(static fn (): Response => throw new LogicException('broken'), onError: $errors->record(...));

    try {
        expect(withDisplayErrors('0', static fn (): string => exchange($server, "GET / HTTP/1.1\r\nHost: a\r\n\r\n")))->toStartWith('HTTP/1.1 500 ')
            ->and($errors->messages)->toBe(['broken']);
    } finally {
        $server->close();
    }
});

it('keeps each throw of a handler without onError, so the next close() throws ClosedWithErrors with them in order, and a second close() throws nothing', function (): void {
    $server = new Server(static fn (Request $request): never => throw new LogicException('broken '.$request->path()));
    // The ticks answer the 500 and throw nothing.
    $answers = withDisplayErrors('0', static fn (): string => exchange($server, "GET /a HTTP/1.1\r\nHost: a\r\n\r\n").exchange($server, "GET /b HTTP/1.1\r\nHost: a\r\n\r\n"));

    expect(substr_count($answers, "HTTP/1.1 500 Internal Server Error\r\n"))->toBe(2)
        ->and(closedWithErrors($server))->toBe([
            ['LogicException: broken /a', 'LogicException: broken /b'],
            'The server closed with 2 errors that no onError callback received; ClosedWithErrors::$errors lists each one. Fix the handler, or let each request finish before close(). To fail the test at the error instead of at close(), pass onError: static fn (Throwable $e): never => throw $e; a test that expects the error passes an onError that records it. The first, from GET /a: broken /a',
            true,
        ])
        ->and($server->streams())->toBe([[], []])
        ->and(closedWithErrors($server))->toBeNull();
});

it('lets what onError throws leave close() once each connection and the listener are closed, and gives the rest to the next close()', function (): void {
    $server = new Server(
        static fn (Request $request): Response => new Response(200, [], (string) $request->body),
        onError: static fn (Throwable $throwable, Request $request): never => throw new RuntimeException('onError: '.$request->target.': '.$throwable->getMessage()),
    );
    $address = $server->address();
    $first = connect($server);
    $second = connect($server);
    $ended = static function (mixed $client): bool {
        assert(is_resource($client));
        $read = [$client];
        $none = null;
        stream_select($read, $none, $none, 1);

        return fread($client, 1) === '' && feof($client);
    };
    $thrown = [];

    try {
        converse($server, $first, "POST /first HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\na");
        converse($server, $second, "POST /second HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nb");

        for ($i = 0; $i < 3; $i++) {
            try {
                $server->close();
                $thrown[] = null;
            } catch (Throwable $throwable) {
                $thrown[] = [$throwable::class.': '.$throwable->getMessage(), $ended($first), $ended($second), withoutWarnings(static fn (): mixed => stream_socket_client('tcp://'.$address, timeout: 1))];
            }

            // After close(), a tick does nothing: it gives onError no error, so it throws nothing.
            $server->tick(0);
        }

        expect($thrown)->toBe([
            ['RuntimeException: onError: /first: The server closed before the body was complete, with 1 of 5 bytes read.', true, true, false],
            ['RuntimeException: onError: /second: The server closed before the body was complete, with 1 of 5 bytes read.', true, true, false],
            null,
        ]);
    } finally {
        fclose($first);
        fclose($second);
    }
});

it('throws the exception of the handler itself out of tick() with the onError of the README', function (): void {
    $exception = new LogicException('thrown by the handler');
    $server = new Server(static fn (): never => throw $exception, onError: static fn (Throwable $e): never => throw $e);
    $client = connect($server);
    $caught = null;

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");
        $start = hrtime(true) / 1e9;

        while (! $caught instanceof LogicException && hrtime(true) / 1e9 - $start < 1) {
            try {
                withDisplayErrors('0', static function () use ($server): void {
                    $server->tick(0.01);
                });
            } catch (LogicException $thrown) {
                $caught = $thrown;
            }
        }
    } finally {
        fclose($client);
        $server->close();
    }

    expect($caught)->toBe($exception);
});
