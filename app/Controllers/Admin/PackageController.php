<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\CourseRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivationService;
use HeleXa\Services\Auth;
use HeleXa\Services\PackageAccess;

final class PackageController extends Controller
{
    private PackageRepository $packages;

    public function __construct()
    {
        $this->packages = new PackageRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.packages.index', [
            'title'    => 'پکیج‌ها',
            'packages' => $this->packages->all(),
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.packages.form', [
            'title'   => 'افزودن پکیج',
            'package' => null,
            'old'     => [],
            'errors'  => [],
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $data = $this->collect($request);

        if ($data['title'] === '') {
            return $this->page('layouts.app', 'admin.packages.form', [
                'title'   => 'افزودن پکیج',
                'package' => null,
                'old'     => $data,
                'errors'  => ['title' => 'عنوان پکیج الزامی است.'],
            ], 422);
        }

        $id = $this->packages->create($data + ['uuid' => Str::uuid4(), 'created_by' => Auth::id()]);
        $package = $this->packages->findById($id);

        \HeleXa\Services\ActivityLogger::log('package.created', Auth::id(), 'package', $id,
            ['title' => $data['title']], 'notice', $request);
        $this->flash('success', 'پکیج ساخته شد. حالا دوره‌های آن را انتخاب کنید.');

        return $this->redirect('/admin/packages/' . $package['uuid']);
    }

    /** The package workbench: what is inside it, and who holds it. */
    public function show(Request $request, array $params = []): Response
    {
        $package = $this->find((string) ($params['uuid'] ?? ''));
        $inside  = $this->packages->courses((int) $package['id']);
        $insideIds = array_map('intval', array_column($inside, 'id'));

        $available = array_values(array_filter(
            (new CourseRepository())->all(),
            static fn (array $c): bool => !in_array((int) $c['id'], $insideIds, true)
        ));

        return $this->page('layouts.app', 'admin.packages.show', [
            'title'     => $package['title'],
            'package'   => $package,
            'courses'   => $inside,
            'available' => $available,
            'catalogue' => PackageAccess::catalogue(),
            'chosen'    => $this->packages->itemsByType((int) $package['id']),
            'members'   => $this->packages->members((int) $package['id']),
            'groups'    => (new \HeleXa\Models\AcademicRepository())->groups(),
            'terms'     => (new \HeleXa\Models\AcademicRepository())->terms(),
            'students'  => (new UserRepository())->paginate(['role' => 'student', 'status' => 'active'], 500, 0),
        ]);
    }

    /**
     * Saves the question bank subjects, Balin lessons and flashcard courses
     * inside the package. Students who already hold it keep what they have;
     * a new choice reaches them the next time the package is activated for
     * them — taking something back silently is not what a tick box means.
     */
    public function saveItems(Request $request, array $params = []): Response
    {
        $package = $this->find((string) ($params['uuid'] ?? ''));

        foreach (PackageRepository::ITEM_TYPES as $type) {
            $raw = $request->input($type);
            $this->packages->syncItems(
                (int) $package['id'],
                $type,
                is_array($raw) ? array_map('intval', $raw) : []
            );
        }

        \HeleXa\Services\ActivityLogger::log('package.items_updated', Auth::id(), 'package', (int) $package['id'],
            [], 'notice', $request);
        $this->flash('success', 'محتوای پکیج ذخیره شد.');

        return $this->redirect('/admin/packages/' . $package['uuid'] . '#contents');
    }

    public function update(Request $request, array $params = []): Response
    {
        $package = $this->find((string) ($params['uuid'] ?? ''));
        $data    = $this->collect($request);

        if ($data['title'] === '') {
            $this->flash('error', 'عنوان پکیج الزامی است.');
            return $this->redirect('/admin/packages/' . $package['uuid']);
        }

        $this->packages->update((int) $package['id'], $data);

        // Marking a package free is a promise to every student, so it is kept
        // at once rather than waiting for each of them to sign up again.
        $becameFree = (int) ($package['is_free'] ?? 0) !== 1 && $data['is_free'] === 1 && $data['status'] === 'published';
        if ($becameFree) {
            $fresh = $this->packages->findById((int) $package['id']) ?? $package;
            $count = PackageAccess::grantToEveryone($fresh, Auth::id());
            $this->flash('success', sprintf('پکیج رایگان شد و برای %s دانشجو فعال شد.', fa((string) $count)));
        } else {
            $this->flash('success', 'پکیج به‌روزرسانی شد.');
        }

        return $this->redirect('/admin/packages/' . $package['uuid']);
    }

