<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Models\PackageRepository;

/**
 * What one student may see, in one place.
 *
 *   sections  = on site-wide  AND  (allowed by their student type
 *                                   OR turned on by one of their live packages)
 *   a live full-access package turns every section on and opens every piece
 *   of content, including what is added after it was given;
 *   a student type can also hand over packages of its own — given when the
 *   type is approved, taken back when it changes.
 *
 * The content itself is still granted through the modules' own tables (see
 * PackageAccess); this class answers the section question and the
 * "does this student hold package X" question that درسنامه‌ها, mind maps and
 * the figure game ask.
 */
final class AccessProfile
{
    /** @var array<int,array> */
    private static array $cache = [];

    /**
     * @return array{full:?array, packages:list<array{id:int,title:string,modules:list<string>}>, held:array<int,true>, modules:list<string>}
     */
    public static function forUser(int $userId): array
    {
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }
        $out = ['full' => null, 'packages' => [], 'held' => [], 'modules' => []];
        try {
            $rows = Database::select(
                "SELECT p.id, p.title, p.is_full_access, p.modules
                 FROM package_activations pa JOIN packages p ON p.id = pa.package_id AND p.deleted_at IS NULL
                 WHERE pa.user_id = :u AND pa.status = 'active'
                   AND (pa.starts_at IS NULL OR pa.starts_at <= NOW()) AND (pa.ends_at IS NULL OR pa.ends_at >= NOW())",
                ['u' => $userId]
            );
        } catch (\PDOException) {
            try { // before 2026_10_04: no modules column yet
                $rows = Database::select(
                    "SELECT p.id, p.title, p.is_full_access, NULL AS modules
                     FROM package_activations pa JOIN packages p ON p.id = pa.package_id AND p.deleted_at IS NULL
                     WHERE pa.user_id = :u AND pa.status = 'active'
                       AND (pa.starts_at IS NULL OR pa.starts_at <= NOW()) AND (pa.ends_at IS NULL OR pa.ends_at >= NOW())",
                    ['u' => $userId]
                );
            } catch (\PDOException) {
                $rows = [];
            }
        }
        foreach ($rows as $r) {
            $mods = StudentTypes::decodeModules($r['modules'] ?? '[]');
            $out['packages'][] = ['id' => (int) $r['id'], 'title' => $r['title'], 'modules' => $mods, 'full' => (int) $r['is_full_access'] === 1];
            $out['held'][(int) $r['id']] = true;
            $out['modules'] = array_values(array_unique(array_merge($out['modules'], $mods)));
            if ((int) $r['is_full_access'] === 1 && $out['full'] === null) {
                $out['full'] = ['id' => (int) $r['id'], 'title' => $r['title']];
            }
        }
        return self::$cache[$userId] = $out;
    }

    public static function hasFullAccess(int $userId): bool
    {
        return self::forUser($userId)['full'] !== null;
    }

    /**
     * Package id => true for every package this student may use content of.
     * A full-access holder may use all of them.
     *
     * @return array<int,true>
     */
    public static function heldMap(int $userId): array
    {
        $p = self::forUser($userId);
        if ($p['full'] === null) {
            return $p['held'];
        }
        $all = [];
        try {
            foreach (Database::select('SELECT id FROM packages WHERE deleted_at IS NULL') as $r) {
                $all[(int) $r['id']] = true;
            }
        } catch (\PDOException) {
        }
        return $all + $p['held'];
    }

    /** Whether content limited to $packageId (null = everyone) is open to this student. */
    public static function canUsePackageContent(int $userId, ?int $packageId): bool
    {
        return $packageId === null || $packageId <= 0 || self::hasFullAccess($userId) || isset(self::forUser($userId)['held'][$packageId]);
    }

    public static function flush(): void
    {
        self::$cache = [];
        Modules::flush();
    }

    /**
     * Section by section, whether it is on for this student and why — for
     * the admin's student page.
     *
     * @return list<array{key:string,label:string,icon:string,tone:string,on:bool,why:string}>
     */
    public static function explain(array $user): array
    {
        $userId = (int) $user['id'];
        $p = self::forUser($userId);
        $off = Settings::get('modules_off', []);
        $off = is_array($off) ? array_map('strval', $off) : [];
        $type = StudentTypes::typeIdOf($user) > 0 ? StudentTypes::find(StudentTypes::typeIdOf($user)) : null;
        $typeList = Modules::forStudentType($user);
        $out = [];
        foreach (Modules::CATALOG as $key => [$label, , $icon, $tone]) {
            if (in_array($key, $off, true)) {
                $on = false;
                $why = 'در کل سایت خاموش است';
            } elseif ($p['full'] !== null) {
                $on = true;
                $why = 'پکیج کامل «' . $p['full']['title'] . '»';
            } elseif ($typeList === null) {
                $on = true;
                $why = $type ? 'نوع «' . $type['title'] . '» محدودیتی ندارد' : 'بدون نوع — همه بخش‌ها';
            } elseif (in_array($key, $typeList, true)) {
                $on = true;
                $why = $type ? 'نوع «' . $type['title'] . '»' : 'پیش‌فرض دانشجوی بدون نوع';
            } else {
                $from = array_values(array_filter($p['packages'], static fn ($pk) => in_array($key, $pk['modules'], true)));
                $on = $from !== [];
                $why = $on ? 'پکیج «' . $from[0]['title'] . '»' : ($type ? 'نوع «' . $type['title'] . '» شامل آن نیست' : 'در پیش‌فرض نیست');
            }
            $out[] = compact('key', 'label', 'icon', 'tone', 'on', 'why');
        }
        return $out;
    }

    /* ============================================ packages of a student type */

    /** @return list<int> */
    public static function typePackageIds(?array $type): array
    {
        if ($type === null) {
            return [];
        }
        $ids = json_decode((string) ($type['package_ids'] ?? '[]'), true);
        return is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
    }

    /**
     * A student's type changed: they receive the new type's packages and lose
     * the ones the old type gave them — never one an admin, a code or a
     * purchase gave.
     */
    public static function syncTypePackages(int $userId, int $newTypeId, ?int $actorId): void
    {
        try {
            $packages = new PackageRepository();
            $want = $newTypeId > 0 ? self::typePackageIds(StudentTypes::find($newTypeId)) : [];
            // Withdraw what another type gave and this one does not.
            foreach (Database::select(
                "SELECT * FROM package_activations WHERE user_id = :u AND source LIKE 'type:%' AND status = 'active'",
                ['u' => $userId]
            ) as $act) {
                if ($act['source'] === 'type:' . $newTypeId && in_array((int) $act['package_id'], $want, true)) {
                    continue;
                }
                $pkg = $packages->findById((int) $act['package_id']);
                if ($pkg !== null) {
                    ActivationService::setPackageStatus($act, $pkg, 'cancelled', $actorId);
                }
                Database::execute('UPDATE package_activations SET source = NULL WHERE id = :id', ['id' => (int) $act['id']]);
            }
            foreach ($want as $pid) {
                self::giveTypePackage($userId, $pid, $newTypeId, $actorId);
            }
        } catch (\PDOException $e) {
            error_log('[access] ' . $e->getMessage());
        }
        self::flush();
    }

    /**
     * The admin changed which packages a type carries: everyone of that type
     * gets the added ones and loses the removed ones (only if the type gave them).
     *
     * @param list<int> $before
     * @param list<int> $after
     * @return int students updated
     */
    public static function applyTypeChange(int $typeId, array $before, array $after, ?int $actorId): int
    {
        if ($before == $after) {
            return 0;
        }
        $n = 0;
        try {
            foreach (Database::select('SELECT id FROM users WHERE student_type_id = :t AND deleted_at IS NULL', ['t' => $typeId]) as $u) {
                self::syncTypePackages((int) $u['id'], $typeId, $actorId);
                $n++;
            }
        } catch (\PDOException) {
        }
        return $n;
    }

    private static function giveTypePackage(int $userId, int $packageId, int $typeId, ?int $actorId): void
    {
        $packages = new PackageRepository();
        $pkg = $packages->findById($packageId);
        if ($pkg === null || ($pkg['status'] ?? 'published') === 'archived') {
            return;
        }
        $existing = $packages->activation($userId, $packageId);
        if ($existing !== null && $existing['status'] === 'active' && $existing['source'] === null) {
            return; // they already hold it on their own; the type does not own it
        }
        $user = Database::selectOne('SELECT * FROM users WHERE id = :id', ['id' => $userId]);
        if ($user === null) {
            return;
        }
        if ($existing === null || $existing['status'] !== 'active') {
            ActivationService::activatePackage($user, $pkg, ['status' => 'active', 'starts_at' => null, 'ends_at' => null], $actorId);
        }
        Database::execute('UPDATE package_activations SET source = :s WHERE user_id = :u AND package_id = :p',
            ['s' => 'type:' . $typeId, 'u' => $userId, 'p' => $packageId]);
    }
}
