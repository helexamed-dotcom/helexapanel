<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Config;
use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Core\Validator;
use HeleXa\Models\PermissionRepository;
use HeleXa\Models\SessionRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * Admin accounts and their permission grants.
 *
 * Rules enforced server-side:
 *  - Only a super admin may create, edit or delete another super admin.
 *  - Nobody can delete or deactivate their own account.
 *  - An admin can never grant a permission they do not themselves hold,
 *    which stops privilege escalation through the permission form.
 */
final class AdminUserController extends Controller
{
    private UserRepository $users;
    private PermissionRepository $permissions;

    public function __construct()
    {
        $this->users       = new UserRepository();
        $this->permissions = new PermissionRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $filters = ['roles' => ['admin', 'super_admin'], 'search' => $request->string('q')];

        return $this->page('layouts.app', 'admin.admins.index', [
            'title'  => 'مدیران',
            'admins' => $this->users->paginate($filters, 100, 0),
            'search' => $filters['search'],
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.admins.form', [
            'title'       => 'افزودن مدیر',
            'admin'       => null,
            'errors'      => [],
            'old'         => [],
            'allPerms'    => $this->groupedPermissions(),
            'granted'     => [],
            'grantable'   => $this->grantableSlugs(),
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $data   = $this->collect($request);
        $errors = $this->validate($data)->errors();

        if ($this->users->usernameExists($data['username'])) {
            $errors['username'] = 'این نام کاربری قبلاً استفاده شده است.';
        }
        if ($data['role'] === 'super_admin' && !Auth::isSuperAdmin()) {
            throw HttpException::forbidden('فقط مدیر ارشد می‌تواند مدیر ارشد بسازد.');
        }

        if ($errors !== []) {
            return $this->page('layouts.app', 'admin.admins.form', [
                'title'     => 'افزودن مدیر',
                'admin'     => null,
                'errors'    => $errors,
                'old'       => $data,
                'allPerms'  => $this->groupedPermissions(),
                'granted'   => $data['permissions'],
                'grantable' => $this->grantableSlugs(),
            ], 422);
        }

        $temporary = Str::temporaryPassword(16);
        $roleId    = (int) $this->permissions->roleBySlug($data['role'])['id'];

        $id = $this->users->create([
            'uuid'                 => Str::uuid4(),
            'role_id'              => $roleId,
            'username'             => $data['username'],
            'mobile'               => $data['mobile'] ?: null,
            'password_hash'        => Auth::hashPassword($temporary),
            'must_change_password' => 1,
            'full_name'            => $data['full_name'],
            'status'               => $data['status'],
            'created_by'           => Auth::id(),
        ]);

        if ($data['role'] === 'admin') {
            $this->permissions->syncUserPermissions($id, $this->filterGrantable($data['permissions']), Auth::id());
        }

        ActivityLogger::log('admin.created', Auth::id(), 'user', $id,
            ['username' => $data['username'], 'role' => $data['role']], 'warning', $request);

        $_SESSION['_temp_password'] = ['username' => $data['username'], 'password' => $temporary];
        $this->flash('success', 'مدیر ساخته شد. رمز موقت را همین حالا یادداشت کنید.');

        return $this->redirect('/admin/admins');
    }

    public function edit(Request $request, array $params = []): Response
    {
        $admin = $this->findAdmin((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'admin.admins.form', [
            'title'     => 'ویرایش ' . $admin['full_name'],
            'admin'     => $admin,
            'errors'    => [],
            'old'       => $admin,
            'allPerms'  => $this->groupedPermissions(),
            'granted'   => array_keys(array_filter(
                $this->permissions->overridesFor((int) $admin['id']),
                static fn (string $effect): bool => $effect === 'allow'
            )),
            'grantable' => $this->grantableSlugs(),
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $admin  = $this->findAdmin((string) ($params['uuid'] ?? ''));
        $data   = $this->collect($request);
        $errors = $this->validate($data)->errors();

        if ($this->users->usernameExists($data['username'], (int) $admin['id'])) {
            $errors['username'] = 'این نام کاربری قبلاً استفاده شده است.';
        }
        if ((int) $admin['id'] === Auth::id() && $data['status'] !== 'active') {
            $errors['status'] = 'نمی‌توانید حساب خودتان را غیرفعال کنید.';
        }

        if ($errors !== []) {
            return $this->page('layouts.app', 'admin.admins.form', [
                'title'     => 'ویرایش ' . $admin['full_name'],
                'admin'     => $admin,
                'errors'    => $errors,
                'old'       => $data,
                'allPerms'  => $this->groupedPermissions(),
                'granted'   => $data['permissions'],
                'grantable' => $this->grantableSlugs(),
            ], 422);
        }

        $this->users->update((int) $admin['id'], $data + ['email' => null, 'major' => null, 'term_id' => null, 'group_id' => null]);

        if ($admin['role_slug'] === 'admin') {
            $this->permissions->syncUserPermissions((int) $admin['id'], $this->filterGrantable($data['permissions']), Auth::id());
        }
        if ($data['status'] !== 'active') {
            (new SessionRepository())->terminateAllForUser((int) $admin['id'], 'admin_force', Auth::id());
        }

        ActivityLogger::log('admin.updated', Auth::id(), 'user', (int) $admin['id'],
            ['permissions' => count($data['permissions'])], 'warning', $request);
        $this->flash('success', 'اطلاعات مدیر به‌روزرسانی شد.');

        return $this->redirect('/admin/admins');
    }

    public function resetPassword(Request $request, array $params = []): Response
    {
        $admin     = $this->findAdmin((string) ($params['uuid'] ?? ''));
        $temporary = Str::temporaryPassword(16);

        $this->users->markMustChangePassword((int) $admin['id'], Auth::hashPassword($temporary));
        (new SessionRepository())->terminateAllForUser((int) $admin['id'], 'password_change', Auth::id());

        ActivityLogger::log('admin.password_reset', Auth::id(), 'user', (int) $admin['id'], [], 'critical', $request);

        $_SESSION['_temp_password'] = ['username' => $admin['username'], 'password' => $temporary];
        $this->flash('success', 'رمز موقت مدیر ساخته شد.');

        return $this->redirect('/admin/admins');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $admin = $this->findAdmin((string) ($params['uuid'] ?? ''));

        if ((int) $admin['id'] === Auth::id()) {
            throw HttpException::forbidden('نمی‌توانید حساب خودتان را حذف کنید.');
        }
        if ($admin['role_slug'] === 'super_admin' && $this->users->countByRole('super_admin') <= 1) {
            throw HttpException::forbidden('آخرین مدیر ارشد قابل حذف نیست.');
        }

        (new SessionRepository())->terminateAllForUser((int) $admin['id'], 'admin_force', Auth::id());
        $this->users->softDelete((int) $admin['id']);

        ActivityLogger::log('admin.deleted', Auth::id(), 'user', (int) $admin['id'],
            ['username' => $admin['username']], 'critical', $request);
        $this->flash('success', 'مدیر حذف شد.');

        return $this->redirect('/admin/admins');
    }

    /* ------------------------------------------------------------ helpers */

    private function findAdmin(string $uuid): array
    {
        $admin = $this->users->findByUuid($uuid);
        if ($admin === null || !in_array($admin['role_slug'], ['admin', 'super_admin'], true)) {
            throw HttpException::notFound('مدیر یافت نشد.');
        }
        if ($admin['role_slug'] === 'super_admin' && !Auth::isSuperAdmin()) {
            throw HttpException::forbidden('فقط مدیر ارشد می‌تواند حساب مدیر ارشد را مدیریت کند.');
        }
        return $admin;
    }

    private function groupedPermissions(): array
    {
        $grouped = [];
        foreach ($this->permissions->all() as $permission) {
            $grouped[$permission['module']][] = $permission;
        }
        return $grouped;
    }

    /** An admin may only hand out permissions they already hold. */
    private function grantableSlugs(): array
    {
        return Auth::isSuperAdmin()
            ? array_column($this->permissions->all(), 'slug')
            : Auth::permissions();
    }

    private function filterGrantable(array $requested): array
    {
        return array_values(array_intersect($requested, $this->grantableSlugs()));
    }

    private function collect(Request $request): array
    {
        $permissions = $request->input('permissions', []);
        $permissions = is_array($permissions) ? array_map('strval', $permissions) : [];

        $role = $request->string('role', 'admin');
        if (!in_array($role, ['admin', 'super_admin'], true)) {
            $role = 'admin';
        }

        $status = $request->string('status', 'active');
        if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            $status = 'active';
        }

        return [
            'full_name'   => $request->string('full_name'),
            'username'    => $request->string('username'),
            'mobile'      => \HeleXa\Services\Jalali::toLatinDigits($request->string('mobile')),
            'role'        => $role,
            'status'      => $status,
            'permissions' => $permissions,
        ];
    }

    private function validate(array $data): Validator
    {
        return (new Validator($data))
            ->required('full_name', 'نام کامل')
            ->length('full_name', 'نام کامل', 3, 191)
            ->required('username', 'نام کاربری')
            ->username('username', 'نام کاربری')
            ->mobile('mobile', 'شماره موبایل');
    }
}
