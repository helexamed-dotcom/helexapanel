<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Config;
use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Validator;
use HeleXa\Core\HttpException;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\SessionRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\AvatarStorage;
use HeleXa\Services\Jalali;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

final class AccountController extends Controller
{
    public function showProfile(Request $request, array $params = []): Response
    {
        $user = Auth::user();

        $academic  = new AcademicRepository();
        $termNames = [];
        foreach (\HeleXa\Services\AcademicScope::termIds($user) as $termId) {
            $term = $academic->findTerm($termId);
            if ($term !== null) {
                $termNames[] = (string) $term['title'];
            }
        }

        $group = null;
        if ($user['group_id'] !== null) {
            foreach ($academic->groups() as $row) {
                if ((int) $row['id'] === (int) $user['group_id']) {
                    $group = $row;
                    break;
                }
            }
        }

        return $this->page('layouts.app', 'account.profile', [
            'title'     => 'پروفایل',
            'profile'   => $user,
            // Only students have a tier; an admin's profile shows none.
            'tier'      => ($user['role_slug'] ?? '') === 'student'
                ? \HeleXa\Services\StudentTier::forStudent((int) $user['id'])
                : null,
            'termNames' => $termNames,
            'groupName' => $group['title'] ?? null,
            'errors'    => [],
            'sessions'  => (new SessionRepository())->activeForUser((int) $user['id']),
            'levelCard' => ($user['role_slug'] ?? '') === 'student' ? $this->levelCard((int) $user['id']) : null,
            'classPlan' => ($user['role_slug'] ?? '') === 'student' ? $this->classPlan($user) : null,
        ]);
    }

