<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Core\Validator;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\PermissionRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Jalali;
use HeleXa\Services\PackageAccess;
use HeleXa\Services\Phone;
use HeleXa\Services\Settings;

/**
 * A student opening their own account.
 *
 * Off unless an admin switches it on in the settings; while it is off both
 * routes answer 404, so the page does not exist rather than existing and
 * refusing. The form asks for the same things an admin fills in for a
 * student — university, major, terms, group — plus a password the student
 * chooses, then signs them straight in.
 *
 * The student lands with the free packages already active, and nothing
 * else: every other door is still opened by an admin.
 */
final class RegisterController extends Controller
{
    public function show(Request $request, array $params = []): Response
    {
        $this->ensureOpen();
        return $this->page('layouts.auth', 'auth.register', $this->viewData());
    }

    public function register(Request $request, array $params = []): Response
    {
        $this->ensureOpen();

        // A form field no person fills in. Bots that do are answered as if all
        // went well, with nothing created.
        if ($request->string('website') !== '') {
            return $this->redirect('/login');
        }

        $academic = new AcademicRepository();
        $users    = new UserRepository();
        $data     = $this->collect($request, $academic);

        $validator = (new Validator($data))
            ->required('full_name', 'نام و نام خانوادگی')
            ->length('full_name', 'نام و نام خانوادگی', 3, 191)
            ->required('password', 'رمز عبور')
            ->password('password', 'رمز عبور')
            ->matches('password_confirm', 'password', 'تکرار رمز عبور با خود رمز یکسان نیست.');

        // Mobile or username: either one is enough to sign in with, so only
        // one of them is required. The username falls back to the mobile.
        if ($data['mobile'] !== '') {
            $validator->mobile('mobile', 'شماره موبایل');
        }
        if ($data['username'] !== '' && $data['username'] !== $data['mobile']) {
            $validator->username('username', 'نام کاربری');
        }

        $errors = $validator->errors();
        if ($data['mobile'] === '' && $data['username'] === '') {
            $errors['username'] = 'شماره موبایل یا نام کاربری را وارد کنید — دست‌کم یکی لازم است.';
        }

        if (!isset($errors['mobile']) && $data['mobile'] !== '' && $users->findByMobile($data['mobile']) !== null) {
            $errors['mobile'] = 'با این شماره قبلاً حساب ساخته شده است. از صفحه ورود وارد شوید.';
        }
        if (!isset($errors['username']) && $data['username'] !== '' && $users->usernameExists($data['username'])) {
            $errors['username'] = 'این نام کاربری قبلاً گرفته شده است.';
        }

        // The academic choices are required only where there is something to
        // choose — a site that has not set up universities still registers.
        $options = $this->options($academic);
        if ($options['universities'] !== [] && $data['university_id'] === null) {
            $errors['university_id'] = 'دانشگاه را انتخاب کنید.';
        }
        if ($data['university_id'] !== null && $data['major_id'] === null
            && $academic->majors($data['university_id'], true) !== []) {
            $errors['major_id'] = 'رشته را انتخاب کنید.';
        }
        if ($data['terms'] === [] && $this->termsFor($options['terms'], $data['major_id']) !== []) {
            $errors['terms'] = 'دست‌کم یک ترم را انتخاب کنید.';
        }

        if ($errors !== []) {
            unset($data['password'], $data['password_confirm']);
            return $this->page('layouts.auth', 'auth.register', $this->viewData($errors, $data), 422);
        }

        $role = (new PermissionRepository())->roleBySlug('student');
        if ($role === null) {
            throw HttpException::notFound();
        }

        $id = $users->create([
            'uuid'                 => Str::uuid4(),
            'role_id'              => (int) $role['id'],
            'username'             => $data['username'],
            'mobile'               => $data['mobile'] !== '' ? $data['mobile'] : null,
            'password_hash'        => Auth::hashPassword($data['password']),
            'must_change_password' => 0,
            'full_name'            => $data['full_name'],
            'gender'               => $data['gender'],
            'university_id'        => $data['university_id'],
            'major_id'             => $data['major_id'],
            'term_id'              => $data['terms'][0] ?? null,
            'group_id'             => $data['group_id'],
            'status'               => 'active',
            'registration_source'  => 'self',
        ]);
        $academic->syncSemesters($id, $data['terms'], $data['major_id']);

        ActivityLogger::log('student.self_registered', $id, 'user', $id,
            ['mobile' => $data['mobile']], 'notice', $request);

        $user = $users->findById($id);
        $codeMessage = null;
        if ($user !== null) {
            PackageAccess::grantFree($user, null);

            // An activation code given at sign-up is used straight away.
            if (trim($request->string('activation_code')) !== '') {
                $redeemed    = \HeleXa\Services\ActivationCodes::redeem($user, $request->string('activation_code'));
                $codeMessage = $redeemed['message'];
            }
        }

        // Straight in, through the ordinary sign-in so every rule it applies
        // (single device, lockout, remember-me) applies here too.
        $result = Auth::attempt($request, $data['username'], $data['password'], true);
        if ($result['ok']) {
            $this->flash('success', 'خوش آمدید! حساب شما ساخته شد.' . ($codeMessage !== null ? ' ' . $codeMessage : ''));
            return $this->redirect('/student');
        }

        $this->flash('success', 'حساب شما ساخته شد. با شماره موبایل و رمزی که ساختید وارد شوید.');
        return $this->redirect('/login');
    }

