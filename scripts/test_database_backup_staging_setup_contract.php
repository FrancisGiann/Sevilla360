<?php
declare(strict_types=1);

require_once __DIR__ . '/database_backup_setup_staging.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
    $assertions++;
};
$throws = static function (callable $callback, string $message) use ($assert): void {
    try { $callback(); }
    catch (Throwable $error) { $assert(true, $message); return; }
    $assert(false, $message);
};

$options = database_backup_setup_staging_parse_options(['setup.php', '--apply']);
$assert($options['mode'] === 'apply', 'apply mode is parsed explicitly.');
$assert(database_backup_setup_staging_parse_options(['setup.php'])['mode'] === 'check', 'read-only check is the default.');
$adminOptions = database_backup_setup_staging_parse_options(['setup.php', '--apply', '--admin-user=local_dba']);
$assert($adminOptions['admin_user'] === 'local_dba', 'a local database administrator can be selected for a hidden terminal password prompt.');
$throws(static fn() => database_backup_setup_staging_parse_options(['setup.php', '--check', '--apply']), 'conflicting setup modes are rejected.');
$throws(static fn() => database_backup_setup_staging_parse_options(['setup.php', '--apply;touch']), 'unknown or injected options are rejected.');
$throws(static fn() => database_backup_setup_staging_parse_options(['setup.php', '--apply', '--admin-user=bad;name']), 'database administrator option injection is rejected.');
$throws(static fn() => database_backup_setup_staging_parse_options(['setup.php', '--admin-user=local_dba']), 'a database administrator prompt is restricted to apply mode.');

$assert(database_backup_setup_staging_host_allowed('localhost'), 'localhost is a supported staging host.');
$assert(database_backup_setup_staging_host_allowed('127.0.0.1'), 'IPv4 loopback is a supported staging host.');
$assert(!database_backup_setup_staging_host_allowed('192.0.2.4'), 'remote database hosts cannot receive a newly provisioned staging database.');
$assert(!database_backup_setup_staging_host_allowed('::1'), 'IPv6 is rejected until the host matching policy is explicitly configured.');
$assert(!database_backup_setup_staging_line_has_override('# Environment=DB_STAGING_HOST=localhost', ['DB_STAGING_HOST']), 'commented service environment lines do not override dotenv settings.');
$assert(!database_backup_setup_staging_line_has_override('; SetEnv DB_STAGING_PASS hidden', ['DB_STAGING_PASS']), 'semicolon comments do not override dotenv settings.');
$assert(database_backup_setup_staging_line_has_override('env[DB_STAGING_USER] = backup', ['DB_STAGING_USER']), 'active PHP-FPM pool staging overrides are detected.');
$unitFixture = tempnam(sys_get_temp_dir(), 's360-staging-unit-');
if ($unitFixture === false) throw new RuntimeException('Unable to create an isolated service config fixture.');
try {
    file_put_contents($unitFixture, "# EnvironmentFile=/tmp/ignored.env\nEnvironmentFile=-/etc/sysconfig/php-fpm\n");
    $assert(database_backup_setup_staging_environment_files([$unitFixture]) === ['/etc/sysconfig/php-fpm'], 'active systemd environment files are discovered while commented paths are ignored.');
} finally {
    @unlink($unitFixture);
}
$assert(!database_backup_setup_staging_line_has_override('EnvironmentFile=/etc/sysconfig/php-fpm', ['DB_STAGING_USER']), 'an environment-file directive is not mistaken for a direct staging key override.');
$assert(database_backup_setup_staging_quote_identifier('s360stage123abc') === '`s360stage123abc`', 'generated schema names are quoted as identifiers.');
$throws(static fn() => database_backup_setup_staging_quote_identifier('s360_stage_123abc'), 'wildcard-sensitive underscores are rejected in generated database names.');
$throws(static fn() => database_backup_setup_staging_quote_identifier('stage` unsafe'), 'unsafe database identifiers are rejected.');
$assert(database_backup_setup_staging_database_name('0123456789abcdef') === 's360stage0123456789abcdef', 'generated staging database names use a letters-and-digits-only pattern safe for MariaDB GRANT scope.');
$throws(static fn() => database_backup_setup_staging_database_name('0123_bad'), 'generated database suffixes reject wildcard characters.');

$assert(database_backup_setup_staging_global_authority(["GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION"]), 'global all privileges with grant option can provision and roll back a dedicated staging schema.');
$assert(database_backup_setup_staging_global_authority(["GRANT CREATE, DROP, CREATE USER ON *.* TO 'root'@'localhost' WITH GRANT OPTION"]), 'the exact global privileges required for safe provisioning are accepted.');
$assert(!database_backup_setup_staging_global_authority(["GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost'"]), 'global DDL without grant option is insufficient for scoped account provisioning.');
$assert(!database_backup_setup_staging_global_authority(["GRANT CREATE, DROP ON *.* TO 'operator'@'localhost' WITH GRANT OPTION"]), 'database creation without CREATE USER authority is rejected.');
$assert(!database_backup_setup_staging_global_authority(["GRANT ALL PRIVILEGES ON `production`.* TO 'operator'@'localhost' WITH GRANT OPTION"]), 'schema-scoped production grants do not count as global provisioning authority.');

$original = "APP_KEY=keep-this-fixture\nBACKUP_DIR=/var/lib/sevilla360/sevilla360-database-backups\nDB_NAME=production_fixture\n# unrelated comment\n";
$settings = [
    'DB_STAGING_HOST' => 'localhost',
    'DB_STAGING_USER' => 's360_stage_123abc',
    'DB_STAGING_PASS' => str_repeat('f', 64),
    'DB_STAGING_NAME' => 's360stage123abc',
    'DB_STAGING_PORT' => '',
];
$updated = database_backup_setup_staging_env_text($original, $settings);
$assert(str_starts_with($updated, $original), 'staging setup appends configuration while preserving all existing lines byte-for-byte.');
$assert(database_backup_setup_env_value($updated, 'DB_STAGING_NAME') === 's360stage123abc', 'the dedicated staging schema is present in dotenv output.');
$assert(database_backup_setup_env_value($updated, 'DB_STAGING_PASS') === str_repeat('f', 64), 'generated staging password survives safe dotenv serialization.');
$assert(database_backup_setup_env_value($updated, 'APP_KEY') === 'keep-this-fixture' && database_backup_setup_env_value($updated, 'BACKUP_DIR') === '/var/lib/sevilla360/sevilla360-database-backups', 'the signing key and configured backup storage remain unchanged.');
$throws(static fn() => database_backup_setup_staging_env_text($updated, ['APP_KEY' => 'replacement']), 'the helper cannot overwrite non-staging environment keys.');
$throws(static fn() => database_backup_setup_staging_env_text($original, ['DB_STAGING_PASS' => "secret\nAPP_KEY=changed"]), 'multiline staging credentials are rejected.');
$throws(static fn() => database_backup_setup_env_value("DB_STAGING_USER=one\nDB_STAGING_USER=two\n", 'DB_STAGING_USER'), 'duplicate existing staging settings are rejected.');

echo "Database backup staging setup contract checks passed ({$assertions} assertions).\n";
