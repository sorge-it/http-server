<?php

declare(strict_types=1);

// composer check:caddy compares caddy.lock with the newest release of Caddy, and frankenphp.lock of
// the layer of Caddy with that of the newest release of FrankenPHP. For Caddy, that is the newest tag
// vX.Y.Z, without beta or rc. Another blob fails the check, but does not stop the next comparison.

use SorgeIt\HttpServer\Tests\FrankenPhp\Release;
use SorgeIt\HttpServer\Tests\Upstream;

require __DIR__.'/../../vendor/autoload.php';

const CADDY = 'https://github.com/caddyserver/caddy.git';
const FRANKENPHP = 'https://github.com/php/frankenphp.git';

$caddy = Upstream::newestTag(CADDY, '/^v2\.\d+\.\d+$/');
$frankenphp = Release::newest();
echo 'Newest Caddy: '.$caddy.'. Newest FrankenPHP: '.$frankenphp.'.'.PHP_EOL;
$same = Upstream::locks(__DIR__.'/../Fixtures/caddy/caddy.lock', CADDY, $caddy);
$same = Upstream::locks(__DIR__.'/../Fixtures/caddy/frankenphp.lock', FRANKENPHP, $frankenphp) && $same;

exit($same ? 0 : 1);
