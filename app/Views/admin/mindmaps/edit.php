<?php
/**
 * The XMind-like editor.
 *
 * @var array $map
 * @var array $tree      the tree
 * @var array $subjects
 * @var array $packages
 * @var array $lessons   uuid => title / status
 * @var array $themes
 * @var array $layouts
 * @var array $tones
 * @var array $colors
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$boot = [
    'uuid'    => $map['uuid'],
    'root'    => $tree,
    'theme'   => $map['theme'],
    'layout'  => $map['layout'],
    'lessons' => $lessons,
];
?>
<div class="me" data-editor data-save="/admin/mindmaps/<?= e($map['uuid']) ?>" data-upload="/admin/mindmaps/media">
    <script type="application/json" data-boot nonce="<?= e($cspNonce) ?>"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <div class="me-bar">
        <a class="ad-icon-btn" href="/admin/mindmaps" title="بازگشت"><?php $icon('chevron', 16); ?></a>
        <input class="me-title" data-meta="title" value="<?= e($map['title']) ?>" maxlength="191" aria-label="عنوان نقشه">
        <div class="me-group">
            <button type="button" data-act="child" title="زیرموضوع (Tab)"><?php $icon('plus', 16); ?> زیرموضوع</button>
            <button type="button" data-act="sibling" title="موضوع هم‌سطح (Enter)">هم‌سطح</button>
            <button type="button" data-act="remove" title="حذف (Delete)"><?php $icon('trash', 16); ?></button>
        </div>
        <div class="me-group">
            <button type="button" data-act="undo" title="برگرداندن (Ctrl+Z)"><?php $icon('undo', 16); ?></button>
            <button type="button" data-act="redo" title="دوباره (Ctrl+Y)"><?php $icon('redo', 16); ?></button>
        </div>
        <div class="me-group">
            <button type="button" data-act="expand" title="باز کردن همه"><?php $icon('expand', 16); ?></button>
            <button type="button" data-act="collapse" title="بستن همه"><?php $icon('collapse', 16); ?></button>
            <button type="button" data-act="fit" title="نمایش کامل (Ctrl+0)"><?php $icon('expand-full', 16); ?></button>
        </div>
        <div class="me-group">
            <button type="button" data-act="io" title="ورود و خروج JSON"><?php $icon('download', 16); ?> JSON</button>
            <a class="ad-icon-btn" href="/student/mindmaps/<?= e($map['uuid']) ?>?preview=1" target="_blank" title="پیش‌نمایش دانشجو"><?php $icon('eye', 16); ?></a>
        </div>
        <span class="me-state" data-state>ذخیره‌شده</span>
        <button class="btn btn-primary me-save" type="button" data-act="save"><?php $icon('check', 16); ?> ذخیره</button>
    </div>

    <div class="me-body">
        <div class="me-canvas">
            <div data-canvas style="position:absolute;inset:0"></div>
            <div class="mm-zoom">
                <button type="button" data-act="zin" title="بزرگ‌نمایی">+</button>
                <small data-zoom>۱۰۰٪</small>
                <button type="button" data-act="zout" title="کوچک‌نمایی">−</button>
            </div>
            <p class="me-help"><kbd>Tab</kbd> زیرموضوع · <kbd>Enter</kbd> هم‌سطح · <kbd>F2</kbd> ویرایش · <kbd>Del</kbd> حذف · <kbd>Space</kbd> بستن/باز · <kbd>Alt+↑↓</kbd> جابه‌جایی · کشیدن روی موضوع دیگر = انتقال</p>
        </div>

        <aside class="me-side">
            <section class="me-card" data-inspector>
                <h4><?php $icon('pen', 15); ?> موضوع انتخاب‌شده</h4>
                <p class="me-empty" data-none>روی یک موضوع بزنید تا رنگ، نشان، یادداشت، تصویر و درسنامه‌اش را تنظیم کنید.</p>
                <div data-some hidden style="display:grid;gap:10px">
                    <label class="hx-field">متن<input class="input" data-f="text" maxlength="300"></label>
                    <div class="hx-field">رنگ شاخه
                        <div class="me-colors" data-colors>
                            <button type="button" class="is-none" data-color="" title="خودکار"></button>
                            <?php foreach ($colors as $c): ?><button type="button" data-color="<?= e($c) ?>" class="tone-<?= e($c) ?>" style="--c: var(--t2)" title="<?= e($c) ?>"></button><?php endforeach; ?>
                        </div>
                    </div>
                    <div class="hx-field">شکل
                        <div class="me-chips" data-shapes>
                            <button type="button" data-shape="">خودکار</button>
                            <button type="button" data-shape="rounded">گرد</button>
                            <button type="button" data-shape="pill">قرصی</button>
                            <button type="button" data-shape="rect">مستطیل</button>
                            <button type="button" data-shape="underline">خط زیر</button>
                            <button type="button" data-shape="cloud">ابر</button>
                        </div>
                    </div>
                    <div class="hx-field">نشان
                        <div class="me-chips" data-markers>
                            <button type="button" data-marker="">—</button>
                            <?php foreach (['⭐', '❗', '❓', '✅', '❌', '🔑', '💡', '⚠️', '🔥', '🫀', '🧠', '🦴', '💊', '🩸', '1️⃣', '2️⃣', '3️⃣'] as $m): ?>
                                <button type="button" data-marker="<?= e($m) ?>"><?= e($m) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <label class="hx-field">یادداشت <small class="hx-muted">(با زدن روی موضوع برای دانشجو باز می‌شود)</small><textarea class="input" data-f="note" rows="4" maxlength="2000"></textarea></label>
                    <div class="hx-field">تصویر
                        <div class="me-img">
                            <img alt="" data-img hidden>
                            <label class="btn btn-ghost btn-sm" style="position:relative"><?php $icon('image', 15); ?> انتخاب<input type="file" accept="image/*" data-img-input style="position:absolute;inset:0;opacity:0"></label>
                            <button type="button" class="btn btn-ghost btn-sm" data-img-remove hidden>حذف</button>
                        </div>
                    </div>
                    <div class="hx-field">درسنامه مرتبط
                        <div class="me-lesson-now" data-lesson-now hidden><?php $icon('lesson', 15); ?> <span data-lesson-title></span><button type="button" data-lesson-clear title="برداشتن"><?php $icon('close', 14); ?></button></div>
                        <input class="input" list="me-lessons" data-lesson-pick placeholder="جستجوی درسنامه…">
                        <datalist id="me-lessons">
                            <?php foreach ($lessons as $uuid => $l): ?><option value="<?= e($l['title']) ?>" data-uuid="<?= e($uuid) ?>"></option><?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
            </section>

            <section class="me-card">
                <h4><?php $icon('sliders', 15); ?> نقشه</h4>
                <div class="hx-field">وضعیت
                    <div class="seg-pick">
                        <label><input type="radio" name="me-status" value="draft" data-meta="status" <?= $map['status'] !== 'published' ? 'checked' : '' ?>><span>پیش‌نویس</span></label>
                        <label><input type="radio" name="me-status" value="published" data-meta="status" <?= $map['status'] === 'published' ? 'checked' : '' ?>><span>منتشر</span></label>
                    </div>
                </div>
                <label class="hx-field">پوسته
                    <select class="input" data-meta="theme"><?php foreach ($themes as $k => $l): ?><option value="<?= e($k) ?>" <?= $map['theme'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                </label>
                <label class="hx-field">چیدمان
                    <select class="input" data-meta="layout"><?php foreach ($layouts as $k => $l): ?><option value="<?= e($k) ?>" <?= $map['layout'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                </label>
                <label class="hx-field">خلاصه یک‌خطی<input class="input" data-meta="summary" maxlength="300" value="<?= e((string) ($map['summary'] ?? '')) ?>"></label>
                <label class="hx-field">درس / زیردرس
                    <select class="input" data-meta="subject_id">
                        <option value="0">— بدون درس —</option>
                        <?php foreach ($subjects as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) ($map['subject_id'] ?? 0) === $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label class="hx-field">فقط برای دارندگان پکیج
                    <select class="input" data-meta="package_id">
                        <option value="0">— همه دانشجوها —</option>
                        <?php foreach ($packages as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($map['package_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <div class="hx-field">رنگ کارت در کتابخانه
                    <div class="me-colors" data-tones>
                        <?php foreach ($tones as $t): ?><button type="button" data-tone="<?= e($t) ?>" class="tone-<?= e($t) ?><?= $map['tone'] === $t ? ' is-on' : '' ?>" style="--c: var(--t2)"></button><?php endforeach; ?>
                    </div>
                </div>
            </section>
        </aside>
    </div>

    <div class="me-dialog" data-io hidden>
        <div>
            <h3>ورود و خروج نقشه</h3>
            <div class="me-tabs"><button type="button" class="is-on" data-io-tab="export">خروجی</button><button type="button" data-io-tab="import">جایگزینی با JSON / فهرست</button></div>
            <div data-io-pane="export">
                <p class="me-empty">نقشه را به صورت JSON دانلود کنید تا در سایت دیگری یا بعداً وارد شود، یا به صورت فهرست تو‌رفته برای Word.</p>
                <div class="me-row">
                    <a class="btn btn-primary" href="/admin/mindmaps/<?= e($map['uuid']) ?>/export"><?php $icon('download', 16); ?> دانلود JSON</a>
                    <a class="btn btn-ghost" href="/admin/mindmaps/<?= e($map['uuid']) ?>/export?as=outline">دانلود فهرست متنی</a>
                    <button class="btn btn-ghost" type="button" data-copy-json><?php $icon('copy', 16); ?> کپی JSON</button>
                </div>
            </div>
            <div data-io-pane="import" hidden>
                <p class="me-empty">JSON نقشه (با کلید <code>root</code>) یا یک فهرست تو‌رفته (هر سطح با دو فاصله یا خط تیره) را بچسبانید. نقشه فعلی جایگزین می‌شود؛ با Ctrl+Z برمی‌گردد.</p>
                <textarea class="input" data-io-text placeholder='{"root":{"text":"...","children":[]}}'></textarea>
                <div class="me-row"><button class="btn btn-primary" type="button" data-io-apply>جایگزین کن</button><label class="btn btn-ghost" style="position:relative">از فایل…<input type="file" accept=".json,.txt,application/json,text/plain" data-io-file style="position:absolute;inset:0;opacity:0"></label></div>
            </div>
            <div class="me-row" style="justify-content:flex-end"><button class="btn btn-ghost" type="button" data-io-close>بستن</button></div>
        </div>
    </div>
</div>
