-- Papel usado pela aplicação em runtime.
--
-- Deliberadamente NÃO é superuser e NÃO tem BYPASSRLS: o Postgres ignora
-- políticas de Row Level Security para papéis com esses atributos, o que
-- desativaria silenciosamente a terceira camada de isolamento.
--
-- O papel de migração (POSTGRES_USER, dono das tabelas) continua superuser,
-- pois precisa de DDL. A separação é intencional: quem cria o schema não é
-- quem serve requests.
CREATE ROLE tomenu_app WITH LOGIN PASSWORD 'secret' NOBYPASSRLS;

GRANT CONNECT ON DATABASE tomenu TO tomenu_app;

\connect tomenu

GRANT USAGE ON SCHEMA public TO tomenu_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO tomenu_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO tomenu_app;

-- Tabelas criadas depois deste ponto (migrations) herdam as mesmas permissões.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO tomenu_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO tomenu_app;
