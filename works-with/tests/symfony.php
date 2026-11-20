<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * A handler for `new Server()` that passes each request to a Symfony kernel in the test process.
 */
function symfonyHandler(HttpKernelInterface $kernel): Closure
{
    return static function (Request $request) use ($kernel): Response {
        $symfonyRequest = symfonyRequest($request);
        $symfonyResponse = $kernel->handle($symfonyRequest)->prepare($symfonyRequest);
        $response = serverResponse($symfonyResponse);

        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($symfonyRequest, $symfonyResponse);
        }

        return $response;
    };
}

/**
 * Converts the request into a Symfony request. An `application/x-www-form-urlencoded` body also
 * fills the form fields; a multipart body stays unparsed, so the request has no uploaded files.
 */
function symfonyRequest(Request $request): SymfonyRequest
{
    $server = [
        'REQUEST_METHOD' => $request->method,
        'REQUEST_URI' => $request->target,
        'QUERY_STRING' => $request->query(),
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REMOTE_ADDR' => trim(substr($request->remoteAddress, 0, (int) strrpos($request->remoteAddress, ':')), '[]'),
    ];

    foreach ($request->headers as $name => $values) {
        $key = strtoupper(strtr($name, '-', '_'));
        $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = implode(', ', $values);
    }

    $cookies = [];

    foreach (explode(';', $request->header('Cookie') ?? '') as $pair) {
        [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

        if ($name !== '') {
            $cookies[$name] = urldecode($value);
        }
    }

    parse_str($request->query(), $query);
    $body = (string) $request->body;
    $form = [];

    if (str_starts_with(strtolower($request->header('Content-Type') ?? ''), 'application/x-www-form-urlencoded')) {
        parse_str($body, $form);
    }

    return new SymfonyRequest($query, $form, [], $cookies, [], $server, $body);
}

/**
 * Converts a Symfony response, with the body that `sendContent()` writes, so a `StreamedResponse`
 * works too. The body is held in memory.
 */
function serverResponse(SymfonyResponse $response): Response
{
    $body = '';
    ob_start(static function (string $buffer) use (&$body): string {
        $body .= $buffer;

        return '';
    });

    try {
        $response->sendContent();
    } finally {
        ob_end_flush();
    }

    return new Response($response->getStatusCode(), $response->headers->all(), $body);
}
