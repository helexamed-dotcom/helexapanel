<div class="card" style="max-width:820px;">
    <h3 class="card-title">تنظیمات امنیتی</h3>
    <p style="color:var(--ink-3); font-size:13px; margin:-8px 0 18px;">
        همه مقادیر در سرور به بازه امن محدود می‌شوند. تغییر این تنظیمات در گزارش فعالیت با سطح بحرانی ثبت می‌شود.
    </p>

    <form method="post" action="/admin/settings">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <h4 class="card-title">سیاست تک‌دستگاه</h4>
        <label class="switch-row">
            <input type="checkbox" name="single_device_enabled" value="1" <?= $values['single_device_enabled'] ? 'checked' : '' ?>>
            <span>فعال بودن محدودیت یک نشست فعال برای هر دانشجو</span>
        </label>
        <label class="switch-row">
            <input type="checkbox" name="single_device_admins" value="1" <?= $values['single_device_admins'] ? 'checked' : '' ?>>
            <span>اعمال همین محدودیت برای مدیران</span>
        </label>

        <div class="field" style="max-width:360px;">
            <label class="label" for="single_device_behavior">رفتار هنگام ورود از دستگاه دوم</label>
            <select class="input" id="single_device_behavior" name="single_device_behavior">
                <option value="block_new" <?= $values['single_device_behavior'] === 'block_new' ? 'selected' : '' ?>>
                    ورود جدید مسدود شود
                </option>
                <option value="force_logout_previous" <?= $values['single_device_behavior'] === 'force_logout_previous' ? 'selected' : '' ?>>
                    دستگاه قبلی خارج شود
                </option>
            </select>
        </div>

        <h4 class="card-title" style="margin-top:24px;">زمان‌ها و محدودیت‌ها</h4>
        <div class="form-grid">
            <?php
            $labels = [
                'session_idle_timeout'     => 'بی‌کاری دانشجو (ثانیه)',
                'session_absolute_timeout' => 'حداکثر طول نشست (ثانیه)',
                'admin_idle_timeout'       => 'بی‌کاری مدیر (ثانیه)',
                'login_max_attempts'       => 'حداکثر تلاش ناموفق ورود',
                'login_lockout_seconds'    => 'مدت قفل پس از تلاش زیاد (ثانیه)',
                'viewer_token_ttl'         => 'عمر توکن نمایش محتوا (ثانیه)',
                'heartbeat_interval'       => 'فاصله Heartbeat مطالعه (ثانیه)',
                'content_max_upload_mb'    => 'حداکثر حجم فایل محتوا (مگابایت)',
            ];
            foreach ($labels as $key => $label):
                [$min, $max] = $ranges[$key];
            ?>
                <div class="field">
                    <label class="label" for="<?= e($key) ?>"><?= e($label) ?></label>
                    <input class="input" type="number" id="<?= e($key) ?>" name="<?= e($key) ?>" dir="ltr"
                           min="<?= (int) $min ?>" max="<?= (int) $max ?>" value="<?= (int) $values[$key] ?>">
                    <div style="color:var(--ink-3); font-size:11.5px; margin-top:4px;">
                        بازه مجاز: <?= e(fa((string) $min)) ?> تا <?= e(fa((string) $max)) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <h4 class="card-title" style="margin-top:24px;">محافظت از محتوا</h4>
        <label class="switch-row">
            <input type="checkbox" name="watermark_enabled" value="1" <?= $values['watermark_enabled'] ? 'checked' : '' ?>>
            <span>واترمارک اختصاصی هر دانشجو روی جزوه‌ها (از فاز ۳ اعمال می‌شود)</span>
        </label>
        <label class="switch-row">
            <input type="checkbox" name="print_protection_enabled" value="1" <?= $values['print_protection_enabled'] ? 'checked' : '' ?>>
            <span>مسدودسازی چاپ در نمایشگر محتوا (لایه بازدارنده، نه DRM)</span>
        </label>
        <label class="switch-row">
            <input type="checkbox" name="viewer_local_font" value="1" <?= $values['viewer_local_font'] ? 'checked' : '' ?>>
            <span>تزریق فونت فارسی محلی به جزوه‌ها (اگر فونت CDN در دسترس نبود، متن با فونت درست نمایش داده شود)</span>
        </label>
        <label class="switch-row">
            <input type="checkbox" name="viewer_allow_external_fonts" value="1" <?= $values['viewer_allow_external_fonts'] ? 'checked' : '' ?>>
            <span>اجازه بارگذاری منابع بیرونی (فونت، CSS و JS) داخل جزوه‌ها</span>
        </label>

        <div class="field" style="max-width:360px; margin-top:14px;">
            <label class="label" for="content_cache_mode">حالت کش محتوا</label>
            <select class="input" id="content_cache_mode" name="content_cache_mode">
                <option value="revalidate" <?= $values['content_cache_mode'] === 'revalidate' ? 'selected' : '' ?>>
                    اعتبارسنجی مجدد (مصرف اینترنت کمتر)
                </option>
                <option value="strict" <?= $values['content_cache_mode'] === 'strict' ? 'selected' : '' ?>>
                    بدون ذخیره در مرورگر (هر بار دانلود کامل)
                </option>
            </select>
        </div>

        <button class="btn btn-primary" style="margin-top:20px;" type="submit">ذخیره تنظیمات</button>
    </form>
