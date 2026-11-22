<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Tests\FrankenPhp;

use RuntimeException;
use SorgeIt\HttpServer\Internal\Answer;
use SorgeIt\HttpServer\Limits;

/**
 * FrankenPHP with `app/index.php` or `go/index.php`: one process for each php.ini, app and set of
 * options, at most 8 at a time. The binary is that of `composer frankenphp:install`, which this
 * class runs first, or that of `FRANKENPHP_BINARY`, as the weekly job sets it.
 */
final class FrankenPhpReference
{
    private static ?self $instance = null;

    /**
     * The failure of `composer frankenphp:install` in this process: each later test gets it again
     * instead of another download.
     */
    private static ?RuntimeException $failure = null;

    /**
     * @var array<string, array{resource, int}> the process and its port, by php.ini, app, options
     */
    private array $servers = [];

    private function __construct(public readonly string $binary, private readonly string $directory) {}

    public static function get(): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        if (self::$failure instanceof RuntimeException) {
            throw self::$failure;
        }

        $binary = getenv('FRANKENPHP_BINARY');

        if (! is_string($binary) || $binary === '') {
            [$status, $output] = self::run([PHP_BINARY, __DIR__.'/install-frankenphp.php']);

            if ($status !== 0) {
                throw self::$failure = new RuntimeException('composer frankenphp:install failed: '.$output);
            }

            $binary = dirname(__DIR__, 2).'/.frankenphp/frankenphp';
        }

        $directory = sys_get_temp_dir().'/http-server-frankenphp-'.getmypid();

        if (! is_dir($directory) && ! mkdir($directory, 0o700, true)) {
            throw new RuntimeException('No directory '.$directory);
        }

        self::$instance = new self($binary, $directory);
        register_shutdown_function(self::$instance->stop(...));

