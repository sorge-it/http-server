<?php

declare(strict_types=1);

use SorgeIt\HttpServer\Body;
use SorgeIt\HttpServer\ClosedWithErrors;
use SorgeIt\HttpServer\Limits;
use SorgeIt\HttpServer\ListenFailed;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;
use SorgeIt\HttpServer\Server;
use SorgeIt\HttpServer\StringBody;

/**
 * Each class below `src`, by its full name, split into those directly in `src` and all others.
 *
 * @return array{list<class-string>, list<class-string>}
 */
function sourceClasses(): array
{
    $root = dirname(__DIR__, 2).'/src/';
    $classes = [[], []];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $name = substr($file->getPathname(), strlen($root), -strlen('.php'));
        /** @var class-string $class */
        $class = 'SorgeIt\\HttpServer\\'.str_replace('/', '\\', $name);
        $classes[str_contains($name, '/') ? 1 : 0][] = $class;
    }

    sort($classes[0]);
    sort($classes[1]);

    return $classes;
}

it('has eight classes and interfaces in its API, each marked @api, and each other class marked @internal below Internal', function (): void {
    [$api, $others] = sourceClasses();

    expect($api)->toBe([Body::class, ClosedWithErrors::class, Limits::class, ListenFailed::class, Request::class, Response::class, Server::class, StringBody::class])
        ->and(array_map(static fn (string $class): string|false => new ReflectionClass($class)->getDocComment(), $api))->each->toContain("\n * @api\n")
        ->and($others)->not->toBeEmpty()->each->toStartWith('SorgeIt\\HttpServer\\Internal\\')
        ->and(array_map(static fn (string $class): string|false => new ReflectionClass($class)->getDocComment(), $others))->each->toContain("\n * @internal\n");
});

it('names no internal class in a public method, parameter, default value or property of its API', function (): void {
    $types = [];

    foreach (sourceClasses()[0] as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $types[] = (string) $method->getReturnType();

            foreach ($method->getParameters() as $parameter) {
                $types[] = (string) $parameter->getType();

                // A default of `new` makes an object, which the caller then holds.
                if ($parameter->isDefaultValueAvailable()) {
                    $types[] = get_debug_type($parameter->getDefaultValue());
                }
            }
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $types[] = (string) $property->getType();
        }
    }

    $names = array_merge(...array_map(static fn (string $type): array => preg_split('/[|&?()]/', $type) ?: [], $types));

    // A class of the API in the list shows that the scan reads the names of classes.
    expect($names)->toContain(Limits::class)
        ->and(array_values(array_filter($names, static fn (string $name): bool => str_contains($name, '\\Internal\\'))))->toBe([]);
});
