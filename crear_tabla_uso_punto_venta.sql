-- ========================================================
-- Tabla: uso_punto_venta
-- Descripción: Registra eventos de telemetría del POS
-- Eventos: login, cierre_turno, salida_app
-- ========================================================

CREATE TABLE IF NOT EXISTS uso_punto_venta (
    -- Identificador único del evento
    id INT AUTO_INCREMENT PRIMARY KEY,

    -- Tipo de evento registrado
    tipo_evento VARCHAR(50) NOT NULL COMMENT 'login, cierre_turno, salida_app',

    -- Información del usuario
    usuario VARCHAR(100) NOT NULL COMMENT 'Nombre de usuario del sistema POS',
    rol VARCHAR(20) DEFAULT NULL COMMENT 'administrador o cajero',

    -- Contexto del evento
    tipo_sesion VARCHAR(50) DEFAULT NULL COMMENT 'nuevo_turno, continuacion, admin_override, modo_informe',

    -- Información de la instalación
    negocio VARCHAR(200) DEFAULT NULL COMMENT 'Nombre del negocio cliente',
    hardware_id VARCHAR(50) DEFAULT NULL COMMENT 'ID único de la instalación',

    -- Geolocalización (capturada automáticamente)
    ip_address VARCHAR(45) DEFAULT NULL COMMENT 'Dirección IP del cliente',
    pais VARCHAR(100) DEFAULT 'Desconocido' COMMENT 'País obtenido de ip-api.com',
    ciudad VARCHAR(100) DEFAULT 'Desconocido' COMMENT 'Ciudad obtenida de ip-api.com',

    -- Timestamp del evento
    fecha_hora DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Momento exacto del evento',

    -- Índices para optimizar consultas frecuentes
    INDEX idx_fecha (fecha_hora DESC) COMMENT 'Búsqueda por fecha',
    INDEX idx_usuario (usuario) COMMENT 'Búsqueda por usuario',
    INDEX idx_tipo_evento (tipo_evento) COMMENT 'Filtrado por tipo de evento',
    INDEX idx_negocio (negocio) COMMENT 'Análisis por negocio',
    INDEX idx_hardware_id (hardware_id) COMMENT 'Tracking de instalaciones únicas'

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Telemetría de uso del Punto de Venta';

-- ========================================================
-- Consultas de ejemplo para análisis
-- ========================================================

-- Ver últimos 20 eventos
-- SELECT * FROM uso_punto_venta ORDER BY fecha_hora DESC LIMIT 20;

-- Instalaciones activas últimos 7 días
-- SELECT COUNT(DISTINCT hardware_id) as instalaciones_activas
-- FROM uso_punto_venta
-- WHERE fecha_hora >= DATE_SUB(NOW(), INTERVAL 7 DAY);

-- Distribución por tipo de evento
-- SELECT tipo_evento, COUNT(*) as cantidad
-- FROM uso_punto_venta
-- GROUP BY tipo_evento;

-- Distribución geográfica
-- SELECT pais, ciudad, COUNT(*) as eventos
-- FROM uso_punto_venta
-- GROUP BY pais, ciudad
-- ORDER BY eventos DESC;
