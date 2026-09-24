<?php
declare(strict_types=1);

namespace HeleXa\Services\QuestionBank;

use HeleXa\Models\QuestionBank\QbAccessRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\Settings;

/**
 * The one place that decides whether a student may see question-bank content.
 *
 *     real content  ⇔  status is published  AND  this student holds the درس
 *
 * Every route that serves a question calls this. Hiding a menu entry is a
 * courtesy; this is the control. A student who types a URL for a درس they were
 * never granted gets the same closed page as one who followed no link at all.
 *
 * The default for a student with no grant row is deny — which is why access is
 * its own table rather than a column defaulting to 1. A newly created account
 * sees nothing until an admin says otherwise.
 */
final class QbAccess
{
    public const STATUS_COMING_SOON = 'coming_soon';
    public const STATUS_PUBLISHED   = 'published';
    public const STATUS_DISABLED    = 'disabled';

    public const OK          = 'ok';
    public const COMING_SOON = 'coming_soon';
    public const DISABLED    = 'disabled';
    public const NO_ACCESS   = 'no_access';

    public const STATUSES = [
        self::STATUS_COMING_SOON => 'به‌زودی',
        self::STATUS_PUBLISHED   => 'منتشرشده',
        self::STATUS_DISABLED    => 'غیرفعال',
    ];

    public static function status(): string
    {
        $status = (string) Settings::get('qbank_status', self::STATUS_COMING_SOON);

        // An unrecognised value closes the bank rather than opening it. A
        // typo in the settings table should never be the thing that publishes
        // content.
        return isset(self::STATUSES[$status]) ? $status : self::STATUS_COMING_SOON;
    }

    /**
     * Pure decision, so it can be exercised without a database.
     *
     * @return array{allowed:bool, reason:string}
     */
    public static function evaluate(string $status, bool $hasAnyGrant): array
    {
        if ($status === self::STATUS_DISABLED) {
            return ['allowed' => false, 'reason' => self::DISABLED];
        }
        if ($status === self::STATUS_COMING_SOON) {
            return ['allowed' => false, 'reason' => self::COMING_SOON];
        }
        if (!$hasAnyGrant) {
            return ['allowed' => false, 'reason' => self::NO_ACCESS];
        }

        return ['allowed' => true, 'reason' => self::OK];
    }

    /**
     * The decision for one student, with the message the page should show.
     *
     * @return array{allowed:bool, reason:string, status:string, title:string, message:string}
     */
    public static function forStudent(?int $userId): array
    {
        $status  = self::status();
        $granted = $userId !== null && (new QbAccessRepository())->subjectIdsFor($userId) !== [];

        $decision = self::evaluate($status, $granted);

        return $decision + [
            'status'  => $status,
            'title'   => self::titleFor($decision['reason']),
            'message' => self::messageFor($decision['reason']),
        ];
    }

    /**
     * Whether this student may open this particular درس.
     *
     * Checked per request against the grant table rather than against a list
     * built when the page was rendered: a revoked grant takes effect on the
     * next click, not on the next login.
     */
    public static function allowsSubject(int $userId, int $subjectId): bool
    {
        if (self::status() !== self::STATUS_PUBLISHED) {
            return false;
        }

        return (new QbAccessRepository())->has($userId, $subjectId);
    }

    /** Only `disabled` removes the entry; the other two keep it visible and clickable. */
    public static function menuVisible(): bool
    {
        return self::status() !== self::STATUS_DISABLED;
    }

    /**
     * Admins do not go through the student gate — previewing a question before
     * it is published is the point of preview. The check is a permission, not
     * a publication state.
     */
    public static function canPreview(): bool
    {
        return Auth::check() && !Auth::isStudent() && Auth::can('qbank.view');
    }

    public static function comingSoonText(): string
    {
        $text = trim((string) Settings::get('qbank_coming_soon_text', ''));

        return $text !== '' ? $text : 'بانک سوال به‌زودی در دسترس قرار می‌گیرد.';
    }

    private static function titleFor(string $reason): string
    {
        return match ($reason) {
            self::NO_ACCESS => 'دسترسی شما هنوز فعال نشده',
            self::DISABLED  => 'در دسترس نیست',
            default         => 'بانک سوال',
        };
    }

    private static function messageFor(string $reason): string
    {
        return match ($reason) {
            self::COMING_SOON => self::comingSoonText(),
            self::NO_ACCESS   => 'هنوز درسی از بانک سوال برای حساب شما فعال نشده است. '
                               . 'برای فعال‌سازی با پشتیبانی در تماس باش.',
            self::DISABLED    => 'این بخش در حال حاضر در دسترس نیست.',
            default           => '',
        };
    }
}
