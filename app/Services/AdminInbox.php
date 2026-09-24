<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;

/**
 * The queues an admin works through — tickets, requests, orders, reports —
 * for the 🔔 panel and the admin home.
 */
final class AdminInbox
{
    /**
     * What an admin can act on, as rows with a count and a link. Each count is
     * read only when the admin holds the permission for it.
     *
     * @return list<array{label:string,count:int,href:string,icon:string,tone:string}>
     */
    public static function rows(): array
    {
        $rows = [];
        $count = static function (string $sql): int {
            try {
                return (int) (Database::selectOne($sql)['c'] ?? 0);
            } catch (\PDOException) {
                return 0;
            }
        };

        if (Auth::can('manage_messages')) {
            $rows[] = ['label' => 'تیکت‌های باز پشتیبانی', 'href' => '/admin/support', 'icon' => 'chat', 'tone' => 'teal',
                       'count' => $count("SELECT COUNT(*) AS c FROM support_tickets WHERE status = 'open'")];
        }
        if (Auth::can('manage_students')) {
            $rows[] = ['label' => 'درخواست نوع دانشجو', 'href' => '/admin/student-types/requests', 'icon' => 'school', 'tone' => 'violet',
                       'count' => \HeleXa\Services\StudentTypes::pendingCount()];
            $rows[] = ['label' => 'ورود مشکوک', 'href' => '/admin/security/flags', 'icon' => 'shield', 'tone' => 'red',
                       'count' => \HeleXa\Services\IpWatch::openCount()];
        }
        if (Auth::can('shop.orders')) {
            $rows[] = ['label' => 'سفارش‌های در انتظار بررسی', 'href' => '/admin/shop/orders?status=review', 'icon' => 'receipt', 'tone' => 'orange',
                       'count' => $count("SELECT COUNT(*) AS c FROM shop_orders WHERE status = 'review'")];
        }
        if (Auth::can('qbank.view')) {
            $rows[] = ['label' => 'گزارش اشکال سوال', 'href' => '/admin/qbank/reports', 'icon' => 'qbank', 'tone' => 'amber',
                       'count' => $count("SELECT COUNT(*) AS c FROM qb_reports WHERE status = 'open'")];
        }

        return $rows;
    }

}
