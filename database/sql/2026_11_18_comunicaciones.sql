-- Dexi · Comunicaciones por WhatsApp (wa.me): plantillas, controles programados y mensajes enviados.
-- Equivale a la migración 2026_11_18_000001_create_communications_tables (MySQL 8 / MariaDB 10.4+).
-- Idempotente: se puede ejecutar más de una vez sin romper nada.
--
-- Después: php artisan db:seed --class=PermissionSeeder y RolePermissionSeeder
-- (communications.*), y dar el módulo «recordatorios» a los planes.

CREATE TABLE IF NOT EXISTS message_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY message_templates_company_id_kind_unique (company_id, kind),
    CONSTRAINT message_templates_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_followups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    consultation_id BIGINT UNSIGNED NULL,
    due_date DATE NOT NULL,
    reason VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    closed_at TIMESTAMP NULL,
    closed_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    KEY patient_followups_company_id_status_due_date_index (company_id, status, due_date),
    CONSTRAINT patient_followups_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT patient_followups_patient_id_foreign FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT patient_followups_consultation_id_foreign FOREIGN KEY (consultation_id) REFERENCES consultations (id) ON DELETE SET NULL,
    CONSTRAINT patient_followups_closed_by_foreign FOREIGN KEY (closed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT patient_followups_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS communications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    patient_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NULL,
    followup_id BIGINT UNSIGNED NULL,
    kind VARCHAR(20) NOT NULL,
    channel VARCHAR(12) NOT NULL DEFAULT 'whatsapp',
    recipient VARCHAR(40) NULL,
    message TEXT NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    KEY communications_company_id_created_at_index (company_id, created_at),
    KEY communications_patient_id_kind_index (patient_id, kind),
    CONSTRAINT communications_company_id_foreign FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT communications_patient_id_foreign FOREIGN KEY (patient_id) REFERENCES patients (id) ON DELETE CASCADE,
    CONSTRAINT communications_appointment_id_foreign FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL,
    CONSTRAINT communications_followup_id_foreign FOREIGN KEY (followup_id) REFERENCES patient_followups (id) ON DELETE SET NULL,
    CONSTRAINT communications_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (migration, batch)
SELECT m.migration, COALESCE((SELECT MAX(batch) FROM migrations), 0) + 1
FROM (SELECT '2026_11_18_000001_create_communications_tables' AS migration) m
WHERE NOT EXISTS (SELECT 1 FROM migrations x WHERE x.migration = m.migration);
