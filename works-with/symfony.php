<?php

declare(strict_types=1);

// A Symfony app of symfony/framework-bundle serves the request of check.php through
// symfonyHandler() of the README. The CI job `works-with` runs this script in this directory.

use SorgeIt\HttpServer\Server;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/check.php';
require __DIR__.'/tests/symfony.php';

final class WorksWithKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle];
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/works-with-symfony/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/works-with-symfony/log';
    }

    /**
     * A streamed response, so the body comes from `sendContent()`.
     */
    public function worksWith(Request $request): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($request): void {
            echo json_encode([
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'host' => $request->getHttpHost(),
                'ip' => $request->getClientIp(),
                'query' => $request->query->get('q'),
                'form' => $request->request->get('name'),
                'cookie' => $request->cookies->get('c'),
                'token' => $request->headers->get('X-Token'),
            ]);
        }, 201, ['X-Answer' => 'yes']);
        $response->headers->setCookie(Cookie::create('a', '1'));
        $response->headers->setCookie(Cookie::create('b', '2'));

        return $response;
    }

    /**
     * FrameworkBundle answers the exception with its error page, so `onError` gets none.
     */
    public function throws(): never
    {
        throw new RuntimeException('thrown by the app');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'works-with', 'test' => true]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('works_with', '/works-with')->controller([$this, 'worksWith'])->methods(['POST']);
        $routes->add('works_with_throw', '/works-with/throw')->controller([$this, 'throws'])->methods(['POST']);
    }
}

$kernel = new WorksWithKernel('test', true);
$kernel->boot();
$server = new Server(symfonyHandler($kernel), onError: static fn (Throwable $e): never => throw $e);

try {
    $output = fetch($server);
    // An onError that got the exception would throw it out of fetch().
    $thrown = fetch($server, '/works-with/throw');
} finally {
    $server->close();
}

if (! str_starts_with($thrown, 'HTTP/1.1 500 ')) {
    fwrite(STDERR, "symfony/http-kernel: the exception of the app did not give the 500 of FrameworkBundle:\n".$thrown."\n");
    exit(1);
}

check('symfony/http-kernel', $server, $output);
