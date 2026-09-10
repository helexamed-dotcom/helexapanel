<div class="auth-card" style="max-width:520px; text-align:right;">
    <div class="error-code" style="text-align:center;"><?= e(fa('413')) ?></div>
    <h3 class="card-title" style="justify-content:center;">حجم درخواست بیش از حد مجاز است</h3>

    <p style="color:var(--ink-2); font-size:13.5px; line-height:2;">
        چیزی که فرستادید <strong><?= e(fa((string) $sentMb)) ?> مگابایت</strong> بود،
        ولی سقف فعلی سرور <strong><?= e(fa((string) $limitMb)) ?> مگابایت</strong> است.
        سرور بدنه‌ی درخواست را قبل از رسیدن به برنامه دور ریخته، بنابراین هیچ داده‌ای ذخیره نشده است.
    </p>

    <table class="data" style="min-width:auto; margin:14px 0;">
        <tr><th>post_max_size</th><td class="mono"><?= e(fa((string) $limitMb)) ?> MB</td></tr>
        <tr><th>upload_max_filesize</th><td class="mono"><?= e(fa((string) $uploadMb)) ?> MB</td></tr>
        <tr><th>max_input_vars</th><td class="mono"><?= e(fa($inputVars)) ?></td></tr>
    </table>

    <p style="color:var(--ink-3); font-size:12.5px; line-height:2;">
        اگر مدیر هستید: مقادیر بالا از <span class="mono">public_html/.user.ini</span> خوانده می‌شوند.
        اگر بعد از ویرایش آن فایل عدد عوض نشد، یعنی هاست جلوی آن را گرفته و باید از
        <span class="mono">Select PHP Version → Options</span> در دایرکت‌ادمین تغییرش دهید.
        صفحه‌ی «بازبینی امنیت» در پنل، مقادیر مؤثر واقعی را نشان می‌دهد.
    </p>

    <a class="btn btn-ghost btn-block" href="/">بازگشت</a>
</div>
