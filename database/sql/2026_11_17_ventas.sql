-- Dexi · Ventas y cobros: ventas, líneas, pagos/abonos y enlaces «ya cobrado».
-- Equivale a la migración 2026_11_17_000003_create_sales_tables (MySQL 8 / MariaDB 10.4+).
-- Idempotente: se puede ejecutar más de una vez sin romper nada.
--
-- Después: php artisan db:seed --class=PermissionSeeder y RolePermissionSeeder
-- (sales.*, receivables.*), y dar el módulo «ventas» a los planes.

CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    patient_id BIGINT UNSIGNED NULL,
    cash_session_id BIGINT UNSIGNED NULL,
    number INT UNSIGNED NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'completada',
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    notes TEXT NULL,
    user_id BIGINT UNSIGNED NULL,
    cancelled_at TIMESTAMP NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancel_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY sales_company_id_number_unique (company_id, number),
    KEY sales_company_id_created_at_index (company_id, created_at),
    KEY sales_patient_id_balance_index (patient_id, balance),
    CONSTRAINT sales_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT sales_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
    CONSTRAINT sales_patient_id_foreign FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE SET NULL,
    CONSTRAINT sales_cash_session_id_foreign FOREIGN KEY (cash_session_id) REFERENCES cash_sessions (id) ON DELETE SET NULL,
    CONSTRAINT sales_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT sales_cancelled_by_foreign FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    procedure_id BIGINT UNSIGNED NULL,
    consultation_procedure_id BIGINT UNSIGNED NULL,
    treatment_plan_item_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    tooth TINYINT UNSIGNED NULL,
    quantity DECIMAL(8,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT sale_items_sale_id_foreign FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE,
    CONSTRAINT sale_items_procedure_id_foreign FOREIGN KEY (procedure_id) REFERENCES procedures (id) ON DELETE SET NULL,
    CONSTRAINT sale_items_consultation_procedure_id_foreign FOREIGN KEY (consultation_procedure_id) REFERENCES consultation_procedures (id) ON DELETE SET NULL,
    CONSTRAINT sale_items_treatment_plan_item_id_foreign FOREIGN KEY (treatment_plan_item_id) REFERENCES treatment_plan_items (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    sale_id BIGINT UNSIGNED NOT NULL,
    payment_method_id BIGINT UNSIGNED NULL,
    cash_session_id BIGINT UNSIGNED NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'venta',
    amount DECIMAL(12,2) NOT NULL,
    reference VARCHAR(80) NULL,
    user_id BIGINT UNSIGNED NULL,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY sale_payments_company_id_paid_at_index (company_id, paid_at),
    CONSTRAINT sale_payments_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT sale_payments_sale_id_foreign FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE,
    CONSTRAINT sale_payments_payment_method_id_foreign FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE SET NULL,
    CONSTRAINT sale_payments_cash_session_id_foreign FOREIGN KEY (cash_session_id) REFERENCES cash_sessions (id) ON DELETE SET NULL,
    CONSTRAINT sale_payments_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- «Ya cobrado» en lo realizado y en los ítems de plan (solo si no existe la columna).
DROP PROCEDURE IF EXISTS dexi_add_sale_link;
DELIMITER $$
CREATE PROCEDURE dexi_add_sale_link(IN tbl VARCHAR(64), IN after_col VARCHAR(64))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = 'sale_item_id') THEN
        SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `sale_item_id` BIGINT UNSIGNED NULL AFTER `', after_col, '`, ',
                          'ADD CONSTRAINT `', tbl, '_sale_item_id_foreign` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`id`) ON DELETE SET NULL');
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;
CALL dexi_add_sale_link('consultation_procedures', 'notes');
CALL dexi_add_sale_link('treatment_plan_items', 'consultation_procedure_id');
DROP PROCEDURE IF EXISTS dexi_add_sale_link;

INSERT INTO migrations (migration, batch)
SELECT m.migration, COALESCE((SELECT MAX(batch) FROM migrations), 0) + 1
FROM (SELECT '2026_11_17_000003_create_sales_tables' AS migration) m
WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.migration);