</div>

<div class="card" style="margin-top:16px;">
    <h3 class="card-title">برند و تصاویر</h3>
    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        فایل JPG، PNG یا SVG را از همین‌جا آپلود کنید، یا اگر فایلی را مستقیماً با FTP داخل
        <span class="mono">public_html/assets/images</span> گذاشته‌اید، از فهرست «انتخاب از فایل‌های موجود»
        همان را انتخاب کنید. فایل‌های SVG قبل از ذخیره پاک‌سازی می‌شوند تا هیچ کد اجراشدنی نداشته باشند.
    </p>

    <div class="grid grid-3">
        <?php foreach ([
            'logo'          => ['title' => 'لوگوی برنامه', 'hint' => 'در بالای منو و صفحه ورود نشان داده می‌شود.'],
            'avatar_male'   => ['title' => 'آواتار پیش‌فرض مرد', 'hint' => 'وقتی کاربر مرد عکس پروفایل نگذاشته باشد.'],
            'avatar_female' => ['title' => 'آواتار پیش‌فرض زن', 'hint' => 'وقتی کاربر زن عکس پروفایل نگذاشته باشد.'],
        ] as $slot => $meta): ?>
            <div class="brand-slot">
                <div class="brand-preview">
                    <?php if ($brand[$slot] !== ''): ?>
                        <img src="/assets/<?= e($brand[$slot]) ?>" alt="">
                    <?php else: ?>
                        <span class="brand-preview-empty">پیش‌فرض داخلی</span>
                    <?php endif; ?>
                </div>
                <strong style="font-size:13px;"><?= e($meta['title']) ?></strong>
                <span style="color:var(--ink-3); font-size:11px;"><?= e($meta['hint']) ?></span>

                <form method="post" action="/admin/settings/brand/<?= e($slot) ?>" enctype="multipart/form-data" class="brand-form">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input class="input" type="file" name="image" accept="image/png,image/jpeg,image/webp,.svg">
                    <?php if ($libraryImages !== []): ?>
                        <select class="input" name="existing_path">
                            <option value="">— یا از فایل‌های موجود انتخاب کنید —</option>
                            <?php foreach ($libraryImages as $image): ?>
                                <option value="<?= e($image['path']) ?>"><?= e($image['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
                </form>
                <?php if ($brand[$slot] !== ''): ?>
                    <form method="post" action="/admin/settings/brand/<?= e($slot) ?>/reset" style="margin:0;">
                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                        <button class="btn btn-ghost btn-sm" type="submit">بازگشت به پیش‌فرض</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
