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
            'termNames' => $termNames,
            'groupName' => $group['title'] ?? null,
            'errors'    => [],
            'sessions'  => (new SessionRepository())->activeForUser((int) $user['id']),
        ]);
    }

    public function updateProfile(Request $request, array $params = []): Response
    {
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

    public function showPasswordForm(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'account.password', [
            'title'  => 'تغییر رمز عبور',
            'errors' => [],
        ]);
    }

    public function updatePassword(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $current = (string) $request->input('current_password', '');
        $new     = (string) $request->input('new_password', '');
        $confirm = (string) $request->input('new_password_confirmation', '');

        $minLength = Auth::isAdmin()
            ? (int) Config::get('security.password.admin_min_length', 12)
            : (int) Config::get('security.password.min_length', 10);

        $validator = (new Validator([
            'current_password'          => $current,
            'new_password'              => $new,
            'new_password_confirmation' => $confirm,
        ]))
            ->required('current_password', 'رمز عبور فعلی')
            ->password('new_password', 'رمز عبور جدید', $minLength)
            ->matches('new_password_confirmation', 'new_password', 'تکرار رمز عبور مطابقت ندارد.');

        $users = new UserRepository();
        $fresh = $users->findById((int) $user['id']);

        if (!$validator->fails() && ($fresh === null || !password_verify($current, (string) $fresh['password_hash']))) {
            return $this->page('layouts.app', 'account.password', [
                'title'  => 'تغییر رمز عبور',
                'errors' => ['current_password' => 'رمز عبور فعلی نادرست است.'],
            ], 422);
        }

        if ($validator->fails()) {
            return $this->page('layouts.app', 'account.password', [
                'title'  => 'تغییر رمز عبور',
                'errors' => $validator->errors(),
            ], 422);
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

        ActivityLogger::log('account.password_changed', (int) $user['id'], 'user', (int) $user['id'], [], 'notice', $request);
        $this->flash('success', 'رمز عبور با موفقیت تغییر کرد.');

        return $this->redirect(Auth::isAdmin() ? '/admin' : '/student');
    }
}
