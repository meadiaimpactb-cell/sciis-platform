<?php

declare(strict_types=1);

namespace App\Services\Agreements;

/**
 * AGR-02 — line-level comparison of two agreement versions (Myers' O(ND) algorithm, after trimming the common
 * prefix and suffix). Very large rewrites fall back to "all removed / all added" for the changed middle part.
 */
final class LineDiff
{
    /** Edit distance beyond which the middle part is shown as replaced (keeps memory bounded). */
    private const MAX_EDITS = 600;

    /**
     * @return array{lines: list<array{type: 'same'|'added'|'removed', text: string, old: int|null, new: int|null}>, added: int, removed: int}
     */
    public static function compare(?string $old, ?string $new): array
    {
        $a = self::lines($old);
        $b = self::lines($new);

        $start = 0;
        $maxStart = min(count($a), count($b));
        while ($start < $maxStart && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = count($a);
        $endB = count($b);
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }

        $midA = array_slice($a, $start, $endA - $start);
        $midB = array_slice($b, $start, $endB - $start);
        $ops = self::myers($midA, $midB) ?? array_merge(
            array_map(fn (int $i): array => ['-', $i], array_keys($midA)),
            array_map(fn (int $j): array => ['+', $j], array_keys($midB)),
        );

        $lines = [];
        for ($i = 0; $i < $start; $i++) {
            $lines[] = ['type' => 'same', 'text' => $a[$i], 'old' => $i + 1, 'new' => $i + 1];
        }
        $added = 0;
        $removed = 0;
        foreach ($ops as $op) {
            if ($op[0] === '=') {
                $lines[] = ['type' => 'same', 'text' => $midA[$op[1]], 'old' => $start + $op[1] + 1, 'new' => $start + $op[2] + 1];
            } elseif ($op[0] === '-') {
                $lines[] = ['type' => 'removed', 'text' => $midA[$op[1]], 'old' => $start + $op[1] + 1, 'new' => null];
                $removed++;
            } else {
                $lines[] = ['type' => 'added', 'text' => $midB[$op[1]], 'old' => null, 'new' => $start + $op[1] + 1];
                $added++;
            }
        }
        for ($i = $endA, $j = $endB; $i < count($a); $i++, $j++) {
            $lines[] = ['type' => 'same', 'text' => $a[$i], 'old' => $i + 1, 'new' => $j + 1];
        }

        return ['lines' => $lines, 'added' => $added, 'removed' => $removed];
    }

    /** @return list<string> */
    private static function lines(?string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        if ($text === '') {
            return [];
        }

        return array_map('rtrim', explode("\n", $text));
    }

    /**
     * Shortest edit script; null when it would exceed MAX_EDITS.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: string, 1: int, 2?: int}>|null ['=', i, j] | ['-', i] | ['+', j]
     */
    private static function myers(array $a, array $b): ?array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 && $m === 0) {
            return [];
        }

        $v = [1 => 0];
        $trace = [];
        $limit = min($n + $m, self::MAX_EDITS);
        $done = false;
        for ($d = 0; $d <= $limit; $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                $x = ($k === -$d || ($k !== $d && ($v[$k - 1] ?? 0) < ($v[$k + 1] ?? 0)))
                    ? ($v[$k + 1] ?? 0)
                    : ($v[$k - 1] ?? 0) + 1;
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    $done = true;
                    break 2;
                }
            }
        }
        if (! $done) {
            return null;
        }

        $ops = [];
        $x = $n;
        $y = $m;
        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;
            $prevK = ($k === -$d || ($k !== $d && ($v[$k - 1] ?? 0) < ($v[$k + 1] ?? 0))) ? $k + 1 : $k - 1;
            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;
            while ($x > $prevX && $y > $prevY) {
                $ops[] = ['=', $x - 1, $y - 1];
                $x--;
                $y--;
            }
            if ($d > 0) {
                $ops[] = $x === $prevX ? ['+', $prevY] : ['-', $prevX];
            }
            $x = $prevX;
            $y = $prevY;
        }

        return array_reverse($ops);
    }
}
