<?php

declare(strict_types=1);

/*
 * A guard for each row out of Scope in the README, in table order. `row` starts the row.
 * `absent` maps a pattern that `src` must not match to a line it finds. `probes` get the 400 of
 * Scope. `none` says why a row has no check.
 */
return [
    [
        'row' => 'The size of a buffer',
        'absent' => [
            '/copy_?buf_?pool_?size/i' => 'private const int COPY_BUF_POOL_SIZE = 32768;',
            '/\bCOPY\b/' => 'public const int COPY = 32 << 10;',
            '/\b32\s*<<\s*10\b/' => '$length = min(32 << 10, $this->left);',
            '/\b1\s*<<\s*15\b/' => '$length = min(1 << 15, $this->left);',
            '/\b32768\b/' => '$length = min(32768, $this->left);',
            '/\?\s*(?:Go::|self::)?SNIFF\b/' => '$length = $this->handle === null ? Go::SNIFF : 1 << 16;',
            '/\bJOIN\b/' => '$size > self::JOIN => [dechex($size)."\r\n", $chunk, "\r\n"],',
        ],
    ],
    [
        'row' => 'The trailers of a response',
        'absent' => [
            '/BAD_TRAILERS/' => 'private const array BAD_TRAILERS = [];',
            '/function trailers\b/' => 'private static function trailers(array $header): string',
            '/finalTrailers/i' => '$last .= self::finalTrailers($header);',
            '/declareTrailer/i' => '$names = Header::declareTrailer($values);',
            '/ValidTrailerHeader/i' => 'if (! self::validTrailerHeader($name)) {',
            '/"0\\\\r\\\\n"\s*\./' => '$last = $chunked ? "0\r\n".$trailers."\r\n" : \'\';',
        ],
    ],
    [
        'row' => 'How the reference uses memory',
        'none' => 'The server uses memory of its own. No pattern tells a copy of the reference from its own use.',
    ],
    [
        'row' => 'Timing that does not change the result',
        'none' => 'The server measures time of its own. No pattern tells a copy of the reference from its own timing.',
    ],
    [
        'row' => 'The exact place of a limit',
        'absent' => [
            '/\+\s*4096\b/' => '$left = $this->maxHead + 4096;',
            '/\+=\s*4096\b/' => '$left += 4096;',
            '/\b4096\s*\+/' => '$left = 4096 + $this->maxHead;',
            '/slop/i' => '$left = $this->maxHead + Go::SLOP;',
        ],
    ],
    [
        'row' => 'h2c, HTTP/2, HTTP/3 and TLS',
        'absent' => [
            '/stream_socket_enable_crypto/' => 'stream_socket_enable_crypto($client, true);',
            '/\bssl:\/\//' => "stream_socket_server('ssl://127.0.0.1:0');",
            '/\btls:\/\//' => "stream_socket_server('tls://127.0.0.1:0');",
            '/\bsslv[23]:\/\//' => "stream_socket_server('sslv3://127.0.0.1:0');",
            '/\btlsv1\.[0-3]:\/\//' => "stream_socket_server('tlsv1.3://127.0.0.1:0');",
            '/\budp:\/\//' => "stream_socket_server('udp://127.0.0.1:0');",
            '/SOCK_DGRAM/' => 'socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);',
            '/Alt-Svc/i' => "\$header['Alt-Svc'] = ['h3=\":443\"'];",
        ],
        'probes' => ["PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"],
    ],
    [
        'row' => 'A request that no browser sends',
        'absent' => ['/[\'"]cookie[\'"]/i' => "return implode(\$name === 'cookie' ? '; ' : ', ', \$values);"],
        // Each under the rule of A valid request in the README that names its case.
        'probes' => [
            // 1. The request line: the method, the target, the version.
            "GET http://a/ HTTP/1.1\r\nHost: a\r\n\r\n",
            "GET a:80 HTTP/1.1\r\nHost: a\r\n\r\n",
            "OPTIONS * HTTP/1.1\r\nHost: a\r\n\r\n",
            "GET  HTTP/1.1\r\nHost: a\r\n\r\n",
            "GET /a#b HTTP/1.1\r\nHost: a\r\n\r\n",
            "GET /a\x7Fb HTTP/1.1\r\nHost: a\r\n\r\n",
            "GET /\xC3\xA4 HTTP/1.1\r\nHost: a\r\n\r\n",
            "CONNECT a:80 HTTP/1.1\r\nHost: a:80\r\n\r\n",
            "connect / HTTP/1.1\r\nHost: a\r\n\r\n",
            "TRACE / HTTP/1.1\r\nHost: a\r\n\r\n",
            "trace / HTTP/1.1\r\nHost: a\r\n\r\n",
            "TRACK / HTTP/1.1\r\nHost: a\r\n\r\n",
            "G(T / HTTP/1.1\r\nHost: a\r\n\r\n",
            "GET  / HTTP/1.1\r\nHost: a\r\n\r\n",
            "GET\t/ HTTP/1.1\r\nHost: a\r\n\r\n",
            "GET / HTTP/1.0\r\n\r\n",
            "GET / http/1.1\r\nHost: a\r\n\r\n",
            "GET / HTTP/1.2\r\nHost: a\r\n\r\n",
            "GET / HTTP/2.0\r\nHost: a\r\n\r\n",
            // 2. The ends of the lines, obs-fold, and an empty line before the request.
            "GET / HTTP/1.1\nHost: a\n\n",
            "GET / HTTP/1.1\r\nHost: a\r\nX: 1\n\r\n",
            "GET / HTTP/1.1\rHost: a\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a\r\nX: a\rb\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a\r\nX: 1\r\n 2\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a\r\nX: 1\r\n\t2\r\n\r\n",
            "\r\nGET / HTTP/1.1\r\nHost: a\r\n\r\n",
            // 3. A field line.
            "GET / HTTP/1.1\r\nHost: a\r\nX : 1\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a\r\nX(: 1\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a\r\n: 1\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a\r\nX: a\x00b\r\n\r\n",
            // 4. `Host`.
            "GET / HTTP/1.1\r\n\r\n",
            "GET / HTTP/1.1\r\nHost:\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a\r\nHost: a\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: u@a\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a b\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a%20b\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a:123456\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: [1.2.3.4]\r\n\r\n",
            // 5. `Content-Length`.
            "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 1\r\nContent-Length: 1\r\n\r\nx",
            "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 1, 1\r\n\r\nx",
            "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: +1\r\n\r\nx",
            "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 01\r\n\r\nx",
            "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 0x1\r\n\r\nx",
            // 6. `Transfer-Encoding`, `Expect`, `Trailer` and `TE`.
            "POST / HTTP/1.1\r\nHost: a\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n",
            "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 4\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n",
            "POST / HTTP/1.1\r\nHost: a\r\nTransfer-Encoding: identity\r\n\r\n",
            "POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 1\r\nExpect: 100-continue\r\n\r\nx",
            "GET / HTTP/1.1\r\nHost: a\r\nTrailer: X\r\n\r\n",
            "GET / HTTP/1.1\r\nHost: a\r\nTE: trailers\r\n\r\n",
        ],
    ],
    [
        'row' => 'A listener on an address that is not loopback',
        'none' => 'No pattern finds a missing check, and each probe goes to loopback. `new Server()` refuses such an address with `ListenFailed`, as the test "refuses an address that is not loopback with ListenFailed before it binds the port" of `Transport` shows.',
    ],
    [
        'row' => "The routes of FrankenPHP's `php_server`",
        'absent' => [
            '/index\.php/' => "\$path .= 'index.php';",
            '/try_files/' => "\$try_files = ['{path}', 'index.php'];",
            '/gzencode/' => '$body = gzencode($body);',
            '/zlib_encode/' => '$body = zlib_encode($body, ZLIB_ENCODING_DEFLATE);',
            '/brotli/i' => '$body = brotli_compress($body);',
            '/zstd/i' => '$body = zstd_compress($body);',
        ],
    ],
    [
        'row' => 'FrankenPHP\'s `$_SERVER`',
        'absent' => [
            '/\$_SERVER/' => "\$_SERVER['REMOTE_ADDR'] = \$peer;",
            '/output_buffering/' => "ini_get('output_buffering');",
            '/implicit_flush/' => "ini_get('implicit_flush');",
            '/ob_start/' => 'ob_start();',
        ],
    ],
    [
        'row' => 'A limit against a client',
        'absent' => [
            '/max_connections/i' => 'if (count($this->connections) >= self::MAX_CONNECTIONS) {',
            '/maxConnections/i' => 'if (count($this->connections) >= $this->maxConnections) {',
            '/max_clients/i' => 'if (count($this->clients) >= self::MAX_CLIENTS) {',
            '/maxClients/i' => 'if (count($this->clients) >= $this->maxClients) {',
        ],
    ],
    [
        'row' => 'A limit for the handler',
        'absent' => [
            '/set_time_limit/' => 'set_time_limit(30);',
            '/max_execution_time/' => "ini_set('max_execution_time', '30');",
            '/memory_limit/' => "ini_set('memory_limit', '128M');",
        ],
    ],
    [
        'row' => 'File descriptors exhausted',
        'absent' => [
            '/tempDelay/i' => '$tempDelay = min($tempDelay * 2, 1.0);',
            '/backoff/i' => 'usleep($this->backoff);',
        ],
    ],
];
