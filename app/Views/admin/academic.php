<p style="color:var(--ink-3); font-size:12.5px; margin:0 0 16px;">
    ترتیب ساختار: <strong>دانشگاه ← رشته ← ترم ← گروه</strong>.
    ترمی که رشته نداشته باشد «عمومی» است و برای همه رشته‌ها قابل انتخاب می‌ماند؛
    ترم‌هایی که قبل از این تغییر ساخته شده‌اند در همین دسته‌اند.
</p>

<div class="grid grid-2">
    <!-- ------------------------------------------------------ universities -->
    <div class="card">
        <h3 class="card-title">دانشگاه‌ها</h3>
        <form method="post" action="/admin/academic/universities" class="filters">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <input class="input" name="title" placeholder="نام دانشگاه" required>
            <input class="input" name="city" placeholder="شهر" style="max-width:130px;">
            <button class="btn btn-primary btn-sm" type="submit">افزودن</button>
        </form>

        <?php if ($universities === []): ?>
            <div class="empty">هنوز دانشگاهی ثبت نشده است.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data" style="min-width:auto;">
                    <tbody>
                    <?php foreach ($universities as $university): ?>
                        <tr>
                            <td>
                                <?= e($university['title']) ?>
                                <?php if ($university['city']): ?>
                                    <span class="leaf-meta"><?= e($university['city']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="stat-chip chip-gray"><?= e(fa((string) $university['major_count'])) ?> رشته</span></td>
                            <td>
                                <span class="stat-chip <?= (int) $university['is_active'] === 1 ? 'chip-green' : 'chip-gray' ?>">
                                    <?= (int) $university['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                                </span>
                            </td>
                            <td class="row-actions">
                                <form method="post" action="/admin/academic/universities/<?= (int) $university['id'] ?>/toggle">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">
                                        <?= (int) $university['is_active'] === 1 ? 'غیرفعال' : 'فعال' ?>
                                    </button>
                                </form>
                                <form method="post" action="/admin/academic/universities/<?= (int) $university['id'] ?>/delete"
                                      data-confirm="با حذف دانشگاه، رشته‌ها و ترم‌های زیرمجموعه‌اش هم حذف می‌شوند. دانشجویان حذف نمی‌شوند. ادامه؟">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ------------------------------------------------------------ majors -->
    <div class="card">
        <h3 class="card-title">رشته‌ها</h3>
        <form method="post" action="/admin/academic/majors" class="filters">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <select class="input" name="university_id" required>
                <option value="">انتخاب دانشگاه</option>
                <?php foreach ($universities as $university): ?>
                    <option value="<?= (int) $university['id'] ?>"><?= e($university['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <input class="input" name="title" placeholder="نام رشته" required>
            <button class="btn btn-primary btn-sm" type="submit">افزودن</button>
        </form>

        <?php if ($majors === []): ?>
            <div class="empty">هنوز رشته‌ای ثبت نشده است.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data" style="min-width:auto;">
                    <tbody>
                    <?php foreach ($majors as $major): ?>
                        <tr>
                            <td>
                                <?= e($major['title']) ?>
                                <span class="leaf-meta"><?= e($major['university_title']) ?></span>
                            </td>
                            <td><span class="stat-chip chip-gray"><?= e(fa((string) $major['term_count'])) ?> ترم</span></td>
                            <td>
                                <span class="stat-chip <?= (int) $major['is_active'] === 1 ? 'chip-green' : 'chip-gray' ?>">
                                    <?= (int) $major['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                                </span>
                            </td>
                            <td class="row-actions">
                                <form method="post" action="/admin/academic/majors/<?= (int) $major['id'] ?>/toggle">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">
                                        <?= (int) $major['is_active'] === 1 ? 'غیرفعال' : 'فعال' ?>
                                    </button>
                                </form>
                                <form method="post" action="/admin/academic/majors/<?= (int) $major['id'] ?>/delete"
                                      data-confirm="با حذف رشته، ترم‌های آن هم حذف می‌شوند. ادامه؟">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3 class="card-title">دروس (برای برنامه هفتگی و امتحانات)</h3>
    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        این فهرست کاملاً مستقل از «دوره‌ها»ست. دروسی که اینجا اضافه می‌کنید هیچ محتوایی
        در سایت ندارند و فقط برای یکدست نگه‌داشتن نام کلاس‌ها در برنامه هفتگی و امتحانات
        استفاده می‌شوند. برای هر جلسه یا امتحان، انتخاب درس از این فهرست کاملاً اختیاری است.
    </p>
    <form method="post" action="/admin/academic/subjects" class="filters">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <select class="input" name="major_id">
            <option value="">عمومی (همه رشته‌ها)</option>
            <?php foreach ($majors as $major): ?>
                <option value="<?= (int) $major['id'] ?>">
                    <?= e($major['university_title']) ?> — <?= e($major['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input class="input" name="title" placeholder="نام درس، مثلاً آناتومی اعصاب" required>
        <input class="input" type="color" name="color" value="#2563eb" style="max-width:60px;">
        <button class="btn btn-primary btn-sm" type="submit">افزودن</button>
    </form>

    <?php if ($subjects === []): ?>
        <div class="empty">هنوز درسی تعریف نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data" style="min-width:auto;">
                <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr>
                        <td>
                            <span style="display:inline-block; width:10px; height:10px; border-radius:3px;
                                background: <?= e($subject['color'] ?: '#94a3b8') ?>; margin-left:6px;"></span>
                            <?= e($subject['title']) ?>
                            <span class="leaf-meta">
                                <?= $subject['major_title'] ? e($subject['university_title'] . ' — ' . $subject['major_title']) : 'عمومی' ?>
                            </span>
                        </td>
                        <td>
                            <span class="stat-chip <?= (int) $subject['is_active'] === 1 ? 'chip-green' : 'chip-gray' ?>">
                                <?= (int) $subject['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?>
                            </span>
                        </td>
                        <td class="row-actions">
                            <form method="post" action="/admin/academic/subjects/<?= (int) $subject['id'] ?>/toggle">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">
                                    <?= (int) $subject['is_active'] === 1 ? 'غیرفعال' : 'فعال' ?>
                                </button>
                            </form>
                            <form method="post" action="/admin/academic/subjects/<?= (int) $subject['id'] ?>/delete"
                                  data-confirm="این درس حذف شود؟ جلسات و امتحانات متصل به آن حذف نمی‌شوند، فقط اتصالشان قطع می‌شود.">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-2" style="margin-top:16px;">
    <!-- ------------------------------------------------------------- terms -->
    <div class="card">
        <h3 class="card-title">ترم‌ها</h3>
        <form method="post" action="/admin/academic/terms" class="filters">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <select class="input" name="major_id">
                <option value="">عمومی (همه رشته‌ها)</option>
                <?php foreach ($majors as $major): ?>
                    <option value="<?= (int) $major['id'] ?>">
                        <?= e($major['university_title']) ?> — <?= e($major['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input class="input" name="title" placeholder="عنوان ترم، مثلاً ترم ۵" required>
            <input class="input" name="number" dir="ltr" placeholder="شماره" style="max-width:90px;">
            <button class="btn btn-primary btn-sm" type="submit">افزودن</button>
        </form>

        <?php if ($terms === []): ?>
            <div class="empty">هنوز ترمی تعریف نشده است.</div>
        <?php else: ?>
            <table class="data" style="min-width:auto;">
                <tbody>
                <?php foreach ($terms as $term): ?>
                    <tr>
                        <td>
                            <?= e($term['title']) ?>
                            <span class="leaf-meta">
                                <?= $term['major_title']
                                    ? e($term['university_title'] . ' — ' . $term['major_title'])
                                    : 'عمومی' ?>
                            </span>
                        </td>
                        <td style="text-align:left;">
                            <form method="post" action="/admin/academic/terms/<?= (int) $term['id'] ?>/delete"
                                  data-confirm="با حذف ترم، گروه‌های آن هم حذف می‌شوند. ادامه؟" style="margin:0;">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- ------------------------------------------------------------ groups -->
    <div class="card">
        <h3 class="card-title">گروه‌ها</h3>
        <form method="post" action="/admin/academic/groups" class="filters">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <select class="input" name="term_id" required>
                <option value="">انتخاب ترم</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?= (int) $term['id'] ?>">
                        <?= $term['major_title'] ? e($term['major_title']) . ' — ' : '' ?><?= e($term['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input class="input" name="title" placeholder="عنوان گروه، مثلاً گروه ۲۳" required>
            <button class="btn btn-primary btn-sm" type="submit">افزودن</button>
        </form>

        <?php if ($groups === []): ?>
            <div class="empty">هنوز گروهی تعریف نشده است.</div>
        <?php else: ?>
            <table class="data" style="min-width:auto;">
                <tbody>
                <?php foreach ($groups as $group): ?>
                    <tr>
                        <td>
                            <?= e($group['title']) ?>
                            <span class="leaf-meta">
                                <?= e(trim(($group['university_title'] ?? '') . ' ' .
                                    ($group['major_title'] ? '— ' . $group['major_title'] : '') . ' — ' . $group['term_title'], ' —')) ?>
                            </span>
                        </td>
                        <td style="text-align:left;">
                            <form method="post" action="/admin/academic/groups/<?= (int) $group['id'] ?>/delete"
                                  data-confirm="این گروه حذف شود؟" style="margin:0;">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
