-- Dexi · Pacientes y sus documentos (consentimientos, radiografías, fotos).
-- Equivale a la migración 2026_11_16_000003_create_patients_tables (MySQL 8 / MariaDB 10.4+).
-- Idempotente: se puede ejecutar más de una vez sin romper nada.
--
-- Después: php artisan db:seed --class=PermissionSeeder y RolePermissionSeeder
-- (permisos patients.* y patient_files.*). Los archivos van al disco privado
-- storage/app/private/patients/{clínica}/{paciente}/.

CREATE TABLE IF NOT EXISTS patients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    number INT UNSIGNED NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    document_type VARCHAR(12) NULL,
    document_number VARCHAR(30) NULL,
    birth_date DATE NULL,
    gender VARCHAR(1) NULL,
    occupation VARCHAR(100) NULL,
    phone VARCHAR(30) NULL,
    whatsapp VARCHAR(30) NULL,
    email VARCHAR(255) NULL,
    address VARCHAR(255) NULL,
    emergency_contact VARCHAR(120) NULL,
    emergency_phone VARCHAR(30) NULL,
    guardian_name VARCHAR(120) NULL,
    guardian_relationship VARCHAR(40) NULL,
    guardian_phone VARCHAR(30) NULL,
    referral_source VARCHAR(20) NULL,
    referred_by VARCHAR(120) NULL,
    insurance VARCHAR(120) NULL,
    conditions LONGTEXT NULL,
    allergies TEXT NULL,
    medications TEXT NULL,
    pregnant TINYINT(1) NOT NULL DEFAULT 0,
    blood_type VARCHAR(3) NULL,
    medical_notes TEXT NULL,
    medical_updated_at TIMESTAMP NULL,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY patients_company_id_number_unique (company_id, number),
    KEY patients_company_id_document_number_index (company_id, document_number),
    KEY patients_company_id_last_name_first_name_index (company_id, last_name, first_name),
    CONSTRAINT patients_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT patients_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
    CONSTRAINT patients_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(20) NOT NULL,
    title VARCHAR(150) NOT NULL,
    path VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NULL,
    size INT UNSIGNED NOT NULL DEFAULT 0,
    taken_on DATE NULL,
    notes TEXT NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY patient_documents_patient_id_category_index (patient_id, category),
    CONSTRAINT patient_documents_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT patient_documents_patient_id_foreign FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT patient_documents_uploaded_by_foreign FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration, batch)
SELECT m.migration, COALESCE((SELECT MAX(batch) FROM migrations), 0) + 1
FROM (SELECT '2026_11_16_000003_create_patients_tables' AS migration) m
WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.migration);
