<?php
/**
 * The media library.
 *
 * Files live under storage/private and are served through a controller that
 * checks the session — a clinical photograph should not be reachable by
 * guessing a URL.
 *
 * @var array  $items
 * @var string $kind
 */
$kinds = ['' => 'همه', 'image' => 'تصویر', 'audio' => 'صوت', 'video' => 'ویدیو'];
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">کتابخانه رسانه</h3>
        <form method="get" action="/admin/balin/media" class="balin-filter">
            <select name="kind">
                <?php foreach ($kinds as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $kind === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-sm" type="submit">نمایش</button>
        </form>
    </div>

    <?php if ($items === []): ?>
        <div class="empty">هنوز فایلی آپلود نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>فایل</th><th>نوع</th><th>حجم</th><th>متن جایگزین</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <?= e($item['original_name'] ?: $item['storage_path']) ?>
                            <div class="leaf-meta"><?= e($item['mime']) ?></div>
                        </td>
                        <td><?= e($kinds[$item['kind']] ?? $item['kind']) ?></td>
                        <td><?= e(fa(round((int) $item['byte_size'] / 1024))) ?> کیلوبایت</td>
                        <td>
                            <?php if ($item['kind'] === 'image' && !$item['alt_text']): ?>
                                <span class="stat-chip chip-red">ندارد</span>
                            <?php else: ?>
                                <?= e(mb_substr((string) $item['alt_text'], 0, 40, 'UTF-8') ?: '—') ?>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions">
                            <details>
                                <summary class="btn btn-ghost btn-sm">ویرایش</summary>
                                <form method="post" action="/admin/balin/media/<?= e($item['uuid']) ?>" class="form-grid">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <label class="field span-2"><span>متن جایگزین (Alt)</span>
                                        <input type="text" name="alt_text" maxlength="255" value="<?= e((string) $item['alt_text']) ?>"></label>
                                    <label class="field span-2"><span>زیرنویس</span>
                                        <input type="text" name="caption" maxlength="255" value="<?= e((string) $item['caption']) ?>"></label>
                                    <label class="field span-2"><span>متن گفتار (برای صوت)</span>
                                        <textarea name="transcript" rows="2"><?= e((string) $item['transcript']) ?></textarea></label>
                                    <label class="field"><span>عرض (٪)</span>
                                        <input type="number" name="width_percent" min="10" max="100" value="<?= (int) $item['width_percent'] ?>"></label>
                                    <label class="field"><span>چیدمان</span>
                                        <select name="position">
                                            <option value="center" <?= $item['position'] === 'center' ? 'selected' : '' ?>>وسط</option>
                                            <option value="start"  <?= $item['position'] === 'start' ? 'selected' : '' ?>>ابتدا</option>
                                            <option value="end"    <?= $item['position'] === 'end' ? 'selected' : '' ?>>انتها</option>
                                        </select></label>
                                    <label class="field check">
                                        <input type="checkbox" name="allow_download" value="1" <?= (int) $item['allow_download'] === 1 ? 'checked' : '' ?>>
                                        <span>اجازه دانلود</span></label>
                                    <div class="form-actions span-2">
                                        <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
                                    </div>
                                </form>
                                <form method="post" action="/admin/balin/media/<?= e($item['uuid']) ?>/delete"
                                      data-confirm="این فایل حذف شود؟">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (can('balin.create')): ?>
<div class="card">
    <h3 class="card-title">آپلود فایل</h3>
    <form method="post" action="/admin/balin/media" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <label class="field"><span>نوع</span>
            <select name="kind">
                <option value="image">تصویر</option>
                <option value="audio">صوت</option>
                <option value="video">ویدیو</option>
            </select></label>
        <label class="field"><span>فایل *</span><input type="file" name="file" required></label>
        <label class="field span-2"><span>متن جایگزین (برای تصویر الزامی است)</span>
            <input type="text" name="alt_text" maxlength="255"></label>
        <label class="field span-2"><span>زیرنویس</span><input type="text" name="caption" maxlength="255"></label>
        <label class="field span-2"><span>متن گفتار (برای صوت)</span><textarea name="transcript" rows="2"></textarea></label>
        <label class="field"><span>عرض (٪)</span><input type="number" name="width_percent" min="10" max="100" value="100"></label>
        <label class="field check"><input type="checkbox" name="allow_download" value="1"><span>اجازه دانلود</span></label>
        <div class="form-actions span-2"><button class="btn btn-primary" type="submit">آپلود</button></div>
    </form>
</div>
<?php endif; ?>
