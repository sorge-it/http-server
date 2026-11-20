<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Internal\RequestParser;

it('names the fault after the requests it could read, and reads nothing more', function (): void {
    $parser = new RequestParser;

    expect($parser->feed("GET /a HTTP/1.1\r\nHost: a\r\n\r\nNOPE\r\n\r\n"))->toHaveCount(1)
        ->and($parser->error())->not->toBeNull()
        ->and($parser->feed("GET /b HTTP/1.1\r\nHost: a\r\n\r\n"))->toBe([]);
});
