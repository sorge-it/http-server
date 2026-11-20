<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

use RuntimeException;

/**
 * A request the server does not take: it answers with the status and closes the connection.
 *
 * @internal
 */
final class BadRequest extends RuntimeException
{
    /**
     * @param  400|431  $status
     * @param  int|null  $offset  bytes of the client read up to the fault
     */
    public function __construct(public readonly int $status, public readonly ?int $offset = null)
    {
        parent::__construct($status.' '.Answer::TEXTS[$status]);
    }

    /**
     * The fault with the offset at which the parser stopped.
     */
    public function at(int $offset): self
    {
        return new self($this->status, $offset);
    }

    public function answer(): string
    {
        return 'HTTP/1.1 '.$this->getMessage()."\r\nContent-Type: text/plain; charset=utf-8\r\nConnection: close\r\n\r\n".$this->getMessage();
    }
}
