<?php
/**
 * The full note page. notes.js builds the editor into [data-note-page].
 *
 * @var array $note
 * @var bool  $pdfjs
 */
?>
<div class="note-page-wrap">
    <p style="margin:0 0 10px;"><a class="lib-back" href="/student/notes">→ همه یادداشت‌ها</a></p>
    <div data-note-page data-uuid="<?= e($note['uuid']) ?>" data-pdfjs="<?= $pdfjs ? '1' : '0' ?>">
        <div class="note-loading">در حال باز کردن یادداشت…</div>
    </div>
    <noscript><div class="alert alert-error"><div>برای نوشتن در یادداشت، جاوااسکریپت مرورگر باید روشن باشد.</div></div></noscript>
</div>