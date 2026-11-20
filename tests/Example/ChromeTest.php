<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;

// Not in the README: the CI jobs on Ubuntu and macOS set CHROME to the Chrome of the runner, and
// the Docker jobs have no Chrome.
beforeEach(function (): void {
    if (getenv('CHROME') === false) {
        $this->markTestSkipped('CHROME names no Chrome');
    }
});

it("shows Chrome a user that exists only in the test's transaction", function (): void {
    $db = new PDO('sqlite::memory:');
    $db->exec('CREATE TABLE users (name TEXT)');
    $db->beginTransaction(); // like Laravel's RefreshDatabase: never committed
    $db->exec("INSERT INTO users (name) VALUES ('Ada')");

    $server = new Server(static function (Request $request) use ($db): Response {
        if ($request->path() !== '/users') {
            return new Response(404); // answer every path: a browser may also ask for /favicon.ico
        }

        $names = $db->query('SELECT name FROM users')->fetchAll(PDO::FETCH_COLUMN);

        return new Response(200, ['Content-Type' => 'text/html'], '<h1>'.implode(', ', $names).'</h1>');
    }, onError: static fn (Throwable $e): never => throw $e); // a handler exception fails the test immediately
    $chrome = null;

    try {
        // Chrome loads the page in its own process; the test serves the page while it waits.
        $chrome = proc_open(
            [getenv('CHROME') ?: throw new RuntimeException('Set CHROME to the path of Chrome'), '--headless', ...(PHP_OS_FAMILY === 'Linux' ? ['--no-sandbox'] : []), '--dump-dom', $server->url().'/users'],
            [1 => ['pipe', 'w'], 2 => STDERR],
            $pipes,
        ) ?: throw new RuntimeException('Chrome did not start');
        stream_set_blocking($pipes[1], false);
        $dom = '';
        $deadline = hrtime(true) / 1e9 + 30; // the first start of Chrome on a new machine is slow

        // Chrome prints the DOM once the page has loaded; its exit can take longer.
        while (! str_contains($dom, '</html>') && hrtime(true) / 1e9 < $deadline) {
            $server->tick(0.01);
            $dom .= fread($pipes[1], 8192);
        }

        if (! str_contains($dom, '</html>')) {
            throw new RuntimeException('Chrome printed no page within 30 s');
        }
    } finally {
        if (is_resource($chrome)) {
            proc_terminate($chrome); // ends Chrome, which may still be exiting
            proc_close($chrome);
        }

        $server->close();
    }

    expect($dom)->toContain('<h1>Ada</h1>');
});
