<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Internal\RequestParser;
use SorgeIt\HttpServer\Request;

it('reads a request that comes one byte at a time', function (): void {
    $answer = echoed(str_split("POST /p HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nhello"));

    expect($answer)->toContain('"method":"POST"')->toContain('"body":"hello"');
});

it('knows when part of a request waits for more bytes', function (): void {
    $parser = new RequestParser;

    expect($parser->pending())->toBeFalse()
        ->and($parser->feed("POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 4\r\n\r\nab"))->toBe([])
        ->and($parser->pending())->toBeTrue()
        ->and($parser->feed('cd'))->toHaveCount(1)
        ->and($parser->pending())->toBeFalse();
});

it('takes the bytes after a body as the start of the next request', function (): void {
    $parser = new RequestParser;

    expect(array_map(static fn (Request $read): string => (string) $read->body, $parser->feed("POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 3\r\n\r\nabcd")))->toBe(['abc'])
        ->and($parser->pending())->toBeTrue()
        ->and($parser->feed("\r\n\r\n"))->toBe([])
        ->and($parser->error())->not->toBeNull();
});

it('names the offset in the bytes of the client after the last line or part of a body it read before a fault, whole and one byte at a time', function (string $bytes, int $offset): void {
    foreach ([0, 1] as $piece) {
        $parser = new RequestParser(maxHead: 100);
        parse($bytes, $piece, $parser);

        expect($parser->error()?->offset)->toBe($offset);
    }
})->with([
    'a request line' => ["NOPE\r\n\r\n", 6],
    'a field line' => ["GET / HTTP/1.1\r\nHost: a\r\nX y\r\n\r\n", 30],
    'the request after a body' => ["POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 8140\r\n\r\n".str_repeat('x', 8140)."NOPE\r\n\r\n", 50 + 8140 + 6],
    'a field line over maxHead' => ["GET / HTTP/1.1\r\nX: ".str_repeat('a', 200), 16],
]);

it('forgets a client that leaves before its request line ends', function (): void {
    $server = echoServer();

    try {
        expect(exchange($server, 'GET /'))->toBe('')
            ->and(readStreamsOnceForgotten($server))->toBe(1);
    } finally {
        $server->close();
    }
});
