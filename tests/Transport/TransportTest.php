<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Internal\BodyReader;
use SorgeIt\HttpServer\Internal\Clock;
use SorgeIt\HttpServer\Internal\Connection;
use SorgeIt\HttpServer\Internal\Deadlines;
use SorgeIt\HttpServer\Internal\Exchange;
use SorgeIt\HttpServer\Internal\HeadReader;
use SorgeIt\HttpServer\Internal\ReadBuffer;
use SorgeIt\HttpServer\Internal\RequestParser;
use SorgeIt\HttpServer\Internal\RequestReader;
use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\ListenFailed;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;
use SorgeIt\HttpServer\Tests\GoNetHttp\GoReference;

/**
 * Whether the system can listen on the IPv6 loopback, which a container may lack.
 */
function hasIpv6Loopback(): bool
{
    $listener = withoutWarnings(static fn (): mixed => stream_socket_server('tcp://[::1]:0'));

    if (! is_resource($listener)) {
        return false;
    }

    fclose($listener);

    return true;
}

it('reads a large body in pieces in linear time', function (): void {
    // Pieces of 1 KB: to copy the buffer or the body again with each one moves more than 64 GB.
    $message = static fn (int $size): string => "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: ".$size."\r\n\r\n".str_repeat('x', $size);

    [$linear, $requests, $time] = inLinearTime($message, 16 << 20, static fn (): RequestParser => new RequestParser, 1 << 10);

    expect($requests)->toBe(1)
        ->and($linear)->toBeTrue($time);
});

it('writes a large answer to a client that reads it in pieces', function (): void {
    $answer = visible(samePhp(answer(static fn (): Response => new Response(200, [], str_repeat('x', 20 << 20)), "GET / HTTP/1.1\r\nHost: a\r\n\r\n")));
    $model = visible(modelAnswers('GET /respond?fill='.(20 << 20)." HTTP/1.1\r\nHost: a\r\n\r\n"));

    // The length first: a diff of 20 MB takes minutes.
    expect(strlen($answer))->toBe(strlen($model) - strlen("\n<closed>"))
        ->and($answer."\n<closed>")->toBe($model);
});

it('names the address it could not listen on, and port 0 as the way out', function (): void {
    $server = echoServer();

    try {
        expect(fn (): Server => new Server(static fn (): Response => new Response, '127.0.0.1', (int) substr($server->address(), 10)))
            ->toThrow(ListenFailed::class, 'The server could not listen on 127.0.0.1:')
            ->and(fn (): Server => new Server(static fn (): Response => new Response, '127.0.0.1', (int) substr($server->address(), 10)))
            ->toThrow(ListenFailed::class, '. Port 0 picks a free port.');
    } finally {
        $server->close();
    }
});

it('leaves error_get_last() empty after a call it expects to fail', function (): void {
    $server = echoServer();
    error_clear_last();

    try {
        new Server(static fn (): Response => new Response, '127.0.0.1', (int) substr($server->address(), 10));
    } catch (ListenFailed) {
        // Expected: the port is taken.
    } finally {
        $server->close();
    }

    expect(error_get_last())->toBeNull();
});

it('listens on an IPv6 address given without brackets', function (string $host): void {
    $server = new Server(static fn (Request $request): Response => new Response(200, [], (string) $request->header('Host')), $host);

    try {
        expect($server->address())->toStartWith('[::1]:')
            ->and($server->url())->toBe('http://'.$server->address())
            ->and(exchange($server, "GET / HTTP/1.1\r\nHost: ".$server->address()."\r\n\r\n"))->toEndWith("\r\n\r\n".$server->address());
    } finally {
        $server->close();
    }
})->with(['::1', '[::1]'])->skip(fn (): bool => ! hasIpv6Loopback(), 'The system has no IPv6 loopback.');

it('listens on a name by the loopback address it gives, and on another address of 127.0.0.0/8', function (string $host, string $address): void {
    // macOS has only 127.0.0.1 without an alias; Linux has the whole 127.0.0.0/8.
    $probe = withoutWarnings(static fn (): mixed => stream_socket_server('tcp://'.$host.':0'));

    if (! is_resource($probe)) {
        $this->markTestSkipped('The system has no '.$host.'.');
    }

    fclose($probe);
    $server = new Server(static fn (): Response => new Response(200, [], 'ok'), $host);

    try {
        expect($server->address())->toMatch($address)
            ->and(exchange($server, "GET / HTTP/1.1\r\nHost: a\r\n\r\n"))->toEndWith("\r\n\r\nok");
    } finally {
        $server->close();
    }
})->with([
    'localhost' => ['localhost', '/^(127\.0\.0\.1|\[::1]):\d+$/'],
    '127.0.0.2' => ['127.0.0.2', '/^127\.0\.0\.2:\d+$/'],
]);

