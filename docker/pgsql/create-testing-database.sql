SELECT 'CREATE DATABASE testing'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'testing')\gexec

ALTER DATABASE testing SET idle_in_transaction_session_timeout = '60s';

CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
