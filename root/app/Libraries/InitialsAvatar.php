<?php

namespace App\Libraries;

use Config\ImageUpload as ImageUploadConfig;

/**
 * The avatar shown when auth_master.avatar_path is empty: the initials of full_name as an SVG, kept at
 * {uploadPath}initials-avatar/{initials}.svg and served back by the image/initials-avatar/{initials}.svg route.
 * One file per set of initials, so "Mary Jane Watson" and "Molly Weasley" share MW.svg; the colours come from the
 * initials too, so a shared file is always the same picture.
 */
class InitialsAvatar
{
    /** Folder under ImageUpload::$uploadPath, and the used-for segment of its URL */
    public const FOLDER = 'initials-avatar';

    /** What a file name may be: letters with their combining marks, and digits only (no dots or slashes) */
    public const NAME_PATTERN = '/^[\p{L}\p{M}\p{N}]{1,16}$/u';

    /** [background, text] pairs */
    private const COLORS = [
        ['#E3F2FD', '#1565C0'],
        ['#FCE4EC', '#AD1457'],
        ['#E8F5E9', '#2E7D32'],
        ['#FFF3E0', '#E65100'],
        ['#EDE7F6', '#6A1B9A'],
        ['#E0F2F1', '#00695C'],
        ['#F3E5F5', '#7B1FA2'],
        ['#FFF8E1', '#8D6E00'],
        ['#E1F5FE', '#0277BD'],
        ['#FBE9E7', '#BF360C'],
        ['#F1F8E9', '#558B2F'],
        ['#E8EAF6', '#283593'],
        ['#F9FBE7', '#827717'],
        ['#ECEFF1', '#37474F'],
        ['#E0E0E0', '#424242'],
    ];

    /** Width and height of the SVG, as the profile-picture used-for */
    private const SIZE = 512;

    /**
     * URL of the initials avatar of a full name, making its file first when there is none yet
     * @param string|null $fullName
     * @return string|null Null when the name gives no initials or the file cannot be written (the app shows its
     *                     default avatar then)
     */
    public static function urlFor(?string $fullName): ?string
    {
        $initials = self::initials((string) $fullName);
        if ($initials === '') {
            return null;
        }
        $path = self::folder() . $initials . '.svg';
        if (! is_file($path) && ! self::write($path, $initials)) {
            return null;
        }

        return base_url('image/' . self::FOLDER . '/' . rawurlencode($initials) . '.svg');
    }

    /**
     * One word gives its first letter; two or more give the first letters of the first and last words.
     * A Thai word starts at its first consonant, so "สมชาย ใจดี" is สจ and not สใ. Latin letters are upper-cased.
     * @param string $name
     * @return string Empty when no word starts with a letter or digit
     */
    public static function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) > 1) {
            $words = [$words[0], $words[count($words) - 1]];
        }

        $initials = '';
        foreach ($words as $word) {
            // leading vowels are written before the consonant they follow, so they cannot be initials
            $word = preg_replace('/^[เแโใไ]+/u', '', $word) ?? '';
            // handle Thai first; more cases can be added when more languages are added
            if (preg_match('/^[ก-ฮ]/u', $word, $matches) || preg_match('/^[\p{L}\p{N}]\p{M}*/u', $word, $matches)) {
                $initials .= $matches[0];
            }
        }

        return mb_strtoupper($initials, 'UTF-8');
    }

    /**
     * @param string $initials
     * @return array{0: string, 1: string} [background, text]
     */
    public static function colors(string $initials): array
    {
        return self::COLORS[crc32($initials) % count(self::COLORS)];
    }

    /**
     * @return string With a trailing slash
     */
    public static function folder(): string
    {
        return rtrim(config(ImageUploadConfig::class)->uploadPath, '/') . '/' . self::FOLDER . '/';
    }

    /**
     * Written beside the final file and renamed, so a sign-in at the same moment never serves half a file
     * @param string $path
     * @param string $initials
     * @return bool
     */
    private static function write(string $path, string $initials): bool
    {
        $folder = dirname($path) . '/';
        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            log_message('error', 'InitialsAvatar: cannot create {folder}', ['folder' => $folder]);

            return false;
        }

        // the leading dot keeps it out of NAME_PATTERN and so never served
        $temp = $folder . '.' . bin2hex(random_bytes(6)) . '.svg';
        if (@file_put_contents($temp, self::svg($initials)) === false || ! @rename($temp, $path)) {
            @unlink($temp);
            log_message('error', 'InitialsAvatar: cannot write {path}', ['path' => $path]);

            return false;
        }

        return true;
    }

    /**
     * @param string $initials
     * @return string
     */
    private static function svg(string $initials): string
    {
        [$background, $text] = self::colors($initials);
        $size     = self::SIZE;
        $half     = $size / 2;
        $fontSize = mb_strlen($initials, 'UTF-8') > 1 ? 200 : 240;
        $label    = htmlspecialchars($initials, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="{$size}" height="{$size}" viewBox="0 0 {$size} {$size}">
              <rect width="{$size}" height="{$size}" fill="{$background}"/>
              <text x="{$half}" y="{$half}" dy="0.35em" text-anchor="middle" fill="{$text}" font-family="'Noto Sans Thai', 'Noto Sans', system-ui, -apple-system, 'Segoe UI', sans-serif" font-size="{$fontSize}" font-weight="600">{$label}</text>
            </svg>

            SVG;
    }
}
