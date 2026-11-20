<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

/**
 * The body of a response that is a file, while the server writes it. The file is opened when its
 * turn comes, so a hundred answers in one connection do not hold a hundred files open, and no more
 * bytes are sent than the `Content-Length` named.
 *
 * @internal
 */
final class FileBody
{
    /**
     * @var resource|null
     */
    private mixed $handle = null;

    /**
     * @param  int  $left  the bytes still to send
     * @param  bool  $chunked  each piece goes in a chunk
     */
    public function __construct(
        private readonly string $path,
        private int $left,
        private readonly bool $delete,
        private readonly bool $chunked,
    ) {}

    /**
     * Removes a file made for a response, as `close()` does, for a file that is not sent.
     */
    public static function discard(string $path, bool $delete): void
    {
        if ($delete && is_file($path)) {
            Quietly::run(static fn (): bool => unlink($path));
        }
    }

    /**
     * The next piece, in a chunk where the body is chunked, or null where the file shrank or
     * cannot be read any more. The file is opened for the first piece, and closed after the last.
     */
    public function next(): ?string
    {
        $length = min(1 << 16, $this->left);
        $path = $this->path;
        $this->handle ??= Quietly::run(static fn (): mixed => fopen($path, 'rb')) ?: null;
        $handle = $this->handle;
        $piece = is_resource($handle) && $length > 0 ? Quietly::run(static fn (): string|false => fread($handle, $length)) : false;

        if ($piece === false || $piece === '') {
            return null;
        }

        // A file that grew sends only the bytes its length named.
        $this->left -= strlen($piece);

        if ($this->left === 0) {
            $this->close();
        }

        return $this->chunked ? dechex(strlen($piece))."\r\n".$piece."\r\n" : $piece;
    }

    /**
     * The queue replaces the file with its last piece; an earlier piece goes in front of the file,
     * which stays for the next piece.
     */
    public function done(): bool
    {
        return $this->left === 0;
    }

    /**
     * Closes the file once it is sent or not needed, and removes one made for the response. A
     * file the process may not remove stays: its warning would make `tick()` throw under an error
     * handler that turns a warning into an exception.
     */
    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
        self::discard($this->path, $this->delete);
    }
}
