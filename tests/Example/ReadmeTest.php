<?php

declare(strict_types=1);
use SorgeIt\HttpServer\Server;

/**
 * The first PHP block after the README heading.
 */
function readmeBlock(string $heading): string
{
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');
    $start = (int) strpos($readme, "```php\n", (int) strpos($readme, "\n".$heading."\n")) + 7;

    return substr($readme, $start, (int) strpos($readme, "```\n", $start) - $start);
}

/**
 * Starts a README script in a PHP process of its own, with the autoloader of this checkout, and
 * returns the process, its pipes and the URL that it prints first.
 *
 * @return array{resource, array<int, resource>, string}
 */
function startReadmeScript(string $code): array
{
    $file = tempnam(sys_get_temp_dir(), 'readme').'.php';
    file_put_contents($file, str_replace("__DIR__.'/vendor/autoload.php'", var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true), $code));
    $process = proc_open([PHP_BINARY, $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('The script did not start');
    }

    stream_set_timeout($pipes[1], 10);

    return [$process, $pipes, trim((string) fgets($pipes[1]))];
}

it('shows each example test in the README', function (string $file, string $heading): void {
    $test = (string) file_get_contents(__DIR__.'/'.$file);
    $start = (int) strpos($test, "\nuse ") + 1;
    $imports = substr($test, $start, (int) strpos($test, "\n\n", $start) + 1 - $start);
    $body = substr($test, (int) strpos($test, "\nit(") + 1);

    expect(readmeBlock($heading))->toBe("<?php\n\ndeclare(strict_types=1);\n\n".$imports."\n".$body);
})->with([
    ['ChromeTest.php', '## A browser test with Chrome'],
    ['FormTest.php', '### Waiting for a request'],
    ['UnitTest.php', "## A handler's unit test"],
]);

it('shows each handler that the job works-with runs', function (string $file, string $heading): void {
    expect(readmeBlock($heading))->toBe(file_get_contents(dirname(__DIR__, 2).'/works-with/tests/'.$file));
})->with([
    ['symfony.php', '### Symfony'],
    ['laravel.php', '### Laravel'],
    ['psr-15.php', '### PSR-15'],
]);

it('serves the quick start until its deadline', function (): void {
    [$process, $pipes, $url] = startReadmeScript(str_replace('hrtime(true) / 1e9 + 60', 'hrtime(true) / 1e9 + 2', readmeBlock('## Quick start')));

    try {
        expect($url)->toStartWith('http://127.0.0.1:')
            ->and(file_get_contents($url.'/readme', false, stream_context_create(['http' => ['timeout' => 5]])))->toBe('Hello /readme')
            ->and(stream_get_contents($pipes[1]))->toBe('')
            ->and(stream_get_contents($pipes[2]))->toBe('');
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
});

it("serves the driver's wait loop and echoes STDIN until it ends", function (): void {
    [$process, $pipes, $url] = startReadmeScript(readmeBlock("## A driver's wait loop"));

    try {
        fwrite($pipes[0], "a line\n");
        $page = file_get_contents($url.'/loop', false, stream_context_create(['http' => ['timeout' => 5]]));
        fclose($pipes[0]);

        expect($page)->toBe('Hello /loop')
            ->and(stream_get_contents($pipes[1]))->toBe("stdin: a line\n")
            ->and(stream_get_contents($pipes[2]))->toBe('');
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
});

it('reads a form with the request body loop of the README', function (): void {
    $handler = eval('return static function (SorgeIt\HttpServer\Request $request): SorgeIt\HttpServer\Response {'
        .readmeBlock('### The request body')
        .'return new SorgeIt\HttpServer\Response(200, [], json_encode($fields, JSON_THROW_ON_ERROR)); };');

    if (! $handler instanceof Closure) {
        throw new LogicException('The README block gave no handler');
    }

    $server = new Server($handler);

    try {
        expect(exchange($server, "POST / HTTP/1.1\r\nHost: a\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: 18\r\n\r\nname=Ada&lang=php\n"))
            ->toEndWith("\r\n\r\n".'{"name":"Ada","lang":"php\n"}');
    } finally {
        $server->close();
    }
});
