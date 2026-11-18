-- Dexi · Reportes visibles y Usuarios como módulo de la plataforma
-- Idempotente: se puede correr más de una vez.
--
-- 1) Reportes: crea los permisos reports.* (faltaban en las bases creadas antes
--    del módulo), se los da a los roles y suma el módulo «estadisticas» al plan Clínica.
-- 2) Usuarios: pasa a ser de la plataforma (solo el superadmin). Se quitan los
--    permisos users.* de los roles de clínica; las clínicas dan acceso desde
--    «Odontólogos y personal».

-- ── 1. Reportes ─────────────────────────────────────────────────────────────

INSERT INTO permissions (name, slug, module, created_at, updated_at)
SELECT * FROM (
    SELECT 'Ver Reportes' AS name, 'reports.view' AS slug, 'reports' AS module, NOW() AS c, NOW() AS u
    UNION ALL SELECT 'Ver cifras de dinero en Reportes', 'reports.finance', 'reports', NOW(), NOW()
    UNION ALL SELECT 'Exportar Reportes', 'reports.export', 'reports', NOW(), NOW()
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.slug = nuevos.slug);

-- Superadmin y admin de clínica: todo. Odontólogo y recepcionista: ver (sin dinero).
INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug IN ('reports.view', 'reports.finance', 'reports.export')
WHERE r.slug IN ('super_admin', 'admin')
  AND NOT EXISTS (SELECT 1 FROM role_permission rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.slug = 'reports.view'
WHERE r.slug IN ('odontologo', 'recepcionista')
  AND NOT EXISTS (SELECT 1 FROM role_permission rp WHERE rp.role_id = r.id AND rp.permission_id = p.id);

-- El plan Clínica incluye Reportes (módulo «estadisticas»).
UPDATE plans
SET features = JSON_ARRAY_APPEND(features, '$', 'estadisticas'), updated_at = NOW()
WHERE slug = 'clinica'
  AND NOT JSON_CONTAINS(features, '"estadisticas"');

-- ── 2. Usuarios: solo el superadmin ─────────────────────────────────────────

DELETE rp FROM role_permission rp
JOIN permissions p ON p.id = rp.permission_id
JOIN roles r ON r.id = rp.role_id
WHERE p.module = 'users'
  AND r.slug <> 'super_admin';
