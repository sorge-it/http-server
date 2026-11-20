<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Tests\FrankenPhp\Cases;

// The script FrankenPHP runs for FrankenPhpTest: it sets each header with header(), the first value
// of a name replacing, then the status, then echoes the body, as Cases::handler() describes it.

require_once __DIR__.'/../Cases.php';

$number = is_string($_GET['case'] ?? null) ? (int) $_GET['case'] : -1;
$case = Cases::get($number);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$target = $_SERVER['REQUEST_URI'] ?? '';
assert(is_string($method) && is_string($target));

if (($case['echo'] ?? false) === true) {
    echo Cases::seen($method, $target, $number);

    return;
}

Cases::set($case);
$headers = $case['headers'] ?? [];
$status = $case['status'] ?? 200;
assert(is_array($headers) && is_int($status));

foreach ($headers as $name => $values) {
    assert(is_array($values));

    foreach (array_values($values) as $index => $value) {
        assert(is_string($value));
        header($name.': '.$value, $index === 0);
    }
}

http_response_code($status);

if (isset($case['file'])) {
    $file = (string) tempnam(sys_get_temp_dir(), 'frankenphp');
    file_put_contents($file, Cases::bytes($case['file']));
    header('Content-Length: '.filesize($file));
    readfile($file);
    unlink($file);
}

if (isset($case['chunks'])) {
    assert(is_array($case['chunks']));

    foreach ($case['chunks'] as $chunk) {
        echo Cases::bytes($chunk);
        flush();
    }
}

echo Cases::bytes($case['body'] ?? '');

$throws = Cases::throws($case);

if ($throws !== null) {
    throw new RuntimeException($throws);
}
