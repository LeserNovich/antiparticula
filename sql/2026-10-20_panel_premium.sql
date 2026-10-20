-- ══════════════════════════════════════════════════════════════════════════
-- Migración: el panel móvil pasa a Premium + contador de la prueba gratis por PC
-- Fecha:     2026-10-20
-- Base:      u450756829_PaginaWeb
--
-- ANTES DE EJECUTAR: en phpMyAdmin haz clic en TU base (u450756829_PaginaWeb) en la
-- columna izquierda y SOLO ENTONCES abre la pestaña «SQL». Si la base activa es
-- information_schema, mysql o ninguna, el script lo detecta, NO cambia nada y avisa.
--
-- Requerida por (el PHP nuevo NO falla si esto no se corrió: sin estas columnas el
-- panel sigue abierto para todos, como antes):
--   · public_html/sync_pos.php, get_dashboard_data.php, prueba_gratis.php
--   · POS 3.4.0 (manda su clave premium firmada y el ID estable de la PC)
--
-- ¡OJO CON LA FECHA! Al correrla, CADA panel que ya existe recibe 30 días gratis contados
-- desde HOY (el aviso a los clientes de antes). Córrela el MISMO DÍA en que subas el PHP y
-- el panel nuevos: si la corres antes, esos días se descuentan del aviso.
--
-- Solo AGREGA columnas y tablas. No borra nada: volver atrás es re-subir los archivos
-- anteriores (las columnas no estorban). Es idempotente: se puede correr dos veces; la
-- segunda NO vuelve a dar 30 días (solo llena los paneles que todavía no tienen fecha).
--
-- Cómo ejecutar en Hostinger: hPanel → Bases de datos → phpMyAdmin → TU base → SQL.
-- ══════════════════════════════════════════════════════════════════════════

-- ¿Estás en TU base? Esta consulta debe decir u450756829_PaginaWeb:
SELECT DATABASE() AS base_actual;

SET @base_ok := (DATABASE() IS NOT NULL
                 AND DATABASE() NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys'));

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos.gratis_hasta: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos ADD COLUMN gratis_hasta DATE NULL COMMENT ''Último día del panel sin Premium: prueba de 15 días (panel nuevo) o aviso de 30 días (panel de antes)'''))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos'
      AND COLUMN_NAME  = 'gratis_hasta');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(@base_ok = 0,
        'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos.premium_hasta: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos ADD COLUMN premium_hasta DATE NULL COMMENT ''Vencimiento de la clave premium firmada más lejana que mandó el POS (verificada aquí)'' AFTER gratis_hasta'))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos'
      AND COLUMN_NAME  = 'premium_hasta');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Los paneles que ya existen: 30 días de aviso desde hoy. Solo los que no tienen fecha, así
-- que correr el script otra vez no los alarga.
SET @ddl := IF(@base_ok = 0,
    'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
    'UPDATE dashboard_pos SET gratis_hasta = CURRENT_DATE + INTERVAL 30 DAY WHERE gratis_hasta IS NULL');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Qué paneles usa cada clave premium ───────────────────────────────────
-- Una clave abre a lo más 2 paneles (la PC de la tienda y una reinstalación con otro ID):
-- así no se presta para abrir los paneles de otros negocios.
SET @ddl := IF(@base_ok = 0,
    'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
    'CREATE TABLE IF NOT EXISTS dashboard_licencias (
        huella      CHAR(28)    NOT NULL COMMENT ''Los 14 bytes de datos de la clave en hexadecimal (únicos por clave)'',
        hardware_id VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL,
        vence       DATE        NOT NULL,
        visto       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (huella, hardware_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Paneles que abrió cada clave premium''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── La prueba gratis, por PC y no por instalación ────────────────────────
-- equipo = el Hardware ID estable de la PC (el MachineGuid de Windows: no cambia al
-- desinstalar y volver a instalar el programa, solo al reinstalar Windows).
-- *_usados solo sube: el POS manda lo que le queda y aquí se guarda lo más gastado.
SET @ddl := IF(@base_ok = 0,
    'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
    'CREATE TABLE IF NOT EXISTS pruebas_equipo (
        equipo          CHAR(16)         NOT NULL,
        primera_vez     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT ''La primera vez que esta PC habló con el servidor'',
        exportar_usados TINYINT UNSIGNED NOT NULL DEFAULT 0,
        importar_usados TINYINT UNSIGNED NOT NULL DEFAULT 0,
        actualizado     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (equipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT=''Intentos gratis de Excel ya usados en cada PC''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── Verificación ────────────────────────────────────────────────────────
-- Deben aparecer las 2 columnas:
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM   INFORMATION_SCHEMA.COLUMNS
WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dashboard_pos'
  AND  COLUMN_NAME IN ('gratis_hasta', 'premium_hasta');

-- Y las 2 tablas:
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('dashboard_licencias', 'pruebas_equipo');

-- Ningún panel debe quedar sin fecha (0):
SET @ddl := IF(@base_ok = 0,
    'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso',
    'SELECT COUNT(*) AS paneles_sin_fecha FROM dashboard_pos WHERE gratis_hasta IS NULL');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Soporte ──────────────────────────────────────────────────────────────
-- Darle más días gratis a un panel:
--   UPDATE dashboard_pos SET gratis_hasta = CURRENT_DATE + INTERVAL 15 DAY WHERE hardware_id = 'EL_ID_DEL_PANEL';
-- Una clave que ya abrió 2 paneles y el cliente cambió de PC: liberar el panel viejo
--   DELETE FROM dashboard_licencias WHERE hardware_id = 'EL_ID_DEL_PANEL_VIEJO';
-- Devolverle la prueba de Excel a una PC:
--   UPDATE pruebas_equipo SET exportar_usados = 0, importar_usados = 0 WHERE equipo = 'EL_HARDWARE_ID';
