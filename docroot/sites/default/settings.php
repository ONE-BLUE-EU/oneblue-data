<?php

// phpcs:ignoreFile

/**
 * @file
 * Drupal settings, driven entirely by environment variables (see .env).
 *
 * This file contains no secrets and is committed. Machine-specific overrides
 * go in settings.local.php, which is git-ignored.
 */

$env = static function (string $name, ?string $default = NULL): ?string {
  $value = getenv($name);
  return ($value === FALSE || $value === '') ? $default : $value;
};

$app_env = $env('APP_ENV', 'prod');

$databases['default']['default'] = [
  'driver' => 'mysql',
  'host' => $env('DB_HOST', 'db'),
  'port' => $env('DB_PORT', '3306'),
  'database' => $env('DB_NAME', 'drupal'),
  'username' => $env('DB_USER', 'drupal'),
  'password' => $env('DB_PASSWORD', ''),
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
  'isolation_level' => 'READ COMMITTED',
];

$settings['hash_salt'] = $env('HASH_SALT', '');

// Comma-separated regexes, e.g. "^data\.one-blue\.eu$,^203\.0\.113\.10$".
$trusted_hosts = array_filter(array_map('trim', explode(',', $env('TRUSTED_HOSTS', ''))));
if ($trusted_hosts) {
  $settings['trusted_host_patterns'] = array_values($trusted_hosts);
}
elseif ($app_env === 'dev') {
  $settings['trusted_host_patterns'] = ['.*'];
}

// Paths are identical in dev and prod (see compose.*.yaml).
$settings['config_sync_directory'] = '../config/sync';
$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = '../private';
$settings['file_temp_path'] = '/tmp';

// In prod, Apache only receives traffic from Caddy on the private compose
// network, so the immediate peer is always the proxy.
if ($app_env === 'prod' && isset($_SERVER['REMOTE_ADDR'])) {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = [$_SERVER['REMOTE_ADDR']];
}

// The "cron" container runs drush cron; don't also run it on page requests.
$config['automated_cron.settings']['interval'] = 0;

$settings['update_free_access'] = FALSE;
$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
$settings['file_scan_ignore_directories'] = ['node_modules', 'bower_components'];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['state_cache'] = TRUE;
$settings['migrate_node_migrate_type_classic'] = FALSE;

if ($app_env === 'dev') {
  $config['system.logging']['error_level'] = 'verbose';
  $settings['skip_permissions_hardening'] = TRUE;
}

if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}
