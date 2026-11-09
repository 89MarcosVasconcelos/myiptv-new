-- Links de stream com mais de 1000 caracteres (ex.: URLs do Amagi/Rakuten com
-- dezenas de parâmetros ads_*) derrubavam a importação inteira com
-- "Data too long for column 'url'" (erro real de 2026-11-09, lista vicentetv).
-- Sobe o limite pra 4000. Os índices continuam em url(255) (prefixo), então não
-- mudam. Idempotente: rodar de novo não altera nada.
ALTER TABLE streams MODIFY COLUMN url VARCHAR(4000) NOT NULL;
ALTER TABLE vod_streams MODIFY COLUMN url VARCHAR(4000) NOT NULL;
ALTER TABLE vod_items MODIFY COLUMN stream_url VARCHAR(4000) NULL;
