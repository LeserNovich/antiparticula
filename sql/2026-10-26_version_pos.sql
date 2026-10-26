-- ══════════════════════════════════════════════════════════════════════════
-- Migración: versión del punto de venta en la telemetría
-- Fecha:     2026-10-26
-- Base:      u450756829_PaginaWeb
--
-- ANTES DE EJECUTAR: en phpMyAdmin haz clic en TU base (u450756829_PaginaWeb) en la
-- columna izquierda y SOLO ENTONCES abre la pestaña «SQL». Si la base activa es
-- information_schema, mysql o ninguna, el script lo detecta, NO cambia nada y avisa.
--
-- Requerida por: public_html/registrar_uso.php (el POS posterior a 3.6.0 manda `version`) y la
-- sección Computadoras de /admin/ («En qué versión están»). Sin la columna TODO SIGUE FUNCIONANDO:
-- el endpoint guarda el evento sin la versión y /admin/ no muestra lo de las versiones.
--
-- Solo AGREGA una columna (`version_pos`, junto al ID del equipo). Es idempotente: se puede correr dos veces.
-- Cómo ejecutar en Hostinger: hPanel → Bases de datos → phpMyAdmin → TU base → SQL.
-- ══════════════════════════════════════════════════════════════════════════

-- ¿Estás en TU base? Esta consulta debe decir u450756829_PaginaWeb:
SELECT DATABASE() AS base_actual;

SET @base_ok := (DATABASE() IS NOT NULL
                 AND DATABASE() NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys'));
SET @aviso := 'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso';

-- ─── Versión del programa que mandó el evento («3.7.0»), o NULL (las de hasta 3.6.0 no la mandan) ───
SET @ya := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uso_punto_venta' AND COLUMN_NAME = 'version_pos');
SET @ddl := IF(@base_ok = 0, @aviso,
            IF(@ya > 0, 'SELECT ''La columna version_pos ya existia: no se cambio nada'' AS aviso',
               'ALTER TABLE uso_punto_venta ADD COLUMN version_pos VARCHAR(20) NULL DEFAULT NULL AFTER hardware_id'));
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── Verificación ────────────────────────────────────────────────────────
-- Debe aparecer 1 fila (version_pos · varchar(20)):
SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uso_punto_venta' AND COLUMN_NAME = 'version_pos';

-- ─── Soporte ──────────────────────────────────────────────────────────────
-- En qué versión entró cada computadora la última vez:
--   SELECT hardware_id, SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(version_pos, '') ORDER BY fecha_hora DESC), ',', 1) AS version,
--          MAX(fecha_hora) AS ultima
--   FROM uso_punto_venta GROUP BY hardware_id ORDER BY ultima DESC;
