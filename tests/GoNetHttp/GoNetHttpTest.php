<?php

declare(strict_types=1);

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SorgeIt\HttpServer\Internal\Answer;
use SorgeIt\HttpServer\Internal\BadRequest;
use SorgeIt\HttpServer\Internal\Go;
use SorgeIt\HttpServer\Internal\RequestParser;
use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Tests\GoNetHttp\GoReference;
use SorgeIt\HttpServer\Tests\Upstream;

/**
 * The cases of the fixture, read once.
 *
 * @return list<array<string, mixed>>
 */
function goFixture(): array
{
    /** @var list<array<string, mixed>>|null $cases */
    static $cases = null;

    if ($cases === null) {
        /** @var list<array<string, mixed>> $read */
        $read = json_decode((string) file_get_contents(GoReference::FIXTURE.'/cases.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = $read;
    }

    return $cases;
}

/**
 * @return list<string>
 */
function goGroups(): array
{
    $groups = [];

    foreach (goFixture() as $case) {
        assert(is_string($case['group']));
        $groups[$case['group']] = $case['group'];
    }

    return array_values($groups);
}

/**
 * Of each case of the group, by number and name: the bytes, the limits and the
 * `MaxHeaderValueCount` for the parser. One case at a time, because a group holds bodies of
 * megabytes. A data set for each case makes two thousand tests, which hold too much memory.
 *
 * @return Generator<string, array{string, Limits, int|null}>
 */
function goGroup(string $group): Generator
{
    foreach (goFixture() as $number => $case) {
        if ($case['group'] === $group) {
            $maxHead = $case['maxHeaderBytes'] ?? 1 << 20;
            $maxValues = $case['maxHeaderValueCount'] ?? null;
            $maxBody = $case['maxBody'] ?? null;
            assert(is_string($case['name']) && is_int($maxHead) && (is_int($maxValues) || $maxValues === null) && (is_int($maxBody) || $maxBody === null));

            yield sprintf('#%d %s', $number + 1, addcslashes($case['name'], "\0..\37\177..\377")) => [goBytes($case['request'] ?? ''), new Limits(maxHead: $maxHead, maxBody: $maxBody), $maxValues];
        }
    }
}

/**
 * The request in the terms of Go: `path` decoded, as `URL.Path`, and `rawPath` the path as it came,
 * where the escapes of Go for `path` give other bytes, as `URL.setPath()` sets `RawPath`.
 *
 * @return array<string, mixed>
 */
function asGo(Request $request): array
{
    $headers = $request->headers;
    unset($headers['host']);
    ksort($headers);
    $path = rawurldecode($request->path());
    // Each byte but these, which the escapes of a path in Go keep.
    $escaped = (string) preg_replace_callback('/[^A-Za-z0-9\-_.~$&+,\/:;=@]/', static fn (array $byte): string => sprintf('%%%02X', ord($byte[0])), $path);

    return [
        'method' => $request->method,
        'target' => $request->target,
        'proto' => 'HTTP/1.1',
        'host' => $request->header('Host'),
        'path' => $path,
        'rawPath' => $escaped !== $request->path() ? $request->path() : null,
        'rawQuery' => $request->query(),
        'header' => $headers,
        'body' => (string) $request->body,
        'bodyError' => $request->body->error(),
    ];
}

/**
 * What the parser reads from the bytes in pieces of the size, or whole for 0, then from the end of
 * the client, as the server takes it: one request at a time, and nothing after a request that
 * closes the connection. A `maxValues` of null is the count of the server.
 *
 * @param  int<0, max>  $piece
 * @return list<string|array<string, mixed>>
 */
function parserEvents(string $bytes, int $piece, Limits $limits, ?int $maxValues): array
{
    $parser = new RequestParser($limits->maxHead, $maxValues ?? Go::MAX_HEADER_VALUES);
    $events = [];
    $open = true;

    foreach ([...($piece === 0 ? [$bytes] : str_split($bytes, max(1, $piece))), null] as $part) {
        if ($part === null) {
            $parser->end();
        }

        while ($open && ($request = $parser->feed((string) $part, 1)[0] ?? null) instanceof Request) {
            $part = '';

            // As a handler of Go that reads the body through `MaxBytesReader` first.
            if ($limits->maxBody > 0 && strlen((string) $request->body) > $limits->maxBody) {
                $error = Go::error(413);
                $answer = Answer::of(new Response(413, $error->headers, $error->body), $request, static fn (): bool => true);

                return [...$events, ['refused' => withoutDate($answer->head.implode('', array_filter($answer->parts, is_string(...))))]];
            }

            $events[] = ['request' => asGo($request)];
            // As after the answer of a handler of Go that writes nothing.
            $open = ! Answer::of(new Response, $request, $parser->settle(...))->closes;
        }

        if (! $open) {
            return $events;
        }
    }

    $error = $parser->error();

    return $error instanceof BadRequest ? [...$events, ['refused' => withoutDate($error->answer())]] : $events;
}

/**
 * What the parser must read, as `scopeAnswer()` decides for the server: for a request that is not
 * good, the requests before it and the 400; for a head over `maxHead`, those before it and the 431.
 *
 * @param  Closure(string): string  $shown  what Go with the handler `show` writes for the bytes
 * @param  list<string|array<string, mixed>>  $events
 * @return list<string|array<string, mixed>>
 */
function scopeEvents(array $events, string $bytes, Closure $shown, int $maxHead): array
{
    [$verdict, $before] = browserSorted($bytes, $maxHead);

    if ($verdict === 'good' || $verdict === 'cut') {
        return goEvents($shown($bytes));
    }

    $answers = $before === '' ? '' : $shown($before);
    $refused = [...goEvents($answers), ...(str_contains($answers, "\r\nConnection: close\r\n") ? [] : [['refused' => $verdict === 'large' ? HEAD_TOO_LARGE : BAD_REQUEST]])];

    if ($events === $refused || $verdict === 'large') {
        return $refused;
    }

    $whole = goEvents($shown($bytes));
    $last = end($whole);

    $limit = is_array($last) && is_string($last['refused'] ?? null) && preg_match('/^HTTP\/1\.[01] 4(13|31) /', $last['refused']) === 1;

    return $limit ? $whole : $refused;
}

// The weekly job of go-net-http.yml runs with a newer Go, so it leaves out this group.
it('names in each place the Go of go.mod, which the fixture comes from', function (): void {
    $go = preg_match('/^go (\S+)$/m', (string) file_get_contents(__DIR__.'/go.mod'), $match) === 1 ? $match[1] : '';

    expect('go'.$go)->toBe(GoReference::version())
        ->and(file_get_contents(__DIR__.'/install-go.php'))->toContain("'go".$go."' => [")
        ->and(file_get_contents(__DIR__.'/../../.github/docker/debian/Dockerfile'))->toContain('FROM golang:'.$go.'@')
        ->and(file_get_contents(__DIR__.'/../../.github/docker/alpine/Dockerfile'))->toContain('FROM golang:'.$go.'-alpine@');
})->group('pins');

// A git blob is the SHA-1 of `blob <length>\0<bytes>`. VERSION is not in go.lock, because it
// changes with each release. The test skips a file whose name starts with a dot, such as .DS_Store
// of macOS.
it('has in go/ the test files and the LICENSE of go.lock, each with its blob, and no other file but VERSION', function (): void {
    $blobs = [];

    foreach (Upstream::lines(GoReference::FIXTURE.'/go.lock') as $line) {
        [$blob, $path] = explode('  ', $line, 2) + [1 => ''];

        if ($path === 'LICENSE' || str_ends_with($path, '_test.go')) {
            $blobs[$path] = $blob;
        }
    }

    $copies = [];
    $directory = GoReference::FIXTURE.'/go/';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getPathname() !== $directory.'VERSION' && ! str_starts_with($file->getFilename(), '.')) {
            $bytes = (string) file_get_contents($file->getPathname());
            $copies[substr($file->getPathname(), strlen($directory))] = sha1('blob '.strlen($bytes)."\0".$bytes);
        }
    }

    ksort($blobs);
    ksort($copies);

    expect($blobs)->toHaveCount(8)
        ->and($copies)->toBe($blobs);
});

