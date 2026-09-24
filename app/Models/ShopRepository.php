<?php
declare(strict_types=1);

namespace HeleXa\Models;

use HeleXa\Core\Database;
use HeleXa\Core\Str;

/**
 * «فروشگاه»: categories, products, carts, discount codes and orders.
 * Prices are whole تومان.
 */
final class ShopRepository extends BaseRepository
{
    public const KINDS  = ['package' => 'پکیج آموزشی', 'physical' => 'کالای فیزیکی', 'service' => 'خدمت / کلاس'];
    public const STATUS = [
        'pending'   => ['در انتظار پرداخت', 'amber'],
        'review'    => ['در انتظار تأیید رسید', 'orange'],
        'paid'      => ['پرداخت‌شده', 'green'],
        'rejected'  => ['رد شده', 'red'],
        'cancelled' => ['لغو شده', 'slate'],
    ];

    public static function ready(): bool
    {
        try {
            Database::selectOne('SELECT 1 FROM shop_products LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /* ========================================================== categories */

    public function categories(): array
    {
        return $this->select(
            "SELECT c.*, (SELECT COUNT(*) FROM shop_products p WHERE p.category_id = c.id AND p.deleted_at IS NULL AND p.status = 'published') AS products
             FROM shop_categories c ORDER BY c.sort_order, c.id"
        );
    }

    public function saveCategory(?int $id, array $d): int
    {
        $row = ['t' => $d['title'], 'i' => $d['icon'], 'tn' => $d['tone'], 's' => (int) $d['sort_order']];
        if ($id !== null) {
            $this->execute('UPDATE shop_categories SET title = :t, icon = :i, tone = :tn, sort_order = :s WHERE id = :id', $row + ['id' => $id]);
            return $id;
        }
        return $this->insert('INSERT INTO shop_categories (title, icon, tone, sort_order, created_at) VALUES (:t, :i, :tn, :s, :now)', $row + ['now' => $this->now()]);
    }

    public function deleteCategory(int $id): void
    {
        $this->execute('DELETE FROM shop_categories WHERE id = :id', ['id' => $id]);
    }

    /* ============================================================ products */

    /** @param array{q?:string, category?:int, status?:string, kind?:string} $f */
    public function products(array $f = [], bool $publishedOnly = false): array
    {
        $where  = ['p.deleted_at IS NULL'];
        $params = [];
        if ($publishedOnly) {
            $where[] = "p.status = 'published'";
        } elseif (isset(['draft' => 1, 'published' => 1, 'archived' => 1][$f['status'] ?? ''])) {
            $where[] = 'p.status = :st';
            $params['st'] = $f['status'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(p.title LIKE :q1 OR p.subtitle LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['q']) . '%';
        }
        if ((int) ($f['category'] ?? 0) > 0) {
            $where[] = 'p.category_id = :c';
            $params['c'] = (int) $f['category'];
        }
        if (isset(self::KINDS[$f['kind'] ?? ''])) {
            $where[] = 'p.kind = :k';
            $params['k'] = $f['kind'];
        }
        $order = match ($f['sort'] ?? '') {
            'cheap'   => 'p.price ASC',
            'popular' => 'p.sold_count DESC',
            'new'     => 'p.id DESC',
            default   => 'p.featured DESC, p.sort_order, p.id DESC',
        };
        return $this->decodeAll($this->select(
            'SELECT p.*, c.title AS category_title, pk.title AS package_title
             FROM shop_products p
             LEFT JOIN shop_categories c ON c.id = p.category_id
             LEFT JOIN packages pk ON pk.id = p.package_id
             WHERE ' . implode(' AND ', $where) . " ORDER BY {$order} LIMIT 500",
            $params
        ));
    }

    public function findProduct(string $uuid): ?array
    {
        $row = $this->selectOne(
            'SELECT p.*, c.title AS category_title, pk.title AS package_title FROM shop_products p
             LEFT JOIN shop_categories c ON c.id = p.category_id LEFT JOIN packages pk ON pk.id = p.package_id
             WHERE p.uuid = :u AND p.deleted_at IS NULL',
            ['u' => $uuid]
        );
        return $row === null ? null : $this->decode($row);
    }

    public function findProductById(int $id): ?array
    {
        $row = $this->selectOne('SELECT * FROM shop_products WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        return $row === null ? null : $this->decode($row);
    }

    public function saveProduct(?int $id, array $d, ?int $actorId): array
    {
        $row = [
            'cat'   => $d['category_id'],   'kind'  => $d['kind'],         'title' => $d['title'],
            'sub'   => $d['subtitle'],      'descr' => $d['description'],  'feat'  => json_encode($d['features'], JSON_UNESCAPED_UNICODE),
            'tone'  => $d['tone'],          'badge' => $d['badge'],        'price' => $d['price'],
            'cmp'   => $d['compare_price'], 'pkg'   => $d['package_id'],   'days'  => $d['access_days'],
            'stock' => $d['stock'],         'maxq'  => $d['max_per_order'], 'fe'   => $d['featured'],
            'st'    => $d['status'],        'so'    => $d['sort_order'],   'gal'   => json_encode($d['gallery'] ?? [], JSON_UNESCAPED_UNICODE),
            'now'   => $this->now(),
        ];
        if ($id !== null) {
            $this->execute(
                'UPDATE shop_products SET category_id = :cat, kind = :kind, title = :title, subtitle = :sub, description = :descr,
                    features = :feat, tone = :tone, badge = :badge, price = :price, compare_price = :cmp, package_id = :pkg,
                    access_days = :days, stock = :stock, max_per_order = :maxq, featured = :fe, status = :st, sort_order = :so,
                    gallery = :gal, updated_at = :now
                 WHERE id = :id',
                $row + ['id' => $id]
            );
            return $this->findProductById($id) ?? [];
        }
        $uuid = Str::uuid4();
        $newId = $this->insert(
            'INSERT INTO shop_products (uuid, category_id, kind, title, subtitle, description, features, tone, badge, price, compare_price,
                package_id, access_days, stock, max_per_order, featured, status, sort_order, gallery, created_by, created_at)
             VALUES (:uuid, :cat, :kind, :title, :sub, :descr, :feat, :tone, :badge, :price, :cmp, :pkg, :days, :stock, :maxq, :fe, :st, :so, :gal, :by, :now)',
            $row + ['uuid' => $uuid, 'by' => $actorId]
        );
        return $this->findProductById($newId) ?? [];
    }

    public function setCover(int $id, ?string $path): void
    {
        $this->execute('UPDATE shop_products SET cover_path = :p, updated_at = :now WHERE id = :id', ['p' => $path, 'id' => $id, 'now' => $this->now()]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->execute('UPDATE shop_products SET status = :s, updated_at = :now WHERE id = :id', ['s' => $status, 'id' => $id, 'now' => $this->now()]);
    }

    public function softDeleteProduct(int $id): void
    {
        $this->execute('UPDATE shop_products SET deleted_at = :now, status = \'archived\' WHERE id = :id', ['id' => $id, 'now' => $this->now()]);
        $this->execute('DELETE FROM shop_cart_items WHERE product_id = :id', ['id' => $id]);
    }

    /* ================================================================ cart */

    /** @return list<array> cart lines joined to the live product */
    public function cart(int $userId): array
    {
        return $this->decodeAll($this->select(
            "SELECT p.*, ci.qty, ci.created_at AS added_at FROM shop_cart_items ci
             JOIN shop_products p ON p.id = ci.product_id AND p.deleted_at IS NULL AND p.status = 'published'
             WHERE ci.user_id = :u ORDER BY ci.created_at",
            ['u' => $userId]
        ));
    }

    public function cartCount(int $userId): int
    {
        return (int) Database::scalar(
            "SELECT COALESCE(SUM(ci.qty), 0) FROM shop_cart_items ci JOIN shop_products p ON p.id = ci.product_id
             AND p.deleted_at IS NULL AND p.status = 'published' WHERE ci.user_id = :u",
            ['u' => $userId]
        );
    }

    public function setCartQty(int $userId, int $productId, int $qty): void
    {
        if ($qty <= 0) {
            $this->execute('DELETE FROM shop_cart_items WHERE user_id = :u AND product_id = :p', ['u' => $userId, 'p' => $productId]);
            return;
        }
        $this->execute(
            'INSERT INTO shop_cart_items (user_id, product_id, qty, created_at) VALUES (:u, :p, :q, :now)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty)',
            ['u' => $userId, 'p' => $productId, 'q' => $qty, 'now' => $this->now()]
        );
    }

    public function cartQty(int $userId, int $productId): int
    {
        return (int) Database::scalar('SELECT qty FROM shop_cart_items WHERE user_id = :u AND product_id = :p', ['u' => $userId, 'p' => $productId]);
    }

    public function clearCart(int $userId): void
    {
        $this->execute('DELETE FROM shop_cart_items WHERE user_id = :u', ['u' => $userId]);
    }

    /* ============================================================= coupons */

    public function coupons(): array
    {
        return $this->select(
            "SELECT c.*, (SELECT COUNT(*) FROM shop_orders o WHERE o.coupon_id = c.id AND o.status IN ('paid','review')) AS used
             FROM shop_coupons c ORDER BY c.status, c.id DESC"
        );
    }

    public function findCoupon(string $code): ?array
    {
        return $this->selectOne('SELECT * FROM shop_coupons WHERE code = :c', ['c' => mb_strtoupper(trim($code))]);
    }

    public function findCouponById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM shop_coupons WHERE id = :id', ['id' => $id]);
    }

    /** Uses that still count: paid, and receipts waiting for review. */
    public function couponUses(int $couponId, ?int $userId = null): int
    {
        $sql = "SELECT COUNT(*) FROM shop_orders WHERE coupon_id = :c AND status IN ('paid','review')";
        $p = ['c' => $couponId];
        if ($userId !== null) {
            $sql .= ' AND user_id = :u';
            $p['u'] = $userId;
        }
        return (int) Database::scalar($sql, $p);
    }

    public function saveCoupon(?int $id, array $d): int
    {
        $row = [
            'code' => $d['code'], 'kind' => $d['kind'], 'amt' => $d['amount'], 'mx' => $d['max_discount'], 'mn' => $d['min_total'],
            'sa' => $d['starts_at'], 'ea' => $d['ends_at'], 'ul' => $d['usage_limit'], 'pu' => $d['per_user_limit'],
            'pi' => $d['product_ids'] === [] ? null : json_encode(array_values($d['product_ids'])), 'st' => $d['status'],
            'note' => $d['note'], 'now' => $this->now(),
        ];
        if ($id !== null) {
            $this->execute(
                'UPDATE shop_coupons SET code = :code, kind = :kind, amount = :amt, max_discount = :mx, min_total = :mn, starts_at = :sa,
                    ends_at = :ea, usage_limit = :ul, per_user_limit = :pu, product_ids = :pi, status = :st, note = :note, updated_at = :now
                 WHERE id = :id',
                $row + ['id' => $id]
            );
            return $id;
        }
        return $this->insert(
            'INSERT INTO shop_coupons (code, kind, amount, max_discount, min_total, starts_at, ends_at, usage_limit, per_user_limit, product_ids, status, note, created_at)
             VALUES (:code, :kind, :amt, :mx, :mn, :sa, :ea, :ul, :pu, :pi, :st, :note, :now)',
            $row
        );
    }

    public function deleteCoupon(int $id): void
    {
        $this->execute('DELETE FROM shop_coupons WHERE id = :id', ['id' => $id]);
    }

    /* ============================================================== orders */

    /** @param list<array{product:array, qty:int}> $lines */
    public function createOrder(int $userId, array $totals, array $lines, string $method, ?array $coupon, ?array $shipping): array
    {
        return Database::transaction(function () use ($userId, $totals, $lines, $method, $coupon, $shipping): array {
            $uuid = Str::uuid4();
            $id = $this->insert(
                'INSERT INTO shop_orders (uuid, number, user_id, status, method, subtotal, discount, total, coupon_id, coupon_code, shipping, created_at)
                 VALUES (:uuid, :num, :u, \'pending\', :m, :sub, :dis, :tot, :cid, :code, :ship, :now)',
                [
                    'uuid' => $uuid, 'num' => 'T' . bin2hex(random_bytes(4)), 'u' => $userId, 'm' => $method,
                    'sub' => $totals['subtotal'], 'dis' => $totals['discount'], 'tot' => $totals['total'],
                    'cid' => $coupon['id'] ?? null, 'code' => $coupon['code'] ?? null,
                    'ship' => $shipping === null ? null : json_encode($shipping, JSON_UNESCAPED_UNICODE), 'now' => $this->now(),
                ]
            );
            // A short, readable order number: year + running id.
            $this->execute('UPDATE shop_orders SET number = :n WHERE id = :id', ['n' => (string) (1000 + $id), 'id' => $id]);
            foreach ($lines as $l) {
                $p = $l['product'];
                $this->insert(
                    'INSERT INTO shop_order_items (order_id, product_id, title, kind, price, qty, package_id, access_days)
                     VALUES (:o, :p, :t, :k, :pr, :q, :pk, :d)',
                    ['o' => $id, 'p' => (int) $p['id'], 't' => $p['title'], 'k' => $p['kind'], 'pr' => (int) $p['price'], 'q' => $l['qty'],
                     'pk' => $p['package_id'] !== null ? (int) $p['package_id'] : null, 'd' => $p['access_days'] !== null ? (int) $p['access_days'] : null]
                );
            }
            return $this->findOrderById($id) ?? [];
        });
    }

    public function findOrder(string $uuid): ?array
    {
        return $this->withUser($this->selectOne('SELECT * FROM shop_orders WHERE uuid = :u', ['u' => $uuid]));
    }

    public function findOrderById(int $id): ?array
    {
        return $this->withUser($this->selectOne('SELECT * FROM shop_orders WHERE id = :id', ['id' => $id]));
    }

    public function findOrderByAuthority(string $authority): ?array
    {
        return $this->withUser($this->selectOne('SELECT * FROM shop_orders WHERE gateway_authority = :a', ['a' => $authority]));
    }

    public function items(int $orderId): array
    {
        return $this->select(
            'SELECT i.*, p.uuid AS product_uuid, p.cover_path, p.tone FROM shop_order_items i
             LEFT JOIN shop_products p ON p.id = i.product_id WHERE i.order_id = :o ORDER BY i.id',
            ['o' => $orderId]
        );
    }

    public function ordersFor(int $userId): array
    {
        return $this->select(
            'SELECT o.*, (SELECT GROUP_CONCAT(i.title SEPARATOR \'، \') FROM shop_order_items i WHERE i.order_id = o.id) AS titles
             FROM shop_orders o WHERE o.user_id = :u ORDER BY o.id DESC LIMIT 100',
            ['u' => $userId]
        );
    }

    /** @param array{status?:string, q?:string} $f */
    public function orders(array $f, int $limit = 200): array
    {
        $where = ['1 = 1'];
        $p = [];
        if (isset(self::STATUS[$f['status'] ?? ''])) {
            $where[] = 'o.status = :st';
            $p['st'] = $f['status'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(o.number LIKE :q1 OR u.full_name LIKE :q2 OR u.username LIKE :q3 OR u.mobile LIKE :q4)';
            $p['q1'] = $p['q2'] = $p['q3'] = $p['q4'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['q']) . '%';
        }
        return $this->select(
            'SELECT o.*, u.full_name, u.username, u.mobile,
                    (SELECT GROUP_CONCAT(i.title SEPARATOR \'، \') FROM shop_order_items i WHERE i.order_id = o.id) AS titles
             FROM shop_orders o JOIN users u ON u.id = o.user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(o.status, \'review\') DESC, o.id DESC LIMIT ' . max(1, min(1000, $limit)),
            $p
        );
    }

    public function statusCounts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUS), 0);
        foreach ($this->select('SELECT status, COUNT(*) AS c FROM shop_orders GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    public function update(int $orderId, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $set = [];
        $p = ['id' => $orderId, 'now' => $this->now()];
        foreach ($fields as $k => $v) {
            if (preg_match('/^[a-z_]+$/', $k) !== 1) {
                continue;
            }
            $set[] = "{$k} = :f_{$k}";
            $p['f_' . $k] = $v;
        }
        $this->execute('UPDATE shop_orders SET ' . implode(', ', $set) . ', updated_at = :now WHERE id = :id', $p);
    }

    /**
     * Moves an order from one status to another only if it is still in one
     * of $from — the guard that keeps a gateway callback and an admin click
     * from both fulfilling the same order.
     */
    public function transition(int $orderId, array $from, string $to, array $fields = []): bool
    {
        $in = implode(',', array_map(static fn (string $s): string => Database::connection()->quote($s), $from));
        $set = ['status = :to', 'updated_at = :now'];
        $p = ['id' => $orderId, 'to' => $to, 'now' => $this->now()];
        foreach ($fields as $k => $v) {
            if (preg_match('/^[a-z_]+$/', $k) === 1) {
                $set[] = "{$k} = :f_{$k}";
                $p['f_' . $k] = $v;
            }
        }
        return $this->execute('UPDATE shop_orders SET ' . implode(', ', $set) . " WHERE id = :id AND status IN ({$in})", $p) === 1;
    }

    public function markFulfilled(int $itemId): void
    {
        $this->execute('UPDATE shop_order_items SET fulfilled_at = :now WHERE id = :id', ['id' => $itemId, 'now' => $this->now()]);
    }

    public function countSold(int $productId, int $qty): void
    {
        $this->execute(
            'UPDATE shop_products SET sold_count = sold_count + :q, stock = CASE WHEN stock IS NULL THEN NULL ELSE GREATEST(0, stock - :q2) END WHERE id = :id',
            ['q' => $qty, 'q2' => $qty, 'id' => $productId]
        );
    }

    public function salesSummary(): array
    {
        $row = $this->selectOne(
            "SELECT COALESCE(SUM(CASE WHEN paid_at >= CURDATE() THEN total END), 0) AS today,
                    COALESCE(SUM(CASE WHEN paid_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN total END), 0) AS month,
                    COUNT(CASE WHEN paid_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) AS month_orders,
                    COALESCE(SUM(total), 0) AS all_time
             FROM shop_orders WHERE status = 'paid'"
        ) ?? [];
        return array_map('intval', $row);
    }

    /** Paid totals per day for the last $days days, oldest first. */
    public function daily(int $days = 14): array
    {
        $rows = [];
        foreach ($this->select(
            "SELECT DATE(paid_at) AS d, SUM(total) AS t FROM shop_orders WHERE status = 'paid' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL :n DAY) GROUP BY DATE(paid_at)",
            ['n' => $days - 1]
        ) as $r) {
            $rows[$r['d']] = (int) $r['t'];
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} day"));
            $out[$d] = $rows[$d] ?? 0;
        }
        return $out;
    }

    public function topProducts(int $limit = 5): array
    {
        return $this->select(
            "SELECT uuid, title, sold_count, price, tone, cover_path FROM shop_products WHERE deleted_at IS NULL AND sold_count > 0
             ORDER BY sold_count DESC LIMIT " . max(1, min(20, $limit))
        );
    }

    /* ============================================================ internals */

    private function withUser(?array $order): ?array
    {
        if ($order === null) {
            return null;
        }
        $u = $this->selectOne('SELECT id, uuid, full_name, username, mobile, email, role_id, status FROM users WHERE id = :id', ['id' => (int) $order['user_id']]);
        $order['user'] = $u ?? [];
        $order['shipping'] = $order['shipping'] !== null ? (json_decode((string) $order['shipping'], true) ?: null) : null;
        return $order;
    }

    private function decode(array $p): array
    {
        $p['features'] = $p['features'] !== null ? (json_decode((string) $p['features'], true) ?: []) : [];
        $p['gallery']  = isset($p['gallery']) && $p['gallery'] !== null ? (json_decode((string) $p['gallery'], true) ?: []) : [];
        return $p;
    }

    private function decodeAll(array $rows): array
    {
        return array_map(fn (array $r): array => $this->decode($r), $rows);
    }
}
