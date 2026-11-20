<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Tests\GoNetHttp;

use RuntimeException;
use SorgeIt\HttpServer\Limits;

/**
 * The servers of Go net/http of `reference/main.go`, with the Go that the fixture names. Each case
 * goes to Go at the time of the test, so the fixture holds no answer of Go.
 */
final class GoReference
{
    public const string FIXTURE = __DIR__.'/../Fixtures/go-net-http';

    private static ?self $instance = null;

    /** @var array<string, string> */
    private array $addresses = [];

    private ?string $stopped = null;

    /**
     * @param  resource  $process
     * @param  resource  $input
     * @param  resource  $output
     */
    private function __construct(private readonly string $binary, private readonly mixed $process, private readonly mixed $input, private readonly mixed $output) {}

    /**
     * The version of the Go whose test files the fixture holds, such as `go1.27.4`.
     */
    public static function version(): string
    {
        return (string) strtok((string) file_get_contents(self::FIXTURE.'/go/VERSION'), "\n");
    }

    /**
     * The reference, built with that Go and started once for each process.
     */
    public static function get(): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $binary = sys_get_temp_dir().'/http-server-go-reference-'.getmypid();
        self::go(['build', '-o', $binary, './reference']);

        // The reference outlives the test that starts it. A socket of that test it inherits stays
        // open after the test closed it, and the client of the test waits for an end that never comes.
        $inherited = array_filter(array_map(intval(...), scandir('/dev/fd') ?: []), static fn (int $descriptor): bool => $descriptor > 2);
        $process = proc_open([$binary], [['pipe', 'r'], ['pipe', 'w'], STDERR] + array_fill_keys($inherited, ['file', '/dev/null', 'r']), $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('The reference of Go did not start.');
        }

        self::$instance = new self($binary, $process, $pipes[0], $pipes[1]);
        register_shutdown_function(self::$instance->stop(...));

