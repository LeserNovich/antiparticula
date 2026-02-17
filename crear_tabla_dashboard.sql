-- ============================================================
-- Tabla: dashboard_pos
-- Descripción: Almacena datos sincronizados desde el POS para
--              el dashboard móvil PWA de Antiparticula.
-- Ejecutar en phpMyAdmin (base de datos: u450756829_PaginaWeb)
-- ============================================================

CREATE TABLE IF NOT EXISTS dashboard_pos (
    hardware_id      VARCHAR(50) PRIMARY KEY,
    negocio          VARCHAR(200) DEFAULT '',
    ultima_sync      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ventas_hoy       BIGINT DEFAULT 0,        -- en centavos
    tickets_hoy      INT DEFAULT 0,
    ganancia_hoy     BIGINT DEFAULT 0,        -- en centavos
    turno_usuario    VARCHAR(100) DEFAULT '',
    turno_inicio     VARCHAR(30) DEFAULT '',
    turno_abierto    TINYINT(1) DEFAULT 0,
    ventas_turno     BIGINT DEFAULT 0,        -- en centavos
    tickets_turno    INT DEFAULT 0,
    ultimas_ventas   TEXT,                    -- JSON: [{hora, total_centavos, cajero}, ...]
    historico_semana TEXT,                    -- JSON: [{fecha, total_centavos}, ...] 7 dias
    INDEX idx_sync (ultima_sync DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
