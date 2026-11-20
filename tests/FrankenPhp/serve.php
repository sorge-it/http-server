<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Server;
use SorgeIt\HttpServer\Tests\FrankenPhp\Cases;

// The server with the handler of the cases and the php.ini of its command line, for a case whose
// expose_php differs from that of the test process: only a php.ini sets it. It ends with its input.
// Each error goes out after the address, one a line, as `failed()` gives it.

require dirname(__DIR__, 2).'/vendor/autoload.php';

$server = new Server(Cases::handler(...), onError: static function (Throwable $throwable): void {
    echo $throwable::class, ': ', $throwable->getMessage(), PHP_EOL;
});
echo $server->address(), PHP_EOL;
stream_set_blocking(STDIN, false);

while (! feof(STDIN)) {
    fread(STDIN, 1024);
    $server->tick(0.01);
}

$server->close();