it('has as cases the bytes of the test files of Go, as `extract` takes them from there, then the probes', function (): void {
    $copy = temporaryFile((string) file_get_contents(GoReference::FIXTURE.'/cases.json'));
    GoReference::go(['run', './extract', (string) realpath(GoReference::FIXTURE.'/go'), $copy]);

    expect(file_get_contents($copy))->toBe(file_get_contents(GoReference::FIXTURE.'/cases.json'));
});

it('reads each good request of the cases of Go net/http as Go does, and refuses each other with 400, whole and one byte at a time, a case over 256 KB in pieces of 4093 bytes', function (string $group): void {
    foreach (goGroup($group) as $name => [$bytes, $limits, $maxValues]) {
        $piece = strlen($bytes) > 1 << 18 ? 4093 : 1;

        foreach (['Whole, ' => 0, 'In pieces, ' => $piece] as $how => $size) {
            $events = parserEvents($bytes, $size, $limits, $maxValues);

            expect($events)->toBe(scopeEvents($events, $bytes, static fn (string $sent): string => GoReference::get()->exchange($sent, $limits, $size, maxValues: $maxValues), $limits->maxHead), $how.$name);
        }
    }
})->with(goGroups());

it('answers each good request of the cases of Go net/http with the bytes of FrankenPHP, with the test handler, and each other with 400', function (string $group): void {
    foreach (goGroup($group) as $name => [$bytes, $limits]) {
        $answer = answers(goHandler(...), $bytes, $limits);

        expect(visible($answer))->toBe(visible(scopeAnswer($answer, $bytes, static fn (string $sent): string => modelAnswers($sent, $limits), $limits->maxHead)), $name);
    }
})->with(goGroups());

