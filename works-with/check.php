<?php

declare(strict_types=1);

// The request that each script of the CI job `works-with` sends, and the checks of its response.

use Composer\InstalledVersions;
use SorgeIt\HttpServer\Server;

/**
 * Sends a form with a query, a cookie and a header through curl, in a process of its own, and
 * ticks the server until curl ends, as a test ticks it while the browser loads a page. Returns
 * the head and the body of the response.
 */
function fetch(Server $server, string $target = '/works-with?q=1'): string
{
    $curl = proc_open(
        ['curl', '--silent', '--show-error', '--include', '--max-time', '10', '--cookie', 'c=v%20w', '--header', 'X-Token: t', '--data', 'name=Ada', $server->url().$target],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    ) ?: throw new RuntimeException('curl did not start');
    stream_set_blocking($pipes[1], false);
    $output = '';

    while (! feof($pipes[1])) {
        $server->tick(0.01);
        $output .= fread($pipes[1], 8192);
    }

    $error = stream_get_contents($pipes[2]);

    if (proc_close($curl) !== 0) {
        throw new RuntimeException('curl failed: '.$error);
    }

    return $output;
}

/**
 * Ends the script with exit code 1 when the app did not read the request of fetch() or did not
 * write its response. Else it prints the result, and adds a row to the summary of the run on
 * GitHub Actions.
 *
 * @param  array<string, mixed>  $read  what the app read beyond the request itself, such as the user of the test
 */
function check(string $package, Server $server, string $output, array $read = []): void
{
    [$head, $body] = array_pad(explode("\r\n\r\n", $output, 2), 2, '');
    $lines = explode("\r\n", $head);
    $headers = [];

    foreach (array_slice($lines, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower($name)][] = trim($value);
    }

    $cookies = array_map(static fn (string $cookie): string => explode('=', $cookie, 2)[0], $headers['set-cookie'] ?? []);
    $expected = ['method' => 'POST', 'path' => '/works-with', 'host' => $server->address(), 'ip' => '127.0.0.1', 'query' => '1', 'form' => 'Ada', 'cookie' => 'v w', 'token' => 't', ...$read];
    $answer = json_decode($body, true);

    $error = match (true) {
        ! str_starts_with($lines[0], 'HTTP/1.1 201 ') => sprintf('%s answered `%s`, not 201.', $package, $lines[0]),
        ($headers['x-answer'] ?? []) !== ['yes'] => sprintf('%s sent no header `X-Answer: yes`.', $package),
        // Laravel adds the cookies of its session.
        array_diff(['a', 'b'], $cookies) !== [] => sprintf('%s set the cookies `%s`, not a and b.', $package, implode(', ', $cookies)),
        $answer !== $expected => sprintf("%s read\n%s\nnot\n%s", $package, $body, json_encode($expected)),
        default => null,
    };

    if ($error !== null) {
        fwrite(STDERR, $error."\n");

        exit(1);
    }

    $release = ltrim(InstalledVersions::getPrettyVersion($package) ?? 'unknown', 'v');
    printf("%s %s read the request and wrote the response.\n", $package, $release);

    $summary = getenv('GITHUB_STEP_SUMMARY');

    if (is_string($summary) && $summary !== '') {
        file_put_contents($summary, sprintf("| `%s` | %s | PHP %s |\n", $package, $release, PHP_VERSION), FILE_APPEND);
    }
}
