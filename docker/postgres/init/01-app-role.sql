-- Papel usado pela aplicação — em runtime E nas migrations.
--
-- Deliberadamente NÃO é superuser e NÃO tem BYPASSRLS: o Postgres ignora
-- políticas de Row Level Security para papéis com esses atributos, o que
-- desativaria silenciosamente a terceira camada de isolamento.
--
-- Não existe papel de migração separado: o `DB_USERNAME` do .env.prod é este
-- mesmo, então `artisan migrate` roda como tomenu_app. Isso é o que a migration
-- 000400 (RLS) exige — `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` e
-- `CREATE POLICY` pedem POSSE da tabela, não superuser. O `FORCE ROW LEVEL
-- SECURITY` da própria migration impede que a dona escape das policies.
CREATE ROLE tomenu_app WITH LOGIN PASSWORD 'secret' NOBYPASSRLS;

GRANT CONNECT ON DATABASE tomenu TO tomenu_app;

\connect tomenu

-- Desde o PG15 o schema public não concede CREATE a todos, e seu dono é o dono
-- do banco. Sem transferir a posse, `artisan migrate` falha logo na primeira
-- tabela com `permission denied for schema public`.
ALTER SCHEMA public OWNER TO tomenu_app;
GRANT ALL ON SCHEMA public TO tomenu_app;
GRANT USAGE ON SCHEMA public TO tomenu_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO tomenu_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO tomenu_app;

-- Tabelas criadas depois deste ponto (migrations) herdam as mesmas permissões.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO tomenu_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO tomenu_app;
