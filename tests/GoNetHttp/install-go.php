<?php

declare(strict_types=1);

// composer go:install puts the Go of go.mod into .go/go, for GoNetHttpTest. The checksums are those
// of https://go.dev/dl/?mode=json&include=all; a new Go in go.mod needs its four here.

const SHA256 = [
    'go1.27.4' => [
        'darwin-amd64' => '2b799377704ef12db39bd254995d5c348c9cc8d86562ca817724390e56c38f43',
        'darwin-arm64' => '91a44a2b8a716600ad94a4f143e156116620db8828086a6e3602291be9bd0bb4',
        'linux-amd64' => '7226736c2339719cda28fbf3a54d71aa63bcefe376e98cef00034268dab24840',
        'linux-arm64' => '3392582988a273a87ab5043ee1b2c9c17a2c3c00580bf9326c47a2e0aaaeac92',
    ],
];

$go = preg_match('/^go (\S+)$/m', (string) file_get_contents(__DIR__.'/go.mod'), $match) === 1 ? 'go'.$match[1] : '';
$directory = dirname(__DIR__, 2).'/.go';
$installed = @file_get_contents($directory.'/go/VERSION');

if (is_string($installed) && strtok($installed, "\n") === $go) {
    echo $go.' is in .go/go.'.PHP_EOL;

    exit(0);
}

$system = strtolower(PHP_OS_FAMILY).'-'.match (php_uname('m')) {
    'arm64', 'aarch64' => 'arm64',
    'x86_64', 'amd64' => 'amd64',
    default => php_uname('m'),
};
$sha256 = SHA256[$go][$system] ?? null;

if ($sha256 === null) {
    fwrite(STDERR, 'No checksum of '.$go.' for '.$system.' in tests/GoNetHttp/install-go.php: install that Go and put it on the PATH.'.PHP_EOL);

    exit(1);
}

if (! extension_loaded('curl')) {
    fwrite(STDERR, 'composer go:install needs the curl extension of PHP.'.PHP_EOL);

    exit(1);
}

if (! is_dir($directory) && ! mkdir($directory, 0o755, true)) {
    fwrite(STDERR, 'No directory '.$directory.PHP_EOL);

    exit(1);
}

$archive = $directory.'/'.$go.'.'.$system.'.tar.gz';
$file = fopen($archive, 'wb');
assert(is_resource($file));
$download = curl_init('https://go.dev/dl/'.$go.'.'.$system.'.tar.gz');
// No total time: the archive has 70 MB. A stalled download ends after 30 seconds below 1 KB/s.
curl_setopt_array($download, [CURLOPT_FILE => $file, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 30]);
echo 'Download '.$go.' for '.$system.PHP_EOL;
$downloaded = curl_exec($download);
$error = curl_error($download);
fclose($file);

if ($downloaded !== true || hash_file('sha256', $archive) !== $sha256) {
    unlink($archive);
    fwrite(STDERR, 'The download of '.$go.' failed or has another SHA-256. '.$error.PHP_EOL);

    exit(1);
}

$new = $directory.'/new';
exec(sprintf('rm -rf %1$s && mkdir %1$s && tar -xzf %2$s -C %1$s && rm -rf %3$s && mv %1$s/go %3$s && rm -rf %1$s %2$s', escapeshellarg($new), escapeshellarg($archive), escapeshellarg($directory.'/go')), $output, $status);

if ($status !== 0) {
    fwrite(STDERR, 'The archive of '.$go.' could not be unpacked.'.PHP_EOL);

    exit(1);
}

echo $go.' is in .go/go.'.PHP_EOL;