    /**
     * "Give it to everyone": activates a free package for every active
     * student, and brings what is inside it up to date for those who hold it.
     */
    public function grantEveryone(Request $request, array $params = []): Response
    {
        $package = $this->find((string) ($params['uuid'] ?? ''));

        if ((int) ($package['is_free'] ?? 0) !== 1) {
            $this->flash('error', 'این دکمه فقط برای پکیج رایگان است.');
            return $this->redirect('/admin/packages/' . $package['uuid']);
        }

        $count = PackageAccess::grantToEveryone($package, Auth::id());
        $this->flash('success', sprintf('پکیج رایگان برای %s دانشجو فعال یا به‌روز شد.', fa((string) $count)));

        return $this->redirect('/admin/packages/' . $package['uuid']);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $package = $this->find((string) ($params['uuid'] ?? ''));

        // Soft delete only. The courses students already received through this
        // package stay theirs; removing the bundle is not the same as
        // withdrawing access, and silently doing both would be a nasty surprise.
        $this->packages->softDelete((int) $package['id']);

        \HeleXa\Services\ActivityLogger::log('package.deleted', Auth::id(), 'package', (int) $package['id'],
            ['title' => $package['title']], 'warning', $request);
        $this->flash('success', 'پکیج حذف شد. دسترسی دانشجویانی که قبلاً آن را گرفته‌اند دست‌نخورده می‌ماند.');

        return $this->redirect('/admin/packages');
    }

    /* ------------------------------------------------------------ courses */

    public function addCourse(Request $request, array $params = []): Response
    {
        $package = $this->find((string) ($params['uuid'] ?? ''));
        $course  = (new CourseRepository())->findById($request->int('course_id'));

        if ($course === null) {
            $this->flash('error', 'دوره انتخاب‌شده معتبر نیست.');
            return $this->redirect('/admin/packages/' . $package['uuid']);
        }

        $added = $this->packages->addCourse((int) $package['id'], (int) $course['id']);

        if ($added) {
            $backfilled = ActivationService::backfillCourse($package, (int) $course['id'], Auth::id());
            $this->flash('success', $backfilled > 0
                ? sprintf('دوره اضافه شد و برای %s دانشجوی فعلی این پکیج هم فعال شد.', (string) $backfilled)
                : 'دوره به پکیج اضافه شد. دانشجویان فعلی این پکیج آن را دریافت نکردند؛ برای تغییر این رفتار گزینه فعال‌سازی خودکار را روشن کنید.');
        } else {
            $this->flash('error', 'این دوره از قبل داخل پکیج است.');
        }

        return $this->redirect('/admin/packages/' . $package['uuid']);
    }

    public function removeCourse(Request $request, array $params = []): Response
    {
        $package = $this->find((string) ($params['uuid'] ?? ''));

        $this->packages->removeCourse((int) $package['id'], (int) ($params['course'] ?? 0));
        $this->flash('success', 'دوره از پکیج حذف شد. دسترسی دانشجویانی که قبلاً آن را گرفته‌اند تغییر نمی‌کند.');

        return $this->redirect('/admin/packages/' . $package['uuid']);
    }

    /* ------------------------------------------------------------ members */

    public function activate(Request $request, array $params = []): Response
    {
        $package = $this->find((string) ($params['uuid'] ?? ''));
        $student = (new UserRepository())->findByUuid($request->string('student_uuid'));

        if ($student === null || $student['role_slug'] !== 'student') {
            $this->flash('error', 'دانشجوی انتخاب‌شده معتبر نیست.');
            return $this->redirect('/admin/packages/' . $package['uuid']);
        }
        if ($this->isEmptyPackage($package)) {
            $this->flash('error', 'این پکیج هنوز هیچ محتوایی ندارد.');
            return $this->redirect('/admin/packages/' . $package['uuid']);
        }

        $result = ActivationService::activatePackage($student, $package, [
            'status'    => 'active',
            'starts_at' => $this->date($request->string('starts_at')),
            'ends_at'   => $this->date($request->string('ends_at'), true),
        ], Auth::id());

        $this->flash('success', sprintf(
            'پکیج برای %s فعال شد: %s.%s',
            (string) $student['full_name'],
            PackageAccess::summarise($result),
            $result['notified'] ? ' یک اطلاعیه هم برای او ثبت شد.' : ''
        ));

        return $this->redirect('/admin/packages/' . $package['uuid']);
    }

