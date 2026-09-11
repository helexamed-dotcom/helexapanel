<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Models\Balin\BalinAccessRepository;
use HeleXa\Services\Auth;

/**
 * The one place that decides whether a student may see real Balin content.
 *
 *     real content  ⇔  status is published  AND  this student has access
 *
 * Two independent conditions, checked here and nowhere else. Every route
 * that serves content calls this; hiding a link in the navigation is a
 * courtesy, never the control.
 *
 * The default for a student with no access row is deny. That is the whole
 * reason access is its own table rather than a column with a default of 1:
 * a new student is silent about Balin until an admin says otherwise.
 */
final class Access
{
    public const OK           = 'ok';
    public const DISABLED     = 'disabled';
    public const COMING_SOON  = 'coming_soon';
    public const MAINTENANCE  = 'maintenance';
    public const NO_ACCESS    = 'no_access';

    /**
     * Pure decision, so it can be exercised without a database.
     *
     * @return array{allowed:bool, reason:string}
     */
    public static function evaluate(string $status, bool $hasAccess): array
    {
        if ($status === BalinSettings::STATUS_DISABLED) {
            return ['allowed' => false, 'reason' => self::DISABLED];
        }
        if ($status === BalinSettings::STATUS_MAINTENANCE) {
            // Deliberately ahead of the access check: maintenance closes the
            // island for everyone, including the students who do have access.
            return ['allowed' => false, 'reason' => self::MAINTENANCE];
        }
        if ($status === BalinSettings::STATUS_COMING_SOON) {
            return ['allowed' => false, 'reason' => self::COMING_SOON];
        }
        if (!$hasAccess) {
            return ['allowed' => false, 'reason' => self::NO_ACCESS];
        }

        return ['allowed' => true, 'reason' => self::OK];
    }

    /**
     * The decision for one student, with the message the page should show.
     *
     * @return array{allowed:bool, reason:string, status:string, title:string, message:string, eta:string}
     */
    public static function forStudent(?int $userId): array
    {
        $status    = BalinSettings::status();
        $hasAccess = $userId !== null && (new BalinAccessRepository())->isEnabled($userId);

        $decision = self::evaluate($status, $hasAccess);

        return $decision + [
            'status'  => $status,
            'title'   => self::titleFor($decision['reason']),
            'message' => self::messageFor($decision['reason']),
            'eta'     => $decision['reason'] === self::MAINTENANCE ? BalinSettings::maintenanceEta() : '',
        ];
    }

    /**
     * Whether the navigation entry is shown at all.
     * Only `disabled` removes it; the other three states keep it visible and
     * clickable, which is what the spec asks for while the island is still
     * being built.
     */
    public static function menuVisible(): bool
    {
        return BalinSettings::status() !== BalinSettings::STATUS_DISABLED;
    }

    /**
     * Admins do not go through the student gate. Previewing content before
     * it is published is the entire point of the preview mode, so the check
     * here is a permission, not a publication state.
     */
    public static function canPreview(): bool
    {
        return Auth::check() && !Auth::isStudent() && Auth::can('balin.view');
    }

    private static function titleFor(string $reason): string
    {
        return match ($reason) {
            self::MAINTENANCE => 'در حال به‌روزرسانی',
            self::NO_ACCESS   => 'دسترسی شما هنوز فعال نشده',
            self::DISABLED    => 'در دسترس نیست',
            default           => 'جزیره بالین',
        };
    }

    private static function messageFor(string $reason): string
    {
        return match ($reason) {
            self::COMING_SOON => BalinSettings::comingSoonText(),
            self::MAINTENANCE => BalinSettings::maintenanceText(),
            self::NO_ACCESS   => 'جزیره بالین برای حساب شما فعال نشده است. پیشرفت و امتیاز شما محفوظ می‌ماند؛ '
                               . 'برای فعال‌سازی با پشتیبانی در تماس باشید.',
            self::DISABLED    => 'این بخش در حال حاضر در دسترس نیست.',
            default           => '',
        };
    }
}
