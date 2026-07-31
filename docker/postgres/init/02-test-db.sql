-- Banco dedicado à suíte de testes.
--
-- Separado do banco de desenvolvimento porque o RefreshDatabase derruba e
-- recria todas as tabelas a cada rodada: apontar os testes para `tomenu`
-- apagaria os dados locais toda vez que alguém rodasse `artisan test`.
--
-- OWNER tomenu_app é o ponto crítico. As migrations de RLS exigem POSSE das
-- tabelas para `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` e `CREATE POLICY`,
-- e o papel precisa ser não-superuser para que as policies sejam de fato
-- avaliadas — o Postgres as ignora para superusers. Com o dono errado a suíte
-- fica verde sem exercitar a terceira camada de isolamento, que é justamente
-- a que protege os dados se as camadas de aplicação falharem.
CREATE DATABASE tomenu_test OWNER tomenu_app;

GRANT CONNECT ON DATABASE tomenu_test TO tomenu_app;

\connect tomenu_test

-- Mesmo tratamento do banco principal: desde o PG15 o schema public não
-- concede CREATE a todos, e `artisan migrate` falharia na primeira tabela.
ALTER SCHEMA public OWNER TO tomenu_app;
GRANT ALL ON SCHEMA public TO tomenu_app;
GRANT USAGE ON SCHEMA public TO tomenu_app;

ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO tomenu_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO tomenu_app;
