<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;

/**
 * A handler for `new Server()` that passes each request to a PSR-15 request handler, such as a
 * Slim app. A PSR-17 factory, such as `Nyholm\Psr7\Factory\Psr17Factory`, builds the request.
 * A multipart body stays unparsed: the request has no uploaded files.
 */
function psr15Handler(RequestHandlerInterface $handler, ServerRequestFactoryInterface $requests, StreamFactoryInterface $streams): Closure
{
    return static function (Request $request) use ($handler, $requests, $streams): Response {
        $cookies = [];

        foreach (explode(';', $request->header('Cookie') ?? '') as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            if ($name !== '') {
                $cookies[$name] = urldecode($value);
            }
        }

        parse_str($request->query(), $query);
        $body = (string) $request->body;
        $server = ['REMOTE_ADDR' => trim(substr($request->remoteAddress, 0, (int) strrpos($request->remoteAddress, ':')), '[]')];
        $psrRequest = $requests->createServerRequest($request->method, 'http://'.$request->header('Host').$request->target, $server)
            ->withCookieParams($cookies)
            ->withQueryParams($query)
            ->withBody($streams->createStream($body));

        foreach ($request->headers as $name => $values) {
            $psrRequest = $psrRequest->withHeader($name, $values);
        }

        if (str_starts_with(strtolower($request->header('Content-Type') ?? ''), 'application/x-www-form-urlencoded')) {
            parse_str($body, $form);
            $psrRequest = $psrRequest->withParsedBody($form);
        }

        $psrResponse = $handler->handle($psrRequest);

        return new Response($psrResponse->getStatusCode(), $psrResponse->getHeaders(), (string) $psrResponse->getBody());
    };
}
