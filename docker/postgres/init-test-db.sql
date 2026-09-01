-- Runs once, on first initialisation of the postgres data volume.
--
-- The test suite uses a SEPARATE database so that running it never destroys
-- development data: RefreshDatabase drops and re-migrates every table it can
-- see, which against the dev database would be a very unpleasant surprise.
SELECT 'CREATE DATABASE label_extractor_test'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'label_extractor_test')\gexec

GRANT ALL PRIVILEGES ON DATABASE label_extractor_test TO label_extractor;
