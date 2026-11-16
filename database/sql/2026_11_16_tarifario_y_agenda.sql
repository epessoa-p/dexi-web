-- Dexi · Tarifario (especialidades y procedimientos) y Agenda (horarios, bloqueos, citas).
-- Equivale a las migraciones 2026_11_16_000004_create_procedures_tables y
-- 2026_11_16_000005_create_agenda_tables (MySQL 8 / MariaDB 10.4+).
-- Idempotente: se puede ejecutar más de una vez sin romper nada.
--
-- Después: php artisan db:seed --class=PermissionSeeder y RolePermissionSeeder
-- (procedures.*, appointments.*, schedules.*), y dar el módulo «agenda» a los planes.

CREATE TABLE IF NOT EXISTS specialties (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    color VARCHAR(7) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY specialties_company_id_name_unique (company_id, name),
    CONSTRAINT specialties_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS procedures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    specialty_id BIGINT UNSIGNED NULL,
    code VARCHAR(20) NULL,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    price_max DECIMAL(12,2) NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    applies_to VARCHAR(10) NOT NULL DEFAULT 'paciente',
    color VARCHAR(7) NULL,
    description TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY procedures_company_id_active_index (company_id, active),
    KEY procedures_company_id_code_index (company_id, code),
    CONSTRAINT procedures_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT procedures_specialty_id_foreign FOREIGN KEY (specialty_id) REFERENCES specialties (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Columnas de agenda en personal (solo si no existen).
DROP PROCEDURE IF EXISTS dexi_add_column;
DELIMITER $$
CREATE PROCEDURE dexi_add_column(IN tbl VARCHAR(64), IN col VARCHAR(64), IN definition TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col) THEN
        SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', definition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL dexi_add_column('personal', 'attends_patients', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER active');
CALL dexi_add_column('personal', 'specialty_id', 'BIGINT UNSIGNED NULL AFTER attends_patients');
CALL dexi_add_column('personal', 'agenda_color', 'VARCHAR(7) NULL AFTER specialty_id');
DROP PROCEDURE IF EXISTS dexi_add_column;

CREATE TABLE IF NOT EXISTS schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    personal_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    starts_at TIME NOT NULL,
    ends_at TIME NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY schedules_personal_id_weekday_index (personal_id, weekday),
    CONSTRAINT schedules_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT schedules_personal_id_foreign FOREIGN KEY (personal_id) REFERENCES personal (id) ON DELETE CASCADE,
    CONSTRAINT schedules_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedule_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    personal_id BIGINT UNSIGNED NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    reason VARCHAR(120) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY schedule_blocks_company_id_starts_at_index (company_id, starts_at),
    CONSTRAINT schedule_blocks_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT schedule_blocks_personal_id_foreign FOREIGN KEY (personal_id) REFERENCES personal (id) ON DELETE CASCADE,
    CONSTRAINT schedule_blocks_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    personal_id BIGINT UNSIGNED NOT NULL,
    procedure_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'agendada',
    confirmed_at TIMESTAMP NULL,
    arrived_at TIMESTAMP NULL,
    started_at TIMESTAMP NULL,
    finished_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    cancel_reason VARCHAR(255) NULL,
    reschedule_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    overbooked TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY appointments_company_id_starts_at_index (company_id, starts_at),
    KEY appointments_personal_id_starts_at_index (personal_id, starts_at),
    KEY appointments_patient_id_starts_at_index (patient_id, starts_at),
    CONSTRAINT appointments_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT appointments_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL,
    CONSTRAINT appointments_patient_id_foreign FOREIGN KEY (patient_id) REFERENCES patients (id),
    CONSTRAINT appointments_personal_id_foreign FOREIGN KEY (personal_id) REFERENCES personal (id),
    CONSTRAINT appointments_procedure_id_foreign FOREIGN KEY (procedure_id) REFERENCES procedures (id) ON DELETE SET NULL,
    CONSTRAINT appointments_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointment_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    appointment_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(20) NOT NULL,
    from_value VARCHAR(255) NULL,
    to_value VARCHAR(255) NULL,
    note VARCHAR(255) NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    CONSTRAINT appointment_logs_appointment_id_foreign FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE,
    CONSTRAINT appointment_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration, batch)
SELECT m.migration, COALESCE((SELECT MAX(batch) FROM migrations), 0) + 1
FROM (SELECT '2026_11_16_000004_create_procedures_tables' AS migration
      UNION ALL SELECT '2026_11_16_000005_create_agenda_tables') m
WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.migration);
