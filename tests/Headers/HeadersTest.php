<?php

declare(strict_types=1);

// What the parser reads from field lines, and what it refuses there, are cases of
// tests/Fixtures/go-net-http/cases.json, which GoNetHttpTest compares with Go. Here is only how
// `Request` gives the headers, which has no part in Go.

it('joins the lines of a header by a comma', function (): void {
    expect(requests("GET / HTTP/1.1\r\nHost: a\r\nX: a\r\nX: b\r\n\r\n")[0]->header('X'))->toBe('a, b');
});

it('finds a header by its name in any case', function (): void {
    [$request] = requests("GET / HTTP/1.1\r\nhOsT: a\r\nX-PROBE: v\r\n\r\n");

    expect($request->header('host'))->toBe('a')
        ->and($request->header('X-Probe'))->toBe('v')
        ->and($request->headers)->toBe(['host' => ['a'], 'x-probe' => ['v']]);
});
