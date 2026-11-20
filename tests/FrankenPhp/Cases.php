<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Tests\FrankenPhp;

use Generator;
use RuntimeException;
use SorgeIt\HttpServer\Request;
use SorgeIt\HttpServer\Response;

/**
 * The cases of `cases.json`, and the handler that gives for each the response that `app/index.php`
 * makes in FrankenPHP: the same status, headers and body, and the same throw.
 */
final class Cases
{
    public const string FILE = __DIR__.'/../Fixtures/frankenphp/cases.json';

    /**
     * The php.ini of each case, before the ini of the case: the defaults of PHP, without the errors
     * on the page.
     */
    public const array INI = ['expose_php' => '1', 'default_mimetype' => 'text/html', 'default_charset' => 'UTF-8', 'display_errors' => '0'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        /** @var list<array<string, mixed>>|null $cases */
        static $cases = null;

        if ($cases === null) {
            /** @var list<array<string, mixed>> $read */
            $read = json_decode((string) file_get_contents(self::FILE), true, flags: JSON_THROW_ON_ERROR);
            $cases = $read;
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(int $number): array
    {
        return self::all()[$number] ?? throw new RuntimeException('No case '.$number.' in cases.json.');
    }

    /**
     * A case names only the values it changes; both sides start from `INI`, so a value that a case
     * leaves out is the same for FrankenPHP and for this server.
     *
     * @return array<string, string>
     */
    public static function ini(int $number): array
    {
        $overrides = self::get($number)['ini'] ?? [];
        assert(is_array($overrides));
        $ini = self::INI;

        foreach ($overrides as $name => $value) {
            assert(is_string($name) && is_string($value));
            $ini[$name] = $value;
        }

        return $ini;
    }

    /**
     * The bytes of the request of the case: `Connection: close`, so the server closes once it
     * answered. A client that sends its end makes FrankenPHP stop the script at its next output.
     */
    public static function request(int $number): string
    {
        $case = self::get($number);
        $method = $case['method'] ?? 'GET';
        $protocol = $case['protocol'] ?? '1.1';
        assert(is_string($method) && is_string($protocol));

        return $method.' /?case='.$number.' HTTP/'.$protocol."\r\n".($protocol === '1.1' ? "Host: a\r\nConnection: close\r\n" : '')."\r\n";
    }

    /**
     * The bytes of a body of the fixture: a string, or `{"repeat": "a", "times": n}`.
     */
    public static function bytes(mixed $value): string
    {
        if (is_array($value)) {
            assert(is_string($value['repeat']) && is_int($value['times']) && $value['times'] >= 0);

            return str_repeat($value['repeat'], $value['times']);
        }

        assert(is_string($value));

        return $value;
    }

    /**
     * What the side got: the method, the target, the case and the values of `INI` it runs with.
     */
    public static function seen(string $method, string $target, int $number): string
    {
        $ini = [];

        foreach (array_keys(self::INI) as $name) {
            $ini[$name] = ini_get($name);
        }

        return json_encode(['method' => $method, 'target' => $target, 'case' => self::get($number), 'ini' => $ini], JSON_THROW_ON_ERROR);
    }

    /**
     * The message of the throw of the case, `boom` for `true`, or null where it throws not.
     *
     * @param  array<string, mixed>  $case
     */
    public static function throws(array $case): ?string
    {
        $throws = $case['throws'] ?? false;
        assert(is_bool($throws) || is_string($throws));

        return match ($throws) {
            false => null,
            true => 'boom',
            default => $throws,
        };
    }

    /**
     * Sets the ini that the case names under `set`, as a script does with `ini_set()`.
     *
     * @param  array<string, mixed>  $case
     */
    public static function set(array $case): void
    {
        $set = $case['set'] ?? [];
        assert(is_array($set));

        foreach ($set as $name => $value) {
            assert(is_string($name) && is_string($value));
            ini_set($name, $value);
        }
    }

    public static function handler(Request $request): Response
    {
        parse_str($request->query(), $query);
        $number = is_string($query['case'] ?? null) ? (int) $query['case'] : -1;
        $case = self::get($number);

        if (($case['echo'] ?? false) === true) {
            return new Response(body: self::seen($request->method, $request->target, $number));
        }

        self::set($case);

        $throws = self::throws($case);

        if ($throws !== null && ! isset($case['chunks'])) {
            throw new RuntimeException($throws);
        }

        $status = $case['status'] ?? 200;
        $headers = $case['headers'] ?? [];
        assert(is_int($status) && is_array($headers));
        /** @var array<string, list<string>> $headers */
        if (isset($case['file'])) {
            $file = (string) tempnam(sys_get_temp_dir(), 'frankenphp');
            file_put_contents($file, self::bytes($case['file']));

            return new Response($status, $headers, file: $file, deleteFile: true);
        }

        if (isset($case['chunks'])) {
            assert(is_array($case['chunks']));
            $chunks = array_map(self::bytes(...), $case['chunks']);

            return new Response($status, $headers, (static function () use ($chunks, $throws): Generator {
                yield from $chunks;

                if ($throws !== null) {
                    throw new RuntimeException($throws);
                }
            })());
        }

        return new Response($status, $headers, self::bytes($case['body'] ?? ''));
    }
}
