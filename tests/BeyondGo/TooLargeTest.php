<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;

// FrankenPHP keeps the connection after the 413 of `request_body`; the server closes it, see the
// deliberate differences in the README.

it('closes the connection after the 413 of maxBody, with Connection: close', function (): void {
    $answer = answers(static fn (Request $request): Response => new Response(200, [], $request->path()), "POST /a HTTP/1.1\r\nHost: a\r\nContent-Length: 11\r\n\r\n01234567890GET /b HTTP/1.1\r\nHost: a\r\n\r\n", new Limits(maxBody: 10));

    expect($answer)->toStartWith("HTTP/1.1 413 Request Entity Too Large\r\n")
        ->and($answer)->toEndWith("\r\nConnection: close\r\n\r\nRequest Entity Too Large\n\n<closed>")
        ->and(substr_count($answer, 'HTTP/1.1 '))->toBe(1);
});
