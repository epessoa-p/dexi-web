-- Dexi · Atención clínica: consultas, diagnósticos, procedimientos realizados y odontograma.
-- Equivale a la migración 2026_11_17_000001_create_clinical_tables (MySQL 8 / MariaDB 10.4+).
-- Idempotente: se puede ejecutar más de una vez sin romper nada.
--
-- Después: php artisan db:seed --class=PermissionSeeder y RolePermissionSeeder
-- (consultations.*, odontogram.*), y dar el módulo «historia_clinica» a los planes.

CREATE TABLE IF NOT EXISTS consultations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NULL,
    personal_id BIGINT UNSIGNED NOT NULL,
    number INT UNSIGNED NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'abierta',
    reason VARCHAR(255) NULL,
    history TEXT NULL,
    exam TEXT NULL,
    plan TEXT NULL,
    indications TEXT NULL,
    next_visit VARCHAR(255) NULL,
    addendum TEXT NULL,
    started_at TIMESTAMP NULL,
    closed_at TIMESTAMP NULL,
    closed_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY consultations_company_id_number_unique (company_id, number),
    KEY consultations_patient_id_started_at_index (patient_id, started_at),
    KEY consultations_company_id_status_index (company_id, status),
    CONSTRAINT consultations_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT consultations_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
    CONSTRAINT consultations_patient_id_foreign FOREIGN KEY (patient_id) REFERENCES patients (id),
    CONSTRAINT consultations_appointment_id_foreign FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL,
    CONSTRAINT consultations_personal_id_foreign FOREIGN KEY (personal_id) REFERENCES personal (id),
    CONSTRAINT consultations_closed_by_foreign FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT consultations_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultation_diagnoses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    consultation_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    label VARCHAR(150) NOT NULL,
    tooth TINYINT UNSIGNED NULL,
    surfaces VARCHAR(5) NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT consultation_diagnoses_consultation_id_foreign FOREIGN KEY (consultation_id) REFERENCES consultations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultation_procedures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    consultation_id BIGINT UNSIGNED NOT NULL,
    procedure_id BIGINT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    tooth TINYINT UNSIGNED NULL,
    surfaces VARCHAR(5) NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT consultation_procedures_consultation_id_foreign FOREIGN KEY (consultation_id) REFERENCES consultations (id) ON DELETE CASCADE,
    CONSTRAINT consultation_procedures_procedure_id_foreign FOREIGN KEY (procedure_id) REFERENCES procedures (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS odontogram_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    consultation_id BIGINT UNSIGNED NULL,
    tooth TINYINT UNSIGNED NOT NULL,
    surface VARCHAR(1) NULL,
    `condition` VARCHAR(25) NOT NULL,
    notes VARCHAR(255) NULL,
    personal_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    resolved_at TIMESTAMP NULL,
    resolution VARCHAR(255) NULL,
    resolved_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY odontogram_entries_patient_id_tooth_index (patient_id, tooth),
    KEY odontogram_entries_patient_id_resolved_at_index (patient_id, resolved_at),
    CONSTRAINT odontogram_entries_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT odontogram_entries_patient_id_foreign FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT odontogram_entries_consultation_id_foreign FOREIGN KEY (consultation_id) REFERENCES consultations (id) ON DELETE SET NULL,
    CONSTRAINT odontogram_entries_personal_id_foreign FOREIGN KEY (personal_id) REFERENCES personal (id) ON DELETE SET NULL,
    CONSTRAINT odontogram_entries_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT odontogram_entries_resolved_by_foreign FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration, batch)
SELECT m.migration, COALESCE((SELECT MAX(batch) FROM migrations), 0) + 1
FROM (SELECT '2026_11_17_000001_create_clinical_tables' AS migration) m
WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.migration);
