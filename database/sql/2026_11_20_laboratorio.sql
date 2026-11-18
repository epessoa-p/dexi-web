-- Dexi · Laboratorio dental: órdenes de trabajo (OT-000001) y su recorrido.
-- Equivale a la migración 2026_11_20_000001_create_lab_orders_tables (MySQL 8 / MariaDB 10.4+). Idempotente.
--
-- Después: php artisan db:seed --class=PermissionSeeder y RolePermissionSeeder
-- (lab_orders.*), y dar el módulo «laboratorio» a los planes.

CREATE TABLE IF NOT EXISTS lab_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    number INT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    personal_id BIGINT UNSIGNED NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    consultation_id BIGINT UNSIGNED NULL,
    treatment_plan_item_id BIGINT UNSIGNED NULL,
    work_type VARCHAR(30) NOT NULL,
    teeth VARCHAR(120) NULL,
    material VARCHAR(40) NULL,
    shade VARCHAR(20) NULL,
    instructions TEXT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'enviado',
    sent_at DATE NOT NULL,
    due_at DATE NULL,
    received_at DATETIME NULL,
    installed_at DATETIME NULL,
    returns_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    expense_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY lab_orders_company_id_number_unique (company_id, number),
    KEY lab_orders_company_id_status_due_at_index (company_id, status, due_at),
    CONSTRAINT lab_orders_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT lab_orders_patient_id_foreign FOREIGN KEY (patient_id) REFERENCES patients (id),
    CONSTRAINT lab_orders_personal_id_foreign FOREIGN KEY (personal_id) REFERENCES personal (id) ON DELETE SET NULL,
    CONSTRAINT lab_orders_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
    CONSTRAINT lab_orders_consultation_id_foreign FOREIGN KEY (consultation_id) REFERENCES consultations (id) ON DELETE SET NULL,
    CONSTRAINT lab_orders_treatment_plan_item_id_foreign FOREIGN KEY (treatment_plan_item_id) REFERENCES treatment_plan_items (id) ON DELETE SET NULL,
    CONSTRAINT lab_orders_expense_id_foreign FOREIGN KEY (expense_id) REFERENCES expenses (id) ON DELETE SET NULL,
    CONSTRAINT lab_orders_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_order_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lab_order_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(12) NULL,
    to_status VARCHAR(12) NOT NULL,
    note VARCHAR(255) NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    CONSTRAINT lab_order_logs_lab_order_id_foreign FOREIGN KEY (lab_order_id) REFERENCES lab_orders (id) ON DELETE CASCADE,
    CONSTRAINT lab_order_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration, batch)
SELECT m.migration, COALESCE((SELECT MAX(batch) FROM migrations), 0) + 1
FROM (SELECT '2026_11_20_000001_create_lab_orders_tables' AS migration) m
WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.migration);
