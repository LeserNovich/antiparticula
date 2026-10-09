-- ══════════════════════════════════════════════════════════════════════════
-- LÍNEA BASE RECONSTRUIDA — SOLO PARA PRUEBAS. NO SE EJECUTA EN PRODUCCIÓN.
--
-- La tabla `dashboard_pos` original nunca se versionó (el repo solo trae los
-- ALTER posteriores). Este archivo la reconstruye a partir de las columnas que
-- lee y escribe `sync_pos.php` y de las migraciones 001 y 2026-09-01, para que
-- `tests/correr_api.sh` pueda levantar una base idéntica en forma a la de
-- producción ANTES de la migración 2026-10-09 y probar que el PHP nuevo no
-- rompe nada si esa migración todavía no se ha corrido.
--
-- Producción ya tiene todo esto. Si alguna vez difiere (tipos exactos), lo que
-- importa para las pruebas son los nombres de columna y la clave primaria.
-- ══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS dashboard_pos (
    hardware_id      VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL,
    negocio          VARCHAR(200) NOT NULL DEFAULT '',
    ultima_sync      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ventas_hoy       BIGINT NOT NULL DEFAULT 0,
    tickets_hoy      INT    NOT NULL DEFAULT 0,
    ganancia_hoy     BIGINT NOT NULL DEFAULT 0,
    abonos_hoy       BIGINT NOT NULL DEFAULT 0,
    turno_usuario    VARCHAR(100) NOT NULL DEFAULT '',
    turno_inicio     VARCHAR(30)  NOT NULL DEFAULT '',
    turno_abierto    TINYINT NOT NULL DEFAULT 0,
    ventas_turno     BIGINT NOT NULL DEFAULT 0,
    tickets_turno    INT    NOT NULL DEFAULT 0,
    abonos_turno     BIGINT NOT NULL DEFAULT 0,
    ultimas_ventas   TEXT NULL,
    historico_semana TEXT NULL,
    historico_anual  TEXT NULL,
    PRIMARY KEY (hardware_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_pos_historico (
    hardware_id       VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL,
    fecha             DATE        NOT NULL,
    ventas_centavos   BIGINT      NOT NULL DEFAULT 0,
    ganancia_centavos BIGINT      NOT NULL DEFAULT 0,
    tickets           INT         NOT NULL DEFAULT 0,
    actualizado       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (hardware_id, fecha),
    KEY idx_hw_fecha (hardware_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_pos_ventas (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    hardware_id    VARCHAR(50)     NOT NULL,
    fecha          DATE            NOT NULL,
    hora           TINYINT UNSIGNED NOT NULL,
    minuto         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    cajero         VARCHAR(100)    NOT NULL DEFAULT '',
    total_centavos INT             NOT NULL DEFAULT 0,
    venta_key      VARCHAR(64)     NOT NULL,
    creado_en      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_venta       (hardware_id, fecha, venta_key),
    KEY        idx_hw_fecha   (hardware_id, fecha),
    KEY        idx_purga      (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
