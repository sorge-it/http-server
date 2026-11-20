<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer;

use ValueError;

/**
 * The handler's response. The server sends it as the pinned FrankenPHP build sends the response of
 * a script that sets the headers and the status, then echoes the body.
 *
 * @api
 */
final readonly class Response
{
    /**
     * @param  int  $status  0 sets none, so a `Location` gives 302, as in PHP; outside 100 to 999,
     *                       the server sends 500, as FrankenPHP does
     * @param  array<string, string|list<string>>  $headers
     * @param  string|iterable<string>  $body  an iterable is a streamed body: each string goes out
     *                                         as it comes, so the body need not be in memory whole
     * @param  bool  $deleteFile  for a file made for this response
     */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string|iterable $body = '',
        public ?string $file = null,
        public bool $deleteFile = false,
    ) {
        $fault = $this->fault($headers);

        if ($fault !== null) {
            throw new ValueError(sprintf('%s::__construct(): Argument #2 ($headers) %s', self::class, $fault));
        }

        if ($body !== '' && $file !== null) {
            throw new ValueError(sprintf('%s::__construct(): Argument #3 ($body) must be empty when Argument #4 ($file) is given', self::class));
        }
    }

    /**
     * What makes the headers ones Go cannot hold, if anything. An integer key is that of a list,
     * such as `['X: y']`, not a header name.
     *
     * @param  array<mixed>  $headers  as the caller gave them, whatever their documented type
     */
    private function fault(array $headers): ?string
    {
        foreach ($headers as $name => $values) {
            if (is_int($name)) {
                return 'has the key '.$name.', which is not a header name';
            }

            // Not `(array)`: it makes an object the list of its properties.
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (! is_string($value)) {
                    return 'has a value of '.addcslashes($name, "\0..\37\177..\377").' that is not a string';
                }
            }
        }

        return null;
    }
}
