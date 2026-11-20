<?php

declare(strict_types=1);

// composer check:frankenphp compares frankenphp.lock, release.lock and php-src.lock with the newest
// FrankenPHP, a tag vX.Y.Z. A difference does not stop the next comparison. Its binary for this
// machine stays in .frankenphp/newest; the output `binary` names it for a workflow.

use SorgeIt\HttpServer\Tests\FrankenPhp\Release;
use SorgeIt\HttpServer\Tests\Upstream;

require __DIR__.'/../../vendor/autoload.php';

const FRANKENPHP = 'https://github.com/php/frankenphp.git';

$tag = Upstream::newestTag(FRANKENPHP, '/^v1\.\d+\.\d+$/');
echo 'Newest FrankenPHP: '.$tag.PHP_EOL;
$same = Upstream::locks(__DIR__.'/../Fixtures/frankenphp/frankenphp.lock', FRANKENPHP, $tag);

$asset = Release::ASSETS[Release::system()] ?? throw new RuntimeException('FrankenPHP has no binary for '.Release::system().', so the versions of '.$tag.' are not known here.');
$sha256 = Release::digests($tag)[$asset] ?? throw new RuntimeException('FrankenPHP '.$tag.' has no '.$asset.'.');
$directory = dirname(__DIR__, 2).'/.frankenphp/newest';

if (@file_get_contents($directory.'/VERSION') !== $tag.' '.$sha256 || ! is_executable($directory.'/frankenphp')) {
    if (! is_dir($directory) && ! mkdir($directory, 0o755, true)) {
        throw new RuntimeException('No directory '.$directory);
    }

    Release::download($tag, $asset, $sha256, $directory.'/frankenphp', 'the API of GitHub');
    file_put_contents($directory.'/VERSION', $tag.' '.$sha256);
}

Upstream::output('binary', $directory.'/frankenphp');
$version = Release::versionOf($directory.'/frankenphp');
$same = Upstream::same('frankenphp/release.lock', Upstream::lines(Release::LOCK), [$version], $tag) && $same;
$php = explode(' ', $version)[3];
echo 'PHP of FrankenPHP '.$tag.': '.$php.PHP_EOL;
$same = Upstream::locks(__DIR__.'/../Fixtures/frankenphp/php-src.lock', 'https://github.com/php/php-src.git', 'php-'.$php) && $same;

exit($same ? 0 : 1);
