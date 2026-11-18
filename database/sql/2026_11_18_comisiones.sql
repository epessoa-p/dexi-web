-- Dexi · Comisiones: reglas, comisiones por línea cobrada, liquidaciones y «quién atendió» en las ventas.
-- Equivale a la migración 2026_11_18_000002_create_commission_tables (MySQL 8 / MariaDB 10.4+).
-- Idempotente: se puede ejecutar más de una vez sin romper nada.
--
-- Después: php artisan db:seed --class=PermissionSeeder y RolePermissionSeeder
-- (commissions.*), y dar el módulo «comisiones» a los planes.

-- «Quién atendió» en ventas y líneas (solo si no existe la columna).
DROP PROCEDURE IF EXISTS dexi_add_personal_link;
DELIMITER $$
CREATE PROCEDURE dexi_add_personal_link(IN tbl VARCHAR(64), IN after_col VARCHAR(64))
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = 'personal_id') THEN
        SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `personal_id` BIGINT UNSIGNED NULL AFTER `', after_col, '`, ',
                          'ADD CONSTRAINT `', tbl, '_personal_id_foreign` FOREIGN KEY (`personal_id`) REFERENCES `personal` (`id`) ON DELETE SET NULL');
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;
CALL dexi_add_personal_link('sales', 'user_id');
CALL dexi_add_personal_link('sale_items', 'treatment_plan_item_id');
DROP PROCEDURE IF EXISTS dexi_add_personal_link;

CREATE TABLE IF NOT EXISTS commission_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    personal_id BIGINT UNSIGNED NULL,
    applies_to VARCHAR(15) NOT NULL,
    specialty_id BIGINT UNSIGNED NULL,
    procedure_id BIGINT UNSIGNED NULL,
    type VARCHAR(12) NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY commission_rules_company_id_active_index (company_id, active),
    CONSTRAINT commission_rules_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT commission_rules_personal_id_foreign FOREIGN KEY (personal_id) REFERENCES personal (id) ON DELETE CASCADE,
    CONSTRAINT commission_rules_specialty_id_foreign FOREIGN KEY (specialty_id) REFERENCES specialties (id) ON DELETE CASCADE,
    CONSTRAINT commission_rules_procedure_id_foreign FOREIGN KEY (procedure_id) REFERENCES procedures (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_settlements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    personal_id BIGINT UNSIGNED NOT NULL,
    number INT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    commissions_count INT UNSIGNED NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pagada',
    expense_id BIGINT UNSIGNED NULL,
    paid_at TIMESTAMP NULL,
    paid_by BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    cancelled_at TIMESTAMP NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancel_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY commission_settlements_company_id_number_unique (company_id, number),
    CONSTRAINT commission_settlements_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT commission_settlements_personal_id_foreign FOREIGN KEY (personal_id) REFERENCES personal (id),
    CONSTRAINT commission_settlements_expense_id_foreign FOREIGN KEY (expense_id) REFERENCES expenses (id) ON DELETE SET NULL,
    CONSTRAINT commission_settlements_paid_by_foreign FOREIGN KEY (paid_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT commission_settlements_cancelled_by_foreign FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    sale_id BIGINT UNSIGNED NOT NULL,
    sale_item_id BIGINT UNSIGNED NULL,
    sale_payment_id BIGINT UNSIGNED NULL,
    personal_id BIGINT UNSIGNED NOT NULL,
    commission_rule_id BIGINT UNSIGNED NULL,
    commission_settlement_id BIGINT UNSIGNED NULL,
    kind VARCHAR(12) NOT NULL DEFAULT 'devengada',
    adjusts_commission_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    base DECIMAL(12,2) NOT NULL,
    type VARCHAR(12) NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    earned_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY commissions_company_id_personal_id_earned_at_index (company_id, personal_id, earned_at),
    CONSTRAINT commissions_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT commissions_sale_id_foreign FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE,
    CONSTRAINT commissions_sale_item_id_foreign FOREIGN KEY (sale_item_id) REFERENCES sale_items (id) ON DELETE SET NULL,
    CONSTRAINT commissions_sale_payment_id_foreign FOREIGN KEY (sale_payment_id) REFERENCES sale_payments (id) ON DELETE SET NULL,
    CONSTRAINT commissions_personal_id_foreign FOREIGN KEY (personal_id) REFERENCES personal (id),
    CONSTRAINT commissions_commission_rule_id_foreign FOREIGN KEY (commission_rule_id) REFERENCES commission_rules (id) ON DELETE SET NULL,
    CONSTRAINT commissions_commission_settlement_id_foreign FOREIGN KEY (commission_settlement_id) REFERENCES commission_settlements (id) ON DELETE SET NULL,
    CONSTRAINT commissions_adjusts_commission_id_foreign FOREIGN KEY (adjusts_commission_id) REFERENCES commissions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration, batch)
SELECT m.migration, COALESCE((SELECT MAX(batch) FROM migrations), 0) + 1
FROM (SELECT '2026_11_18_000002_create_commission_tables' AS migration) m
WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.migration);
