-- ══════════════════════════════════════════════════════════════════════════
-- Migración: tu página privada de datos (/admin/)
-- Fecha:     2026-10-21
-- Base:      u450756829_PaginaWeb
--
-- ANTES DE EJECUTAR: en phpMyAdmin haz clic en TU base (u450756829_PaginaWeb) en la
-- columna izquierda y SOLO ENTONCES abre la pestaña «SQL». Si la base activa es
-- information_schema, mysql o ninguna, el script lo detecta, NO cambia nada y avisa.
--
-- Requerida por: public_html/admin/ y admin_comun.php. Sin admin_intentos la página NO deja
-- entrar (sin freno a quien adivina claves no se abre); sin admin_ocultos todo funciona, pero
-- no se puede apartar un equipo de las cifras.
--
-- Solo AGREGA dos tablas. No toca las demás. Es idempotente: se puede correr dos veces.
-- Cómo ejecutar en Hostinger: hPanel → Bases de datos → phpMyAdmin → TU base → SQL.
-- ══════════════════════════════════════════════════════════════════════════

-- ¿Estás en TU base? Esta consulta debe decir u450756829_PaginaWeb:
SELECT DATABASE() AS base_actual;

SET @base_ok := (DATABASE() IS NOT NULL
                 AND DATABASE() NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys'));
SET @aviso := 'SELECT ''ELIGE TU BASE DE DATOS en el panel izquierdo de phpMyAdmin y vuelve a abrir la pestaña SQL: no se cambió nada'' AS aviso';

-- ─── Claves incorrectas por IP y hora (frena a quien las adivina) ─────────
SET @ddl := IF(@base_ok = 0, @aviso,
    'CREATE TABLE IF NOT EXISTS admin_intentos (
        ip      VARCHAR(45) NOT NULL,
        hora    CHAR(13)    NOT NULL COMMENT ''YYYY-MM-DD HH'',
        fallos  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (ip, hora)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Entradas fallidas a /admin/ por IP y hora''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Equipos que no cuentan (los tuyos, los de pruebas) ───────────────────
-- hardware_id: el ID del panel y de la telemetría (uso_punto_venta, dashboard_pos), no el de la licencia.
SET @ddl := IF(@base_ok = 0, @aviso,
    'CREATE TABLE IF NOT EXISTS admin_ocultos (
        hardware_id VARCHAR(50)  NOT NULL,
        nota        VARCHAR(100) NOT NULL DEFAULT '''',
        creado      DATETIME     NOT NULL,
        PRIMARY KEY (hardware_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=''Equipos que la página no suma (pruebas, los del dueño)''');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─── Verificación ────────────────────────────────────────────────────────
-- Deben aparecer las 2 tablas:
SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
WHERE  TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('admin_intentos', 'admin_ocultos');

-- ─── Soporte ──────────────────────────────────────────────────────────────
-- Te bloqueaste (demasiadas claves incorrectas en esta hora):
--   DELETE FROM admin_intentos;
-- Volver a contar un equipo que apartaste:
--   DELETE FROM admin_ocultos WHERE hardware_id = 'EL_ID';
