<?php

declare(strict_types=1);

/**
 * The bytes that Chrome 147 sent on each connection, see tests/Fixtures/README.md.
 *
 * @return array<string, array{string}>
 */
function chromeConnections(): array
{
    $connections = [];

    foreach (glob(__DIR__.'/../Fixtures/chrome/*.req') ?: [] as $file) {
        $connections[basename($file, '.req')] = [(string) file_get_contents($file)];
    }

    return $connections;
}

it('sorts the 35 requests that Chrome 147 sent as good, but the one whose path has a bad percent escape', function (): void {
    $good = 0;
    $bad = [];

    foreach (chromeConnections() as $name => [$bytes]) {
        [$verdict, $before, $requests] = browserSorted($bytes);
        $good += count($requests);

        if ($verdict !== 'good') {
            $bad[] = [$name, $verdict, strstr(substr($bytes, strlen($before)), "\r\n", true)];
        }
    }

    expect($good)->toBe(34)
        ->and($bad)->toBe([['conn-005', 'bad', 'GET /a%20b/%C3%A4%22%7B%7D%7C%5E%60/%zz/x HTTP/1.1']]);
});

// PHP parses a multipart body itself, so FrankenPHP gives `goHandler()` none; the suite `Handler`
// tests such a body.
it('answers the requests of Chrome on each connection as FrankenPHP, without those of a multipart body, and the one with a bad percent escape with 400', function (string $bytes): void {
    [, $before, $requests] = browserSorted($bytes);
    $requests = array_filter($requests, static fn (string $request): bool => preg_match('/\r\ncontent-type: multipart\/form-data;/i', substr($request, 0, (int) strpos($request, "\r\n\r\n"))) !== 1);
    $kept = implode('', $requests).substr($bytes, strlen($before));
    $answer = answers(goHandler(...), $kept);

    expect(visible($answer))->toBe(visible(scopeAnswer($answer, $kept, static fn (string $sent): string => modelAnswers($sent))));
})->with(chromeConnections());
