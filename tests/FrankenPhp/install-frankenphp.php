<?php

declare(strict_types=1);

// composer frankenphp:install puts the FrankenPHP of release.lock into .frankenphp, for each suite
// that compares with FrankenPHP. It checks the SHA-256 that the API of GitHub gives today, the
// versions that the binary names, and with gh its attestation. VERSION holds these versions.

use SorgeIt\HttpServer\Tests\FrankenPhp\Release;

require_once __DIR__.'/Release.php';

$version = Release::version();
$tag = Release::tag($version);
$system = Release::system();
$directory = dirname(__DIR__, 2).'/.frankenphp';

if (! isset(Release::ASSETS[$system])) {
    fwrite(STDERR, 'FrankenPHP has no binary for '.$system.($system === 'darwin-amd64' ? ' (macOS on x86_64)' : '').', so the suites that compare with FrankenPHP cannot run here. The binaries: '.implode(', ', Release::ASSETS).'.'.PHP_EOL);

    exit(1);
}

$asset = Release::ASSETS[$system];
$installed = @file_get_contents($directory.'/VERSION') === $version && is_executable($directory.'/frankenphp');

if ($installed && is_file($directory.'/ATTESTED')) {
    echo $version.' is in .frankenphp.'.PHP_EOL;

    exit(0);
}

// Upstream rebuilds a release, so release.lock pins no SHA-256; the attestation shows who built the
// binary of today. Where gh cannot check, as in a Docker image, the binary runs unchecked until an
// install where it can; in CI, a Docker job installs it on the runner, which has gh.
$canAttest = Release::canAttest();

if ($installed && ! $canAttest) {
    echo $version.' is in .frankenphp, without a check of its attestation: no gh with a login or a token.'.PHP_EOL;

    exit(0);
}

if (! is_dir($directory) && ! mkdir($directory, 0o755, true)) {
    fwrite(STDERR, 'No directory '.$directory.PHP_EOL);

    exit(1);
}

try {
    if (! $installed) {
        // A VERSION or ATTESTED without its binary would name a binary that no check passed.
        @unlink($directory.'/VERSION');
        @unlink($directory.'/ATTESTED');
        echo 'Download FrankenPHP '.$tag.' for '.$system.PHP_EOL;
        $sha256 = Release::digests($tag)[$asset] ?? throw new RuntimeException('FrankenPHP '.$tag.' has no '.$asset.'.');
        Release::download($tag, $asset, $sha256, $directory.'/frankenphp', 'the API of GitHub');
    }

    // The check comes before the binary runs for its versions.
    if ($canAttest) {
        try {
            Release::attest($directory.'/frankenphp', $asset);
        } catch (RuntimeException $exception) {
            @unlink($directory.'/VERSION');
            unlink($directory.'/frankenphp');

            throw $exception;
        }

        touch($directory.'/ATTESTED');
        echo 'gh verified the attestation of '.$asset.'.'.PHP_EOL;
    } else {
        echo 'No gh with a login or a token: the attestation is not checked.'.PHP_EOL;
    }

    if (! $installed) {
        $found = Release::versionOf($directory.'/frankenphp');

        if ($found !== $version) {
            @unlink($directory.'/ATTESTED');
            unlink($directory.'/frankenphp');

            throw new RuntimeException('The binary of FrankenPHP '.$tag.' names '.$found.', release.lock '.$version.'. Upstream built it again with other versions: run composer frankenphp:lock.');
        }
    }
} catch (RuntimeException|JsonException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);

    exit(1);
}

file_put_contents($directory.'/VERSION', $version);
echo $version.' is in .frankenphp.'.PHP_EOL;
