<?php

declare(strict_types=1);

namespace SorgeIt\HttpServer\Internal;

/**
 * `DetectContentType()` of Go `net/http`: the type of a body by its first 512 bytes, after the
 * standard of WHATWG for MIME sniffing.
 *
 * @internal
 */
final class Sniff
{
    /**
     * The tags of HTML, each to be followed by a space or `>`.
     */
    private const array HTML = ['<!DOCTYPE HTML', '<HTML', '<HEAD', '<SCRIPT', '<IFRAME', '<H1', '<DIV', '<FONT', '<TABLE', '<A', '<STYLE', '<TITLE', '<B', '<BODY', '<BR', '<P', '<!--'];

    /**
     * Patterns and their masks: a byte of the data counts where the mask has 0xFF.
     */
    private const array MASKED = [
        "\xFE\xFF\x00\x00" => ["\xFF\xFF\x00\x00", 'text/plain; charset=utf-16be'],
        "\xFF\xFE\x00\x00" => ["\xFF\xFF\x00\x00", 'text/plain; charset=utf-16le'],
        "\xEF\xBB\xBF\x00" => ["\xFF\xFF\xFF\x00", 'text/plain; charset=utf-8'],
        "\x00\x00\x01\x00" => [null, 'image/x-icon'],
        "\x00\x00\x02\x00" => [null, 'image/x-icon'],
        'BM' => [null, 'image/bmp'],
        'GIF87a' => [null, 'image/gif'],
        'GIF89a' => [null, 'image/gif'],
        "RIFF\x00\x00\x00\x00WEBPVP" => ["\xFF\xFF\xFF\xFF\x00\x00\x00\x00\xFF\xFF\xFF\xFF\xFF\xFF", 'image/webp'],
        "\x89PNG\x0D\x0A\x1A\x0A" => [null, 'image/png'],
        "\xFF\xD8\xFF" => [null, 'image/jpeg'],
        "FORM\x00\x00\x00\x00AIFF" => ["\xFF\xFF\xFF\xFF\x00\x00\x00\x00\xFF\xFF\xFF\xFF", 'audio/aiff'],
        'ID3' => [null, 'audio/mpeg'],
        "OggS\x00" => [null, 'application/ogg'],
        "MThd\x00\x00\x00\x06" => [null, 'audio/midi'],
        "RIFF\x00\x00\x00\x00AVI " => ["\xFF\xFF\xFF\xFF\x00\x00\x00\x00\xFF\xFF\xFF\xFF", 'video/avi'],
        "RIFF\x00\x00\x00\x00WAVE" => ["\xFF\xFF\xFF\xFF\x00\x00\x00\x00\xFF\xFF\xFF\xFF", 'audio/wave'],
    ];

    /**
     * The patterns after video/mp4, in their order.
     */
    private const array AFTER_MP4 = [
        "\x1A\x45\xDF\xA3" => [null, 'video/webm'],
        "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00LP" => ["\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF", 'application/vnd.ms-fontobject'],
        "\x00\x01\x00\x00" => [null, 'font/ttf'],
        'OTTO' => [null, 'font/otf'],
        'ttcf' => [null, 'font/collection'],
        'wOFF' => [null, 'font/woff'],
        'wOF2' => [null, 'font/woff2'],
        "\x1F\x8B\x08" => [null, 'application/x-gzip'],
        "PK\x03\x04" => [null, 'application/zip'],
        "Rar!\x1A\x07\x00" => [null, 'application/x-rar-compressed'],
        "Rar!\x1A\x07\x01\x00" => [null, 'application/x-rar-compressed'],
        "\x00asm" => [null, 'application/wasm'],
    ];

    /**
     * The bytes that make data binary, section 5 of the standard: control bytes except tab, LF,
     * FF, CR and ESC.
     */
    private const string BINARY = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1C\x1D\x1E\x1F";

    public static function type(string $data): string
    {
        $data = substr($data, 0, Go::SNIFF);
        $text = substr($data, strspn($data, "\t\n\x0C\r "));

        foreach (self::HTML as $tag) {
            if (strncasecmp($text, $tag, strlen($tag)) === 0 && in_array($text[strlen($tag)] ?? '', [' ', '>'], true)) {
                return 'text/html; charset=utf-8';
            }
        }

        if (str_starts_with($text, '<?xml')) {
            return 'text/xml; charset=utf-8';
        }

        if (str_starts_with($data, '%PDF-')) {
            return 'application/pdf';
        }

        if (str_starts_with($data, '%!PS-Adobe-')) {
            return 'application/postscript';
        }

        return self::match($data, self::MASKED)
            ?? (self::mp4($data) ? 'video/mp4' : null)
            ?? self::match($data, self::AFTER_MP4)
            ?? (strpbrk($text, self::BINARY) === false ? 'text/plain; charset=utf-8' : 'application/octet-stream');
    }

    /**
     * The type of the first pattern the data starts with.
     *
     * @param  array<string, array{string|null, string}>  $patterns
     */
    private static function match(string $data, array $patterns): ?string
    {
        foreach ($patterns as $pattern => [$mask, $type]) {
            $start = substr($data, 0, strlen($pattern));

            if (strlen($start) === strlen($pattern) && ($mask === null ? $start : $start & $mask) === $pattern) {
                return $type;
            }
        }

        return null;
    }

    /**
     * The signature of MP4: a box of `ftyp` whose brands name `mp4`, except in the version at
     * byte 12.
     */
    private static function mp4(string $data): bool
    {
        if (strlen($data) < 12 || substr($data, 4, 4) !== 'ftyp') {
            return false;
        }

        $size = (ord($data[0]) << 24) | (ord($data[1]) << 16) | (ord($data[2]) << 8) | ord($data[3]);

        if (strlen($data) < $size || $size % 4 !== 0) {
            return false;
        }

        for ($at = 8; $at < $size; $at += 4) {
            if ($at !== 12 && substr($data, $at, 3) === 'mp4') {
                return true;
            }
        }

        return false;
    }
}