// With a `default_mimetype`, PHP names the type, and Go sniffs only without one.
it('answers each case of the sniff test of Go with the type of Go where default_mimetype is empty, as FrankenPHP', function (): void {
    $before = (string) ini_get('default_mimetype');
    ini_set('default_mimetype', '');

    try {
        foreach (goFixture() as $number => $case) {
            assert(is_string($case['name']));

            if (str_starts_with($case['name'], 'sniff ')) {
                $bytes = goBytes($case['request'] ?? '');
                $answer = answers(goHandler(...), $bytes);

                expect(visible($answer))->toBe(visible(scopeAnswer($answer, $bytes, static fn (string $sent): string => modelAnswers($sent))), sprintf('#%d %s', $number + 1, $case['name']));
            }
        }
    } finally {
        ini_set('default_mimetype', $before);
    }
});

it('answers each mutated good request with the bytes of FrankenPHP, with the test handler, and each other with 400', function (): void {
    $seeds = array_values(fuzzSeeds());
    // The weekly job of go-net-http.yml sends more.
    $count = (int) (getenv('GO_NET_HTTP_FUZZ') ?: 300);

    for ($case = 0; $case < $count; $case++) {
        $random = new Randomizer(new Xoshiro256StarStar($case));
        $bytes = mutate($random, $seeds[$case % count($seeds)], $seeds);
        // Small limits on each second case, so that a mutated request can reach them.
        $maxBody = $random->getInt(0, 13);
        $limits = $case % 2 === 0 ? new Limits : new Limits(maxHead: $random->getInt(1, 1024), maxBody: $maxBody === 13 ? null : $maxBody);
        $shown = addcslashes($bytes, "\0..\37\"\\\$\177..\377");

        $answer = answers(goHandler(...), $bytes, $limits);

        expect(visible($answer))->toBe(visible(scopeAnswer($answer, $bytes, static fn (string $sent): string => modelAnswers($sent, $limits), $limits->maxHead)), sprintf('case %d, maxHead %d, maxBody %s: "%s"', $case, $limits->maxHead, $limits->maxBody ?: 'none', $shown));

        // A small count of field values, which only the parser takes.
        if ($case % 2 === 1) {
            $maxValues = $random->getInt(1, 8);
            $events = parserEvents($bytes, 0, $limits, $maxValues);

            expect($events)->toBe(scopeEvents($events, $bytes, static fn (string $sent): string => GoReference::get()->exchange($sent, $limits, maxValues: $maxValues), $limits->maxHead), sprintf('case %d, parser, maxValues %d: "%s"', $case, $maxValues, $shown));
        }
    }
});
