<?php

declare(strict_types=1);

// A Slim app, a PSR-15 request handler, serves the request of check.php through psr15Handler() of
// the README, with the PSR-17 factory of nyholm/psr7. The CI job `works-with` runs this script in
// this directory.

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use SorgeIt\HttpServer\Server;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/check.php';
require __DIR__.'/tests/psr-15.php';

$factory = new Psr17Factory;
$app = AppFactory::create($factory);
$app->post('/works-with', static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
    $form = $request->getParsedBody();
    $response->getBody()->write((string) json_encode([
        'method' => $request->getMethod(),
        'path' => $request->getUri()->getPath(),
        'host' => $request->getHeaderLine('Host'),
        'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
        'query' => $request->getQueryParams()['q'] ?? null,
        'form' => is_array($form) ? $form['name'] ?? null : null,
        'cookie' => $request->getCookieParams()['c'] ?? null,
        'token' => $request->getHeaderLine('X-Token'),
    ]));

    return $response->withStatus(201)->withHeader('X-Answer', 'yes')->withAddedHeader('Set-Cookie', 'a=1')->withAddedHeader('Set-Cookie', 'b=2');
});
$server = new Server(psr15Handler($app, $factory, $factory), onError: static fn (Throwable $e): never => throw $e);

try {
    $output = fetch($server);
} finally {
    $server->close();
}

check('slim/slim', $server, $output);
