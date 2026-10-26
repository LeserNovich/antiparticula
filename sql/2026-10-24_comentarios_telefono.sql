-- ══════════════════════════════════════════════════════════════════════════
-- Migración: teléfono en los comentarios del programa
-- Fecha:     2026-10-24
-- Base:      u450756829_PaginaWeb
--
-- ANTES DE EJECUTAR: en phpMyAdmin haz clic en TU base (u450756829_PaginaWeb) en la
-- columna izquierda y SOLO ENTONCES abre la pestaña «SQL». Si la base activa es
-- information_schema, mysql o ninguna, el script lo detecta, NO cambia nada y avisa.
--
-- Requerida por: public_html/registrar_comentario.php (el POS posterior a 3.6.0 manda `telefono`) y la
-- sección Comentarios de /admin/. Sin la columna TODO SIGUE FUNCIONANDO: el endpoint guarda el
-- mensaje con el teléfono al final del texto y /admin/ no muestra el botón de WhatsApp.
--
-- Solo AGREGA una columna (`telefono`, junto al correo). Es idempotente: se puede correr dos veces.
-- Cómo ejecutar en Hostinger: hPanel → Bases de datos → phpMyAdmin → TU base → SQL.
-- ══════════════════════════════════════════════════════════════════════════

-- ¿Estás en TU base? Esta consulta debe decir u450756829_PaginaWeb:
SELECT DATABASE() AS base_actual;

SET @base_ok := (DATABASE() IS NOT NULL
                 AND DATABASE() NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys'));
SET @aviso := 'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso';

-- ─── Teléfono de quien comenta: solo dígitos y un + opcional (8 a 15), o NULL ───
SET @ya := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comentarios_sugerencias' AND COLUMN_NAME = 'telefono');
SET @ddl := IF(@base_ok = 0, @aviso,
            IF(@ya > 0, 'SELECT ''La columna telefono ya existia: no se cambio nada'' AS aviso',
               'ALTER TABLE comentarios_sugerencias ADD COLUMN telefono VARCHAR(20) NULL DEFAULT NULL AFTER email'));
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── Verificación ────────────────────────────────────────────────────────
-- Debe aparecer 1 fila (telefono · varchar(20)):
SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comentarios_sugerencias' AND COLUMN_NAME = 'telefono';

-- ─── Soporte ──────────────────────────────────────────────────────────────
-- Ver los mensajes que dejaron teléfono:
--   SELECT fecha_hora, telefono, email, comentario FROM comentarios_sugerencias WHERE telefono IS NOT NULL ORDER BY id DESC;
-- Los que llegaron ANTES de correr esta migración traen el teléfono al final del texto («Teléfono: …»).
