-- ══════════════════════════════════════════════════════════════════════════
-- Migración: registros de la página de los anuncios (/gratis)
-- Fecha:     2026-10-27
-- Base:      u450756829_PaginaWeb
--
-- ANTES DE EJECUTAR: en phpMyAdmin haz clic en TU base (u450756829_PaginaWeb) en la
-- columna izquierda y SOLO ENTONCES abre la pestaña «SQL». Si la base activa es
-- information_schema, mysql o ninguna, el script lo detecta, NO cambia nada y avisa.
--
-- Requerida por: public_html/registrar_gratis.php (el formulario de /gratis: WhatsApp y giro de quien
-- vio el anuncio en el celular), public_html/registrar_descarga.php (marca quién de ellos descargó) y la
-- sección Registros de /admin/. Sin la tabla, /gratis responde «no disponible» y las descargas se
-- registran como siempre.
--
-- Solo CREA una tabla nueva (y le agrega las columnas del seguimiento si se creó con la primera versión de este
-- archivo, la del 27 de octubre en la mañana). Es idempotente: se puede correr dos veces.
-- Cómo ejecutar en Hostinger: hPanel → Bases de datos → phpMyAdmin → TU base → SQL.
-- ══════════════════════════════════════════════════════════════════════════

-- ¿Estás en TU base? Esta consulta debe decir u450756829_PaginaWeb:
SELECT DATABASE() AS base_actual;

SET @base_ok := (DATABASE() IS NOT NULL
                 AND DATABASE() NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys'));
SET @aviso := 'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso';

-- ─── Quién pidió el link desde el celular ─────────────────────────────────
-- codigo:   el de su link personal (antiparticula.com/d/CODIGO): une el celular con la computadora
-- telefono: E.164 (+52 y 10 dígitos en México); uno por persona
-- ttclid:   el identificador del clic en el anuncio de TikTok (llega en la URL); con él TikTok atribuye
--           la descarga hecha días después en la computadora
-- ttp:      la cookie _ttp del Pixel de TikTok en el celular
SET @ddl := IF(@base_ok = 0, @aviso,
    'CREATE TABLE IF NOT EXISTS registros_gratis (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        codigo       VARCHAR(8)   NOT NULL,
        telefono     VARCHAR(16)  NOT NULL,
        nombre       VARCHAR(60)  NULL,
        giro         VARCHAR(30)  NOT NULL,
        ttclid       VARCHAR(500) NULL,
        ttp          VARCHAR(100) NULL,
        ip           VARCHAR(45)  NOT NULL,
        user_agent   VARCHAR(255) NULL,
        creado       DATETIME     NOT NULL,
        descargo_en  DATETIME     NULL COMMENT ''la primera descarga desde su link'',
        sistema      VARCHAR(10)  NULL COMMENT ''windows, macArm o macIntel'',
        contactado_en DATETIME    NULL COMMENT ''la última vez que le escribiste por WhatsApp desde /admin/'',
        baja_en      DATETIME     NULL COMMENT ''pidió que no le escribieran más (BAJA)'',
        PRIMARY KEY (id),
        UNIQUE KEY uq_codigo (codigo),
        UNIQUE KEY uq_telefono (telefono),
        KEY ix_ip_creado (ip, creado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Quien pidió el link de descarga en /gratis''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── El seguimiento desde /admin/ (si la tabla se creó sin él) ─────────────
SET @ya := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registros_gratis' AND COLUMN_NAME = 'contactado_en');
SET @ddl := IF(@base_ok = 0, @aviso,
            IF(@ya > 0, 'SELECT ''La columna contactado_en ya existia'' AS aviso',
               'ALTER TABLE registros_gratis ADD COLUMN contactado_en DATETIME NULL COMMENT ''la última vez que le escribiste por WhatsApp desde /admin/'' AFTER sistema'));
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ya := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registros_gratis' AND COLUMN_NAME = 'baja_en');
SET @ddl := IF(@base_ok = 0, @aviso,
            IF(@ya > 0, 'SELECT ''La columna baja_en ya existia'' AS aviso',
               'ALTER TABLE registros_gratis ADD COLUMN baja_en DATETIME NULL COMMENT ''pidió que no le escribieran más (BAJA)'' AFTER contactado_en'));
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── Verificación ────────────────────────────────────────────────────────
-- Debe aparecer 1 fila (registros_gratis · 14 columnas):
SELECT TABLE_NAME, (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS c
                    WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME) AS columnas
FROM   INFORMATION_SCHEMA.TABLES t
WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registros_gratis';

-- ─── Soporte ──────────────────────────────────────────────────────────────
-- Los registros de hoy y si ya descargaron:
--   SELECT creado, nombre, telefono, giro, codigo, descargo_en FROM registros_gratis
--   WHERE creado >= CURDATE() ORDER BY creado DESC;
-- Alguien pide que borremos sus datos (derechos ARCO):
--   DELETE FROM registros_gratis WHERE telefono = '+525512345678';
