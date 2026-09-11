<?php
/**
 * The island when it is closed: coming soon, under maintenance, or not yet
 * enabled for this student.
 *
 * No countdown and no release date. Neither is known, and inventing one is
 * the fastest way to lose the trust the message is trying to build.
 *
 * @var array{reason:string, title:string, message:string, eta:string} $gate
 */
$isComingSoon = $gate['reason'] === \HeleXa\Services\Balin\Access::COMING_SOON;
?>
<div class="balin-gate">
    <div class="balin-gate-card">
        <div class="balin-gate-mark" aria-hidden="true">🏝️</div>

        <h2 class="balin-gate-title">
            <?= $isComingSoon ? 'جزیره بالین' : e($gate['title']) ?>
        </h2>

        <p class="balin-gate-text"><?= e($gate['message']) ?></p>

        <?php if ($gate['eta'] !== ''): ?>
            <p class="balin-gate-eta">برآورد بازگشت: <?= e($gate['eta']) ?></p>
        <?php endif; ?>

        <?php if ($isComingSoon): ?>
            <div class="balin-gate-preview">
                <div class="balin-gate-preview-label">چیزی که در راه است</div>
                <ul class="balin-gate-list">
                    <li><span aria-hidden="true">🫀</span> کیس‌های بالینی تعاملی با گفت‌وگوی استاد و دانشجو</li>
                    <li><span aria-hidden="true">🎯</span> آزمون‌های بین‌مرحله‌ای برای سنجش مهارت‌های بالینی</li>
                    <li><span aria-hidden="true">🩺</span> مسیر مهارت: شرح‌حال‌گیری، معاینه، تشخیص افتراقی و…</li>
                    <li><span aria-hidden="true">🏆</span> سطح، رتبه و رقابت هفتگی</li>
                </ul>
            </div>
        <?php endif; ?>

        <a class="btn btn-ghost" href="/student">بازگشت به داشبورد</a>
    </div>
</div>
