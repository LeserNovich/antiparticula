-- ══════════════════════════════════════════════════════════════════════════
-- TABLAS RECONSTRUIDAS — SOLO PARA PRUEBAS. NO SE EJECUTA EN PRODUCCIÓN.
--
-- uso_punto_venta, registros_descargas y comentarios_sugerencias se crearon a mano en
-- phpMyAdmin y nunca se versionaron. Aquí se rehacen con las columnas que escriben
-- registrar_uso.php, registrar_descarga.php y registrar_comentario.php, para que
-- tests/correr_admin.sh pruebe la página privada (/admin/) contra algo con su forma.
--
-- A PROPÓSITO en otra intercalación (utf8mb4_general_ci) que las tablas de las migraciones
-- (utf8mb4_unicode_ci): la página no debe comparar columnas de unas con otras en SQL, porque
-- MySQL rechaza la mezcla («Illegal mix of collations»). Si alguien agrega un JOIN así, la
-- prueba falla.
-- ══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS uso_punto_venta (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    tipo_evento VARCHAR(20)  NOT NULL,
    usuario     VARCHAR(100) NOT NULL,
    rol         VARCHAR(20)  NULL,
    tipo_sesion VARCHAR(30)  NULL,
    negocio     VARCHAR(200) NULL,
    hardware_id VARCHAR(50)  NULL,
    ip_address  VARCHAR(45)  NOT NULL,
    pais        VARCHAR(100) NULL,
    ciudad      VARCHAR(100) NULL,
    fecha_hora  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Columnas tal como están en producción (information_schema, 2026-10-21). La fecha es fecha_hora, NO
-- `timestamp` como decía la documentación vieja: con ese nombre la página falló en el hosting.
CREATE TABLE IF NOT EXISTS registros_descargas (
    id         INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fecha_hora TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    pais       VARCHAR(100) NULL,
    ciudad     VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS comentarios_sugerencias (
    id           INT(11) NOT NULL AUTO_INCREMENT,
    comentario   TEXT NOT NULL,
    email        VARCHAR(255) DEFAULT NULL,
    nombre       VARCHAR(100) DEFAULT NULL,
    tipo_negocio VARCHAR(100) DEFAULT NULL,
    calificacion TINYINT DEFAULT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    pais         VARCHAR(100) DEFAULT 'Desconocido',
    ciudad       VARCHAR(100) DEFAULT 'Desconocida',
    fecha_hora   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_fecha (fecha_hora DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
