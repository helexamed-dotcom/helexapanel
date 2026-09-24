<?php
/**
 * Activation codes: make a batch, see who used what.
 *
 * @var array      $codes
 * @var array      $packages
 * @var array      $filters
 * @var int        $total
 * @var array|null $fresh  the batch just made: ['package' => title, 'codes' => [...]]
 * @var \HeleXa\Core\Paginator $paginator
 */
$now = time();
$state = static function (array $c) use ($now): array {
    if ($c['revoked_at'] !== null) {
        return ['chip-gray', 'باطل‌شده'];
    }
    if ($c['redeemed_by'] !== null) {
        return ['chip-green', 'استفاده‌شده'];
    }
    if ($c['expires_at'] !== null && strtotime((string) $c['expires_at']) < $now) {
        return ['chip-red', 'منقضی'];
    }
    return ['chip-blue', 'آماده'];
};
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon" aria-hidden="true">🎟️</div>
            <div>
                <h2>کدهای فعال‌سازی</h2>
                <p>هر کد یک‌بارمصرف است: یک پکیج را فقط برای اولین دانشجویی که واردش کند فعال می‌کند.</p>
            </div>
        </div>
        <div class="qb-hero-actions">
            <a class="btn btn-ghost" href="/admin/activation-codes/export<?= $filters['package_id'] ? '?package=' . (int) $filters['package_id'] : '' ?>">⬇ دانلود کدهای آماده (CSV)</a>
        </div>
    </section>

    <?php if ($fresh !== null && $fresh['codes'] !== []): ?>
        <section class="qb-section hx-fresh-codes">
            <div class="qb-section-head">
                <h3>✨ کدهای تازه — <?= e($fresh['package']) ?></h3>
                <button type="button" class="btn btn-primary btn-sm" data-copy-codes>کپی همه</button>
            </div>
            <textarea class="input mono" rows="<?= min(12, count($fresh['codes']) + 1) ?>" readonly dir="ltr" data-codes><?= e(implode("\n", $fresh['codes'])) ?></textarea>
            <p class="qb-hint">این کدها را برای دانشجویان بفرستید. در ثبت‌نام یا از «خرید و فعال‌سازی» واردشان می‌کنند.</p>
        </section>
    <?php endif; ?>

    <form method="post" action="/admin/activation-codes" class="qb-section hx-codegen">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="qb-section-head"><h3>➕ ساخت کد</h3></div>
        <div class="qb-filters">
            <div class="field" style="flex:1 1 220px;">
                <label class="label" for="cg-p">پکیج</label>
                <select class="input" id="cg-p" name="package_id" required>
                    <option value="">انتخاب پکیج…</option>
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= e($p['title']) ?><?= (int) ($p['is_full_access'] ?? 0) === 1 ? ' (کامل)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex:0 1 110px;">
                <label class="label" for="cg-n">تعداد کد</label>
                <input class="input" id="cg-n" type="number" name="count" min="1" max="500" value="1" dir="ltr">
            </div>
            <div class="field" style="flex:0 1 150px;">
                <label class="label" for="cg-d">مدت دسترسی (روز)</label>
                <input class="input" id="cg-d" type="number" name="duration_days" min="0" max="3650" placeholder="بی‌پایان" dir="ltr">
            </div>
            <div class="field" style="flex:0 1 170px;">
                <label class="label" for="cg-e">مهلت استفاده از کد</label>
                <input class="input" id="cg-e" type="date" name="expires_at" dir="ltr">
            </div>
            <div class="field" style="flex:1 1 200px;">
                <label class="label" for="cg-note">یادداشت (برای چه کسی؟)</label>
                <input class="input" id="cg-note" name="note" maxlength="191" placeholder="مثلاً: علی رضایی — واریز ۱۲ مهر">
            </div>
            <div class="row-actions" style="margin:0; align-self:flex-end;">
                <button class="btn btn-primary" type="submit" data-lock-on-submit>ساخت کد</button>
            </div>
        </div>
    </form>

    <section class="qb-section">
        <form method="get" action="/admin/activation-codes" class="qb-filters">
            <div class="field" style="flex:1 1 180px;">
                <label class="label" for="cf-q">جستجو</label>
                <input class="input" id="cf-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="کد یا یادداشت" dir="auto">
            </div>
            <div class="field" style="flex:1 1 180px;">
                <label class="label" for="cf-p">پکیج</label>
                <select class="input" id="cf-p" name="package">
                    <option value="">همه</option>
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $filters['package_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="flex:0 1 150px;">
                <label class="label" for="cf-s">وضعیت</label>
                <select class="input" id="cf-s" name="status">
                    <?php foreach (['' => 'همه', 'unused' => 'آماده', 'used' => 'استفاده‌شده', 'expired' => 'منقضی', 'revoked' => 'باطل‌شده'] as $k => $l): ?>
                        <option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row-actions" style="margin:0;">
                <button class="btn btn-ghost btn-sm" type="submit">فیلتر</button>
            </div>
        </form>

        <?php if ($codes === []): ?>
            <div class="empty">کدی پیدا نشد.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>کد</th><th>پکیج</th><th>وضعیت</th><th>مدت</th><th>یادداشت</th><th>استفاده</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($codes as $c): [$cls, $label] = $state($c); ?>
                        <tr>
                            <td class="mono" style="font-weight:700; letter-spacing:.04em;"><?= e($c['code']) ?></td>
                            <td><?= e($c['package_title']) ?></td>
                            <td><span class="stat-chip <?= e($cls) ?>"><?= e($label) ?></span></td>
                            <td><?= $c['duration_days'] ? e(fa((string) $c['duration_days'])) . ' روز' : 'بی‌پایان' ?></td>
                            <td><?= e($c['note'] ?? '') ?></td>
                            <td>
                                <?php if ($c['redeemed_by'] !== null): ?>
                                    <a href="/admin/students/<?= e($c['redeemer_uuid']) ?>/access"><?= e($c['redeemer_name']) ?></a>
                                    <div class="leaf-meta"><?= e(jdate($c['redeemed_at'])) ?></div>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if ($c['redeemed_by'] === null && $c['revoked_at'] === null): ?>
                                    <form method="post" action="/admin/activation-codes/<?= (int) $c['id'] ?>/revoke" data-confirm="این کد باطل شود؟" style="margin:0;">
                                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">ابطال</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php \HeleXa\Core\View::partial('partials.pagination', ['paginator' => $paginator]); ?>
        <?php endif; ?>
    </section>
</div>
