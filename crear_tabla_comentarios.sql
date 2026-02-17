-- ============================================
-- Tabla para almacenar comentarios y sugerencias
-- del sistema POS Antiparticula
-- ============================================

CREATE TABLE IF NOT EXISTS `comentarios_sugerencias` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `comentario` TEXT NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `pais` VARCHAR(100) DEFAULT 'Desconocido',
  `ciudad` VARCHAR(100) DEFAULT 'Desconocida',
  `fecha_hora` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fecha` (`fecha_hora` DESC),
  INDEX `idx_pais` (`pais`),
  INDEX `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comentarios sobre la estructura:
-- - id: Identificador único autoincrementable
-- - comentario: Texto del comentario (hasta ~65,535 caracteres)
-- - email: Opcional, para contacto si el usuario lo proporciona
-- - ip_address: Dirección IP del cliente (soporta IPv4 e IPv6)
-- - pais/ciudad: Geolocalización aproximada vía ip-api.com
-- - fecha_hora: Timestamp automático del registro
-- - Índices para mejorar consultas por fecha, país y email
