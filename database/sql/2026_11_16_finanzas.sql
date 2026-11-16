-- Dexi · Caja por turnos y Finanzas (cuentas, gastos, categorías, proveedores).
-- Equivale a las migraciones 2026_11_16_000001_create_cash_tables y
-- 2026_11_16_000002_create_finance_tables (MySQL 8 / MariaDB 10.4+).
-- Idempotente: se puede ejecutar más de una vez sin romper nada.
--
-- Después, para que aparezca en el panel:
--   1. Dar los módulos «caja» y/o «finanzas» a los planes (Plataforma › Planes).
--   2. Crear los permisos y repartirlos: php artisan db:seed --class=PermissionSeeder
--      y php artisan db:seed --class=RolePermissionSeeder (o a mano en Roles).
-- Los métodos de pago y las categorías por defecto se crean solos la primera
-- vez que cada clínica entra a Caja o a Gastos.

CREATE TABLE IF NOT EXISTS payment_methods (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,
    type VARCHAR(12) NOT NULL,
    requires_reference TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY payment_methods_company_id_active_index (company_id, active),
    CONSTRAINT payment_methods_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    caja_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'open',
    opened_by BIGINT UNSIGNED NULL,
    opened_at DATETIME NOT NULL,
    opening_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    closed_by BIGINT UNSIGNED NULL,
    closed_at DATETIME NULL,
    expected_cash DECIMAL(14,2) NULL,
    counted_cash DECIMAL(14,2) NULL,
    difference DECIMAL(14,2) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY cash_sessions_caja_id_status_index (caja_id, status),
    CONSTRAINT cash_sessions_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT cash_sessions_caja_id_foreign FOREIGN KEY (caja_id) REFERENCES cajas (id),
    CONSTRAINT cash_sessions_opened_by_foreign FOREIGN KEY (opened_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT cash_sessions_closed_by_foreign FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    cash_session_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(12) NOT NULL,
    payment_method_id BIGINT UNSIGNED NULL,
    amount DECIMAL(14,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    source_type VARCHAR(255) NULL,
    source_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY cash_movements_source_type_source_id_index (source_type, source_id),
    CONSTRAINT cash_movements_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT cash_movements_cash_session_id_foreign FOREIGN KEY (cash_session_id) REFERENCES cash_sessions (id) ON DELETE CASCADE,
    CONSTRAINT cash_movements_payment_method_id_foreign FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE SET NULL,
    CONSTRAINT cash_movements_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    name VARCHAR(80) NOT NULL,
    type VARCHAR(20) NOT NULL,
    bank_name VARCHAR(80) NULL,
    account_number VARCHAR(40) NULL,
    opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
    balance DECIMAL(15,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY finance_accounts_company_id_active_index (company_id, active),
    CONSTRAINT finance_accounts_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT finance_accounts_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    finance_account_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(10) NOT NULL,
    origin VARCHAR(20) NOT NULL,
    concept VARCHAR(255) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    balance_after DECIMAL(15,2) NOT NULL,
    occurred_on DATE NOT NULL,
    reference VARCHAR(80) NULL,
    transfer_movement_id BIGINT UNSIGNED NULL,
    reverses_movement_id BIGINT UNSIGNED NULL,
    source_type VARCHAR(255) NULL,
    source_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    cancelled_at TIMESTAMP NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY finance_movements_finance_account_id_id_index (finance_account_id, id),
    KEY finance_movements_source_type_source_id_index (source_type, source_id),
    CONSTRAINT finance_movements_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT finance_movements_finance_account_id_foreign FOREIGN KEY (finance_account_id) REFERENCES finance_accounts (id),
    CONSTRAINT finance_movements_transfer_movement_id_foreign FOREIGN KEY (transfer_movement_id) REFERENCES finance_movements (id) ON DELETE SET NULL,
    CONSTRAINT finance_movements_reverses_movement_id_foreign FOREIGN KEY (reverses_movement_id) REFERENCES finance_movements (id) ON DELETE SET NULL,
    CONSTRAINT finance_movements_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT finance_movements_cancelled_by_foreign FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    payee VARCHAR(12) NOT NULL DEFAULT 'ninguno',
    recurring TINYINT(1) NOT NULL DEFAULT 0,
    frequency VARCHAR(10) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY expense_categories_company_id_name_unique (company_id, name),
    CONSTRAINT expense_categories_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    tax_id VARCHAR(32) NULL,
    contact_name VARCHAR(120) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(255) NULL,
    address VARCHAR(255) NULL,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY suppliers_company_id_name_index (company_id, name),
    CONSTRAINT suppliers_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    number INT UNSIGNED NOT NULL,
    expense_category_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NULL,
    personal_id BIGINT UNSIGNED NULL,
    commission_settlement_id BIGINT UNSIGNED NULL,
    concept VARCHAR(255) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    spent_at DATE NOT NULL,
    reference VARCHAR(80) NULL,
    source VARCHAR(10) NOT NULL,
    finance_account_id BIGINT UNSIGNED NULL,
    finance_movement_id BIGINT UNSIGNED NULL,
    payment_method_id BIGINT UNSIGNED NULL,
    cash_session_id BIGINT UNSIGNED NULL,
    cash_movement_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    receipt VARCHAR(255) NULL,
    notes TEXT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'registrado',
    cancelled_at TIMESTAMP NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancel_reason VARCHAR(255) NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY expenses_company_id_number_unique (company_id, number),
    KEY expenses_company_id_spent_at_index (company_id, spent_at),
    CONSTRAINT expenses_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT expenses_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
    CONSTRAINT expenses_expense_category_id_foreign FOREIGN KEY (expense_category_id) REFERENCES expense_categories (id),
    CONSTRAINT expenses_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
    CONSTRAINT expenses_personal_id_foreign FOREIGN KEY (personal_id) REFERENCES personal (id) ON DELETE SET NULL,
    CONSTRAINT expenses_finance_account_id_foreign FOREIGN KEY (finance_account_id) REFERENCES finance_accounts (id) ON DELETE SET NULL,
    CONSTRAINT expenses_finance_movement_id_foreign FOREIGN KEY (finance_movement_id) REFERENCES finance_movements (id) ON DELETE SET NULL,
    CONSTRAINT expenses_payment_method_id_foreign FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE SET NULL,
    CONSTRAINT expenses_cash_session_id_foreign FOREIGN KEY (cash_session_id) REFERENCES cash_sessions (id) ON DELETE SET NULL,
    CONSTRAINT expenses_cash_movement_id_foreign FOREIGN KEY (cash_movement_id) REFERENCES cash_movements (id) ON DELETE SET NULL,
    CONSTRAINT expenses_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT expenses_cancelled_by_foreign FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para que `php artisan migrate` no intente crearlas de nuevo.
INSERT INTO migrations (migration, batch)
SELECT m.migration, COALESCE((SELECT MAX(batch) FROM migrations), 0) + 1
FROM (SELECT '2026_11_16_000001_create_cash_tables' AS migration
      UNION ALL SELECT '2026_11_16_000002_create_finance_tables') m
WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.migration);
