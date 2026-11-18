-- Dexi · Planes de tratamiento / presupuestos.
-- Equivale a la migración 2026_11_17_000002_create_treatment_plans_tables (MySQL 8 / MariaDB 10.4+).
-- Idempotente: se puede ejecutar más de una vez sin romper nada.
--
-- Después: php artisan db:seed --class=PermissionSeeder y RolePermissionSeeder
-- (treatment_plans.*), y dar el módulo «tratamientos» a los planes.

CREATE TABLE IF NOT EXISTS treatment_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    personal_id BIGINT UNSIGNED NULL,
    consultation_id BIGINT UNSIGNED NULL,
    number INT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'borrador',
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    valid_until DATE NULL,
    notes TEXT NULL,
    presented_at TIMESTAMP NULL,
    approved_at TIMESTAMP NULL,
    approved_by_name VARCHAR(150) NULL,
    completed_at TIMESTAMP NULL,
    closed_at TIMESTAMP NULL,
    close_reason VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY treatment_plans_company_id_number_unique (company_id, number),
    KEY treatment_plans_patient_id_status_index (patient_id, status),
    KEY treatment_plans_company_id_status_index (company_id, status),
    CONSTRAINT treatment_plans_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT treatment_plans_patient_id_foreign FOREIGN KEY (patient_id) REFERENCES patients (id),
    CONSTRAINT treatment_plans_personal_id_foreign FOREIGN KEY (personal_id) REFERENCES personal (id) ON DELETE SET NULL,
    CONSTRAINT treatment_plans_consultation_id_foreign FOREIGN KEY (consultation_id) REFERENCES consultations (id) ON DELETE SET NULL,
    CONSTRAINT treatment_plans_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS treatment_plan_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    treatment_plan_id BIGINT UNSIGNED NOT NULL,
    procedure_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    tooth TINYINT UNSIGNED NULL,
    surfaces VARCHAR(5) NULL,
    phase TINYINT UNSIGNED NOT NULL DEFAULT 1,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    status VARCHAR(10) NOT NULL DEFAULT 'pendiente',
    done_at TIMESTAMP NULL,
    consultation_id BIGINT UNSIGNED NULL,
    consultation_procedure_id BIGINT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY treatment_plan_items_treatment_plan_id_status_index (treatment_plan_id, status),
    CONSTRAINT treatment_plan_items_treatment_plan_id_foreign FOREIGN KEY (treatment_plan_id) REFERENCES treatment_plans (id) ON DELETE CASCADE,
    CONSTRAINT treatment_plan_items_procedure_id_foreign FOREIGN KEY (procedure_id) REFERENCES procedures (id) ON DELETE SET NULL,
    CONSTRAINT treatment_plan_items_consultation_id_foreign FOREIGN KEY (consultation_id) REFERENCES consultations (id) ON DELETE SET NULL,
    CONSTRAINT treatment_plan_items_consultation_procedure_id_foreign FOREIGN KEY (consultation_procedure_id) REFERENCES consultation_procedures (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration, batch)
SELECT m.migration, COALESCE((SELECT MAX(batch) FROM migrations), 0) + 1
FROM (SELECT '2026_11_17_000002_create_treatment_plans_tables' AS migration) m
WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.migration);
