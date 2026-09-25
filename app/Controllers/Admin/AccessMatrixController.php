<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\UserRepository;
use HeleXa\Services\AccessProfile;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Modules;
use HeleXa\Services\Settings;
use HeleXa\Services\StudentTypes;

/**
 * «نقشه دسترسی»: every section of the site against every way of getting it —
 * the site-wide switch, a student with no type, each student type and each
 * package — on one screen, saved at once. Below it, any student's effective
 * access with the reason for each section.
 */
final class AccessMatrixController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $packages = [];
        try {
            $packages = Database::select(
                "SELECT p.id, p.uuid, p.title, p.is_full_access, p.is_free, p.modules,
                        (SELECT COUNT(*) FROM package_activations pa WHERE pa.package_id = p.id AND pa.status = 'active') AS holders
                 FROM packages p WHERE p.deleted_at IS NULL AND p.status <> 'archived' ORDER BY p.is_full_access DESC, p.sort_order, p.id"
            );
        } catch (\PDOException) {
        }
        foreach ($packages as &$p) {
            $p['modules_list'] = StudentTypes::decodeModules($p['modules'] ?? '[]');
        }
        unset($p);

        // «ببین این دانشجو چه می‌بیند»
        $probe = null;
        $q = trim(mb_substr($request->string('student'), 0, 80));
        if ($q !== '') {
            $users = new UserRepository();
            $user = $users->findByIdentifier($q) ?? $users->findByMobile(\HeleXa\Services\Phone::normalize($q));
            if ($user !== null && ($user['role_slug'] ?? '') === 'student') {
                $probe = [
                    'user'     => $user,
                    'sections' => AccessProfile::explain($user),
                    'profile'  => AccessProfile::forUser((int) $user['id']),
                    'type'     => StudentTypes::typeIdOf($user) > 0 ? StudentTypes::find(StudentTypes::typeIdOf($user)) : null,
                ];
            } else {
                $this->flash('error', 'دانشجویی با این نام کاربری یا شماره پیدا نشد.');
            }
        }

        $off = Settings::get('modules_off', []);
        $default = Settings::get('modules_default', []);

        return $this->page('layouts.app', 'admin.access_matrix', [
            'title'    => 'نقشه دسترسی',
            'catalog'  => Modules::CATALOG,
            'groups'   => Modules::GROUPS,
            'off'      => is_array($off) ? array_map('strval', $off) : [],
            'default'  => is_array($default) && $default !== [] ? array_map('strval', $default) : array_keys(Modules::CATALOG),
            'types'    => StudentTypes::all(),
            'packages' => $packages,
            'probe'    => $probe,
            'query'    => $q,
            'extraCss' => ['shop-admin'],
        ]);
    }

    /** POST /admin/access-matrix — the whole grid at once. */
    public function save(Request $request, array $params = []): Response
    {
        $known = array_keys(Modules::CATALOG);
        $pick = static function (string $name) use ($request, $known): array {
            return array_values(array_intersect($known, array_map('strval', (array) $request->input($name, []))));
        };

        $site = $pick('site');
        $this->put('modules_off', json_encode(array_values(array_diff($known, $site))));
        $def = $pick('default');
        // Every section ticked means "no limit", which is what an empty list says.
        $this->put('modules_default', json_encode(count($def) === count($known) ? [] : $def));

        foreach (StudentTypes::all() as $t) {
            Database::execute('UPDATE student_types SET modules = :m, updated_at = NOW() WHERE id = :id',
                ['m' => json_encode($pick('type_' . (int) $t['id'])), 'id' => (int) $t['id']]);
        }
        try {
            foreach (Database::select("SELECT id FROM packages WHERE deleted_at IS NULL AND is_full_access = 0 AND status <> 'archived'") as $p) {
                Database::execute('UPDATE packages SET modules = :m, updated_at = NOW() WHERE id = :id',
                    ['m' => json_encode($pick('pkg_' . (int) $p['id'])), 'id' => (int) $p['id']]);
            }
        } catch (\PDOException) {
            $this->flash('error', 'برای بخش‌های پکیج‌ها ابتدا مهاجرت 2026_10_04_access_profiles.sql را اجرا کنید.');
        }
        Settings::flush();
        AccessProfile::flush();
        ActivityLogger::log('access.matrix_saved', Auth::id(), 'setting', null, [], 'notice', $request);
        $this->flash('success', 'نقشه دسترسی ذخیره شد و از همین حالا برای همه دانشجویان اعمال می‌شود.');

        return $this->redirect('/admin/access-matrix');
    }

    private function put(string $key, string $json): void
    {
        Database::execute(
            'INSERT INTO settings (setting_key, setting_value, value_type, is_public, updated_by, updated_at)
             VALUES (:k, :v, \'json\', 0, :u, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type),
                                     updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            ['k' => $key, 'v' => $json, 'u' => Auth::id()]
        );
    }
}
