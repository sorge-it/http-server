<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;

// PHP sets back each ini of a script at the end of its request. The handler runs in the test
// process, so the server sets back only the ini that its rules read, and the rest stays.

it('keeps an ini that a handler set and no rule reads, for the next request and the test', function (): void {
    $before = ini_get('precision');

    try {
        $answers = answers(static function (Request $request): Response {
            if ($request->path() === '/set') {
                ini_set('precision', '3');
            }

            return new Response(200, [], (string) (1 / 3));
        }, "GET /set HTTP/1.1\r\nHost: a\r\n\r\nGET / HTTP/1.1\r\nHost: a\r\n\r\n");

        // Both answers have the precision of the handler; FrankenPHP gives the second 14 digits.
        expect(substr_count($answers, "\r\nContent-Length: 5\r\n\r\n0.333"))->toBe(2)
            ->and(ini_get('precision'))->toBe('3');
    } finally {
        ini_set('precision', $before);
    }
});

it('prints what a handler echoes and dumps into the output of the test, and sends the body it returned', function (): void {
    // Xdebug writes the file and line before the dump.
    $this->expectOutputRegex('/\Adebug\n.*string\(2\) "ok"\n\z/s');

    $answers = answers(static function (): Response {
        echo "debug\n";
        var_dump('ok');

        return new Response(200, [], 'body');
    }, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");

    expect($answers)->toContain("\r\nContent-Length: 4\r\n\r\nbody")
        ->and($answers)->not->toContain('debug');
});

it('logs what a handler passes to error_log() where the test process logs', function (): void {
    $log = temporaryFile();
    $before = (string) ini_get('error_log');
    ini_set('error_log', $log);

    try {
        answers(static function (): Response {
            error_log('debug of the handler');

            return new Response(200);
        }, "GET / HTTP/1.1\r\nHost: a\r\n\r\n");

        expect((string) file_get_contents($log))->toContain('debug of the handler');
    } finally {
        ini_set('error_log', $before);
    }
});
