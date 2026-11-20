<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

/**
 * The headers of an answer as Go `net/http` keeps them: a map of each canonical name to its values,
 * and the field lines Go writes of it.
 *
 * @internal
 */
final class Header
{
    /**
     * The headers of the handler as `Header.Add()` of Go adds each value: names that differ only in
     * their case are one header.
     *
     * @param  array<string, string|array<string>>  $headers
     * @return array<string, list<string>>
     */
    public static function of(array $headers): array
    {
        $map = [];

        foreach ($headers as $name => $values) {
            foreach ((array) $values as $value) {
                $map[self::canonical($name)][] = $value;
            }
        }

        return $map;
    }

    /**
     * The field lines as `Header.WriteSubset()` of Go writes them: sorted by name, without a name
     * that is not a token, as it could add a line; in a value, CR and LF become spaces, and spaces and
     * tabs at its ends go.
     *
     * @param  array<array-key, list<string>>  $header
     */
    public static function lines(array $header): string
    {
        ksort($header, SORT_STRING);
        $lines = '';

        foreach ($header as $name => $values) {
            $name = (string) $name;

            if ($name === '' || strspn($name, Grammar::TOKEN) !== strlen($name)) {
                continue;
            }

            foreach ($values as $value) {
                $lines .= $name.': '.trim(strtr($value, "\r\n", '  '), " \t")."\r\n";
            }
        }

        return $lines;
    }

    /**
     * `CanonicalMIMEHeaderKey()` of Go: a name of token bytes in lower case, with an upper-case
     * letter at its start and after each hyphen. Go keeps another name as it is.
     */
    public static function canonical(string $name): string
    {
        return strspn($name, Grammar::TOKEN) === strlen($name) ? ucwords(strtolower($name), '-') : $name;
    }
}
