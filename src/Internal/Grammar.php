<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

/**
 * The bytes the fields of RFC 9110 are made of, for the requests the server reads and the answers
 * it writes.
 *
 * @internal
 */
final class Grammar
{
    /**
     * The bytes of a token, RFC 9110 section 5.6.2. Two readers can read a field name of other
     * bytes in two ways.
     */
    public const string TOKEN = "!#$%&'*+-.^_`|~0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";

    /**
     * The control bytes a field value may not hold, RFC 9110 section 5.5: all but the tab.
     */
    public const string CONTROL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /**
     * Whether the lines of a field, as one list of RFC 9110 section 5.6.1, have the element in any
     * case. For `Connection`, section 7.6.1, `x-closer` holds close, but is not close.
     *
     * @param  list<string>  $lines
     */
    public static function listHas(array $lines, string $element): bool
    {
        return in_array($element, array_map(trim(...), explode(',', strtolower(implode(',', $lines)))), true);
    }

    /**
     * Whether the value holds the token in any case, with a space, comma, tab or an end on each
     * side: `hasToken()` of Go `net/http`, so `foo close` holds close.
     */
    public static function hasToken(string $value, string $token): bool
    {
        return preg_match('/(?:^|[ ,\t])'.preg_quote($token, '/').'(?:[ ,\t]|$)/iD', $value) === 1;
    }
}