    /**
     * Activates the package for a whole group (or a whole term) at once —
     * «پکیج باکتری برای همه گروه ۲۴».
     */
    public function activateGroup(Request $request, array $params = []): Response
    {
        $package = $this->find((string) ($params['uuid'] ?? ''));
        $groupId = $request->int('group_id');
        $termId  = $request->int('term_id');

        if ($groupId <= 0 && $termId <= 0) {
            $this->flash('error', 'گروه یا ترم را انتخاب کنید.');
            return $this->redirect('/admin/packages/' . $package['uuid']);
        }
        if ($this->isEmptyPackage($package)) {
            $this->flash('error', 'این پکیج هنوز هیچ محتوایی ندارد.');
            return $this->redirect('/admin/packages/' . $package['uuid']);
        }

        $filters  = ['role' => 'student', 'status' => 'active'] + ($groupId > 0 ? ['group_id' => $groupId] : ['term_id' => $termId]);
        $students = (new UserRepository())->paginate($filters, 5000, 0);
        $window   = [
            'status'    => 'active',
            'starts_at' => $this->date($request->string('starts_at')),
            'ends_at'   => $this->date($request->string('ends_at'), true),
        ];

        foreach ($students as $student) {
            ActivationService::activatePackage($student, $package, $window, Auth::id());
        }

        \HeleXa\Services\ActivityLogger::log('package.group_activated', Auth::id(), 'package', (int) $package['id'],
            ['group' => $groupId ?: null, 'term' => $termId ?: null, 'students' => count($students)], 'notice', $request);
        $this->flash('success', sprintf('پکیج برای %s دانشجو فعال شد.', fa((string) count($students))));

        return $this->redirect('/admin/packages/' . $package['uuid']);
    }

    public function setMemberStatus(Request $request, array $params = []): Response
    {
        $package    = $this->find((string) ($params['uuid'] ?? ''));
        $activation = $this->packages->findActivation((int) ($params['activation'] ?? 0));

        if ($activation === null || (int) $activation['package_id'] !== (int) $package['id']) {
            throw HttpException::notFound('فعال‌سازی یافت نشد.');
        }

        $status = $request->string('status');
        if (!in_array($status, ['active', 'suspended', 'cancelled', 'expired'], true)) {
            throw HttpException::forbidden('وضعیت نامعتبر است.');
        }

        ActivationService::setPackageStatus($activation, $package, $status, Auth::id());
        $this->flash('success', 'وضعیت پکیج این دانشجو تغییر کرد و روی دوره‌های داخل آن هم اعمال شد.');

        return $this->redirect('/admin/packages/' . $package['uuid']);
    }

    /**
     * POST /admin/packages/{uuid}/modules — the sections this package turns
     * on, on top of what each holder's student type allows.
     */
    public function saveModules(Request $request, array $params = []): Response
    {
        $package = $this->find((string) ($params['uuid'] ?? ''));
        $keys = array_values(array_intersect(array_keys(\HeleXa\Services\Modules::CATALOG), array_map('strval', (array) $request->input('modules', []))));
        try {
            \HeleXa\Core\Database::execute('UPDATE packages SET modules = :m, updated_at = NOW() WHERE id = :id',
                ['m' => json_encode($keys), 'id' => (int) $package['id']]);
        } catch (\PDOException) {
            $this->flash('error', 'ابتدا مهاجرت 2026_10_04_access_profiles.sql را اجرا کنید.');
            return $this->redirect('/admin/packages/' . $package['uuid']);
        }
        \HeleXa\Services\AccessProfile::flush();
        \HeleXa\Services\ActivityLogger::log('package.modules', \HeleXa\Services\Auth::id(), 'package', (int) $package['id'], ['modules' => $keys], 'info', $request);
        $this->flash('success', 'بخش‌های این پکیج ذخیره شد؛ برای همه دارندگانش فوراً اعمال می‌شود.');

        return $this->redirect('/admin/packages/' . $package['uuid'] . '#sections');
    }

    /* ------------------------------------------------------------ helpers */

    private function find(string $uuid): array
    {
        $package = $this->packages->findByUuid($uuid);
        if ($package === null) {
            throw HttpException::notFound('پکیج یافت نشد.');
        }
        return $package;
    }

    /** A package with nothing in it has nothing to activate. */
    private function isEmptyPackage(array $package): bool
    {
        if ((int) ($package['is_full_access'] ?? 0) === 1) {
            return false;
        }
        // A package that only turns sections on is not empty.
        if (\HeleXa\Services\StudentTypes::decodeModules($package['modules'] ?? '[]') !== []) {
            return false;
        }
        if ($this->packages->courseIds((int) $package['id']) !== []) {
            return false;
        }
        foreach ($this->packages->itemsByType((int) $package['id']) as $ids) {
            if ($ids !== []) {
                return false;
            }
        }
        return true;
    }


    private function collect(Request $request): array
    {
        $status = $request->string('status', 'published');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            $status = 'published';
        }

        return [
            'title'                  => $request->string('title'),
            'description'            => $request->string('description'),
            'color'                  => preg_match('/^#[0-9a-fA-F]{6}$/', $request->string('color')) === 1
                ? $request->string('color') : null,
            'status'                 => $status,
            'auto_grant_new_courses' => $request->bool('auto_grant_new_courses') ? 1 : 0,
            'is_full_access'         => $request->bool('is_full_access') ? 1 : 0,
            'is_free'                => $request->bool('is_free') ? 1 : 0,
            'sort_order'             => $request->int('sort_order'),
        ];
    }

    /** The package page borrows the question bank's checklist styles. */
    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['qbank' => true], $status);
    }

    private function date(string $value, bool $endOfDay = false): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00')
            : null;
    }
}
