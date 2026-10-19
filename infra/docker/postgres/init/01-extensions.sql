-- Executed once on first cluster init (docker-entrypoint-initdb.d)
CREATE EXTENSION IF NOT EXISTS "pgcrypto";   -- gen_random_uuid()
CREATE EXTENSION IF NOT EXISTS "pg_trgm";    -- trigram search on opportunity names / teasers
CREATE EXTENSION IF NOT EXISTS "unaccent";
