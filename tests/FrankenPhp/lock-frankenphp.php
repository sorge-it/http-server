<?php

declare(strict_types=1);

// composer frankenphp:lock writes the versions of the newest FrankenPHP into release.lock, and only
// new versions. First, gh with a login or a token checks each binary with `gh attestation verify`:
// the workflow static.yaml of php/frankenphp built it on a runner of GitHub.

use SorgeIt\HttpServer\Tests\FrankenPhp\Release;
use SorgeIt\HttpServer\Tests\Upstream;

require __DIR__.'/../../vendor/autoload.php';

$tag = Upstream::newestTag('https://github.com/php/frankenphp.git', '/^v1\.\d+\.\d+$/');
$digests = Release::digests($tag);
$assets = array_values(Release::ASSETS);
sort($assets);

if (array_keys($digests) !== $assets) {
    throw new RuntimeException('FrankenPHP '.$tag.' has not each asset of '.implode(', ', $assets).'.');
}

$local = Release::ASSETS[Release::system()] ?? throw new RuntimeException('FrankenPHP has no binary for '.Release::system().', so its versions are not known here.');
$directory = sys_get_temp_dir().'/http-server-frankenphp-lock-'.bin2hex(random_bytes(6));

if (! mkdir($directory, 0o700)) {
    throw new RuntimeException('No directory '.$directory);
}

try {
    Release::download($tag, $local, $digests[$local], $directory.'/'.$local, 'the API of GitHub');
    $version = Release::versionOf($directory.'/'.$local);

    if ($version !== Release::version()) {
        foreach ($digests as $asset => $sha256) {
            echo 'Check '.$asset.' of FrankenPHP '.$tag.PHP_EOL;

            if ($asset !== $local) {
                Release::download($tag, $asset, $sha256, $directory.'/'.$asset, 'the API of GitHub');
            }

            Release::attest($directory.'/'.$asset, $asset);
        }
    }
} finally {
    Upstream::run(['rm', '-rf', $directory]);
}

if ($version === Release::version()) {
    echo 'release.lock has the newest FrankenPHP: '.$version.'.'.PHP_EOL;

    exit(0);
}

$comments = array_filter(file(Release::LOCK) ?: [], static fn (string $line): bool => str_starts_with($line, '#'));
file_put_contents(Release::LOCK, implode('', $comments).$version.PHP_EOL);
echo 'release.lock has '.$version.', each binary with its attestation. Update frankenphp.lock, php-src.lock and the pins.'.PHP_EOL;
