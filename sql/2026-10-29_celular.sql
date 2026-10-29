-- ══════════════════════════════════════════════════════════════════════════
-- Migración: el celular que incluye cada Premium de PC (la app de Android)
-- Fecha:     2026-10-29
-- Base:      u450756829_PaginaWeb
--
-- ANTES DE EJECUTAR: en phpMyAdmin haz clic en TU base (u450756829_PaginaWeb) en la
-- columna izquierda y SOLO ENTONCES abre la pestaña «SQL». Si la base activa es
-- information_schema, mysql o ninguna, el script lo detecta, NO cambia nada y avisa.
--
-- Requerida por: tienda/celular.php (y lo que agrega tienda_comun.php). Córrela ANTES de
-- subir esos archivos. Sin ella la tienda sigue igual: el celular simplemente no se vincula.
--
-- Solo AGREGA una tabla. No borra nada. Es idempotente: se puede correr dos veces.
-- Cómo ejecutar en Hostinger: hPanel → Bases de datos → phpMyAdmin → TU base → SQL.
-- ══════════════════════════════════════════════════════════════════════════

-- ¿Estás en TU base? Esta consulta debe decir u450756829_PaginaWeb:
SELECT DATABASE() AS base_actual;

SET @base_ok := (DATABASE() IS NOT NULL
                 AND DATABASE() NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys'));
SET @aviso := 'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso';

-- ─── El celular de cada compra ────────────────────────────────────────────
-- Una compra de Premium (o de varias sucursales) para PC incluye UN celular. hardware_id es el
-- ANDROID_ID del teléfono (16 hex, como el de una PC). vinculado: cuándo se vinculó (cambiar de
-- celular se puede una vez cada 30 días).
SET @ddl := IF(@base_ok = 0, @aviso,
    'CREATE TABLE IF NOT EXISTS tienda_celulares (
        compra_id    INT UNSIGNED NOT NULL,
        hardware_id  CHAR(16)     NOT NULL COMMENT ''ANDROID_ID del teléfono'',
        vinculado    DATETIME     NOT NULL,
        PRIMARY KEY (compra_id),
        KEY ix_hw (hardware_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''El celular que incluye cada compra de Premium para PC''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── Verificación ────────────────────────────────────────────────────────
-- Debe aparecer la tabla:
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tienda_celulares';

-- ─── Soporte ──────────────────────────────────────────────────────────────
-- El celular de una compra:
--   SELECT c.codigo, t.hardware_id, t.vinculado FROM tienda_celulares t JOIN tienda_compras c ON c.id = t.compra_id
--   WHERE c.codigo = 'XXXXXXXXXXXX';
-- Dejar que una compra cambie de celular otra vez antes de los 30 días:
--   UPDATE tienda_celulares SET vinculado = NOW() - INTERVAL 31 DAY WHERE compra_id = (SELECT id FROM tienda_compras WHERE codigo = 'XXXXXXXXXXXX');
