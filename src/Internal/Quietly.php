<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use Closure;

/**
 * Runs a system call whose warning is an expected answer, such as a write to a closed connection.
 * Test runners report a warning even under `@`. The caller gets the warning, for a call where a
 * warning may also be a fault.
 *
 * @internal
 */
final class Quietly
{
    /**
     * @template T
     *
     * @param  Closure(): T  $call
     *
     * @param-out string|null  $warning
     *
     * @return T
     */
    public static function run(Closure $call, ?string &$warning = null): mixed
    {
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
