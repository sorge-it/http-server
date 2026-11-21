<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Tests\FrankenPhp;

use RuntimeException;

/**
 * The binaries of a release of FrankenPHP. `install-frankenphp.php` loads this file without the
 * autoloader, because the Docker jobs install FrankenPHP before `composer install`.
 */
final class Release
{
    public const string LOCK = __DIR__.'/../Fixtures/frankenphp/release.lock';

    // The release has no binary for macOS on x86_64.
    public const array ASSETS = [
        'darwin-arm64' => 'frankenphp-mac-arm64',
        'linux-arm64' => 'frankenphp-linux-aarch64',
        'linux-amd64' => 'frankenphp-linux-x86_64',
    ];

    /**
     * This machine, such as `darwin-arm64`.
     */
    public static function system(): string
    {
        return strtolower(PHP_OS_FAMILY).'-'.match (php_uname('m')) {
            'arm64', 'aarch64' => 'arm64',
            'x86_64', 'amd64' => 'amd64',
            default => php_uname('m'),
        };
    }

    /**
     * The versions of release.lock, such as `FrankenPHP v1.13.6 PHP 8.5.13 Caddy v2.11.9`.
     */
    public static function version(): string
    {
        $lines = array_values(array_filter(file(self::LOCK, FILE_IGNORE_NEW_LINES) ?: [], static fn (string $line): bool => $line !== '' && $line[0] !== '#'));

        return $lines[0] ?? '';
    }

    /**
     * The tag of the release in the versions, such as `v1.13.6`.
     */
    public static function tag(string $version): string
    {
        return explode(' ', $version)[1] ?? '';
    }

    /**
     * The versions that the binary names, as release.lock has them.
     */
    public static function versionOf(string $binary): string
    {
        exec(escapeshellarg($binary).' version 2>&1', $output, $status);

        // Such as `FrankenPHP v1.13.6 PHP 8.5.13 Caddy v2.11.9 h1:…`.
        if ($status !== 0 || preg_match('/^FrankenPHP \S+ PHP \S+ Caddy \S+/', implode("\n", $output), $match) !== 1) {
            throw new RuntimeException($binary.' names no versions: '.implode("\n", $output));
        }

        return $match[0];
    }

    /**
     * Whether gh runs here with a login or a token for github.com, so that it can check an
     * attestation. It asks no server: a token that does not work passes here, and `attest()` then
     * fails.
     */
    public static function canAttest(): bool
    {
        exec('gh auth token --hostname github.com 2>&1', $output, $status);

        return $status === 0;
    }

    /**
     * Checks with gh that the workflow static.yaml of php/frankenphp built the binary on a runner
     * of GitHub.
     */
    public static function attest(string $file, string $asset): void
    {
        // gh finds the attestations by the SHA-256 of the file. The JSON must hold a verified
        // attestation too, so that a run of gh that ends with 0 but writes nothing never passes.
        $command = ['gh', 'attestation', 'verify', $file, '--repo', 'php/frankenphp', '--signer-workflow', 'php/frankenphp/.github/workflows/static.yaml', '--deny-self-hosted-runners', '--format', 'json'];
        exec(implode(' ', array_map(escapeshellarg(...), $command)), $output, $status);
        $verified = json_decode(implode("\n", $output), true);

        if ($status !== 0 || ! is_array($verified) || $verified === []) {
            throw new RuntimeException('gh verified no attestation of '.$asset.', exit code '.$status.'.');
        }
    }

    /**
     * The SHA-256 of each asset of ASSETS in the release, by name, as the API of GitHub gives them.
     * A `GITHUB_TOKEN` raises the limit of 60 calls in an hour, but no call needs it.
     *
     * @return array<string, string>
     */
    public static function digests(string $version): array
    {
        $release = self::api('releases/tags/'.$version);
        $sha256 = [];

        foreach (is_array($release) && is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && is_string($asset['digest'] ?? null) && in_array($asset['name'], self::ASSETS, true)) {
                $sha256[$asset['name']] = substr($asset['digest'], strlen('sha256:'));
            }
        }

        ksort($sha256);

        return $sha256;
    }

    /**
     * The tag of the newest release, such as `v1.13.6`. Not the newest tag: upstream tags a version
     * and publishes its release hours later.
     */
    public static function newest(): string
    {
        $release = self::api('releases/latest');

        return is_array($release) && is_string($release['tag_name'] ?? null) ? $release['tag_name'] : throw new RuntimeException('The API of GitHub names no newest release of FrankenPHP.');
    }

    /**
     * Puts the asset of the release into the file, executable, after a check of its SHA-256.
     */
    public static function download(string $version, string $asset, string $sha256, string $file, string $from): void
    {
        $part = $file.'.part';
        self::load('https://github.com/php/frankenphp/releases/download/'.$version.'/'.$asset, [], $part);
        $found = hash_file('sha256', $part);

        if ($found !== $sha256) {
            unlink($part);

            throw new RuntimeException($asset.' of FrankenPHP '.$version.' has another SHA-256 than '.$from.'. Expected: '.$sha256.'. Found: '.$found.'. Upstream uploads the binaries of its newest release again each night; if this is such a time, try again.');
        }

        chmod($part, 0o755);
        rename($part, $file);
    }

    /**
     * The JSON of the path below the repository of FrankenPHP in the API of GitHub. A
     * `GITHUB_TOKEN` raises the limit of 60 calls in an hour, but no call needs it.
     */
    private static function api(string $path): mixed
    {
        $token = getenv('GITHUB_TOKEN');

        return json_decode(self::load('https://api.github.com/repos/php/frankenphp/'.$path, ['Accept: application/vnd.github+json', 'User-Agent: sorge-it/http-server', ...(is_string($token) && $token !== '' ? ['Authorization: Bearer '.$token] : [])]), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The body of the URL, or with a file, the body in that file.
     *
     * @param  list<string>  $headers
     */
    private static function load(string $url, array $headers, ?string $file = null): string
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('The download of FrankenPHP needs the curl extension of PHP.');
        }

        $target = $file === null ? null : @fopen($file, 'wb');

        if ($target === false) {
            throw new RuntimeException('No file '.$file);
        }

        $download = curl_init($url);
        // No total time: a binary has 200 MB. A stalled download ends after 30 seconds below 1 KB/s.
        curl_setopt_array($download, [CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 30] + (is_resource($target) ? [CURLOPT_FILE => $target] : [CURLOPT_RETURNTRANSFER => true]));
        $body = curl_exec($download);
        $limited = $file === null && preg_grep('/^Authorization:/', $headers) === [] && in_array(curl_getinfo($download, CURLINFO_RESPONSE_CODE), [403, 429], true);
        $error = curl_error($download).($limited ? '. Without a token, the API of GitHub allows 60 calls in an hour: set GITHUB_TOKEN.' : '');

        if (is_resource($target)) {
            fclose($target);
        }

        if ($body === false) {
            if ($file !== null) {
                unlink($file);
            }

            throw new RuntimeException('The download of '.$url.' failed: '.$error);
        }

        return is_string($body) ? $body : '';
    }
}
