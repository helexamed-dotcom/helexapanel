<?php
/**
 * A small decorative drawing of a map for its card: a centre and a few
 * curved branches, the same for the same map every time.
 *
 * @var array $map  uuid, node_count
 */
$seed = crc32((string) ($map['uuid'] ?? ''));
$branches = max(3, min(8, (int) round(log(max(2, (int) ($map['node_count'] ?? 4)), 2)) + 2));
$paths = '';
for ($i = 0; $i < $branches; $i++) {
    $side = $i % 2 === 0 ? 1 : -1;
    $row = intdiv($i, 2);
    $spread = (int) ceil($branches / 2);
    $y = 65 + ($row - ($spread - 1) / 2) * (100 / max(1, $spread)) + (($seed >> ($i * 3)) % 9) - 4;
    $x = 150 + $side * (70 + (($seed >> ($i * 2)) % 25));
    $paths .= '<path d="M150 65 C' . (150 + $side * 30) . ' 65 ' . (150 + $side * 40) . ' ' . $y . ' ' . $x . ' ' . $y . '" stroke="rgba(255,255,255,.75)" stroke-width="3" fill="none" stroke-linecap="round"/>'
        . '<rect x="' . ($side > 0 ? $x : $x - 34) . '" y="' . ($y - 7) . '" width="34" height="14" rx="7" fill="rgba(255,255,255,.88)"/>';
}
?>
<svg viewBox="0 0 300 130" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><?= $paths ?><rect x="118" y="51" width="64" height="28" rx="14" fill="#fff"/></svg>
