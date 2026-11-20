<?php

declare(strict_types=1);

// What a test holds while the browser loads a page. As with `RefreshDatabase` in Laravel, the user
// Ada is in a transaction that is never committed.

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/app.php';

$db = new PDO('sqlite:'.__DIR__.'/app.sqlite');
$db->exec('CREATE TABLE IF NOT EXISTS users (name TEXT)');
$db->beginTransaction();
$db->exec("INSERT INTO users (name) VALUES ('Ada')");

/**
 * Loads the page with headless Chromium in a process of its own, as the browser of a test does,
 * calls `$wait` until Chromium is done, and returns the heading of the page.
 */
function browse(string $url, Closure $wait): string
{
    // `php -S` takes a moment until it listens. A server that never answers ends the demo.
    $address = parse_url($url, PHP_URL_HOST).':'.parse_url($url, PHP_URL_PORT);
    $until = hrtime(true) / 1e9 + 10;

    while (! is_resource($probe = @stream_socket_client('tcp://'.$address))) {
        $wait();
        hrtime(true) / 1e9 < $until || throw new RuntimeException('No server listens on '.$address);
    }

    fclose($probe);
    // No sandbox: the container gives Chromium no user namespaces.
    $chrome = proc_open(['chromium', '--headless', '--no-sandbox', '--dump-dom', $url], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    stream_set_blocking($pipes[1], false);
    $dom = '';

    while (! feof($pipes[1]) && hrtime(true) / 1e9 < $until + 20) {
        $wait();
        $dom .= fread($pipes[1], 8192);
    }

    proc_terminate($chrome);
    proc_close($chrome);
    preg_match('#<h1>(.*?)</h1>#', $dom, $heading);
    $text = $heading[1] ?? 'no page';

    // In the terminal of the recording: red for no user, green for Ada.
    return stream_isatty(STDOUT) ? "\033[1;".(str_ends_with($text, 'none') ? '31' : '32')."m{$text}\033[0m\n" : $text."\n";
}
