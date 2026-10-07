-- Initialisation de la base de développement (exécutée une seule fois à la création du volume).
-- Principe (docs/01-schema-base-de-donnees.md §0) : l'application se connecte avec un rôle NON propriétaire,
-- sans BYPASSRLS, afin que la Row-Level Security soit réellement appliquée.

CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS citext;

-- Rôle applicatif client : soumis à la RLS.
CREATE ROLE nova_app LOGIN PASSWORD 'nova_app_dev_only' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

-- Rôle Super Admin : distinct, pour pouvoir le restreindre et le journaliser séparément.
CREATE ROLE nova_platform LOGIN PASSWORD 'nova_platform_dev_only' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

GRANT CONNECT ON DATABASE nova TO nova_app, nova_platform;
GRANT USAGE ON SCHEMA public TO nova_app, nova_platform;

-- Les droits sur les tables sont accordés par les migrations (le propriétaire nova_owner exécute les migrations).
ALTER DEFAULT PRIVILEGES FOR ROLE nova_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO nova_app;
ALTER DEFAULT PRIVILEGES FOR ROLE nova_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO nova_app;
