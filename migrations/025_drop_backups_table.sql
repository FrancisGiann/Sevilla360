-- Retire backup metadata only. This does not remove physical SQL backup files.
DROP TABLE IF EXISTS backups;