        return self::$instance;
    }

    /**
     * What the server on the port wrote for the bytes within the seconds, as `exchange()` gives it.
     * With `end`, the client sends its end after the last byte.
     */
    public static function talk(int $port, string $bytes, float $within = 5.0, bool $end = false): string
    {
        set_error_handler(static fn (): bool => true);

        try {
            $client = stream_socket_client('tcp://127.0.0.1:'.$port, $code, $message, 5);
        } finally {
            restore_error_handler();
        }

        if ($client === false) {
            throw new RuntimeException('No connection to 127.0.0.1:'.$port.': '.$message);
        }

        // A reset of the server makes a read warn; the bytes until then count.
        set_error_handler(static fn (): bool => true);

        try {
            // Writes and reads in turn, so a large answer before the last byte blocks neither side.
            stream_set_blocking($client, false);
            $received = '';
            $start = hrtime(true) / 1e9;
            $ended = ! $end;

            while (! feof($client) && hrtime(true) / 1e9 - $start < $within) {
                if ($bytes === '' && ! $ended) {
                    stream_socket_shutdown($client, STREAM_SHUT_WR);
                    $ended = true;
                }

                $read = [$client];
                $write = $bytes === '' ? [] : [$client];
                $none = null;
                stream_select($read, $write, $none, 0, 100_000);

                if ($write !== []) {
                    $written = fwrite($client, $bytes);
                    $bytes = $written === false ? '' : substr($bytes, $written);
                }

                $received .= (string) fread($client, 1 << 20);
            }

            return $received.(feof($client) ? "\n<closed>" : '');
        } finally {
            restore_error_handler();
            fclose($client);
        }
    }

    /**
     * The php.ini with the values, in a directory of its own, as `PHPRC` names a directory.
     *
     * @param  array<string, string>  $ini
     */
    public function iniFile(array $ini): string
    {
        ksort($ini);
        $lines = '';

        foreach ($ini as $name => $value) {
            $lines .= $name.' = "'.$value."\"\n";
        }

        $directory = $this->directory.'/ini-'.md5($lines);

        if (! is_dir($directory)) {
            mkdir($directory);
            file_put_contents($directory.'/php.ini', $lines);
        }

        return $directory.'/php.ini';
    }

    /**
     * What FrankenPHP with the php.ini wrote for the bytes until it closed, within 5 seconds, and
     * `<closed>` if it closed. The client sends no end: FrankenPHP would stop the script.
     *
     * @param  array<string, string>  $ini
     */
    public function exchange(string $bytes, array $ini): string
    {
        return self::talk($this->port($ini, 'app'), $bytes);
    }

    /**
     * What FrankenPHP with `go/index.php`, the limits and the values of `Cases::INI` in this
     * process wrote for the bytes. Without `within`, it closes 0.1 s after the last answer, and
     * the client ends only without bytes; with `within`, `idleTimeout` counts.
     */
    public function answers(string $bytes, Limits $limits, ?float $within = null): string
    {
        // 0 means no limit here, and a negative time means no limit in Caddy. Caddy takes its default for 0.
        $off = static fn (float $seconds): string => $seconds === 0.0 ? '-1s' : sprintf('%.6Fs', $seconds);
        $server = sprintf(
            "\tservers {\n\t\tmax_header_size %d\n\t\ttimeouts {\n\t\t\tread_header %s\n\t\t\tidle %s\n\t\t}\n\t}\n",
            $limits->maxHead === 0 ? 1 << 40 : $limits->maxHead,
            $off($limits->headerTimeout),
            $within === null ? '100ms' : $off($limits->idleTimeout),
        );

        $ini = array_map(static fn (string $name): string => (string) ini_get($name), array_combine(array_keys(Cases::INI), array_keys(Cases::INI)));

        // `maxBody` as `request_body` of Caddy, with an error route that answers as `http.Error()`.
        $site = $limits->maxBody === 0 ? '' : sprintf("\trequest_body {\n\t\tmax_size %d\n\t}\n\thandle_errors 413 {\n\t\theader Connection close\n\t\theader X-Content-Type-Options nosniff\n\t\trespond \"%s\n\" 413\n\t}\n", $limits->maxBody, Answer::TEXTS[413]);

        // Without bytes, no script runs that the end of the client could stop.
        $answers = self::talk($this->port($ini, 'go', $server, $site), $bytes, $within ?? 10.0, $bytes === '' && $within === null);

        // The `header` of the error route puts the close among the headers of the handler. Go
        // writes the close of `requestTooLarge()` after them, net/http/server.go:1580. A 413 can
        // follow the body of the answer before it on the same line.
        return $site === '' ? $answers : (string) preg_replace('/(HTTP\/1\.1 413 [^\r\n]*\r\n(?:[^\r\n]+\r\n)*?)Connection: close\r\n((?:[^\r\n]+\r\n)*)\r\n/', "\$1\$2Connection: close\r\n\r\n", $answers);
    }

    /**
     * The first lines of the version of the binary, such as `FrankenPHP v1.14.0 PHP 8.6.0 ...`.
     */
    public function version(): string
    {
        return trim(self::run([$this->binary, 'version'])[1]);
    }

    /**
     * The Go the binary is built with, such as `go1.27.4`, from the first line of `build-info`.
     */
    public function goVersion(): string
    {
        return preg_match('/^go\t(\S+)/', self::run([$this->binary, 'build-info'])[1], $match) === 1 ? $match[1] : '';
    }

    public function stop(): void
    {
        foreach ($this->servers as [$process]) {
            $this->kill($process);
        }

        $this->servers = [];
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    /**
     * @param  list<string>  $command
     * @return array{int, string} the status, and what it wrote, its errors after its output
     */
    private static function run(array $command): array
    {
        $errors = tmpfile();
        assert(is_resource($errors));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => $errors], $pipes);

        if (! is_resource($process)) {
            return [-1, ''];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        rewind($errors);

        return [$status, $output.stream_get_contents($errors)];
    }

    /**
     * Ends the server with SIGKILL. On SIGTERM, FrankenPHP waits without end for a script that
     * still reads the body of a client that keeps its connection open, and `proc_close()` with it.
     *
     * @param  resource  $process
     */
    private function kill(mixed $process): void
    {
        proc_terminate($process, 9);
        proc_close($process);
    }

    /**
     * The port of FrankenPHP with the php.ini, the app, and the options of the server and of the
     * site in the Caddyfile, started on the first call.
     *
     * @param  array<string, string>  $ini
     */
    private function port(array $ini, string $app, string $server = '', string $site = ''): int
    {
        $file = $this->iniFile($ini);
        $key = $file.' '.$app.' '.$server.' '.$site;

        if (isset($this->servers[$key])) {
            return $this->servers[$key][1];
        }

        // The fuzz test takes limits at random; a server for each would hold hundreds of threads.
        if (count($this->servers) >= 8) {
            [$process] = array_shift($this->servers);
            $this->kill($process);
        }

        $free = stream_socket_server('tcp://127.0.0.1:0');
        assert(is_resource($free));
        $port = (int) substr((string) stream_socket_get_name($free, false), strrpos((string) stream_socket_get_name($free, false), ':') + 1);
        fclose($free);
        $directory = dirname($file);
        $caddyfile = $directory.'/Caddyfile-'.$port;
        file_put_contents($caddyfile, sprintf("{\n\tadmin off\n\tauto_https off\n\tpersist_config off\n%s\tfrankenphp {\n\t\tnum_threads 2\n\t}\n}\nhttp://:%d {\n\tbind 127.0.0.1\n\troot \"%s\"\n%s\tphp_server\n}\n", $server, $port, __DIR__.'/'.$app, $site));

        // The server outlives the test that starts it. A socket of that test it inherits stays
        // open after the test closed it, and the client of the test waits for an end that never comes.
        $inherited = array_filter(array_map(intval(...), scandir('/dev/fd') ?: []), static fn (int $descriptor): bool => $descriptor > 2);
        $environment = [...getenv(), 'PHPRC' => $directory, 'PHP_INI_SCAN_DIR' => '', 'XDG_DATA_HOME' => $directory, 'XDG_CONFIG_HOME' => $directory];
        $process = proc_open([$this->binary, 'run', '--config', $caddyfile, '--adapter', 'caddyfile'], [['file', '/dev/null', 'r'], ['file', $directory.'/log-'.$port, 'w'], ['file', $directory.'/log-'.$port, 'a']] + array_fill_keys($inherited, ['file', '/dev/null', 'r']), $pipes, $directory, $environment);

        if (! is_resource($process)) {
            throw new RuntimeException('FrankenPHP did not start.');
        }

        $this->servers[$key] = [$process, $port];
        $start = hrtime(true) / 1e9;
        // Each refused connection before FrankenPHP listens warns.
        set_error_handler(static fn (): bool => true);

        try {
            while (($client = stream_socket_client('tcp://127.0.0.1:'.$port, $code, $message, 0.1)) === false) {
                if (! proc_get_status($process)['running'] || hrtime(true) / 1e9 - $start > 10) {
                    throw new RuntimeException('FrankenPHP does not listen on '.$port.': '.file_get_contents($directory.'/log-'.$port));
                }

                usleep(10_000);
            }
        } finally {
            restore_error_handler();
        }

        fclose($client);

        return $port;
    }
}