        return self::$instance;
    }

    /**
     * What the command of that Go wrote, run in this directory: the Go of `composer go:install` in
     * `.go/go`, or else that on the PATH.
     *
     * @param  list<string>  $arguments
     */
    public static function go(array $arguments): string
    {
        $version = self::version();
        $repository = dirname(__DIR__, 2);
        // Nothing of the user's Go and nothing outside `.go/`: no settings of `go env -w`, no flags,
        // and the caches and the telemetry of the go command in a home there.
        $home = $repository.'/.go/home';
        $environment = [...getenv(), 'HOME' => $home, 'XDG_CONFIG_HOME' => $home.'/.config', 'XDG_CACHE_HOME' => $home.'/.cache', 'GOTOOLCHAIN' => 'local', 'CGO_ENABLED' => '0', 'GOENV' => 'off', 'GOFLAGS' => '', 'GOTELEMETRY' => 'off', 'GOCACHE' => $repository.'/.go/cache', 'GOPATH' => $repository.'/.go/path'];
        $go = is_file($repository.'/.go/go/bin/go') ? $repository.'/.go/go/bin/go' : 'go';
        [$status, $found] = self::run([$go, 'env', 'GOVERSION'], $environment);

        if ($status !== 0 || trim($found) !== $version) {
            throw new RuntimeException(sprintf('GoNetHttpTest needs Go %s, but found %s. Run `composer go:install`.', $version, $status === 0 ? trim($found).' at '.$go : 'no Go'));
        }

        [$status, $output, $errors] = self::run([$go, ...$arguments], $environment);

        if ($status !== 0) {
            throw new RuntimeException(sprintf('go %s failed: %s', implode(' ', $arguments), $errors));
        }

        return $output;
    }

    /**
     * What Go with the handler `show` and the limits wrote for the bytes and the end of a client on
     * the host, until the close, or in `within` seconds without the end, then `<closed>` if it
     * closed. A piece of 0 reads the bytes as they come; a `maxValues` of null is that of Go.
     */
    public function exchange(string $bytes, Limits $limits, int $piece = 0, ?float $within = null, string $host = '127.0.0.1', ?int $maxValues = null): string
    {
        $client = $this->connect($limits, $piece, $host, $maxValues);
        stream_set_blocking($client, false);
        $received = '';
        $start = hrtime(true) / 1e9;
        set_error_handler(static fn (): bool => true);

        try {
            if ($bytes === '' && $within === null) {
                stream_socket_shutdown($client, STREAM_SHUT_WR);
            }

            while (! feof($client)) {
                if ($within !== null && hrtime(true) / 1e9 - $start > $within) {
                    break;
                }

                if (hrtime(true) / 1e9 - $start > 5) {
                    throw new RuntimeException($this->stopped() ?? 'Go did not close the connection within 5 seconds.');
                }

                $read = [$client];
                $write = $bytes === '' ? [] : [$client];
                $except = null;
                stream_select($read, $write, $except, 0, $within === null ? 1_000_000 : 10_000);

                if ($write !== []) {
                    $written = fwrite($client, $bytes);
                    // Go closed the connection; it reads no more.
                    $bytes = $written === false ? '' : substr($bytes, $written);

                    if ($bytes === '' && $within === null) {
                        stream_socket_shutdown($client, STREAM_SHUT_WR);
                    }
                }

                $received .= (string) fread($client, 1 << 20);
            }

            // Without the end of the client, as `converse()` marks it.
            return $received.($within !== null && feof($client) ? "\n<closed>" : '');
        } finally {
            restore_error_handler();
            fclose($client);
        }
    }

    /**
     * Whether Go lingers after it answered the bytes and shut its end: it waits `rstAvoidanceDelay`
     * before it closes, so the client can still write; a closed socket answers a write with a reset.
     */
    public function lingers(string $bytes, Limits $limits): bool
    {
        $client = $this->connect($limits, 0);
        set_error_handler(static fn (): bool => true);

        try {
            fwrite($client, $bytes);
            stream_set_timeout($client, 5);

            while (! feof($client) && fread($client, 1 << 16) !== false) {
            }

            // The first write after a close gets the reset; the second fails on it.
            $first = fwrite($client, 'x');
            usleep(100_000);
            $second = fwrite($client, 'x');

            return $first === 1 && $second === 1;
        } finally {
            restore_error_handler();
            fclose($client);
        }
    }

    public function stop(): void
    {
        fclose($this->input);
        fclose($this->output);
        proc_close($this->process);
        unlink($this->binary);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @return array{int, string, string}
     */
    private static function run(array $command, array $environment): array
    {
        $errors = tmpfile();
        assert(is_resource($errors));
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => $errors], $pipes, __DIR__, $environment);

        if (! is_resource($process)) {
            return [-1, '', ''];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        rewind($errors);

        return [$status, $output, (string) stream_get_contents($errors)];
    }

    /**
     * A client on the host of the server of Go with the settings, which listens on the host.
     *
     * @return resource
     */
    private function connect(Limits $limits, int $piece, string $host = '127.0.0.1', ?int $maxValues = null): mixed
    {
        // The order main.go reads: MaxHeaderBytes, MaxHeaderValueCount, maxBody, ReadHeaderTimeout,
        // IdleTimeout, read size, host.
        $count = static fn (int $value): int => $value === 0 ? 1 << 40 : $value;
        // Go runs a negative time without end.
        $off = static fn (float $seconds): float => $seconds === 0.0 ? -1.0 : $seconds;
        $settings = sprintf('listen %d %d %d %F %F %d %s', $count($limits->maxHead), $maxValues === null ? 0 : $count($maxValues), $limits->maxBody ?: -1, $off($limits->headerTimeout), $off($limits->idleTimeout), $piece, $host);

        if (! isset($this->addresses[$settings])) {
            // The fuzz test takes limits at random; a server for each would hold thousands of ports.
            if (count($this->addresses) >= 16) {
                $this->command('close '.array_shift($this->addresses));
            }

            $this->addresses[$settings] = $this->command($settings);
        }

        $port = substr($this->addresses[$settings], strrpos($this->addresses[$settings], ':') + 1);
        $client = @stream_socket_client('tcp://'.(str_contains($host, ':') ? '['.$host.']' : $host).':'.$port, $code, $message, 5);

        if ($client === false) {
            throw new RuntimeException($this->stopped() ?? 'No connection to the reference of Go: '.$message);
        }

        return $client;
    }

    /**
     * The line the reference answers to the command, within 10 seconds.
     */
    private function command(string $command): string
    {
        // A reference that ended gives a broken pipe here; stopped() names why.
        set_error_handler(static fn (): bool => true);

        try {
            $written = fwrite($this->input, $command."\n");
        } finally {
            restore_error_handler();
        }

        $read = [$this->output];
        $none = null;

        if ($written === false || stream_select($read, $none, $none, 10) !== 1 || ($line = fgets($this->output)) === false) {
            throw new RuntimeException($this->stopped() ?? 'The reference of Go gave no answer to "'.$command.'" within 10 seconds.');
        }

        return trim($line);
    }

    /**
     * Why the reference ended, if it did.
     */
    private function stopped(): ?string
    {
        // proc_get_status() gives the exit code only once.
        if ($this->stopped === null && ! ($status = proc_get_status($this->process))['running']) {
            $this->stopped = sprintf('Go reference stopped: exit code %d, signal %d; its errors are on STDERR.', $status['exitcode'], $status['termsig']);
        }

        return $this->stopped;
    }
}
