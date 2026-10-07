-- Base dédiée aux tests automatisés : mêmes rôles et mêmes règles de RLS que la base de développement.
CREATE DATABASE nova_test OWNER nova_owner;

\connect nova_test

CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS citext;

GRANT CONNECT ON DATABASE nova_test TO nova_app, nova_platform;
GRANT USAGE ON SCHEMA public TO nova_app, nova_platform;

ALTER DEFAULT PRIVILEGES FOR ROLE nova_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO nova_app;
ALTER DEFAULT PRIVILEGES FOR ROLE nova_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO nova_app;
