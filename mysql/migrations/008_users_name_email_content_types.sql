-- Cadastro de usuários feito só pelo admin: nome, e-mail e tipos de conteúdo
-- liberados (live, movie, series, anime; NULL = tudo liberado).
ALTER TABLE admin_users ADD COLUMN name VARCHAR(150) NULL;
ALTER TABLE admin_users ADD COLUMN email VARCHAR(255) NULL;
ALTER TABLE admin_users ADD UNIQUE KEY uq_admin_users_email (email);
ALTER TABLE access_tokens ADD COLUMN content_types VARCHAR(100) NULL;
