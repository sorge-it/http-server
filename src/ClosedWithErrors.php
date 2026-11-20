<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer;

use LogicException;
use Throwable;

/**
 * Thrown by `close()` for errors that no `onError` callback received: a handler exception, or a
 * request whose body the server was still waiting for when it closed the connection. This way the
 * test sees every error it did not handle.
 *
 * @api
 */
final class ClosedWithErrors extends LogicException
{
    /**
     * @param  non-empty-list<Throwable>  $errors  in the order the server reported them; the first
     *                                             is also the previous exception
     * @param  Request  $request  the request of the first error
     */
    public function __construct(public readonly array $errors, Request $request)
    {
        parent::__construct(sprintf(
            'The server closed with %d %s that no onError callback received; ClosedWithErrors::$errors lists each one. A test that expects an error passes onError to the Server. The first, from %s %s: %s',
            count($errors),
            count($errors) === 1 ? 'error' : 'errors',
            $request->method,
            $request->target,
            $errors[0]->getMessage(),
        ), previous: $errors[0]);
    }
}
