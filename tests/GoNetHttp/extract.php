<?php

declare(strict_types=1);

// composer go:extract writes cases.json again, with the Go of `composer go:install`: the cases of
// the test files of Go, then the probes it holds.

use SorgeIt\HttpServer\Tests\GoNetHttp\GoReference;

require __DIR__.'/../../vendor/autoload.php';

$fixture = (string) realpath(GoReference::FIXTURE);
GoReference::go(['run', './extract', $fixture.'/go', $fixture.'/cases.json']);
echo 'tests/Fixtures/go-net-http/cases.json is written.'.PHP_EOL;
