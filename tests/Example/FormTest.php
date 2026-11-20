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

it('receives the form that Chrome submits', function (): void {
    $posted = null;

    $server = new Server(static function (Request $request) use (&$posted): Response {
        if ($request->method === 'GET' && $request->path() === '/form') {
            // The script submits the form, as a click on Save would.
            return new Response(200, ['Content-Type' => 'text/html'], '<form method="post" action="/users"><input name="name" value="Ada"><button>Save</button></form><script>document.forms[0].requestSubmit()</script>');
        }

        if ($request->method === 'POST' && $request->path() === '/users') {
            parse_str((string) $request->body, $posted); // read the body here: after the handler, it is closed

            return new Response(303, ['Location' => '/saved']);
        }

        if ($request->method === 'GET' && $request->path() === '/saved') {
            return new Response(200, ['Content-Type' => 'text/html'], '<h1>Saved</h1>');
        }

        return new Response(404);
    }, onError: static fn (Throwable $e): never => throw $e);
    $chrome = null;

    try {
        $chrome = proc_open(
            [getenv('CHROME') ?: throw new RuntimeException('Set CHROME to the path of Chrome'), '--headless', ...(PHP_OS_FAMILY === 'Linux' ? ['--no-sandbox'] : []), '--dump-dom', $server->url().'/form'],
            [1 => ['file', '/dev/null', 'w'], 2 => STDERR],
            $pipes,
        ) ?: throw new RuntimeException('Chrome did not start');
        $deadline = hrtime(true) / 1e9 + 30; // the first start of Chrome on a new machine is slow

        // Ticks until the handler has the form. The handler matches by method and path, because a
        // browser sends its requests in no fixed order.
        while ($posted === null && hrtime(true) / 1e9 < $deadline) {
            $server->tick(0.01);
        }
    } finally {
        if (is_resource($chrome)) {
            proc_terminate($chrome); // end Chrome first: after close(), its next request would find no server
            proc_close($chrome);
        }

        $server->close();
    }

    expect($posted)->toBe(['name' => 'Ada']);
});
