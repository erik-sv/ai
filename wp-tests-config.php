<?php
/**
 * WordPress test configuration for local development.
 */

define( 'DB_NAME', 'wordpress_tests' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );

$table_prefix = 'wptests_';

define( 'WP_DEBUG', true );

define( 'ABSPATH', '/tmp/wordpress/' );
