SELECT 'CREATE DATABASE ziarah_amg_testing'
WHERE NOT EXISTS (
    SELECT FROM pg_database WHERE datname = 'ziarah_amg_testing'
)\gexec

