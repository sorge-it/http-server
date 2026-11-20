<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Tests;

use RuntimeException;

/**
 * What the weekly checks compare with: the newest release of a repository of git, and the blobs of
 * files at its tag. It needs git and the network, and no token.
 */
final class Upstream
{
    /**
     * The newest tag of the repository whose name matches the pattern, by version.
     */
    public static function newestTag(string $url, string $pattern): string
    {
        $tags = [];

        foreach (explode("\n", self::run(['git', 'ls-remote', '--tags', '--refs', $url])) as $line) {
            if (preg_match('#\trefs/tags/(\S+)$#', $line, $match) === 1 && preg_match($pattern, $match[1]) === 1) {
                $tags[] = $match[1];
            }
        }

        if ($tags === []) {
            throw new RuntimeException('No tag of '.$url.' matches '.$pattern.'.');
        }

        usort($tags, static fn (string $one, string $other): int => version_compare((string) preg_replace('/^\D+/', '', $one), (string) preg_replace('/^\D+/', '', $other)));

        return $tags[count($tags) - 1];
    }

    /**
     * Whether the lock file has the blobs of its files at the tag. If not, it writes the lines that
     * only one side has.
     */
    public static function locks(string $lock, string $url, string $tag): bool
    {
        $ours = self::lines($lock);
        $paths = array_map(static fn (string $line): string => explode('  ', $line, 2)[1] ?? '', $ours);

        return self::same(basename(dirname($lock)).'/'.basename($lock), $ours, self::blobs($url, $tag, $paths), $tag);
    }

    /**
     * The lines of a lock file without its comments.
     *
     * @return list<string>
     */
    public static function lines(string $lock): array
    {
        return array_values(array_filter(file($lock, FILE_IGNORE_NEW_LINES) ?: [], static fn (string $line): bool => $line !== '' && $line[0] !== '#'));
    }

    /**
     * Whether both lists are the same. If not, it writes the lines that only one side has.
     *
     * @param  list<string>  $ours
     * @param  list<string>  $theirs
     */
    public static function same(string $name, array $ours, array $theirs, string $tag): bool
    {
        if ($ours === $theirs) {
            echo $name.' is the same at '.$tag.'.'.PHP_EOL;

            return true;
        }

        echo $name.' is not the same at '.$tag.':'.PHP_EOL;

        foreach (array_diff($ours, $theirs) as $line) {
            echo '- '.$line.PHP_EOL;
        }

        foreach (array_diff($theirs, $ours) as $line) {
            echo '+ '.$line.PHP_EOL;
        }

        if (array_diff($ours, $theirs) === [] && array_diff($theirs, $ours) === []) {
            echo 'The lines are the same, but in another order or more than once.'.PHP_EOL;
        }

        return false;
    }

    /**
     * What the command wrote. It throws when the command fails.
     *
     * @param  list<string>  $command
     */
    public static function run(array $command): string
    {
        $errors = tmpfile();

        if ($errors === false) {
            throw new RuntimeException('No temporary file for '.$command[0].'.');
        }

        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => $errors], $pipes);

        if ($process === false) {
            throw new RuntimeException($command[0].' does not start.');
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);

        if ($code !== 0) {
            rewind($errors);

            throw new RuntimeException(implode(' ', $command).' failed with exit code '.$code.($code === 127 ? ', so '.$command[0].' is not installed' : '').': '.stream_get_contents($errors));
        }

        return $output;
    }

    /**
     * In a workflow of GitHub, the value is an output of the step, for the steps after it.
     */
    public static function output(string $name, string $value): void
    {
        $file = getenv('GITHUB_OUTPUT');

        if (is_string($file) && $file !== '') {
            file_put_contents($file, $name.'='.$value.PHP_EOL, FILE_APPEND);
        }
    }

    /**
     * The lines `<blob>  <path>` of the files at the tag, in the order of `git ls-tree`, as a lock
     * file has them. The clone has only the trees.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function blobs(string $url, string $tag, array $paths): array
    {
        $directory = sys_get_temp_dir().'/http-server-upstream-'.bin2hex(random_bytes(6));

        try {
            self::run(['git', 'clone', '--quiet', '--depth', '1', '--branch', $tag, '--filter=blob:none', '--no-checkout', $url, $directory]);
            $tree = self::run(['git', '-C', $directory, 'ls-tree', 'HEAD', '--', ...$paths]);
        } finally {
            self::run(['rm', '-rf', $directory]);
        }

        // Each line is `<mode> blob <object>\t<path>`.
        return array_map(static function (string $line): string {
            [$entry, $path] = explode("\t", $line, 2) + [1 => ''];

            return (explode(' ', $entry)[2] ?? '').'  '.$path;
        }, preg_split('/\n/', $tree, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
