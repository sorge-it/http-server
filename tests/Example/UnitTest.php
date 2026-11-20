<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\StringBody;

it('saves the posted user and redirects', function (): void {
    $posted = null;
    $handler = static function (Request $request) use (&$posted): Response {
        parse_str((string) $request->body, $posted);

        return new Response(303, ['Location' => '/saved']);
    };

    $response = $handler(new Request('POST', '/users', ['content-type' => ['application/x-www-form-urlencoded']], new StringBody('name=Ada')));

    expect($response->status)->toBe(303)
        ->and($response->headers)->toBe(['Location' => '/saved'])
        ->and($posted)->toBe(['name' => 'Ada']);
});
