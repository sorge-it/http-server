<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;

/**
 * A file below `.tmp/tests` of the given megabytes, each of one letter, A first.
 */
function largeFile(int $megabytes): string
{
    $file = temporaryFile();
    $handle = fopen($file, 'wb');
    assert(is_resource($handle));

    for ($i = 0; $i < $megabytes; $i++) {
        fwrite($handle, str_repeat(chr(65 + $i % 26), 1 << 20));
    }

    fclose($handle);

    return $file;
}

/**
 * A file with the content in a new directory below `.tmp/tests` that the process may not write,
 * so it may not remove the file; both are removed after the run.
 */
function lockedFile(string $content): string
{
    $directory = __DIR__.'/../../.tmp/tests/locked-'.bin2hex(random_bytes(4));
    mkdir($directory, 0o755, true);
    file_put_contents($directory.'/file', $content);
    chmod($directory, 0o555);
    register_shutdown_function(static function () use ($directory): void {
        chmod($directory, 0o755);

        if (is_file($directory.'/file')) {
            unlink($directory.'/file');
        }

        rmdir($directory);
    });

    return $directory.'/file';
}

// The bytes are random and go in three parts of at most 64 KB. In a file of one letter, a test
// cannot find bytes in a wrong order.
it('sends each byte of a file of random bytes in its order, with and without chunks', function (bool $chunked): void {
    $content = random_bytes((2 << 16) + 7);
    $answer = answers(static fn (): Response => new Response(200, $chunked ? ['Transfer-Encoding' => 'chunked'] : [], file: temporaryFile($content)), "GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n");
    $head = substr($answer, 0, (int) strpos($answer, "\r\n\r\n") + 2);
    $body = $chunked ? dechex(strlen($content))."\r\n".$content."\r\n0\r\n\r\n" : $content;

    expect(visible($answer))->toBe($head."\r\n".$body."\n<closed>")
        ->and($head)->toStartWith("HTTP/1.1 200 OK\r\n")
        ->and($head)->toContain($chunked ? "\r\nTransfer-Encoding: chunked\r\n" : "\r\nContent-Length: ".strlen($content)."\r\n")
        ->and(substr_count($head, "\r\nContent-Length: "))->toBe($chunked ? 0 : 1)
        ->and(substr_count($head, "\r\nTransfer-Encoding: "))->toBe($chunked ? 1 : 0);
})->with([
    'with a length' => [false],
    'in chunks' => [true],
]);

// After the 1xx, FrankenPHP drops the headers of the script, its length too: Go then knows the
// length only for a file within its buffer.
it('sends a file after a 1xx as FrankenPHP, with the length of Go up to 2048 bytes and in chunks after that', function (int $size): void {
    $answer = answers(static fn (): Response => new Response(103, ['Link' => '</a>'], file: temporaryFile(str_repeat('z', $size))), "GET / HTTP/1.1\r\nHost: a\r\n\r\n");

    expect(visible($answer))->toBe(visible(modelAnswers('GET /respond?status=103&h=Link=%3C%2Fa%3E&ffill='.$size." HTTP/1.1\r\nHost: a\r\n\r\n")));
})->with(['2048 bytes' => [2048], '2049 bytes' => [2049]]);

