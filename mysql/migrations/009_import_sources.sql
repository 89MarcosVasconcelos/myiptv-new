-- Fontes de canais gerenciadas na página "Importar fontes" (só admin).
CREATE TABLE IF NOT EXISTS import_sources (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(200) NOT NULL,
  url VARCHAR(500) NOT NULL,
  origin VARCHAR(20) NOT NULL DEFAULT 'custom',
  status VARCHAR(20) NOT NULL DEFAULT 'approved',
  last_sync_at DATETIME NULL,
  last_status VARCHAR(20) NULL,
  last_summary TEXT NULL,
  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_import_sources_url (url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
