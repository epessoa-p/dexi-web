-- Dexi · Inventario y compras: productos, existencia por consultorio, lotes, kardex, insumos por
-- procedimiento, productos en ventas, compras con recepciones y pagos (cuentas por pagar).
-- Equivale a las migraciones 2026_11_19_000001_create_inventory_tables y
-- 2026_11_19_000002_create_purchases_tables (MySQL 8 / MariaDB 10.4+). Idempotente.
--
-- Después: php artisan db:seed --class=PermissionSeeder y RolePermissionSeeder
-- (products.*, inventory.*, purchases.*), y dar los módulos «inventario» y «compras» a los planes.

CREATE TABLE IF NOT EXISTS product_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY product_categories_company_id_name_unique (company_id, name),
    CONSTRAINT product_categories_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    product_category_id BIGINT UNSIGNED NULL,
    code VARCHAR(40) NULL,
    barcode VARCHAR(60) NULL,
    name VARCHAR(160) NOT NULL,
    brand VARCHAR(80) NULL,
    presentation VARCHAR(120) NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'unidad',
    purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    min_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
    track_batches TINYINT(1) NOT NULL DEFAULT 0,
    for_sale TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY products_company_id_name_index (company_id, name),
    KEY products_company_id_code_index (company_id, code),
    CONSTRAINT products_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT products_product_category_id_foreign FOREIGN KEY (product_category_id) REFERENCES product_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_stocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY product_stocks_product_id_branch_id_unique (product_id, branch_id),
    CONSTRAINT product_stocks_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT product_stocks_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT product_stocks_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    lot VARCHAR(60) NOT NULL,
    expires_at DATE NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    initial_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY product_batches_product_id_branch_id_lot_unique (product_id, branch_id, lot),
    KEY product_batches_company_id_expires_at_index (company_id, expires_at),
    CONSTRAINT product_batches_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT product_batches_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT product_batches_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    product_batch_id BIGINT UNSIGNED NULL,
    type VARCHAR(14) NOT NULL,
    reason VARCHAR(20) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    unit_cost DECIMAL(12,2) NULL,
    source_type VARCHAR(255) NULL,
    source_id BIGINT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY stock_movements_source_type_source_id_index (source_type, source_id),
    KEY stock_movements_product_id_branch_id_id_index (product_id, branch_id, id),
    KEY stock_movements_company_id_created_at_index (company_id, created_at),
    CONSTRAINT stock_movements_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT stock_movements_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT stock_movements_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT stock_movements_product_batch_id_foreign FOREIGN KEY (product_batch_id) REFERENCES product_batches (id) ON DELETE SET NULL,
    CONSTRAINT stock_movements_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS procedure_supplies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    procedure_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY procedure_supplies_procedure_id_product_id_unique (procedure_id, product_id),
    CONSTRAINT procedure_supplies_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT procedure_supplies_procedure_id_foreign FOREIGN KEY (procedure_id) REFERENCES procedures (id) ON DELETE CASCADE,
    CONSTRAINT procedure_supplies_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Productos en las ventas (solo si no existe la columna).
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'product_id');
SET @ddl := IF(@has = 0, 'ALTER TABLE `sale_items` ADD COLUMN `product_id` BIGINT UNSIGNED NULL AFTER `procedure_id`, ADD CONSTRAINT `sale_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS purchases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    number INT UNSIGNED NOT NULL,
    document_number VARCHAR(60) NULL,
    purchased_at DATE NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pedida',
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    notes TEXT NULL,
    user_id BIGINT UNSIGNED NULL,
    received_at DATETIME NULL,
    closing_notes VARCHAR(255) NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY purchases_company_id_number_unique (company_id, number),
    KEY purchases_company_id_balance_index (company_id, balance),
    CONSTRAINT purchases_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT purchases_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id),
    CONSTRAINT purchases_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT purchases_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    purchase_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    received_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(12,2) NOT NULL,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT purchase_items_purchase_id_foreign FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE,
    CONSTRAINT purchase_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_receptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    purchase_id BIGINT UNSIGNED NOT NULL,
    received_at DATETIME NOT NULL,
    notes VARCHAR(255) NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT purchase_receptions_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT purchase_receptions_purchase_id_foreign FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE,
    CONSTRAINT purchase_receptions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_reception_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    purchase_reception_id BIGINT UNSIGNED NOT NULL,
    purchase_item_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    lot VARCHAR(60) NULL,
    expires_at DATE NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT purchase_reception_items_purchase_reception_id_foreign FOREIGN KEY (purchase_reception_id) REFERENCES purchase_receptions (id) ON DELETE CASCADE,
    CONSTRAINT purchase_reception_items_purchase_item_id_foreign FOREIGN KEY (purchase_item_id) REFERENCES purchase_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    purchase_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    source VARCHAR(10) NOT NULL,
    finance_account_id BIGINT UNSIGNED NULL,
    finance_movement_id BIGINT UNSIGNED NULL,
    cash_session_id BIGINT UNSIGNED NULL,
    cash_movement_id BIGINT UNSIGNED NULL,
    payment_method_id BIGINT UNSIGNED NULL,
    amount DECIMAL(12,2) NOT NULL,
    reference VARCHAR(80) NULL,
    paid_at DATE NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'pagado',
    user_id BIGINT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT purchase_payments_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT purchase_payments_purchase_id_foreign FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE CASCADE,
    CONSTRAINT purchase_payments_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
    CONSTRAINT purchase_payments_finance_account_id_foreign FOREIGN KEY (finance_account_id) REFERENCES finance_accounts (id) ON DELETE SET NULL,
    CONSTRAINT purchase_payments_finance_movement_id_foreign FOREIGN KEY (finance_movement_id) REFERENCES finance_movements (id) ON DELETE SET NULL,
    CONSTRAINT purchase_payments_cash_session_id_foreign FOREIGN KEY (cash_session_id) REFERENCES cash_sessions (id) ON DELETE SET NULL,
    CONSTRAINT purchase_payments_cash_movement_id_foreign FOREIGN KEY (cash_movement_id) REFERENCES cash_movements (id) ON DELETE SET NULL,
    CONSTRAINT purchase_payments_payment_method_id_foreign FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE SET NULL,
    CONSTRAINT purchase_payments_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration, batch)
SELECT m.migration, COALESCE((SELECT MAX(batch) FROM migrations), 0) + 1
FROM (SELECT '2026_11_19_000001_create_inventory_tables' AS migration
      UNION ALL SELECT '2026_11_19_000002_create_purchases_tables') m
WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.migration);
