<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Body;
use SorgeIt\HttpServer\Request;

// The script FrankenPHP runs for the cases of Go and Caddy: goHandler() of the tests, with the
// request that FrankenPHP gives to PHP, and its response through header() and echo. A file goes
// with its length as a header, as `Answer` takes it, and `Php` for HEAD.

require dirname(__DIR__, 3).'/vendor/autoload.php';
require_once dirname(__DIR__, 2).'/Pest.php';

$headers = [];

foreach ($_SERVER as $key => $value) {
    if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = [$value];
    }
}

foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
    if (is_string($_SERVER[$key] ?? null) && $_SERVER[$key] !== '') {
        $headers[$name] = [$_SERVER[$key]];
    }
}

$input = fopen('php://input', 'rb');
assert(is_resource($input));

$body = new readonly class($input) implements Body
{
    /** @param resource $input */
    public function __construct(private mixed $input) {}

    public function __toString(): string
    {
        return (string) stream_get_contents($this->input);
    }

    public function read(int $length): string
    {
        return (string) fread($this->input, max(1, $length));
    }

    public function error(): ?string
    {
        return null;
    }
};

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$target = $_SERVER['REQUEST_URI'] ?? '';
assert(is_string($method) && is_string($target));

$response = goHandler(new Request($method, $target, $headers, $body));

// PHP drops a header with a line break or a NUL, and so does `Php`. Only PHP warns, so the script
// mutes the warning.
foreach ($response->headers as $name => $values) {
    foreach ((array) $values as $index => $value) {
        @header($name.': '.$value, $index === 0);
    }
}

http_response_code($response->status);

if ($response->file !== null) {
    header('Content-Length: '.filesize($response->file));
    readfile($response->file);
    unlink($response->file);

    return;
}

if (is_string($response->body)) {
    echo $response->body;

    return;
}

foreach ($response->body as $chunk) {
    echo $chunk;
    flush();
}
