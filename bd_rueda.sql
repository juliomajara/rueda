CREATE DATABASE IF NOT EXISTS rueda
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE rueda;

CREATE TABLE profesores (
  id_profesor INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  horas INT NOT NULL CHECK (horas >= 0),
  especialidad ENUM('Informática', 'SAI') NOT NULL
);

CREATE TABLE modulos (
  id_modulo INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  abreviatura VARCHAR(20) NOT NULL,
  horas INT NOT NULL CHECK (horas > 0),
  curso ENUM('1º', '2º') NOT NULL,
  ciclo ENUM('SMR', 'DAW', 'DAM', 'ASIR') NOT NULL,
  atribucion ENUM('Informática', 'SAI', 'Ambos') NOT NULL
);

CREATE TABLE asignaciones (
  id_asignacion INT AUTO_INCREMENT PRIMARY KEY,
  conjunto_asignaciones INT UNSIGNED NOT NULL DEFAULT 1,
  id_profesor INT NOT NULL,
  id_modulo INT NOT NULL,
  UNIQUE KEY uk_conjunto_modulo (conjunto_asignaciones, id_modulo),
  FOREIGN KEY (id_profesor) REFERENCES profesores(id_profesor)
    ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY (id_modulo) REFERENCES modulos(id_modulo)
    ON DELETE CASCADE ON UPDATE CASCADE
);