<?php
/**
 * wp-phpunit configuration for the wp-env "tests" container.
 *
 * Loaded via WP_PHPUNIT__TESTS_CONFIG inside the test runner.
 *
 * @package PowerAgreement\Tests
 */

// Database — provided by the wp-env tests-mysql service.
$db_host = getenv('WORDPRESS_DB_HOST') ?: 'tests-mysql';
define('DB_NAME', getenv('WORDPRESS_DB_NAME') ?: 'tests-wordpress');
define('DB_USER', getenv('WORDPRESS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD') ?: 'password');
define('DB_HOST', $db_host);
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

// ABSPATH must point to a real WordPress install (the one mounted by wp-env).
define('ABSPATH', '/var/www/html/');

// Test runtime config.
define('WP_DEBUG', true);
define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Power Agreement Tests');
define('WP_PHP_BINARY', 'php');

// Authentication keys & salts (random for tests is fine).
define('AUTH_KEY',         'pa-test-auth-key');
define('SECURE_AUTH_KEY',  'pa-test-secure-auth-key');
define('LOGGED_IN_KEY',    'pa-test-logged-in-key');
define('NONCE_KEY',        'pa-test-nonce-key');
define('AUTH_SALT',        'pa-test-auth-salt');
define('SECURE_AUTH_SALT', 'pa-test-secure-auth-salt');
define('LOGGED_IN_SALT',   'pa-test-logged-in-salt');
define('NONCE_SALT',       'pa-test-nonce-salt');
