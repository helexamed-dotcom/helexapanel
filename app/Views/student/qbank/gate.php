<?php
/** @var array $decision  from QbAccess::forStudent() */
?>
<div class="qb-page">
    <section class="qb-section qb-gate">
        <div class="qb-hero-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'qbank']); ?></div>
        <h2 style="margin:0;"><?= e($decision['title']) ?></h2>
        <p><?= e($decision['message']) ?></p>
        <?php if ($decision['reason'] === 'no_access'): ?>
            <a class="btn btn-primary" href="/student/support">درخواست از پشتیبانی</a>
        <?php endif; ?>
    </section>
</div>