    /* ------------------------------------------------------------ helpers */

    private function ensureOpen(): void
    {
        if (!Settings::bool('registration_enabled', false)) {
            throw HttpException::notFound();
        }
    }

    /** @return array<string,mixed> */
    private function collect(Request $request, AcademicRepository $academic): array
    {
        $mobile = Phone::normalize(Jalali::toLatinDigits($request->string('mobile')));
        if ($mobile === '') {
            $mobile = Jalali::toLatinDigits($request->string('mobile'));
        }
        $username = trim(Jalali::toLatinDigits($request->string('username')));

        $gender = $request->string('gender');
        $gender = in_array($gender, ['male', 'female'], true) ? $gender : null;

        $universityId = $request->int('university_id') ?: null;
        $majorId      = $request->int('major_id') ?: null;
        $groupId      = $request->int('group_id') ?: null;

        $university = $universityId !== null ? $academic->findUniversity($universityId) : null;
        if ($university === null || (int) ($university['is_active'] ?? 1) !== 1) {
            $universityId = null;
        }
        if ($majorId !== null && ($universityId === null || !$academic->majorBelongsToUniversity($majorId, $universityId))) {
            $majorId = null;
        }

        $termIds = [];
        $raw     = $request->input('terms', []);
        if (is_array($raw)) {
            foreach ($raw as $value) {
                $termId = (int) $value;
                if ($termId > 0 && $academic->termBelongsToMajor($termId, $majorId)) {
                    $termIds[] = $termId;
                }
            }
        }
        $termIds = array_values(array_unique($termIds));

        if ($groupId !== null) {
            $belongs = false;
            foreach ($termIds as $termId) {
                if ($academic->groupBelongsToTerm($groupId, $termId)) {
                    $belongs = true;
                    break;
                }
            }
            $groupId = $belongs ? $groupId : null;
        }

        return [
            'full_name'        => trim($request->string('full_name')),
            'mobile'           => $mobile,
            'username'         => $username !== '' ? $username : $mobile,
            'password'         => (string) $request->input('password', ''),
            'password_confirm' => (string) $request->input('password_confirm', ''),
            'gender'           => $gender,
            'university_id'    => $universityId,
            'major_id'         => $majorId,
            'terms'            => $termIds,
            'group_id'         => $groupId,
            'activation_code'  => mb_substr(trim($request->string('activation_code')), 0, 32),
        ];
    }

    /** @return array{universities:array,majors:array,terms:array,groups:array} */
    private function options(AcademicRepository $academic): array
    {
        return [
            'universities' => $academic->universities(true),
            'majors'       => $academic->majors(null, true),
            'terms'        => array_values(array_filter(
                $academic->terms(),
                static fn (array $t): bool => (int) ($t['is_active'] ?? 1) === 1
            )),
            'groups'       => $academic->groups(),
        ];
    }

    /** Terms offered to a major: its own plus the general ones. */
    private function termsFor(array $terms, ?int $majorId): array
    {
        return array_values(array_filter($terms, static fn (array $t): bool =>
            $t['major_id'] === null || ($majorId !== null && (int) $t['major_id'] === $majorId)));
    }

    /** @param array<string,string> $errors */
    private function viewData(array $errors = [], array $old = []): array
    {
        return ['title' => 'ثبت‌نام', 'errors' => $errors, 'old' => $old]
            + $this->options(new AcademicRepository());
    }
}
