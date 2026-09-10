<div class="card" style="max-width:520px;">
    <h3 class="card-title">تغییر رمز عبور</h3>

    <?php if ((int) ($currentUser['must_change_password'] ?? 0) === 1): ?>
        <div class="alert alert-error">برای ادامه باید ابتدا رمز عبور موقت خود را تغییر دهید.</div>
    <?php endif; ?>

    <div class="alert alert-success" style="background:#eff6ff; color:#1e3a8a; border-color:#bfdbfe;">
        رمز عبور باید حداقل ۸ کاراکتر باشد و فقط شامل حروف انگلیسی و اعداد باشد.
    </div>

    <form method="post" action="/account/password" autocomplete="off" novalidate>
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <div class="field">
            <label class="label" for="current_password">رمز عبور فعلی</label>
            <input class="input<?= isset($errors['current_password']) ? ' has-error' : '' ?>"
                   type="password" id="current_password" name="current_password" autocomplete="current-password">
            <?php if (!empty($errors['current_password'])): ?>
                <div class="field-error"><?= e($errors['current_password']) ?></div>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="new_password">رمز عبور جدید</label>
            <input class="input<?= isset($errors['new_password']) ? ' has-error' : '' ?>"
                   type="password" id="new_password" name="new_password" autocomplete="new-password"
                   dir="ltr" inputmode="latin" minlength="8" pattern="[A-Za-z0-9]{8,}">
            <?php if (!empty($errors['new_password'])): ?>
                <div class="field-error"><?= e($errors['new_password']) ?></div>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="new_password_confirmation">تکرار رمز عبور جدید</label>
            <input class="input<?= isset($errors['new_password_confirmation']) ? ' has-error' : '' ?>"
                   type="password" id="new_password_confirmation" name="new_password_confirmation"
                   autocomplete="new-password" dir="ltr" minlength="8" pattern="[A-Za-z0-9]{8,}">
            <?php if (!empty($errors['new_password_confirmation'])): ?>
                <div class="field-error"><?= e($errors['new_password_confirmation']) ?></div>
            <?php endif; ?>
        </div>

        <button class="btn btn-primary" type="submit">ذخیره رمز جدید</button>
        <p style="color:var(--ink-3); font-size:12.5px; margin-top:14px;">
            با تغییر رمز، تمام نشست‌های دیگر شما بسته می‌شوند.
        </p>
    </form>
</div>
