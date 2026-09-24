<?php
declare(strict_types=1);

namespace HeleXa\Services;

/**
 * Server-side twin of HlxInk.clean(): only well-formed strokes are stored,
 * rounded, with a known set of keys, whatever the client sent.
 */
final class InkData
{
    public const MAX_STROKES = 5000;
    public const MAX_POINTS  = 3000;
    public const MAX_BYTES   = 4 * 1024 * 1024;

    /** @return array<int,array<string,mixed>> */
    public static function clean(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $s) {
            if (count($out) >= self::MAX_STROKES) {
                break;
            }
            if (!is_array($s) || !is_array($s['p'] ?? null) || !is_string($s['c'] ?? null)
                || preg_match('/^#[0-9a-f]{6}$/i', $s['c']) !== 1) {
                continue;
            }
            $points = [];
            $raw = array_values($s['p']);
            $limit = min(count($raw), self::MAX_POINTS * 3);
            for ($i = 0; $i + 2 < $limit; $i += 3) {
                if (!is_numeric($raw[$i]) || !is_numeric($raw[$i + 1])) {
                    continue;
                }
                $points[] = round((float) $raw[$i], 1);
                $points[] = round((float) $raw[$i + 1], 1);
                $points[] = is_numeric($raw[$i + 2]) ? round(max(0.0, min(1.0, (float) $raw[$i + 2])), 2) : 0.5;
            }
            if ($points === []) {
                continue;
            }
            $out[] = [
                'id'  => substr(preg_replace('/[^a-z0-9]/i', '', (string) ($s['id'] ?? '')) ?: bin2hex(random_bytes(5)), 0, 24),
                't'   => ($s['t'] ?? '') === 'marker' ? 'marker' : 'pen',
                'c'   => strtolower($s['c']),
                's'   => round(max(0.5, min(60.0, (float) ($s['s'] ?? 3))), 2),
                'w'   => round(max(50.0, min(10000.0, (float) ($s['w'] ?? 800))), 1),
                'sim' => !empty($s['sim']) ? 1 : 0,
                'p'   => $points,
            ];
        }
        return $out;
    }

    public static function encode(array $strokes): string
    {
        $json = (string) json_encode($strokes, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > self::MAX_BYTES) {
            throw new \RuntimeException('حجم نوشته‌ها از حد مجاز بیشتر است؛ بخشی از آن‌ها را پاک کنید.');
        }
        return $json;
    }

    /** @return array<int,mixed> */
    public static function decode(?string $json): array
    {
        $data = $json !== null && $json !== '' ? json_decode($json, true) : null;
        return is_array($data) ? $data : [];
    }
}