it('refuses an address that is not loopback with ListenFailed before it binds the port', function (string $host, string $address): void {
    if (str_contains($address, ':') && ! hasIpv6Loopback()) {
        $this->markTestSkipped('The system has no IPv6 loopback.');
    }

    // On Linux, a bind of the port on each address fails while 127.0.0.1 listens on it: the
    // refusal shows that the server checked before.
    $taken = echoServer();
    $message = '';

    try {
        new Server(static fn (): Response => new Response, $host, (int) substr($taken->address(), 10));
    } catch (ListenFailed $listenFailed) {
        $message = $listenFailed->getMessage();
    } finally {
        $taken->close();
    }

    expect($message)->toBe('The server listens on loopback only, not on '.$address.'. Run the browser on the same host or in the same container as the test.');
})->with([
    'IPv4, each address' => ['0.0.0.0', '0.0.0.0'],
    'IPv4, each address, short' => ['0', '0.0.0.0'],
    'IPv4, each address, shorter by a dot' => ['0.0', '0.0.0.0'],
    'IPv6, each address' => ['::', '[::]'],
    'IPv6, each address, in brackets' => ['[::]', '[::]'],
    'IPv4 in the IPv6 form' => ['::ffff:127.0.0.1', '[::ffff:127.0.0.1]'],
]);

it('refuses a host with a port or a scheme, and names $port as the way out', function (string $host): void {
    expect(fn (): Server => new Server(static fn (): Response => new Response, $host))
        ->toThrow(ListenFailed::class, 'The server takes a name or an IP address as $host, without a scheme or a port, not '.$host.'. Pass the port as $port.');
})->with([
    'IPv4 and port' => ['127.0.0.1:8080'],
    'name and port' => ['localhost:8080'],
    'scheme' => ['http://127.0.0.1'],
    'IPv6 in brackets and port' => ['[::1]:8080'],
]);

it('takes an IPv6 address with a zone as an address, not as a host with a port', function (): void {
    // macOS binds ::1 and drops the zone that no interface has; Linux fails to resolve it.
    $message = '';

    try {
        new Server(static fn (): Response => new Response, '::1%nozone')->close();
    } catch (ListenFailed $listenFailed) {
        $message = $listenFailed->getMessage();
    }

    expect($message)->not->toContain('without a scheme or a port');
});

it('names the address it could not bind', function (): void {
    expect(fn (): Server => new Server(static fn (): Response => new Response, '2001:db8::1'))
        ->toThrow(ListenFailed::class, 'The server could not bind [2001:db8::1]: ');
});

it('listens on the address that it checked, not on the host again', function (): void {
    $taken = echoServer();

    try {
        // A second lookup of `127.1` could give another address than the checked one.
        expect(fn (): Server => new Server(static fn (): Response => new Response, '127.1', (int) substr($taken->address(), 10)))
            ->toThrow(ListenFailed::class, 'The server could not listen on 127.0.0.1:'.substr($taken->address(), 10).': ');
    } finally {
        $taken->close();
    }
});

