-- ══════════════════════════════════════════════════════════════════════════
-- Migración: la tienda de Premium (cobro con Stripe y la clave que llega sola)
-- Fecha:     2026-10-21
-- Base:      u450756829_PaginaWeb
--
-- ANTES DE EJECUTAR: en phpMyAdmin haz clic en TU base (u450756829_PaginaWeb) en la
-- columna izquierda y SOLO ENTONCES abre la pestaña «SQL». Si la base activa es
-- information_schema, mysql o ninguna, el script lo detecta, NO cambia nada y avisa.
--
-- Requerida por: public_html/tienda/*.php y tienda_comun.php. Córrela ANTES de subir esos
-- archivos. sync_pos.php funciona igual sin ella (la columna hw_licencia es opcional).
--
-- Solo AGREGA tablas y una columna. No borra nada. Es idempotente: se puede correr dos veces.
-- Cómo ejecutar en Hostinger: hPanel → Bases de datos → phpMyAdmin → TU base → SQL.
-- ══════════════════════════════════════════════════════════════════════════

-- ¿Estás en TU base? Esta consulta debe decir u450756829_PaginaWeb:
SELECT DATABASE() AS base_actual;

SET @base_ok := (DATABASE() IS NOT NULL
                 AND DATABASE() NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys'));
SET @aviso := 'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso';

-- ─── Cada compra ──────────────────────────────────────────────────────────
-- codigo:  lo que el cliente canjea en el programa (compra desde la página, o al cambiar de PC).
-- hardware_id: la PC a la que está atada (NULL mientras no se canjee). ultimo_cambio_pc: el
-- último cambio de PC (uno cada 12 meses es automático).
SET @ddl := IF(@base_ok = 0, @aviso,
    'CREATE TABLE IF NOT EXISTS tienda_compras (
        id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
        codigo             CHAR(12)     NOT NULL COMMENT ''Sin guiones; se muestra AP-XXXX-XXXX-XXXX'',
        plan               VARCHAR(32)  NOT NULL,
        tipo               VARCHAR(10)  NOT NULL COMMENT ''premium | multi'',
        hardware_id        CHAR(16)     NULL,
        origen             VARCHAR(10)  NOT NULL DEFAULT ''web'' COMMENT ''pos | panel | web'',
        estado             VARCHAR(12)  NOT NULL COMMENT ''pendiente (OXXO sin pagar) | pagada | vencida'',
        email              VARCHAR(255) NULL,
        monto              INT          NOT NULL DEFAULT 0 COMMENT ''Centavos'',
        stripe_sesion      VARCHAR(255) NOT NULL,
        stripe_suscripcion VARCHAR(255) NULL,
        creada             DATETIME     NOT NULL,
        pagada             DATETIME     NULL,
        ultimo_cambio_pc   DATETIME     NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_codigo (codigo),
        UNIQUE KEY uk_sesion (stripe_sesion),
        KEY ix_suscripcion (stripe_suscripcion),
        KEY ix_hw (hardware_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Compras de Premium con Stripe''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Cada pago de una compra (el primero, y cada mes de una suscripción) ───
-- meses = 0 es el pago único «para siempre». aplicado = ya se convirtió en una clave.
SET @ddl := IF(@base_ok = 0, @aviso,
    'CREATE TABLE IF NOT EXISTS tienda_pagos (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        compra_id  INT UNSIGNED NOT NULL,
        stripe_ref VARCHAR(255) NOT NULL COMMENT ''La sesión (primer pago) o la factura (renovación)'',
        meses      TINYINT UNSIGNED NOT NULL,
        aplicado   TINYINT(1)   NOT NULL DEFAULT 0,
        creado     DATETIME     NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_ref (stripe_ref),
        KEY ix_compra (compra_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Pagos confirmados de cada compra''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Cada clave emitida ───────────────────────────────────────────────────
-- vence_pagado: hasta donde llega lo pagado; vence (el de la clave) le suma 3 días de gracia.
SET @ddl := IF(@base_ok = 0, @aviso,
    'CREATE TABLE IF NOT EXISTS tienda_claves (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        compra_id    INT UNSIGNED NOT NULL,
        hardware_id  CHAR(16)     NOT NULL,
        tipo         VARCHAR(10)  NOT NULL,
        vence_pagado DATE         NOT NULL,
        vence        DATE         NOT NULL,
        clave        CHAR(125)    NOT NULL,
        creada       DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY ix_hw (hardware_id, tipo),
        KEY ix_compra (compra_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Claves firmadas que entregó la tienda''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Lo que cada PC ya tenía (claves verificadas que mandó el POS) ─────────
-- Una renovación se suma desde aquí: así quien compró a mano por WhatsApp no pierde días.
SET @ddl := IF(@base_ok = 0, @aviso,
    'CREATE TABLE IF NOT EXISTS tienda_reportados (
        hardware_id CHAR(16)    NOT NULL,
        tipo        VARCHAR(10) NOT NULL,
        vence       DATE        NOT NULL,
        visto       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (hardware_id, tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Vencimiento de las claves que ya tenía cada PC''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Avisos de Stripe ya atendidos (Stripe reintenta: cada uno se procesa una vez) ───
SET @ddl := IF(@base_ok = 0, @aviso,
    'CREATE TABLE IF NOT EXISTS tienda_eventos (
        id       VARCHAR(255) NOT NULL,
        tipo     VARCHAR(80)  NOT NULL,
        recibido TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Eventos de Stripe procesados''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Intentos fallidos de canjear un código (por IP y hora: frena a quien los adivina) ───
SET @ddl := IF(@base_ok = 0, @aviso,
    'CREATE TABLE IF NOT EXISTS tienda_intentos (
        ip      VARCHAR(45) NOT NULL,
        hora    CHAR(13)    NOT NULL COMMENT ''YYYY-MM-DD HH'',
        fallos  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (ip, hora)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Canjes fallidos por IP y hora''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── El panel sabe qué PC es (para comprar desde el celular) ──────────────
-- hw_licencia: el Hardware ID con que se firman las claves de esa PC. Lo manda el POS 3.5.0 al sincronizar.
SET @ddl := (
    SELECT IF(@base_ok = 0, @aviso,
        IF(COUNT(*) > 0,
            'SELECT ''dashboard_pos.hw_licencia: ya existe, omitido'' AS aviso',
            'ALTER TABLE dashboard_pos ADD COLUMN hw_licencia CHAR(16) NULL COMMENT ''Hardware ID de las claves de esa PC (para comprar Premium desde el panel)'''))
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dashboard_pos' AND COLUMN_NAME = 'hw_licencia');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── Verificación ────────────────────────────────────────────────────────
-- Deben aparecer las 6 tablas:
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE  TABLE_SCHEMA = DATABASE()
  AND  TABLE_NAME IN ('tienda_compras', 'tienda_pagos', 'tienda_claves', 'tienda_reportados', 'tienda_eventos', 'tienda_intentos');
-- Y la columna:
SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dashboard_pos' AND COLUMN_NAME = 'hw_licencia';

-- ─── Soporte ──────────────────────────────────────────────────────────────
-- Las compras de un cliente (por correo o por PC):
--   SELECT * FROM tienda_compras WHERE email = 'cliente@correo.com' OR hardware_id = 'EL_HARDWARE_ID';
-- Las claves de una PC (para reenviarlas a mano):
--   SELECT tipo, vence, clave FROM tienda_claves WHERE hardware_id = 'EL_HARDWARE_ID' ORDER BY id DESC;
-- Dejar que una compra cambie de PC otra vez antes de los 12 meses:
--   UPDATE tienda_compras SET ultimo_cambio_pc = NULL WHERE codigo = 'XXXXXXXXXXXX';
