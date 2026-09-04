-- ══════════════════════════════════════════════════════════════════════════
-- Migración: historial anual + soporte de apartados (abonos)
-- Fecha:     2026-09-01
-- Base:      u450756829_PaginaWeb
--
-- Requerida por:
--   · public_html/sync_pos.php           (escribe las columnas y la tabla nueva)
--   · public_html/get_dashboard_data.php (agrega por mes desde la tabla nueva)
--   · public_html/dashboard/dashboard.js (grafica el historial anual)
--
-- IMPORTANTE: ejecutar ANTES de subir el código nuevo. Si el código sube
-- primero, sync_pos.php falla con "Unknown column" y TODOS los POS conectados
-- dejan de sincronizar.
--
-- Cómo ejecutar en Hostinger:
--   hPanel → Bases de datos → phpMyAdmin → pestaña SQL → pegar → Continuar
-- O por consola:
--   mysql -h 127.0.0.1 -u u450756829_Leser -p u450756829_PaginaWeb < este_archivo.sql
--
-- Es idempotente: se puede correr dos veces sin romper nada.
-- ══════════════════════════════════════════════════════════════════════════

-- ─── PASO 0: verificar el estado actual (solo lectura) ───────────────────
-- Verificado en produccion 2026-09-01: las 3 columnas del PASO 1 YA EXISTEN
-- (alguien las agrego antes), asi que ese paso se auto-omite. Se deja por si
-- hay que rehacer la migracion en otra base.
--
-- Lo unico que falta confirmar es si la tabla del PASO 2 ya existe y con que
-- forma. Si devuelve 0 filas, la tabla no existe y el PASO 2 la creara:
SELECT TABLE_NAME, TABLE_COLLATION, ENGINE
FROM   INFORMATION_SCHEMA.TABLES
WHERE  TABLE_SCHEMA = DATABASE()
  AND  TABLE_NAME   = 'dashboard_pos_historico';


-- ─── PASO 1: columnas nuevas en dashboard_pos ────────────────────────────
-- Montos en CENTAVOS (enteros), igual que el resto del esquema.
-- BIGINT para no toparse con el techo de INT (~21 millones de pesos).
--
-- Cada bloque consulta INFORMATION_SCHEMA y solo ejecuta el ALTER si la
-- columna falta. Así el script se puede correr dos veces sin error, y
-- funciona igual en MySQL 5.7/8 y en MariaDB (MySQL no soporta
-- "ADD COLUMN IF NOT EXISTS"; MariaDB sí, pero no queremos depender de eso).

SET @ddl := (
    SELECT IF(COUNT(*) > 0,
        'SELECT ''abonos_hoy: ya existe, omitido'' AS aviso',
        'ALTER TABLE dashboard_pos
             ADD COLUMN abonos_hoy BIGINT NOT NULL DEFAULT 0
             COMMENT ''Abonos de apartados cobrados hoy, en centavos''
             AFTER ganancia_hoy')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos'
      AND COLUMN_NAME  = 'abonos_hoy');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(COUNT(*) > 0,
        'SELECT ''abonos_turno: ya existe, omitido'' AS aviso',
        'ALTER TABLE dashboard_pos
             ADD COLUMN abonos_turno BIGINT NOT NULL DEFAULT 0
             COMMENT ''Abonos de apartados cobrados en el turno actual, en centavos''
             AFTER tickets_turno')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos'
      AND COLUMN_NAME  = 'abonos_turno');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
    SELECT IF(COUNT(*) > 0,
        'SELECT ''historico_anual: ya existe, omitido'' AS aviso',
        'ALTER TABLE dashboard_pos
             ADD COLUMN historico_anual TEXT NULL
             COMMENT ''JSON [{mes:YYYY-MM, ventas_centavos, ganancia_centavos}] enviado por el POS''
             AFTER historico_semana')
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dashboard_pos'
      AND COLUMN_NAME  = 'historico_anual');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── PASO 2: tabla de snapshots diarios ──────────────────────────────────
-- Una fila por dispositivo por día. El PRIMARY KEY compuesto es lo que hace
-- funcionar el ON DUPLICATE KEY UPDATE de sync_pos.php: cada sync del día
-- sobrescribe la fila en vez de duplicarla.
--
-- hardware_id replica EXACTAMENTE el tipo y COLLATE de dashboard_pos.hardware_id
-- (verificado en produccion 2026-09-01: varchar(50) utf8mb4_unicode_ci).
-- sync_pos.php valida el formato con /^[a-zA-Z0-9_\-]{4,50}$/, de ahí el 50.

CREATE TABLE IF NOT EXISTS dashboard_pos_historico (
    hardware_id       VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL
        COMMENT 'Identificador del equipo POS',
    fecha             DATE        NOT NULL
        COMMENT 'Día del snapshot en zona America/Mexico_City (-06:00)',
    ventas_centavos   BIGINT      NOT NULL DEFAULT 0
        COMMENT 'Ingresos del día = ventas + abonos de apartados',
    ganancia_centavos BIGINT      NOT NULL DEFAULT 0,
    tickets           INT         NOT NULL DEFAULT 0,
    actualizado       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (hardware_id, fecha),
    KEY idx_hw_fecha (hardware_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Snapshot diario por POS; alimenta la gráfica anual del dashboard';


-- ─── PASO 3: sembrar el día de hoy ───────────────────────────────────────
-- Sin esto la gráfica anual aparece vacía hasta el siguiente sync de cada POS.
-- Copia lo que ya está en dashboard_pos como snapshot de hoy.
-- (abonos_hoy vale 0 en las filas viejas, así que el total es correcto.)

-- Misma convencion de fecha que sync_pos.php, que hace SET time_zone = '-06:00'
-- antes de escribir. America/Mexico_City es UTC-06:00 todo el año (Mexico
-- elimino el horario de verano en 2022), asi que el offset fijo es correcto.
SET time_zone = '-06:00';

INSERT INTO dashboard_pos_historico
        (hardware_id, fecha, ventas_centavos, ganancia_centavos, tickets)
SELECT   hardware_id,
         CURDATE(),
         ventas_hoy + abonos_hoy,
         ganancia_hoy,
         tickets_hoy
FROM     dashboard_pos
ON DUPLICATE KEY UPDATE
         ventas_centavos   = VALUES(ventas_centavos),
         ganancia_centavos = VALUES(ganancia_centavos),
         tickets           = VALUES(tickets);


-- ─── PASO 4: verificación ────────────────────────────────────────────────
-- Las 3 columnas nuevas deben aparecer:
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM   INFORMATION_SCHEMA.COLUMNS
WHERE  TABLE_SCHEMA = DATABASE()
  AND  TABLE_NAME   = 'dashboard_pos'
  AND  COLUMN_NAME IN ('abonos_hoy', 'abonos_turno', 'historico_anual');

-- Debe devolver una fila por cada POS registrado:
SELECT hardware_id, fecha, ventas_centavos, ganancia_centavos, tickets
FROM   dashboard_pos_historico
ORDER  BY fecha DESC, hardware_id
LIMIT  20;