it('gives each request the IP and port of its client as Go gives RemoteAddr, and names both ends in the error of a read in the form of Go', function (string $host, string $client): void {
    if ($host === '::1' && ! hasIpv6Loopback()) {
        $this->markTestSkipped('The system has no IPv6 loopback.');
    }

    $seen = [];
    $errors = [];
    $server = new Server(static function (Request $request) use (&$seen): Response {
        $body = (string) $request->body;
        $seen[] = $read = '<'.$request->remoteAddress.'|'.$request->body->error().'>'.$body;

        return new Response(200, [], $read);
    }, $host, onError: static function (Throwable $throwable) use (&$errors): void {
        $errors[] = $throwable::class.': '.$throwable->getMessage();
    });

    try {
        $port = substr($server->address(), strrpos($server->address(), ':') + 1);
        $url = sprintf('tcp://%s:%s', $client, $port);
        [$first, $second, $third] = [stream_socket_client($url), stream_socket_client($url), stream_socket_client($url)];
        assert(is_resource($first) && is_resource($second) && is_resource($third));
        $clients = [$first, $second, $third];
        $names = array_map(static fn (mixed $stream): string|false => stream_socket_get_name($stream, false), $clients);
        $closing = 'read tcp '.stream_socket_get_name($third, true).'->'.$names[2].': use of closed network connection';
        array_map(static fn (mixed $stream): bool => stream_set_blocking($stream, false), $clients);
        $request = "GET / HTTP/1.1\r\nHost: a\r\n\r\n";
        $answers = [converse($server, $first, [$request, $request]), converse($server, $second, $request)];
        converse($server, $third, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\n01");
        $server->close();

        // Go gives the forms `127.0.0.1:54321` and `[::1]:54321`. PHP gives the addresses of a
        // client socket in these forms.
        expect(substr_count($answers[0], '<'.$names[0].'|>'))->toBe(2)
            ->and($answers[1])->toEndWith('<'.$names[1].'|>')
            ->and(end($seen))->toBe('<'.$names[2].'|'.$closing.'>01')
            ->and($errors)->toBe(['LogicException: The server closed before the body was complete, with 2 of 10 bytes read'])
            ->and($names[0])->not->toBe($names[1])
            ->and($names[0])->toMatch($client === '[::1]' ? '/^\[::1]:\d+$/' : '/^127\.0\.0\.1:\d+$/');

        // A server of Go on the same host, with a client from the same address, whose handler sets a
        // read deadline that is over: its ports differ in each run, so the ends of its error are
        // compared in their form.
        $go = goEvents(str_replace("\n<closed>", '', GoReference::get()->exchange("POST /?deadline HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\n01", new Limits, within: 2.0, host: $host)));
        $last = end($go);
        assert(is_array($last) && is_array($last['request']) && is_string($last['request']['bodyError']));
        $ends = static fn (string $error): string => (string) preg_replace('/:\d+\b/', ':N', substr($error, 0, (int) strrpos($error, ': ')));

        expect($ends($closing))->toBe($ends($last['request']['bodyError']))
            ->and($last['request']['bodyError'])->toEndWith(': i/o timeout');

        array_map(fclose(...), $clients);
    } finally {
        $server->close();
    }
})->with([
    'IPv4' => ['127.0.0.1', '127.0.0.1'],
    'IPv6' => ['::1', '[::1]'],
]);

it('gives the address of the client to a request whose client reset the connection before the accept, as Go takes it from accept()', function (): void {
    $seen = null;
    $server = new Server(static function (Request $request) use (&$seen): Response {
        $seen = $request->remoteAddress;

        return new Response;
    });

    try {
        $client = stream_socket_client('tcp://'.$server->address());
        assert(is_resource($client));
        $name = stream_socket_get_name($client, false);
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");
        // A linger of zero makes the close a reset.
        $socket = socket_import_stream($client);
        assert($socket instanceof Socket);
        socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        fclose($client);
        unset($socket);
        usleep(50_000);

        for ($i = 0; $i < 20 && $seen === null; $i++) {
            $server->tick(0.01);
        }

        expect($seen)->toBe($name);
    } finally {
        $server->close();
    }
});

it('gives an empty address to a request that no server read, as Go gives RemoteAddr', function (): void {
    expect(new Request('GET', '/', [])->remoteAddress)->toBe('')
        ->and(new RequestParser()->feed("GET / HTTP/1.1\r\nHost: a\r\n\r\n")[0]->remoteAddress)->toBe('')
        ->and(new RequestParser(remoteAddress: '[::1]:2')->feed("GET / HTTP/1.1\r\nHost: a\r\n\r\n")[0]->remoteAddress)->toBe('[::1]:2');
});

it('refuses a tick of seconds that are negative, not finite or above 1e8 with a ValueError', function (float $seconds): void {
    $server = echoServer();

    try {
        expect(static fn () => $server->tick($seconds))->toThrow(ValueError::class, Server::class.'::tick(): Argument #1 ($seconds) must be between 0 and 100000000');
    } finally {
        $server->close();
    }
})->with(['negative' => [-0.001], 'INF' => [INF], 'NAN' => [NAN], 'above 1e8' => [1e8 + 1]]);

it('waits in a tick of the most seconds it takes, and answers what is ready', function (): void {
    $server = echoServer();
    $client = connect($server);

    try {
        // No connection yet, so no timeout shortens the wait: `select()` gets 1e8 seconds, and the
        // listener is ready at once.
        $server->tick(1e8);

        expect($server->streams()[0])->toHaveCount(2)
            ->and(converse($server, $client, "GET /ready HTTP/1.1\r\nHost: a\r\n\r\n"))->toContain('"path":"\/ready"');
    } finally {
        fclose($client);
        $server->close();
    }
});

it('stops to listen and closes its clients once the caller drops it without close()', function (): void {
    $server = echoServer();
    $address = $server->address();
    $client = connect($server);
    $server->tick(0.01);
    unset($server);
    // The read waits for the end, at most a second.
    stream_set_blocking($client, true);
    stream_set_timeout($client, 1);

    try {
        expect(withoutWarnings(static fn (): mixed => stream_socket_client('tcp://'.$address, timeout: 1)))->toBeFalse()
            ->and(fread($client, 1))->toBe('')
            ->and(feof($client))->toBeTrue();
    } finally {
        fclose($client);
    }
});

it('answers fifty clients that all sent before the first tick', function (): void {
    $server = echoServer();
    $clients = [];

    try {
        for ($i = 0; $i < 50; $i++) {
            $clients[$i] = connect($server);
            fwrite($clients[$i], 'GET /'.$i." HTTP/1.1\r\nHost: a\r\n\r\n");
        }

        $received = array_fill(0, 50, '');
        $start = hrtime(true) / 1e9;

        while (count(array_filter($received, static fn (string $bytes): bool => str_ends_with($bytes, '"body":""}'))) < 50 && hrtime(true) / 1e9 - $start < 5) {
            $server->tick(0.01);

            foreach ($clients as $i => $client) {
                $received[$i] .= (string) fread($client, 1 << 16);
            }
        }

        foreach ($received as $i => $answer) {
            expect($answer)->toContain('"path":"\/'.$i.'"');
        }
    } finally {
        array_map(fclose(...), $clients);
        $server->close();
    }
});

it('keeps the state of each client apart when their pieces come in turns', function (): void {
    $server = echoServer();
    $first = connect($server);
    $second = connect($server);

    try {
        expect(converse($server, $first, "POST /1 HTTP/1.1\r\nHost: a\r\nContent-Le"))->toBe('')
            ->and(converse($server, $second, "POST /2 HTTP/1.1\r\nHost: a\r\nContent-Length: 3\r\n\r\ntw"))->toBe('')
            ->and(converse($server, $first, "ngth: 3\r\n\r\none"))->toContain('"path":"\/1"')->toContain('"body":"one"')
            ->and(converse($server, $second, 'o'))->toContain('"path":"\/2"')->toContain('"body":"two"');
    } finally {
        fclose($first);
        fclose($second);
        $server->close();
    }
});

it('waits in a tick at most the seconds it is given, and not at all for 0', function (): void {
    $server = echoServer();

    try {
        $start = hrtime(true);
        $server->tick(0.2);
        $waited = (hrtime(true) - $start) / 1e6;
        $none = INF;

        // The least of three: a busy machine can leave the process without a CPU in one of them.
        for ($i = 0; $i < 3; $i++) {
            $start = hrtime(true);
            $server->tick(0);
            $none = min($none, (hrtime(true) - $start) / 1e6);
        }

        expect($waited)->toBeGreaterThan(150)->toBeLessThan(2_000)
            ->and($none)->toBeLessThan(100);
    } finally {
        $server->close();
    }
});

it('names the streams to wait on: the listener and each client to read, a client to write only with bytes for it', function (): void {
    $server = new Server(static fn (): Response => new Response(200, [], str_repeat('x', 8 << 20)));

    expect($server->streams())->toHaveCount(2)
        ->and($server->streams()[0])->toHaveCount(1)
        ->and($server->streams()[1])->toBe([]);

    $client = connect($server);

    try {
        $server->tick(0.01);

        expect($server->streams()[0])->toHaveCount(2)
            ->and($server->streams()[1])->toBe([]);

        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");
        $server->tick(0.01);

        expect($server->streams()[1])->toHaveCount(1);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('forgets a client that resets the connection during a large answer, without a warning', function (): void {
    $server = new Server(static fn (): Response => new Response(200, [], str_repeat('x', 16 << 20)));
    $client = connect($server);

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");
        $received = '';
        $start = hrtime(true) / 1e9;

        while ($received === '' && hrtime(true) / 1e9 - $start < 1) {
            $server->tick(0.01);
            $received .= (string) fread($client, 1 << 16);
        }

        expect($received)->toStartWith('HTTP/1.1 200 OK')
            ->and($server->streams()[1])->toHaveCount(1);

        // Unread bytes make the system reset the connection.
        fclose($client);

        expect(readStreamsOnceForgotten($server))->toBe(1)
            ->and($server->streams()[1])->toBe([])
            ->and(exchange($server, "GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n"))->toStartWith('HTTP/1.1 200 OK');
    } finally {
        $server->close();
    }
});

it('forgets a client that resets the connection while nothing is to be written', function (): void {
    $server = echoServer();
    $client = connect($server);

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");
        $answered = static function () use ($client): bool {
            $read = [$client];
            $none = null;

            return stream_select($read, $none, $none, 0) === 1;
        };

        for ($i = 0; $i < 100 && ! $answered(); $i++) {
            $server->tick(0.01);
        }

        expect($answered())->toBeTrue()
            ->and($server->streams()[1])->toBe([]);

        // The answer is not read: the system resets the connection, and the read of the server fails.
        fclose($client);

        expect(readStreamsOnceForgotten($server))->toBe(1)
            ->and($server->timeout())->toBeNull()
            ->and(exchange($server, "GET /after HTTP/1.1\r\nHost: a\r\n\r\n"))->toContain('"path":"\/after"');
    } finally {
        $server->close();
    }
});

it('answers every pipelined request of a client that read none for a while, once it reads', function (): void {
    $server = new Server(static fn (): Response => new Response(200, [], str_repeat('z', 1 << 16)));
    $client = connect($server);
    $requests = str_repeat("GET / HTTP/1.1\r\nHost: a\r\n\r\n", 500);

    try {
        $sent = 0;

        for ($i = 0; $i < 200; $i++) {
            $sent += (int) fwrite($client, substr($requests, $sent, 8192));
            $server->tick(0.001);
        }

        $received = 0;
        $start = hrtime(true) / 1e9;

        // The client reads now, and gets the answers to all requests.
        while ($received < 500 * (1 << 16) && hrtime(true) / 1e9 - $start < 10) {
            $sent += (int) fwrite($client, substr($requests, $sent, 8192));
            $server->tick(0.001);

            while (($bytes = fread($client, 1 << 20)) !== '' && $bytes !== false) {
                $received += substr_count($bytes, 'z');
            }
        }

        expect($received)->toBe(500 * (1 << 16))
            ->and($sent)->toBe(strlen($requests));
    } finally {
        fclose($client);
        $server->close();
    }
});

it('throws a RuntimeException from a tick that cannot accept, as when the process has no descriptor left, and accepts once one is free', function (): void {
    $server = new Server(static fn (): Response => new Response(200, [], 'ok'));
    $client = connect($server);
    $files = [];

    try {
        $thrown = withFewDescriptors(static function () use ($server, &$files): string {
            takeEachDescriptor($files, static fn (): mixed => fopen('/dev/null', 'r'));

            try {
                $server->tick(0.01);
            } catch (RuntimeException $runtimeException) {
                return $runtimeException->getMessage();
            }

            return '';
        });

        closeEach($files);

        // The text of EMFILE of glibc and macOS, and of musl.
        expect($thrown)->toMatch('/^The server cannot accept a connection: stream_socket_accept\(\): Accept failed: (Too many open files|No file descriptors available)$/')
            ->and(ask($server, connect($server)))->toStartWith("HTTP/1.1 200 OK\r\n")->toEndWith("\r\n\r\nok");
    } finally {
        closeEach([...$files, $client]);
        $server->close();
    }
})->skip(! function_exists('posix_setrlimit'), 'It needs posix_setrlimit().');

it('throws a RuntimeException from a tick of a listener whose descriptor stream_select() cannot wait on', function (): void {
    $thrown = withHighDescriptors(static function (): string {
        $server = new Server(static fn (): Response => new Response(200));

        try {
            $server->tick(0.01);
        } catch (RuntimeException $runtimeException) {
            return $runtimeException->getMessage();
        } finally {
            $server->close();
        }

        return '';
    });

    expect($thrown)->toStartWith('The server cannot wait on its streams: stream_select(): You MUST recompile PHP with a larger value of FD_SETSIZE.');
})->skip(! function_exists('posix_setrlimit'), 'It needs posix_setrlimit().');

it('takes a signal that ends the wait of a tick as a tick without work', function (): void {
    // Without an idle time, the server names no timeout while it waits.
    $server = echoServer(new Limits(idleTimeout: 0));
    $client = connect($server);
    pcntl_signal(SIGALRM, static function (): void {});

    try {
        expect(converse($server, $client, "GET /first HTTP/1.1\r\nHost: a\r\n\r\n"))->toContain('"path":"\/first"');

        pcntl_alarm(1);
        $start = hrtime(true) / 1e9;

        // The signal comes after a second and ends the wait of three. An accept on the listener
        // would find no client and throw.
        expect(static fn () => $server->tick(3.0))->not->toThrow(Throwable::class)
            ->and(hrtime(true) / 1e9 - $start)->toBeLessThan(2.5)
            ->and(count($server->streams()[0]))->toBe(2)
            ->and($server->timeout())->toBeNull()
            ->and(converse($server, $client, "GET /next HTTP/1.1\r\nHost: a\r\n\r\n"))->toContain('"path":"\/next"');
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
        fclose($client);
        $server->close();
    }
})->skip(! function_exists('pcntl_alarm'), 'It needs pcntl.');

it('closes its clients and listener, and a tick after that does nothing', function (): void {
    $server = echoServer();
    $client = connect($server);

    expect(converse($server, $client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n"))->toStartWith('HTTP/1.1 200 OK');

    $server->close();
    $start = hrtime(true) / 1e9;
    // It returns at once: there is nothing to wait for.
    $server->tick(5.0);

    expect(hrtime(true) / 1e9 - $start)->toBeLessThan(2.5)
        ->and($server->streams())->toBe([[], []])
        ->and(converse($server, $client))->toBe("\n<closed>");

    fclose($client);
});

it('closes the connection and throws on a throwable of the parser that is not a fault of the request, as from a bug', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert(is_array($pair));
    [$server, $client] = $pair;
    stream_set_blocking($server, false);
    // The fiber of the parser fails, as a bug in it would.
    $parser = new ReflectionClass(RequestParser::class)->newInstanceWithoutConstructor();
    $buffer = new ReadBuffer;
    $head = new HeadReader($buffer, 1 << 20, 500);
    $body = new BodyReader($buffer);

    foreach (['buffer' => $buffer, 'body' => $body, 'reader' => new RequestReader($buffer, $head, $body, '', false), 'fiber' => new Fiber(static fn (): never => throw new LogicException('a bug'))] as $name => $value) {
        new ReflectionProperty(RequestParser::class, $name)->setValue($parser, $value);
    }

    $connection = new Connection($server, $parser, new Deadlines(new Clock, new Limits), '', '');

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");

        expect(static fn (): bool => new Exchange(static fn (): Response => new Response(200), static fn (Throwable $throwable): never => throw $throwable, new Limits)->read($connection))->toThrow(LogicException::class, 'a bug')
            ->and($connection->open())->toBeFalse();
    } finally {
        fclose($client);
    }
});

it('closes the connection and throws on a throwable that leaves the fiber of the handler, as from a bug', function (): void {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert(is_array($pair));
    [$server, $client] = $pair;
    stream_set_blocking($server, false);
    $connection = new Connection($server, new RequestParser(streams: true), new Deadlines(new Clock, new Limits), '', '');
    // The fiber takes each throwable of the handler, so only a bug of the server leaves it.
    $connection->handle(static fn (): never => throw new LogicException('a bug'), new Request('GET', '/', []));

    try {
        fwrite($client, 'G');

        expect(static fn (): bool => new Exchange(static fn (): Response => new Response(200), static fn (Throwable $throwable): never => throw $throwable, new Limits)->read($connection))->toThrow(LogicException::class, 'a bug')
            ->and($connection->open())->toBeFalse();
    } finally {
        fclose($client);
    }
});
