<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;

// Go has no string form of a body: a handler of Go calls `io.ReadAll()`. The cast reads as that
// call does, so the bytes on the wire are those of Go, which GoNetHttpTest compares.

it('gives the whole body as a string, and the same string to each later cast', function (): void {
    $answer = answer(static function (Request $request): Response {
        $first = (string) $request->body;

        return new Response(200, [], $first.'|'.$request->body.'|'.$request->body->read(5));
    }, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nhello");

    expect($answer)->toEndWith("\r\n\r\nhello|hello|");
});

it('gives the rest of the body as a string after the handler read a part', function (): void {
    $answer = answer(static function (Request $request): Response {
        $part = $request->body->read(2);

        return new Response(200, [], $part.'|'.$request->body);
    }, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 5\r\n\r\nhello");

    expect($answer)->toEndWith("\r\n\r\nhe|llo");
});

it('gives the part of the body before its error as a string, and the error', function (): void {
    $read = null;
    answers(static function (Request $request) use (&$read): Response {
        $read = $request->body.'|'.$request->body->error();

        return new Response(200);
    }, "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 10\r\n\r\nhello");

    expect($read)->toBe('hello|unexpected EOF');
});

it('holds the whole body for the string form', function (): void {
    $server = new Server(static fn (Request $request): Response => new Response(200, [], (string) strlen((string) $request->body)));
    $client = connect($server);
    $pieces = ["POST / HTTP/1.1\r\nHost: a\r\nContent-Length: ".(32 << 20)."\r\n\r\n", ...array_fill(0, 32, str_repeat('x', 1 << 20))];
    memory_reset_peak_usage();
    $before = memory_get_usage();

    try {
        expect(converse($server, $client, $pieces))->toEndWith("\r\n\r\n".(32 << 20))
            ->and(memory_get_peak_usage() - $before)->toBeGreaterThan(32 << 20);
    } finally {
        fclose($client);
        $server->close();
    }
});
