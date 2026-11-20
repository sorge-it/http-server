<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer;

use SorgeIt\HttpServer\Internal\Caddy;
use SorgeIt\HttpServer\Internal\Clock;
use ValueError;

/**
 * What the server accepts, and how long it waits. Each value has one meaning: null takes the
 * default, and 0 turns the limit off. A negative value, or a timeout that is not finite or above
 * 1e8 s, throws a `ValueError`.
 *
 * @api
 */
final readonly class Limits
{
    /**
     * Bytes of a header section: the request line and the fields, each with its CRLF, and the
     * empty line. A larger header section is rejected with 431. 0: no limit.
     */
    public int $maxHead;

    /**
     * Bytes of a request body; one more byte is rejected with 413. 0: no limit.
     */
    public int $maxBody;

    /**
     * Seconds from the last byte written until the next request; then the connection closes
     * without a response. 0: no timeout.
     */
    public float $idleTimeout;

    /**
     * Seconds to receive a header section: for the first request, counted from when the connection
     * is accepted; for a later one, from its first byte after the previous response. A partial
     * header section then gets a 400. Request bodies have no timeout. 0: no timeout.
     */
    public float $headerTimeout;

    /**
     * The defaults: 16 KiB for the header section, like Caddy; 10 s for it and 120 s idle, as measured
     * on a production FrankenPHP site behind a TLS proxy; no limit for the body.
     */
    public function __construct(
        ?int $maxHead = null,
        ?int $maxBody = null,
        ?float $idleTimeout = null,
        ?float $headerTimeout = null,
    ) {
        foreach (['maxHead' => [1, $maxHead], 'maxBody' => [2, $maxBody]] as $name => [$position, $value]) {
            if ($value !== null && $value < 0) {
                throw new ValueError(sprintf('%s::__construct(): Argument #%d ($%s) must be greater than or equal to 0', self::class, $position, $name));
            }
        }

        foreach (['idleTimeout' => [3, $idleTimeout], 'headerTimeout' => [4, $headerTimeout]] as $name => [$position, $seconds]) {
            // NAN is not a number of seconds, and INF has no nanoseconds.
            if ($seconds !== null && (is_nan($seconds) || $seconds < 0 || $seconds > Clock::MAX_SECONDS)) {
                throw new ValueError(sprintf('%s::__construct(): Argument #%d ($%s) must be between 0 and %d', self::class, $position, $name, Clock::MAX_SECONDS));
            }
        }

        $this->maxHead = $maxHead ?? Caddy::MAX_HEADER_BYTES;
        $this->maxBody = $maxBody ?? 0;
        $this->idleTimeout = $idleTimeout ?? Caddy::IDLE_TIMEOUT;
        $this->headerTimeout = $headerTimeout ?? Caddy::HEADER_TIMEOUT;
    }
}
