<?php

declare(strict_types=1);

require __DIR__.'/test.php';

// A second process serves the page.
$server = proc_open('exec php -S 127.0.0.1:8080 router.php > /dev/null 2>&1', [], $pipes);
echo browse('http://127.0.0.1:8080/', static fn () => usleep(10_000));
proc_terminate($server);
proc_close($server);
