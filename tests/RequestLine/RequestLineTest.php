<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Request;

// What the parser reads from a request line, and what it refuses there, are cases of
// tests/Fixtures/go-net-http/cases.json, which GoNetHttpTest compares with Go. Here are the requests
// a handler makes itself, and what Go has no part in.

it('reads the path and query of a request it did not parse, with the fragment', function (string $target, string $path, string $query): void {
    $request = new Request('GET', $target, []);

    expect([$request->path(), $request->query()])->toBe([$path, $query]);
})->with([
    ['/p?q#f', '/p', 'q#f'],
    ['/p#f?q', '/p#f', 'q'],
    ['/p?q?r', '/p', 'q?r'],
]);
