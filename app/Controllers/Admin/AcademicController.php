<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\SubjectRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Jalali;

/** Terms and groups. Students, schedules and exams are all scoped by these. */
final class AcademicController extends Controller
{
    private AcademicRepository $academic;

    public function __construct()
    {
        $this->academic = new AcademicRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.academic', [
            'title'        => 'ساختار آموزشی',
            'universities' => $this->academic->universities(),
            'majors'       => $this->academic->majors(),
            'terms'        => $this->academic->terms(),
            'groups'       => $this->academic->groups(),
            'subjects'     => (new SubjectRepository())->all(),
        ]);
    }

    /* ---------------------------------------------------------- subjects */

    /**
     * Subjects are the labels used by the weekly schedule and exams. They are
     * deliberately independent of the course catalogue: an admin can add
     * whatever class names are needed here without any of them ever having
     * to correspond to content-bearing material in the LMS.
     */
    public function storeSubject(Request $request, array $params = []): Response
    {
        $title = $request->string('title');
        if (mb_strlen($title) < 2 || mb_strlen($title) > 191) {
            $this->flash('error', 'نام درس باید بین ۲ تا ۱۹۱ کاراکتر باشد.');
            return $this->redirect('/admin/academic');
        }

        $majorId = $request->int('major_id') ?: null;
        if ($majorId !== null && $this->academic->findMajor($majorId) === null) {
            $majorId = null;
        }

        $color = $request->string('color');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : null;

        $subjects = new SubjectRepository();

        try {
            $id = $subjects->create(['major_id' => $majorId, 'title' => $title, 'color' => $color]);
        } catch (\PDOException $e) {
            // 23000 here means the (major_id, title) pair already exists —
            // the one thing this table's unique key exists to prevent.
            if ($e->getCode() === '23000') {
                $this->flash('error', 'این نام درس قبلاً برای همین رشته (یا به‌صورت عمومی) ثبت شده است.');
                return $this->redirect('/admin/academic');
            }
            throw $e;
        }

        ActivityLogger::log('subject.created', Auth::id(), 'subject', $id, ['title' => $title], 'notice', $request);
        $this->flash('success', 'درس اضافه شد.');

        return $this->redirect('/admin/academic');
    }

    public function toggleSubject(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $subjects = new SubjectRepository();
        if ($subjects->find($id) === null) {
            throw HttpException::notFound();
        }
        $subjects->toggle($id);
        $this->flash('success', 'وضعیت درس تغییر کرد.');

        return $this->redirect('/admin/academic');
    }

    public function destroySubject(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $subjects = new SubjectRepository();
        if ($subjects->find($id) === null) {
            throw HttpException::notFound();
        }
        // schedule_items.subject_id and exams.subject_id both SET NULL on
        // delete: existing sessions/exams keep their free-text title and
        // simply lose the link, nothing about them disappears.
        $subjects->delete($id);
        ActivityLogger::log('subject.deleted', Auth::id(), 'subject', $id, [], 'warning', $request);
        $this->flash('success', 'درس حذف شد. جلسات و امتحاناتی که به آن وصل بودند حذف نشدند، فقط اتصالشان برداشته شد.');

        return $this->redirect('/admin/academic');
    }

    /* --------------------------------------------------------- university */

    public function storeUniversity(Request $request, array $params = []): Response
    {
        $title = $request->string('title');
        if (mb_strlen($title) < 2 || mb_strlen($title) > 191) {
            $this->flash('error', 'نام دانشگاه باید بین ۲ تا ۱۹۱ کاراکتر باشد.');
            return $this->redirect('/admin/academic');
        }

        $id = $this->academic->createUniversity($title, $request->string('city') ?: null);
        ActivityLogger::log('university.created', Auth::id(), 'university', $id, ['title' => $title], 'notice', $request);
        $this->flash('success', 'دانشگاه اضافه شد.');

        return $this->redirect('/admin/academic');
    }

    public function toggleUniversity(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if ($this->academic->findUniversity($id) === null) {
            throw HttpException::notFound();
        }
        $this->academic->toggleUniversity($id);
        $this->flash('success', 'وضعیت دانشگاه تغییر کرد.');

        return $this->redirect('/admin/academic');
    }

    public function destroyUniversity(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if ($this->academic->findUniversity($id) === null) {
            throw HttpException::notFound();
        }
        // Foreign keys cascade to majors and their terms, and set the users'
        // university and major back to NULL rather than deleting anyone.
        $this->academic->deleteUniversity($id);
        ActivityLogger::log('university.deleted', Auth::id(), 'university', $id, [], 'warning', $request);
        $this->flash('success', 'دانشگاه و رشته‌های آن حذف شد.');

        return $this->redirect('/admin/academic');
    }

    /* -------------------------------------------------------------- major */

    public function storeMajor(Request $request, array $params = []): Response
    {
        $universityId = $request->int('university_id');
        $title        = $request->string('title');

        if ($this->academic->findUniversity($universityId) === null) {
            $this->flash('error', 'دانشگاه انتخاب‌شده معتبر نیست.');
            return $this->redirect('/admin/academic');
        }
        if (mb_strlen($title) < 2 || mb_strlen($title) > 191) {
            $this->flash('error', 'نام رشته معتبر نیست.');
            return $this->redirect('/admin/academic');
        }

        $id = $this->academic->createMajor($universityId, $title);
        ActivityLogger::log('major.created', Auth::id(), 'major', $id, ['title' => $title], 'notice', $request);
        $this->flash('success', 'رشته اضافه شد.');

        return $this->redirect('/admin/academic');
    }

    public function toggleMajor(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if ($this->academic->findMajor($id) === null) {
            throw HttpException::notFound();
        }
        $this->academic->toggleMajor($id);
        $this->flash('success', 'وضعیت رشته تغییر کرد.');

        return $this->redirect('/admin/academic');
    }

    public function destroyMajor(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if ($this->academic->findMajor($id) === null) {
            throw HttpException::notFound();
        }
        $this->academic->deleteMajor($id);
        ActivityLogger::log('major.deleted', Auth::id(), 'major', $id, [], 'warning', $request);
        $this->flash('success', 'رشته و ترم‌های آن حذف شد.');

        return $this->redirect('/admin/academic');
    }

    public function storeTerm(Request $request, array $params = []): Response
    {
        $title  = $request->string('title');
        $number = (int) Jalali::toLatinDigits($request->string('number'));

        if (mb_strlen($title) < 2 || mb_strlen($title) > 128) {
            $this->flash('error', 'عنوان ترم باید بین ۲ تا ۱۲۸ کاراکتر باشد.');
            return $this->redirect('/admin/academic');
        }

        $majorId = $request->int('major_id') ?: null;
        if ($majorId !== null && $this->academic->findMajor($majorId) === null) {
            $majorId = null;
        }

        $id = $this->academic->createTerm($title, $number > 0 ? $number : null, $majorId);
        ActivityLogger::log('term.created', Auth::id(), 'term', $id, ['title' => $title], 'notice', $request);
        $this->flash('success', 'ترم اضافه شد.');

        return $this->redirect('/admin/academic');
    }

    public function storeGroup(Request $request, array $params = []): Response
    {
        $termId = $request->int('term_id');
        $title  = $request->string('title');

        if ($this->academic->findTerm($termId) === null) {
            throw HttpException::notFound('ترم یافت نشد.');
        }
        if (mb_strlen($title) < 1 || mb_strlen($title) > 128) {
            $this->flash('error', 'عنوان گروه معتبر نیست.');
            return $this->redirect('/admin/academic');
        }

        $id = $this->academic->createGroup($termId, $title);
        ActivityLogger::log('group.created', Auth::id(), 'group', $id, ['title' => $title], 'notice', $request);
        $this->flash('success', 'گروه اضافه شد.');

        return $this->redirect('/admin/academic');
    }

    public function destroyTerm(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if ($this->academic->findTerm($id) === null) {
            throw HttpException::notFound();
        }
        // Foreign keys set users.term_id to NULL and cascade the groups.
        $this->academic->deleteTerm($id);
        ActivityLogger::log('term.deleted', Auth::id(), 'term', $id, [], 'warning', $request);
        $this->flash('success', 'ترم حذف شد.');

        return $this->redirect('/admin/academic');
    }

    public function destroyGroup(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $this->academic->deleteGroup($id);
        ActivityLogger::log('group.deleted', Auth::id(), 'group', $id, [], 'warning', $request);
        $this->flash('success', 'گروه حذف شد.');

        return $this->redirect('/admin/academic');
    }
}
