<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer;

use RuntimeException;

/**
 * The server cannot listen on the address it was given, as when another process holds the port, or
 * the address is not loopback.
 *
 * @api
 */
final class ListenFailed extends RuntimeException {}
