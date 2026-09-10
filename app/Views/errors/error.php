<div class="auth-card" style="text-align:center;">
    <div class="error-code"><?= e(fa((string) ($code ?? 500))) ?></div>
    <p style="color:var(--ink-2); margin:14px 0 22px;"><?= e($message ?? 'خطایی رخ داده است.') ?></p>
    <a class="btn btn-ghost btn-block" href="/">بازگشت به صفحه اصلی</a>
</div>
