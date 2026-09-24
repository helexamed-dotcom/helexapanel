<?php
/**
 * A study session.
 *
 * The queue is handed to flashcards.js as a JSON data block. A block of type
 * application/json is never executed, so it needs no CSP nonce, and it is
 * encoded with JSON_HEX_TAG so no card text can close the tag early.
 *
 * Keyboard: Space / Enter flips, 1–4 rate, S stars, H shows the hint.
 *
 * @var string $heading
 * @var array  $cards
 * @var string $mode
 * @var bool   $shuffle
 * @var int    $limit
 * @var string $backUrl
 * @var array  $labels
 */
$modes = ['due' => '⏰ موعددارها + جدید', 'all' => '📚 همه کارت‌ها', 'starred' => '⭐ ستاره‌دارها', 'hard' => '🧠 کارت‌های سخت'];
$json  = json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="fc-study" data-fc-study data-back="<?= e($backUrl) ?>">
    <div class="fc-head">
        <h3 style="margin:0;"><?= e($heading) ?></h3>
        <a class="btn btn-ghost btn-sm" href="<?= e($backUrl) ?>">خروج</a>
    </div>

    <details class="fc-panel" style="padding:12px 16px;">
        <summary style="cursor:pointer; font-size:13px;">تنظیمات جلسه: <?= e($modes[$mode] ?? '') ?><?= $shuffle ? ' · تصادفی' : '' ?></summary>
        <form method="get" class="fc-options" style="margin-top:10px;">
            <div class="field">
                <label class="label" for="fc-mode">کارت‌ها</label>
                <select class="input" id="fc-mode" name="mode">
                    <?php foreach ($modes as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $mode === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label" for="fc-limit">حداکثر تعداد</label>
                <select class="input" id="fc-limit" name="limit">
                    <?php foreach ([10, 20, 50, 100, 200] as $n): ?>
                        <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= e(fa((string) $n)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="remember-row" style="margin:0 0 10px;">
                <input type="checkbox" name="shuffle" value="1" <?= $shuffle ? 'checked' : '' ?>>
                <span>ترتیب تصادفی</span>
            </label>
            <button class="btn btn-primary btn-sm" type="submit">اعمال</button>
        </form>
    </details>

    <?php if ($cards === []): ?>
        <div class="fc-panel fc-empty">
            <div class="fc-big">🎉</div>
            <strong><?= $mode === 'due' ? 'برای امروز کارتی برای مرور نمانده!' : 'کارتی با این تنظیمات پیدا نشد.' ?></strong>
            <p class="fc-hint">می‌توانی «همه کارت‌ها» را برای مرور آزاد انتخاب کنی.</p>
            <div class="fc-inline" style="justify-content:center;">
                <a class="btn btn-primary" href="?mode=all">مرور آزاد همه کارت‌ها</a>
                <a class="btn btn-ghost" href="<?= e($backUrl) ?>">بازگشت</a>
            </div>
        </div>
    <?php else: ?>
        <script type="application/json" data-fc-queue><?= $json ?></script>

        <div data-fc-run>
            <div class="fc-study-top">
                <span class="fc-counter" data-fc-counter></span>
                <div class="fc-progress"><i data-fc-bar style="width:0%"></i></div>
                <span class="fc-timer" data-fc-timer>۰۰:۰۰</span>
            </div>

            <div class="fc-stage-area" style="margin-top:14px; position:relative;">
                <button type="button" class="fc-star" data-fc-star aria-pressed="false" aria-label="ستاره‌دار کردن (S)">☆</button>
                <button type="button" class="fc-flip" data-fc-flip aria-live="polite">
                    <span class="fc-face fc-face-front">
                        <span class="fc-face-label">روی کارت</span>
                        <span class="fc-new-badge" data-fc-new hidden>جدید</span>
                        <span class="fc-face-text" data-fc-front dir="auto"></span>
                        <span class="fc-face-hint" data-fc-hint hidden></span>
                        <span class="fc-tap" data-fc-tap>برای دیدن پاسخ ضربه بزن یا Space</span>
                    </span>
                    <span class="fc-face fc-face-back">
                        <span class="fc-face-label">پشت کارت</span>
                        <span class="fc-face-text" data-fc-back dir="auto"></span>
                    </span>
                </button>
            </div>

            <div style="margin-top:14px; display:grid; gap:8px;">
                <div class="fc-inline" style="justify-content:space-between;">
                    <button type="button" class="btn btn-ghost btn-sm" data-fc-show-hint hidden>💡 راهنما (H)</button>
                    <span></span>
                </div>
                <button type="button" class="btn btn-primary fc-reveal" data-fc-reveal>نمایش پاسخ</button>
                <div class="fc-rates" data-fc-rates hidden>
                    <?php foreach ($labels as $rating => $label): ?>
                        <button type="button" class="fc-rate r<?= (int) $rating ?>" data-fc-rate="<?= (int) $rating ?>">
                            <?= e($label) ?>
                            <small data-fc-preview="<?= (int) $rating ?>"></small>
                            <span class="fc-kbd"><?= e(fa((string) $rating)) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="fc-panel fc-summary" data-fc-summary hidden>
            <div class="fc-trophy">🏆</div>
            <h3 style="margin:0;">جلسه تمام شد!</h3>
            <div class="fc-summary-grid">
                <div><b data-fc-sum="total">۰</b><span>کارت مرور شد</span></div>
                <div><b data-fc-sum="good">۰</b><span>به خاطر داشتی</span></div>
                <div><b data-fc-sum="again">۰</b><span>دوباره</span></div>
                <div><b data-fc-sum="time">۰۰:۰۰</b><span>زمان</span></div>
            </div>
            <div class="fc-inline" style="justify-content:center;">
                <a class="btn btn-primary" href="" data-fc-restart>جلسه بعدی</a>
                <a class="btn btn-ghost" href="<?= e($backUrl) ?>">بازگشت</a>
            </div>
        </div>
    <?php endif; ?>
</div>
