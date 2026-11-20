<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;

require __DIR__.'/test.php';

// The test process serves the page: it ticks the server while it waits for Chromium.
$server = new Server(static fn (): Response => new Response(200, ['Content-Type' => 'text/html'], page($db)));
echo browse($server->url().'/', static fn () => $server->tick(0.01));
$server->close();
