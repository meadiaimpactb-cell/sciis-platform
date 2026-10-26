<?php

declare(strict_types=1);

namespace App\Support;

/**
 * INV-08 — GD/FreeType draws text left-to-right, glyph by glyph, with no Arabic shaping. This turns a logical-order
 * string into "visual" order for such renderers: Arabic letters are replaced by their contextual presentation forms
 * (Unicode Arabic Presentation Forms-B, incl. the lam-alef ligatures) and right-to-left runs are reversed, while Latin
 * words, digits and e-mails keep their order. Diacritics are dropped. Enough for short watermark lines, not a full
 * Unicode bidi implementation.
 */
final class ArabicGlyphs
{
    /** base letter → [isolated, final, initial, medial] (only the first two for right-joining letters). */
    private const FORMS = [
        0x0621 => [0xFE80], 0x0622 => [0xFE81, 0xFE82], 0x0623 => [0xFE83, 0xFE84], 0x0624 => [0xFE85, 0xFE86],
        0x0625 => [0xFE87, 0xFE88], 0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C], 0x0627 => [0xFE8D, 0xFE8E],
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92], 0x0629 => [0xFE93, 0xFE94], 0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98],
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C], 0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0], 0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4],
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8], 0x062F => [0xFEA9, 0xFEAA], 0x0630 => [0xFEAB, 0xFEAC], 0x0631 => [0xFEAD, 0xFEAE],
        0x0632 => [0xFEAF, 0xFEB0], 0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4], 0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8],
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC], 0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0], 0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4],
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8], 0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC], 0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0],
        0x0640 => [0x0640, 0x0640, 0x0640, 0x0640], 0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4], 0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8],
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC], 0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0], 0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4],
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8], 0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC], 0x0648 => [0xFEED, 0xFEEE],
        0x0649 => [0xFEEF, 0xFEF0], 0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
    ];

    /** lam + alef variant → [isolated, final] ligature. */
    private const LAM_ALEF = [0x0622 => [0xFEF5, 0xFEF6], 0x0623 => [0xFEF7, 0xFEF8], 0x0625 => [0xFEF9, 0xFEFA], 0x0627 => [0xFEFB, 0xFEFC]];

    private const MIRROR = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '<' => '>', '>' => '<', '«' => '»', '»' => '«'];

    public static function hasArabic(string $text): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text);
    }

    /** Logical → visual (shaped, reordered) for left-to-right glyph renderers. Text without Arabic is returned as is. */
    public static function visual(string $text): string
    {
        if (! self::hasArabic($text)) {
            return $text;
        }

        $chars = self::shape(preg_split('//u', (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        // Runs: L = Latin letters / digits (+ neutrals between two L), A = Arabic, N = other neutrals (part of the RTL flow).
        $types = array_map(fn (string $c): string => self::hasArabic($c) ? 'A' : (preg_match('/[\p{L}\p{N}]/u', $c) ? 'L' : 'N'), $chars);
        $count = count($types);
        for ($i = 0; $i < $count; $i++) {
            if ($types[$i] !== 'N') {
                continue;
            }
            $j = $i;
            while ($j < $count && $types[$j] === 'N') {
                $j++;
            }
            $before = $i > 0 ? $types[$i - 1] : null;
            $after = $j < $count ? $types[$j] : null;
            if ($before === 'L' && $after === 'L') {
                for ($k = $i; $k < $j; $k++) {
                    $types[$k] = 'L';
                }
            }
            $i = $j - 1;
        }

        $runs = [];
        foreach ($chars as $i => $c) {
            $kind = $types[$i] === 'L' ? 'L' : 'R';
            if ($runs !== [] && $runs[count($runs) - 1]['kind'] === $kind) {
                $runs[count($runs) - 1]['chars'][] = $c;
            } else {
                $runs[] = ['kind' => $kind, 'chars' => [$c]];
            }
        }

        $out = '';
        foreach (array_reverse($runs) as $run) {
            $out .= $run['kind'] === 'L'
                ? implode('', $run['chars'])
                : implode('', array_map(fn (string $c): string => self::MIRROR[$c] ?? $c, array_reverse($run['chars'])));
        }

        return $out;
    }

    /**
     * Contextual forms + lam-alef ligatures (logical order kept).
     *
     * @param  array<int, string>  $chars
     * @return array<int, string>
     */
    private static function shape(array $chars): array
    {
        $codes = array_map(fn (string $c): int => mb_ord($c, 'UTF-8') ?: 0, $chars);
        $out = [];
        $n = count($codes);
        for ($i = 0; $i < $n; $i++) {
            $code = $codes[$i];
            if (! isset(self::FORMS[$code])) {
                $out[] = $chars[$i];

                continue;
            }

            $prev = $i > 0 ? $codes[$i - 1] : null;
            $joinsBefore = $prev !== null && self::joinsForward($prev) && self::joinsBackward($code);

            // lam + alef → one ligature glyph (right-joining).
            if ($code === 0x0644 && $i + 1 < $n && isset(self::LAM_ALEF[$codes[$i + 1]])) {
                $out[] = mb_chr(self::LAM_ALEF[$codes[$i + 1]][$joinsBefore ? 1 : 0], 'UTF-8');
                $i++;

                continue;
            }

            $next = $i + 1 < $n ? $codes[$i + 1] : null;
            $joinsAfter = $next !== null && self::joinsForward($code) && self::joinsBackward($next);
            $forms = self::FORMS[$code];
            $form = match (true) {
                $joinsBefore && $joinsAfter => $forms[3] ?? $forms[1] ?? $forms[0],
                $joinsBefore => $forms[1] ?? $forms[0],
                $joinsAfter => $forms[2] ?? $forms[0],
                default => $forms[0],
            };
            $out[] = mb_chr($form, 'UTF-8');
        }

        return $out;
    }

    /** The letter connects to the following letter (has initial/medial forms). */
    private static function joinsForward(int $code): bool
    {
        return isset(self::FORMS[$code][2]);
    }

    /** The letter connects to the preceding letter (has a final form). */
    private static function joinsBackward(int $code): bool
    {
        return isset(self::FORMS[$code][1]);
    }
}