    /** The classes the student has taken, for the profile card. */
    private function classPlan(array $user): ?array
    {
        try {
            if (!\HeleXa\Services\StudentSchedule::canChoose($user)) {
                return null;
            }
            return [
                'custom'   => \HeleXa\Services\StudentSchedule::isCustom($user),
                'week'     => \HeleXa\Services\StudentSchedule::week($user),
                'choices'  => \HeleXa\Services\StudentSchedule::choices($user),
                'weekdays' => \HeleXa\Services\Jalali::WEEKDAYS,
            ];
        } catch (\Throwable $e) {
            error_log('[profile classes] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * One level for the whole site: Balin island and the question bank pay
     * into the same XP ledger. Never allowed to break the profile page.
     */
    private function levelCard(int $userId): ?array
    {
        try {
            $balin = new \HeleXa\Services\Balin\ProfileService();
            $stats = (new \HeleXa\Models\Balin\BalinStatsRepository())->findOrEmpty($userId);
            $level = \HeleXa\Services\Balin\Level::progress((int) $stats['total_xp']);
            $board = (new \HeleXa\Models\Balin\BalinLeaderboardRepository())->rankFor($userId, 'overall_xp', null);
            $streak = (new \HeleXa\Models\Balin\BalinStreakRepository())->state($userId);

            return [
                'level'  => $level,
                'rank'   => $balin->rankFor($level['level']),
                'stats'  => $stats,
                'place'  => $board !== null ? (int) $board['rank_position'] : null,
                'streak' => (int) ($streak['current_streak'] ?? 0),
                'qbank'  => (new \HeleXa\Models\QuestionBank\QbPracticeRepository())->totalsFor($userId),
            ];
        } catch (\Throwable $e) {
            error_log('[profile level] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Saves the classes a student has taken. Posted to /account/profile with
     * section=classes, so it needs no route of its own.
     */
    private function saveClasses(Request $request): Response
    {
        $user = Auth::user();
        if (($user['role_slug'] ?? '') !== 'student' || !\HeleXa\Models\ScheduleRepository::choiceReady()) {
            return $this->redirect('/account/profile');
        }

        $ids = [];
        if (!$request->bool('reset')) {
            $raw = $request->input('units', []);
            foreach (is_array($raw) ? $raw : [] as $value) {
                foreach (explode(',', is_scalar($value) ? (string) $value : '') as $id) {
                    if (ctype_digit(trim($id))) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        $result = \HeleXa\Services\StudentSchedule::save($user, array_slice(array_values(array_unique($ids)), 0, 500));

        $this->flash('success', $result['saved'] === 0
            ? 'انتخاب‌ها پاک شد؛ در تقویم و داشبورد برنامه گروه خودتان نمایش داده می‌شود.'
            : 'درس‌های اخذشده ذخیره شد؛ تقویم و داشبورد فقط همین کلاس‌ها را نشان می‌دهند.');
        if ($result['clashes'] !== []) {
            $this->flash('error', 'این کلاس‌ها هم‌زمان‌اند: ' . implode(' — ', $result['clashes']));
        }

        return $this->redirect('/account/profile#classes');
    }

    public function updateProfile(Request $request, array $params = []): Response
    {
        if ($request->string('section') === 'classes') {
            return $this->saveClasses($request);
        }

        $user   = Auth::user();
        $users  = new UserRepository();
        $errors = [];

        $fullName = $request->string('full_name');
        $mobile   = Jalali::toLatinDigits($request->string('mobile'));
        $email    = $request->string('email');
        $gender   = $request->string('gender');
        if (!in_array($gender, ['male', 'female'], true)) {
            $gender = null;
        }

        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 191) {
            $errors['full_name'] = 'نام کامل باید بین ۳ تا ۱۹۱ کاراکتر باشد.';
        }
        if ($mobile !== '' && preg_match('/^09\d{9}$/', $mobile) !== 1) {
            $errors['mobile'] = 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.';
        }
        if ($mobile !== '' && $users->mobileExists($mobile, (int) $user['id'])) {
            $errors['mobile'] = 'این شماره موبایل قبلاً ثبت شده است.';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'ایمیل معتبر نیست.';
        }

        // The student cannot change their own username, term, group or status:
        // those are enrollment facts, not profile preferences.
        if ($errors === []) {
            $users->updateProfile((int) $user['id'], [
                'full_name' => $fullName,
                'mobile'    => $mobile !== '' ? $mobile : null,
                'email'     => $email !== '' ? $email : null,
                'gender'    => $gender,
            ]);
            ActivityLogger::log('account.profile_updated', (int) $user['id'], 'user', (int) $user['id'], [], 'info', $request);
            $this->flash('success', 'پروفایل به‌روزرسانی شد.');

            return $this->redirect('/account/profile');
        }

        return $this->page('layouts.app', 'account.profile', [
            'title'     => 'پروفایل',
            'profile'   => array_merge($user, [
                'full_name' => $fullName,
                'mobile'    => $mobile,
                'email'     => $email,
                'gender'    => $gender,
            ]),
            'termNames' => [],
            'groupName' => null,
            'errors'    => $errors,
            'sessions'  => (new SessionRepository())->activeForUser((int) $user['id']),
        ], 422);
    }

    public function uploadAvatar(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $file    = $request->file('avatar');
        $storage = new AvatarStorage();

        if ($file === null) {
            $this->flash('error', 'تصویری انتخاب نشده است.');
            return $this->redirect('/account/profile');
        }

        try {
            $path = $storage->store($file, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/account/profile');
        }

        $storage->delete($user['avatar_path'] ?? null);
        (new UserRepository())->setAvatar((int) $user['id'], $path);
        $this->flash('success', 'تصویر پروفایل به‌روزرسانی شد.');

        return $this->redirect('/account/profile');
    }

    public function removeAvatar(Request $request, array $params = []): Response
    {
        $user = Auth::user();

        (new AvatarStorage())->delete($user['avatar_path'] ?? null);
        (new UserRepository())->setAvatar((int) $user['id'], null);
        $this->flash('success', 'تصویر پروفایل حذف شد.');

        return $this->redirect('/account/profile');
    }

    /**
     * Serves a private avatar. A student may only fetch their own; admins with
     * the student permission may fetch any, because the panel lists them.
     */
    public function avatar(Request $request, array $params = []): Response
    {
        $uuid    = (string) ($params['uuid'] ?? '');
        $viewer  = Auth::user();
        $target  = (new UserRepository())->findByUuid($uuid);

        if ($target === null) {
            throw HttpException::notFound();
        }
        if ((int) $target['id'] !== (int) $viewer['id'] && !Auth::can('manage_students') && !Auth::can('manage_admins')) {
            throw HttpException::forbidden();
        }

        $resolved = (new AvatarStorage())->resolve($target['avatar_path'] ?? null);
        if ($resolved === null) {
            throw HttpException::notFound();
        }

        $body = (string) file_get_contents($resolved['path']);

        return Response::make($body, 200, [
            'Content-Type'           => $resolved['mime'],
            'Content-Length'         => (string) strlen($body),
            'Cache-Control'          => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline',
        ]);
    }

    /**
     * True when the account has no password at all — it was created by a
     * texted code and its owner has never chosen one.
     *
     * Everything about this form turns on the answer: with no password there
     * is nothing to ask the student to confirm, and demanding a "current
     * password" they never had would lock them out of ever setting one.
     */
    private function hasPassword(array $user): bool
    {
        return is_string($user['password_hash'] ?? null) && $user['password_hash'] !== '';
    }

    private function passwordPageData(array $user, array $errors = []): array
    {
        $creating = !$this->hasPassword($user);

        return [
            'title'     => $creating ? 'ایجاد رمز عبور' : 'تغییر رمز عبور',
            'creating'  => $creating,
            'minLength' => $this->passwordMinLength(),
            'errors'    => $errors,
        ];
    }

    private function passwordMinLength(): int
    {
        return Auth::isAdmin()
            ? (int) Config::get('security.password.admin_min_length', 12)
            : (int) Config::get('security.password.min_length', 10);
    }

    public function showPasswordForm(Request $request, array $params = []): Response
    {
        // Read fresh rather than trusting the session copy: a password set in
        // another tab a moment ago must change which form this renders.
        $user = (new UserRepository())->findById((int) Auth::id()) ?? Auth::user();

        return $this->page('layouts.app', 'account.password', $this->passwordPageData($user));
    }

    public function updatePassword(Request $request, array $params = []): Response
    {
        $users = new UserRepository();
        $user  = $users->findById((int) Auth::id());

        if ($user === null) {
            return $this->redirect('/login');
        }

        $current  = (string) $request->input('current_password', '');
        $new      = (string) $request->input('new_password', '');
        $confirm  = (string) $request->input('new_password_confirmation', '');
        $creating = !$this->hasPassword($user);

        $minLength = $this->passwordMinLength();

        $validator = (new Validator([
            'current_password'          => $current,
            'new_password'              => $new,
            'new_password_confirmation' => $confirm,
        ]))
            ->password('new_password', 'رمز عبور جدید', $minLength)
            ->matches('new_password_confirmation', 'new_password', 'تکرار رمز عبور مطابقت ندارد.');

        // The current password is required only when there is one to give.
        if (!$creating) {
            $validator->required('current_password', 'رمز عبور فعلی');
        }

        if (!$validator->fails() && !$creating && !password_verify($current, (string) $user['password_hash'])) {
            return $this->page('layouts.app', 'account.password',
                $this->passwordPageData($user, ['current_password' => 'رمز عبور فعلی نادرست است.']), 422);
        }

        if ($validator->fails()) {
            return $this->page('layouts.app', 'account.password',
                $this->passwordPageData($user, $validator->errors()), 422);
        }

        $users->updatePassword((int) $user['id'], Auth::hashPassword($new));

        // A password change invalidates every other device, including any
        // browser that was remembering this account.
        (new SessionRepository())->terminateAllForUser(
            (int) $user['id'],
            'password_change',
            null,
            Auth::currentSessionId()
        );
        Auth::revokeRememberTokens((int) $user['id'], 'password_change');

        ActivityLogger::log(
            $creating ? 'account.password_created' : 'account.password_changed',
            (int) $user['id'], 'user', (int) $user['id'], [], 'notice', $request
        );
        $this->flash('success', $creating
            ? 'رمز عبور ساخته شد. از این پس می‌توانی با شماره موبایل و رمز عبور وارد شوی.'
            : 'رمز عبور با موفقیت تغییر کرد.');

        return $this->redirect('/account/profile');
    }
}
