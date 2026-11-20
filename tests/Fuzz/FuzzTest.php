<?php

declare(strict_types=1);

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SorgeIt\HttpServer\Internal\BadRequest;
use SorgeIt\HttpServer\Internal\RequestParser;
use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\Request;

/**
 * Random sizes put the ends of pieces inside terminators, numbers and tokens, where a parser that
 * reads only what has come must wait.
 *
 * @return list<string>
 */
function randomPieces(Randomizer $random, string $bytes): array
{
    $pieces = [];

    for ($at = 0; $at < strlen($bytes); $at += $size) {
        $size = $random->getInt(1, 32);
        $pieces[] = substr($bytes, $at, $size);
    }

    return $pieces;
}

/**
 * The server answers each request before it reads more bytes, so the requests are taken after each
 * piece; then the client sends its end. The last item lists the rules that the parser broke.
 *
 * @param  list<string>  $pieces
 * @return array{list<Request>, string|null, bool, list<string>}
 */
function outcome(Limits $limits, array $pieces, int $most): array
{
    $parser = new RequestParser($limits->maxHead);
    $requests = [];
    $broken = [];
    $pending = false;

    foreach ([...$pieces, null] as $piece) {
        if ($piece === null) {
            $pending = $parser->pending();
            $parser->end();
        }

        for ($read = $parser->feed((string) $piece, $most); $read !== []; $read = $parser->feed('', $most)) {
            array_push($requests, ...$read);
        }
    }

    foreach ($requests as $request) {
        if (isset($request->headers['transfer-encoding'])) {
            $broken[] = 'a request with a Transfer-Encoding';
        }

        foreach (array_merge(...array_values($request->headers)) as $value) {
            if ($value !== ltrim($value, " \t")) {
                $broken[] = 'a field value with a space or tab at its start';
            }
        }
    }

    if ($parser->pending()) {
        $broken[] = 'bytes held after the end of the client';
    }

    $fault = $parser->error();

    return [
        $requests,
        $fault instanceof BadRequest ? $fault->status.' '.$fault->getMessage() : null,
        $pending,
        array_values(array_unique($broken)),
    ];
}

it('reads each mutated request in the same way whole, byte by byte and in random pieces, also at the limit of the head', function (string $seed): void {
    expect(outcome(new Limits, [$seed], PHP_INT_MAX)[1])->toBeNull();

    $seeds = array_values(fuzzSeeds());

    for ($case = 0; $case < 1000; $case++) {
        // One engine for each case, so that the case alone gives its bytes, limits and pieces again.
        $random = new Randomizer(new Xoshiro256StarStar(crc32($seed) + $case));
        $bytes = mutate($random, $seed, $seeds);
        // Small limits on each second case, so that a mutated request can reach them.
        $limits = $case % 2 === 0 ? new Limits : new Limits(maxHead: $random->getInt(64, 256));
        $input = sprintf('case %d, maxHead %d: "%s"', $case, $limits->maxHead, addcslashes($bytes, "\0..\37\"\\\$\177..\377"));

        try {
            [$whole, $bytewise, $pieces, $oneByOne] = whereWarningsThrow(static fn (): array => [
                outcome($limits, [$bytes], PHP_INT_MAX),
                outcome($limits, str_split($bytes), PHP_INT_MAX),
                outcome($limits, randomPieces($random, $bytes), PHP_INT_MAX),
                outcome($limits, randomPieces($random, $bytes), 1),
            ]);
        } catch (Throwable $throwable) {
            throw new RuntimeException('The parser threw '.$throwable::class.' for '.$input, 0, $throwable);
        }

        expect($whole[3])->toBe([], 'Whole, '.$input);

        foreach (['Byte by byte' => $bytewise, 'In random pieces' => $pieces, 'One request per call' => $oneByOne] as $how => $other) {
            expect($other[3])->toBe([], $how.', '.$input)
                ->and($other)->toEqual($whole, $how.', '.$input);
        }
    }
})->with(fuzzSeeds());
