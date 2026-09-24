-- ============================================================================
--  «فروشگاه» — products, cart, orders, payment and discount codes
--
--  shop_categories      the shelves the store is arranged on.
--  shop_products        one thing for sale. A product of kind «package»
--                       hands over a package (courses, question bank, …)
--                       the moment its order is paid; «physical» asks for
--                       a delivery address at checkout; «service» is
--                       anything the institute delivers by hand.
--  shop_cart_items      a student's cart, kept across devices.
--  shop_coupons         discount codes: percent or fixed, a floor, a cap,
--                       dates, total and per-student limits, optionally only
--                       for some products.
--  shop_orders          one checkout. Paid online (gateway) or by card to
--                       card, in which case the student uploads the receipt
--                       and the order waits in «review» for an admin.
--  shop_order_items     the lines of an order, priced as they were sold.
--
--  Settings: shop_gateway / shop_zarinpal_merchant / shop_zarinpal_sandbox,
--  shop_card (card-to-card details), shop_theme (the look of the store).
--
--  Additive and safe to re-run.
-- ============================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shop_categories (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    title       VARCHAR(120)  NOT NULL,
    icon        VARCHAR(32)   NOT NULL DEFAULT 'bag',
    tone        VARCHAR(16)   NOT NULL DEFAULT 'orange',
    sort_order  SMALLINT      NOT NULL DEFAULT 0,
    created_at  DATETIME      NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_products (
    id             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    uuid           CHAR(36)         NOT NULL,
    category_id    INT UNSIGNED     NULL,
    kind           ENUM('package','physical','service') NOT NULL DEFAULT 'package',
    title          VARCHAR(191)     NOT NULL,
    subtitle       VARCHAR(255)     NULL,
    description    MEDIUMTEXT       NULL,
    features       JSON             NULL,
    cover_path     VARCHAR(191)     NULL,
    gallery        JSON             NULL,
    tone           VARCHAR(16)      NOT NULL DEFAULT 'indigo',
    badge          VARCHAR(40)      NULL,
    price          INT UNSIGNED     NOT NULL DEFAULT 0,
    compare_price  INT UNSIGNED     NULL,
    package_id     INT UNSIGNED     NULL,
    access_days    SMALLINT UNSIGNED NULL,
    stock          INT              NULL,
    max_per_order  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    featured       TINYINT(1)       NOT NULL DEFAULT 0,
    status         ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    sort_order     SMALLINT         NOT NULL DEFAULT 0,
    sold_count     INT UNSIGNED     NOT NULL DEFAULT 0,
    created_by     BIGINT UNSIGNED  NULL,
    created_at     DATETIME         NOT NULL,
    updated_at     DATETIME         NULL,
    deleted_at     DATETIME         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shop_products_uuid (uuid),
    KEY idx_shop_products_list (status, deleted_at, sort_order),
    KEY idx_shop_products_category (category_id),
    CONSTRAINT fk_shop_products_category FOREIGN KEY (category_id) REFERENCES shop_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_shop_products_package  FOREIGN KEY (package_id)  REFERENCES packages(id) ON DELETE SET NULL,
    CONSTRAINT fk_shop_products_creator  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_cart_items (
    user_id     BIGINT UNSIGNED   NOT NULL,
    product_id  INT UNSIGNED      NOT NULL,
    qty         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at  DATETIME          NOT NULL,
    PRIMARY KEY (user_id, product_id),
    KEY idx_shop_cart_product (product_id),
    CONSTRAINT fk_shop_cart_user    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_shop_cart_product FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_coupons (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    code            VARCHAR(40)   NOT NULL,
    kind            ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    amount          INT UNSIGNED  NOT NULL,
    max_discount    INT UNSIGNED  NULL,
    min_total       INT UNSIGNED  NULL,
    starts_at       DATETIME      NULL,
    ends_at         DATETIME      NULL,
    usage_limit     INT UNSIGNED  NULL,
    per_user_limit  SMALLINT UNSIGNED NULL DEFAULT 1,
    product_ids     JSON          NULL,
    status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
    note            VARCHAR(255)  NULL,
    created_at      DATETIME      NOT NULL,
    updated_at      DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shop_coupons_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_orders (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid               CHAR(36)        NOT NULL,
    number             VARCHAR(20)     NOT NULL,
    user_id            BIGINT UNSIGNED NOT NULL,
    status             ENUM('pending','review','paid','rejected','cancelled') NOT NULL DEFAULT 'pending',
    method             ENUM('gateway','card','free') NOT NULL DEFAULT 'card',
    subtotal           INT UNSIGNED    NOT NULL DEFAULT 0,
    discount           INT UNSIGNED    NOT NULL DEFAULT 0,
    total              INT UNSIGNED    NOT NULL DEFAULT 0,
    coupon_id          INT UNSIGNED    NULL,
    coupon_code        VARCHAR(40)     NULL,
    shipping           JSON            NULL,
    receipt_path       VARCHAR(191)    NULL,
    receipt_ref        VARCHAR(64)     NULL,
    receipt_note       VARCHAR(500)    NULL,
    receipt_at         DATETIME        NULL,
    gateway_authority  VARCHAR(64)     NULL,
    gateway_ref        VARCHAR(64)     NULL,
    gateway_card       VARCHAR(32)     NULL,
    admin_note         VARCHAR(500)    NULL,
    reviewed_by        BIGINT UNSIGNED NULL,
    reviewed_at        DATETIME        NULL,
    paid_at            DATETIME        NULL,
    created_at         DATETIME        NOT NULL,
    updated_at         DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shop_orders_uuid (uuid),
    UNIQUE KEY uq_shop_orders_number (number),
    KEY idx_shop_orders_user (user_id, created_at),
    KEY idx_shop_orders_status (status, created_at),
    KEY idx_shop_orders_authority (gateway_authority),
    CONSTRAINT fk_shop_orders_user     FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_shop_orders_coupon   FOREIGN KEY (coupon_id)   REFERENCES shop_coupons(id) ON DELETE SET NULL,
    CONSTRAINT fk_shop_orders_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_order_items (
    id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    order_id      BIGINT UNSIGNED   NOT NULL,
    product_id    INT UNSIGNED      NULL,
    title         VARCHAR(191)      NOT NULL,
    kind          VARCHAR(16)       NOT NULL,
    price         INT UNSIGNED      NOT NULL,
    qty           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    package_id    INT UNSIGNED      NULL,
    access_days   SMALLINT UNSIGNED NULL,
    fulfilled_at  DATETIME          NULL,
    PRIMARY KEY (id),
    KEY idx_shop_items_order (order_id),
    KEY idx_shop_items_product (product_id),
    CONSTRAINT fk_shop_items_order   FOREIGN KEY (order_id)   REFERENCES shop_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_shop_items_product FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO permissions (slug, name, module, created_at) VALUES
    ('shop.manage', 'مدیریت فروشگاه: محصولات، کدهای تخفیف، پرداخت و ظاهر', 'shop', NOW()),
    ('shop.orders', 'بررسی سفارش‌ها و رسیدهای کارت به کارت', 'shop', NOW());

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'super_admin' AND p.module = 'shop';
