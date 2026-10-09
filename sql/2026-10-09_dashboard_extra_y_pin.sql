-- ══════════════════════════════════════════════════════════════════════════
-- Migración: panel móvil v2 — pagos, caja, cancelaciones, inventario, PIN
-- Fecha:     2026-10-09
-- Base:      u450756829_PaginaWeb
--
-- ANTES DE EJECUTAR: en phpMyAdmin haz clic en TU base (u450756829_PaginaWeb) en la
-- columna izquierda y SOLO ENTONCES abre la pestaña «SQL». Si la base activa es
-- information_schema, mysql o ninguna, el script lo detecta, NO cambia nada y avisa
-- (antes fallaba con «#1044 Acceso denegado … a la base de datos 'information_schema'»).
--
-- Requerida por (todo opcional: el PHP nuevo NO falla si esto no se corrió, solo
-- omite lo nuevo; aun así conviene correrla ANTES de subir el PHP):
--   · public_html/sync_pos.php, get_dashboard_data.php, dashboard_auth.php
--   · POS 3.3.0 (manda extra, dias, meses y la clave del PIN)
--
-- Solo AGREGA columnas y una tabla. No cambia ni borra nada: volver atrás es
-- re-subir los archivos anteriores; estas columnas no estorban.
--
-- Es idempotente: se puede correr dos veces sin error (patrón de la migración
-- 2026-09-01: consulta INFORMATION_SCHEMA y solo hace el ALTER si falta, para
-- que funcione igual en MySQL 5.7/8 y MariaDB).
--
-- Cómo ejecutar en Hostinger: hPanel → Bases de datos → phpMyAdmin → TU base → SQL.
-- ══════════════════════════════════════════════════════════════════════════

-- ¿Estás en TU base? Esta consulta debe decir u450756829_PaginaWeb:
SELECT DATABASE() AS base_actual;

-- 1 = base normal; 0 = base del sistema o ninguna (entonces todo lo de abajo se omite con un aviso)
SET @base_ok := (DATABASE() IS NOT NULL
                 AND DATABASE() NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys'));

-- ¿La migración 001 (dashboard_pos_ventas, gráfica por hora) ya se aplicó?
-- Esta consulta debe devolver UNA fila; si devuelve 0, corre primero 001:
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dashboard_pos_ventas';

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos.extra_json: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos ADD COLUMN extra_json MEDIUMTEXT NULL COMMENT ''JSON con pagos, caja, cancelaciones e inventario (v1)'' AFTER historico_anual'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos'
      AND COLUMN_NAME  = 'extra_json');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos.pin_hash: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos ADD COLUMN pin_hash VARCHAR(255) NULL COMMENT ''password_hash del PIN del panel; NULL = panel sin PIN'' AFTER extra_json'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos'
      AND COLUMN_NAME  = 'pin_hash');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos.clave_hash: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos ADD COLUMN clave_hash CHAR(64) NULL COMMENT ''sha256 de la clave secreta del POS; con PIN exige firmar las escrituras'' AFTER pin_hash'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos'
      AND COLUMN_NAME  = 'clave_hash');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos.pin_fallos: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos ADD COLUMN pin_fallos TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''PINs incorrectos seguidos'' AFTER clave_hash'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos'
      AND COLUMN_NAME  = 'pin_fallos');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos.pin_bloqueo_hasta: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos ADD COLUMN pin_bloqueo_hasta DATETIME NULL COMMENT ''Hora (America/Mexico_City) hasta la que no se acepta otro PIN'' AFTER pin_fallos'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos'
      AND COLUMN_NAME  = 'pin_bloqueo_hasta');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos_historico.efectivo_centavos: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos_historico ADD COLUMN efectivo_centavos BIGINT NULL COMMENT ''Cobrado en efectivo (incluye abonos). NULL = día anterior a la versión 3.3.0 del POS'' AFTER tickets'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos_historico'
      AND COLUMN_NAME  = 'efectivo_centavos');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos_historico.tarjeta_centavos: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos_historico ADD COLUMN tarjeta_centavos BIGINT NULL AFTER efectivo_centavos'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos_historico'
      AND COLUMN_NAME  = 'tarjeta_centavos');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos_historico.transferencia_centavos: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos_historico ADD COLUMN transferencia_centavos BIGINT NULL AFTER tarjeta_centavos'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos_historico'
      AND COLUMN_NAME  = 'transferencia_centavos');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos_historico.comision_centavos: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos_historico ADD COLUMN comision_centavos BIGINT NULL COMMENT ''Comisión de tarjeta con IVA'' AFTER transferencia_centavos'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos_historico'
      AND COLUMN_NAME  = 'comision_centavos');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos_historico.cancelaciones: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos_historico ADD COLUMN cancelaciones INT NULL COMMENT ''Cancelaciones y devoluciones registradas ese día'' AFTER comision_centavos'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos_historico'
      AND COLUMN_NAME  = 'cancelaciones');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos_historico.cancelado_centavos: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos_historico ADD COLUMN cancelado_centavos BIGINT NULL COMMENT ''Monto cancelado y devuelto ese día'' AFTER cancelaciones'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos_historico'
      AND COLUMN_NAME  = 'cancelado_centavos');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Totales por mes (el año completo, calculado por el POS) ─────────────
-- Antes el año salía de sumar los snapshots diarios desde el día en que cada POS
-- empezó a sincronizar: un negocio con años de historial veía un año casi vacío.
-- Ahora el POS manda sus 12 meses (recalculados: una cancelación se corrige sola).
SET @ddl := IF(@base_ok = 0,
    'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
    'CREATE TABLE IF NOT EXISTS dashboard_pos_meses (
        hardware_id       VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL,
        mes               CHAR(7)     NOT NULL COMMENT ''YYYY-MM'',
        ventas_centavos   BIGINT      NOT NULL DEFAULT 0 COMMENT ''Ventas + abonos de apartados'',
        ganancia_centavos BIGINT      NOT NULL DEFAULT 0,
        tickets           INT         NOT NULL DEFAULT 0,
        actualizado       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
                                      ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (hardware_id, mes)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Totales mensuales por POS; alimentan la pestaña Año del panel''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── Verificación ────────────────────────────────────────────────────────
-- Deben aparecer las 5 columnas de dashboard_pos y las 6 de dashboard_pos_historico:
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM   INFORMATION_SCHEMA.COLUMNS
WHERE  TABLE_SCHEMA = DATABASE()
  AND  ((TABLE_NAME = 'dashboard_pos' AND COLUMN_NAME IN
            ('extra_json','pin_hash','clave_hash','pin_fallos','pin_bloqueo_hasta'))
     OR (TABLE_NAME = 'dashboard_pos_historico' AND COLUMN_NAME IN
            ('efectivo_centavos','tarjeta_centavos','transferencia_centavos',
             'comision_centavos','cancelaciones','cancelado_centavos')))
ORDER  BY TABLE_NAME, ORDINAL_POSITION;

-- Debe existir la tabla (0 filas todavía hasta que cada POS se actualice y sincronice):
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dashboard_pos_meses';

-- ─── Soporte: si un cliente olvida el PIN de su panel (o reinstala sin él) ─
-- Quita la protección; el cliente puede volver a activarla desde Configuración.
--   UPDATE dashboard_pos
--      SET pin_hash = NULL, clave_hash = NULL, pin_fallos = 0, pin_bloqueo_hasta = NULL
--    WHERE hardware_id = 'EL_ID_DEL_CLIENTE';
