-- ─────────────────────────────────────────────────────────────────────
-- Migración 001 — Fidelidad de la gráfica "Ventas por hora"
--
-- PROBLEMA
--   La gráfica horaria se construía desde `dashboard_pos.ultimas_ventas`,
--   un JSON que el POS sobrescribe en cada sync con solo las últimas N
--   ventas. Resultado: la gráfica mostraba únicamente las horas recientes
--   y el resto del día aparecía vacío.
--
-- SOLUCIÓN
--   Acumular cada venta individual en su propia fila. Como el POS
--   reenvía las mismas ventas en syncs consecutivos, la unicidad de
--   (hardware_id, fecha, venta_key) hace el INSERT idempotente.
--
-- Ejecutar una sola vez en la BD u450756829_PaginaWeb.
-- ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS dashboard_pos_ventas (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    hardware_id    VARCHAR(50)     NOT NULL,
    fecha          DATE            NOT NULL,
    hora           TINYINT UNSIGNED NOT NULL,          -- 0..23
    minuto         TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0..59
    cajero         VARCHAR(100)    NOT NULL DEFAULT '',
    total_centavos INT             NOT NULL DEFAULT 0,

    -- Identidad estable de la venta: el folio del POS si lo manda,
    -- si no un hash de (hora, minuto, cajero, importe).
    venta_key      VARCHAR(64)     NOT NULL,

    creado_en      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_venta       (hardware_id, fecha, venta_key),
    KEY        idx_hw_fecha   (hardware_id, fecha),
    KEY        idx_purga      (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
