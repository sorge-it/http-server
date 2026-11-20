<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request as LaravelRequest;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;

require_once __DIR__.'/symfony.php';

/**
 * A handler for `new Server()` that passes each request to a Laravel test's app, as
 * `$this->get()` does: the page sees the test's transaction, its user and its fakes.
 */
function laravelHandler(Application $app): Closure
{
    return static function (Request $request) use ($app): Response {
        $kernel = $app->make(Kernel::class);
        $laravelRequest = LaravelRequest::createFromBase(symfonyRequest($request));
        $laravelResponse = $kernel->handle($laravelRequest);
        $response = serverResponse($laravelResponse);
        $kernel->terminate($laravelRequest, $laravelResponse);

        return $response;
    };
}
