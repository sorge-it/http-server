<?php

declare(strict_types=1);

// composer check:go compares go.lock with the Go that `build-info` of the FrankenPHP of
// release.lock names, since the answers come from that Go. It fails at another blob; the test files
// are in go.lock, so the same blobs give the same cases.

use SorgeIt\HttpServer\Tests\FrankenPhp\FrankenPhpReference;
use SorgeIt\HttpServer\Tests\GoNetHttp\GoReference;
use SorgeIt\HttpServer\Tests\Upstream;

require __DIR__.'/../../vendor/autoload.php';

const GO = 'https://github.com/golang/go.git';

$tag = FrankenPhpReference::get()->goVersion();

if ($tag === '') {
    fwrite(STDERR, 'build-info of '.FrankenPhpReference::get()->binary.' names no Go.'.PHP_EOL);

    exit(1);
}

echo 'Go of FrankenPHP: '.$tag.PHP_EOL;
Upstream::output('version', substr($tag, strlen('go')));

exit(Upstream::locks(GoReference::FIXTURE.'/go.lock', GO, $tag) ? 0 : 1);