it('sends a file in pieces, so it never lies in memory whole', function (): void {
    $file = largeFile(32);
    $server = new Server(static fn (): Response => new Response(200, ['Content-Type' => 'text/plain'], file: $file));
    $client = connect($server);

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n");
        $received = 0;
        $tail = '';
        $start = hrtime(true) / 1e9;
        $before = memory_get_usage();
        memory_reset_peak_usage();

        while (! feof($client) && hrtime(true) / 1e9 - $start < 10) {
            $server->tick(0.01);

            // Counted and dropped: kept, the bytes would fill the memory this test measures.
            while (($bytes = fread($client, 1 << 20)) !== '' && $bytes !== false) {
                $received += strlen($bytes);
                $tail = substr($tail.$bytes, -3);
            }
        }

        expect($received)->toBeGreaterThan(32 << 20)
            ->and($tail)->toBe(str_repeat('F', 3))
            ->and(memory_get_peak_usage() - $before)->toBeLessThan(4 << 20);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('names the length of a file for HEAD, and answers the 500 of a handler that throws for a file it cannot read', function (): void {
    $errors = [];
    $server = new Server(static fn (Request $request): Response => new Response(200, [], file: $request->path() === '/missing' ? __DIR__.'/missing' : __FILE__), onError: static function (Throwable $throwable) use (&$errors): void {
        $errors[] = $throwable::class.': '.$throwable->getMessage();
    });

    try {
        // The connection stays open after the 500, so the client sends no end.
        expect(exchange($server, "HEAD / HTTP/1.1\r\nHost: a\r\n\r\n"))->toContain('Content-Length: '.filesize(__FILE__)."\r\n")->toEndWith("\r\n\r\n")
            ->and(samePhp(withDisplayErrors('0', static fn (): string => exchange($server, "GET /missing HTTP/1.1\r\nHost: a\r\n\r\n")))."\n<closed>")->toBe(thrownAnswer())
            ->and($errors)->toBe(['UnexpectedValueException: The file '.__DIR__.'/missing could not be read.']);
    } finally {
        $server->close();
    }
});

it('answers the 500 of a handler that throws for a directory as a file', function (): void {
    [$answer, $errors] = withDisplayErrors('0', static fn (): array => failedAnswers(static fn (): Response => new Response(200, [], file: __DIR__), "GET / HTTP/1.1\r\nHost: a\r\n\r\n"));

    expect($answer)->toBe(thrownAnswer())
        ->and($errors)->toBe(['UnexpectedValueException: The file '.__DIR__.' could not be read.']);
});

it('sends a file whose last piece is one byte, and serves the next request', function (): void {
    $file = temporaryFile(str_repeat('x', (64 << 10) + 1));
    $server = new Server(static fn (Request $request): Response => $request->path() === '/file' ? new Response(200, [], file: $file) : new Response(200, [], 'next'));

    try {
        $answer = exchange($server, "GET /file HTTP/1.1\r\nHost: a\r\n\r\nGET /next HTTP/1.1\r\nHost: a\r\n\r\n");

        expect($answer)->toContain("\r\n\r\n".str_repeat('x', (64 << 10) + 1).'HTTP/1.1 200 OK')->toEndWith("\r\n\r\nnext");
    } finally {
        $server->close();
    }
});

it('sends the length it named of a file that changes while it is sent', function (string $change): void {
    $file = largeFile(16);
    // Not a multiple of the pieces it is read in, so a last piece could take bytes that came later.
    file_put_contents($file, str_repeat('a', 100), FILE_APPEND);
    $server = new Server(static fn (Request $request): Response => $request->path() === '/file' ? new Response(200, [], file: $file) : new Response(200, [], 'next'));
    $client = connect($server);

    try {
        fwrite($client, "GET /file HTTP/1.1\r\nHost: a\r\n\r\nGET /next HTTP/1.1\r\nHost: a\r\n\r\n");

        // The client reads nothing yet: the server sends what the socket takes, then waits.
        for ($i = 0; $i < 20; $i++) {
            $server->tick(0.005);
        }

        $change === 'grows' ? file_put_contents($file, str_repeat('b', 1000), FILE_APPEND) : truncate($file, 1 << 20);
        $received = '';
        $start = hrtime(true) / 1e9;

        while (! feof($client) && hrtime(true) / 1e9 - $start < 5 && ! str_ends_with($received, 'next')) {
            $server->tick(0.01);

            while (($bytes = fread($client, 1 << 20)) !== '' && $bytes !== false) {
                $received .= $bytes;
            }
        }

        $change === 'grows'
            ? expect($received)->not->toContain('b')->toEndWith("\r\n\r\nnext")
            : expect(feof($client))->toBeTrue()->and(strlen($received))->toBeLessThan(16 << 20);
    } finally {
        fclose($client);
        $server->close();
    }
})->with(['grows', 'shrinks']);

it('gives onError the errors of a pipelined request that waits for its body when a file that shrinks ends the connection', function (): void {
    $file = largeFile(16);
    $errors = [];
    $server = new Server(static function (Request $request) use ($file): Response {
        if ($request->path() === '/file') {
            return new Response(200, [], file: $file);
        }

        throw new RuntimeException('read '.$request->body);
    }, onError: static function (Throwable $throwable) use (&$errors): void {
        $errors[] = $throwable::class.': '.$throwable->getMessage();
    });
    $client = connect($server);

    try {
        fwrite($client, "GET /file HTTP/1.1\r\nHost: a\r\n\r\nPOST /upload HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\nabc");

        // The client reads nothing yet: the file waits to be sent, the POST waits for its body.
        for ($i = 0; $i < 20; $i++) {
            $server->tick(0.005);
        }

        truncate($file, 1 << 20);
        $start = hrtime(true) / 1e9;

        // A read of a socket gives at most 8 KB, so each tick reads all that came.
        while (! feof($client) && hrtime(true) / 1e9 - $start < 5) {
            $server->tick(0.01);

            while (($bytes = fread($client, 1 << 20)) !== '' && $bytes !== false) {
            }
        }

        expect(feof($client))->toBeTrue()
            ->and($errors)->toBe([
                'LogicException: The server closed before the body was complete, with 3 of 10 bytes read',
                'RuntimeException: read abc',
            ]);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('ends the connection after the head when the file is gone before its turn', function (): void {
    // The head of FrankenPHP for the file as it was, without its bytes. Asked first, because the
    // first question of a process starts FrankenPHP, which takes longer than the test allows.
    $head = strstr(modelAnswers("GET /respond?file=content HTTP/1.1\r\nHost: a\r\n\r\n"), "\r\n\r\n", true)."\r\n\r\n";
    // A large file first: its bytes are read as they are sent, so both answers are queued at once.
    $large = largeFile(8);
    $file = temporaryFile('content');
    $server = new Server(static fn (Request $request): Response => new Response(200, [], file: $request->path() === '/large' ? $large : $file));
    $client = connect($server);

    try {
        fwrite($client, "GET /large HTTP/1.1\r\nHost: a\r\n\r\nGET /file HTTP/1.1\r\nHost: a\r\n\r\n");

        for ($i = 0; $i < 20; $i++) {
            $server->tick(0.005);
        }

        unlink($file);
        $start = hrtime(true) / 1e9;
        $answer = converse($server, $client);

        expect(samePhp($answer))->toEndWith($head."\n<closed>")
            ->and(hrtime(true) / 1e9 - $start)->toBeLessThan(2);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('opens a file only when its answer is written', function (): void {
    $server = new Server(static fn (): Response => new Response(200, [], file: __FILE__));
    $client = connect($server);
    $before = count(scandir('/dev/fd') ?: []);

    try {
        fwrite($client, str_repeat("GET / HTTP/1.1\r\nHost: a\r\n\r\n", 300));

        for ($i = 0; $i < 20; $i++) {
            $server->tick(0.005);
        }

        expect(count(scandir('/dev/fd') ?: []) - $before)->toBeLessThan(10);
    } finally {
        fclose($client);
        $server->close();
    }
});

it('sends two files of pipelined requests whole and in order to a client that reads slowly', function (): void {
    $first = largeFile(2);
    $second = temporaryFile(str_repeat('z', 3 << 20));
    $server = new Server(static fn (Request $request): Response => new Response(200, [], file: $request->path() === '/1' ? $first : $second));
    $client = connect($server);

    try {
        fwrite($client, "GET /1 HTTP/1.1\r\nHost: a\r\n\r\nGET /2 HTTP/1.1\r\nHost: a\r\nConnection: close\r\n\r\n");
        $received = '';
        $start = hrtime(true) / 1e9;

        while (! feof($client) && hrtime(true) / 1e9 - $start < 10) {
            $server->tick(0.002);
            $received .= (string) fread($client, 1 << 16);
        }

        [, $one, $two] = preg_split('/HTTP\/1\.1 200 OK\r\n.*?\r\n\r\n/s', $received) ?: [];

        expect($one)->toBe(str_repeat('A', 1 << 20).str_repeat('B', 1 << 20))
            ->and($two)->toBe(str_repeat('z', 3 << 20));
    } finally {
        fclose($client);
        $server->close();
    }
});

it('keeps the connection open after a file of many pieces, removes the file, and answers the next request', function (): void {
    // Five pieces of 64 KB and one of 3 bytes.
    $file = temporaryFile(str_repeat('a', 5 << 16).'end');
    $server = new Server(static fn (Request $request): Response => $request->path() === '/file' ? new Response(200, [], file: $file, deleteFile: true) : new Response(200, [], 'next'));

    try {
        $answer = exchange($server, "GET /file HTTP/1.1\r\nHost: a\r\n\r\nGET /next HTTP/1.1\r\nHost: a\r\n\r\n");

        expect(substr_count($answer, 'HTTP/1.1 200 OK'))->toBe(2)
            ->and($answer)->toContain("\r\n\r\n".str_repeat('a', 5 << 16)."endHTTP/1.1 200 OK\r\n")->toEndWith("\r\n\r\nnext")
            ->and(is_file($file))->toBeFalse();
    } finally {
        $server->close();
    }
});

it('removes a file made for the response once it is sent, or when nothing of it is sent', function (string $method, int $status): void {
    $file = temporaryFile('content');
    $answer = answer(static fn (): Response => new Response($status, [], file: $file, deleteFile: true), $method." / HTTP/1.1\r\nHost: a\r\n\r\n");

    expect($answer)->toStartWith('HTTP/1.1 '.$status.' ')
        ->and(str_ends_with($answer, 'content'))->toBe($method === 'GET' && $status === 200)
        ->and(is_file($file))->toBeFalse();
})->with([
    'sent' => ['GET', 200],
    'HEAD' => ['HEAD', 200],
    '304' => ['GET', 304],
    '204' => ['GET', 204],
]);

it('removes a file made for the response when the 400 for a body that the client cut takes its place', function (): void {
    $file = temporaryFile('content');
    $answer = answers(static function (Request $request) use ($file): Response {
        $request->body->read(10);

        return new Response(200, [], file: $file, deleteFile: true);
    }, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\nabc");

    expect($answer)->toStartWith('HTTP/1.1 400 Bad Request')
        ->and(is_file($file))->toBeFalse();
});

it('removes a file made for the response that it cannot read, and answers the 500 of a handler that throws', function (): void {
    // Root reads a file of any mode, so as root every file can be read.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Root reads a file of any mode.');
    }

    $file = temporaryFile('content');
    chmod($file, 0);

    // Checked in the listing of the directory: the stream wrapper of Pest's mutation testing takes a
    // file the process cannot read as absent, so `is_file()` would pass also with the file there.
    [$answer, $errors] = withDisplayErrors('0', static fn (): array => failedAnswers(static fn (): Response => new Response(200, [], file: $file, deleteFile: true), "GET / HTTP/1.1\r\nHost: a\r\n\r\n"));

    expect($answer)->toBe(thrownAnswer())
        ->and($errors)->toBe(['UnexpectedValueException: The file '.$file.' could not be read.'])
        ->and(scandir(dirname($file)))->not->toContain(basename($file));
});

it('removes a file made for the response when the client leaves before its end', function (): void {
    $file = largeFile(16);
    $server = new Server(static fn (): Response => new Response(200, [], file: $file, deleteFile: true));
    $client = connect($server);

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");
        $received = 0;
        $start = hrtime(true) / 1e9;

        while ($received < 1 << 20 && hrtime(true) / 1e9 - $start < 5) {
            $server->tick(0.002);
            $received += strlen((string) fread($client, 1 << 16));
        }

        // Unread bytes make the system reset the connection, so the server's next write fails.
        fclose($client);

        while (is_file($file) && hrtime(true) / 1e9 - $start < 5) {
            $server->tick(0.01);
        }

        expect(is_file($file))->toBeFalse()
            ->and(readStreamsOnceForgotten($server))->toBe(1);
    } finally {
        $server->close();
    }
});

it('removes a file made for the response when the server closes before it is sent', function (): void {
    $file = largeFile(16);
    $server = new Server(static fn (): Response => new Response(200, [], file: $file, deleteFile: true));
    $client = connect($server);

    try {
        fwrite($client, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");

        for ($i = 0; $i < 5; $i++) {
            $server->tick(0.005);
        }

        expect(is_file($file))->toBeTrue();

        $server->close();

        expect(is_file($file))->toBeFalse();
    } finally {
        fclose($client);
    }
});

it('keeps a file made for the response that it may not remove once it is sent, where a warning throws', function (): void {
    $file = lockedFile('content');

    $answer = whereWarningsThrow(static fn (): string => answer(static fn (Request $request): Response => $request->path() === '/file'
        ? new Response(200, [], file: $file, deleteFile: true)
        : new Response(200, [], 'ok'), "GET /file HTTP/1.1\r\nHost: a\r\n\r\nGET / HTTP/1.1\r\nHost: a\r\n\r\n"));

    expect($answer)->toContain("\r\n\r\ncontent")
        ->toEndWith("\r\n\r\nok")
        ->and(is_file($file))->toBeTrue();
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root may remove the file.');

it('serves a new client after one left a file that the server may not remove, where a warning throws', function (): void {
    // More than the buffers of a loopback socket take, so the server drops the connection with
    // the file still to be sent.
    $file = lockedFile(str_repeat('a', 32 << 20));
    $server = new Server(static fn (Request $request): Response => $request->path() === '/file'
        ? new Response(200, [], file: $file, deleteFile: true)
        : new Response(200, [], 'ok'));
    $client = connect($server);

    try {
        $answer = whereWarningsThrow(static function () use ($server, $client): string {
            fwrite($client, "GET /file HTTP/1.1\r\nHost: a\r\n\r\n");

            for ($i = 0; $i < 5; $i++) {
                $server->tick(0.005);
            }

            // Unread bytes make the system reset the connection, so the server's next write fails.
            fclose($client);
            readStreamsOnceForgotten($server);

            return exchange($server, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");
        });

        expect($answer)->toEndWith("\r\n\r\nok")
            ->and(is_file($file))->toBeTrue();
    } finally {
        $server->close();
    }
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root may remove the file.');
