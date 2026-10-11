<?php

const DBG_LOG_FILE = '/var/log/debugpanel.log';
const DBG_LOG_ENABLED = false;

$DBG_PROJECT_BASE = '';
$DBG_SERVER_BASE = '/var/www/html';

$DBG_HOST = '127.0.0.1';
$DBG_PORT = 9003;
$HTTP_HOST = '127.0.0.1';
$HTTP_PORT = 8088;

$DBG_SITES = [
	'site1.loc' => [
		// Used to map relative breakpoint paths from the browser.
		'server_base' => '/var/www/html',
		// Optional prefix to remove from browser-submitted file paths.
		'project_base' => '',
		// Paths of the PHP entry scripts which start Xdebug sessions.
		// The DBGp init fileuri must be within one of these directories.
		'match_roots' => [
			'/var/www/html',
		],
		// Paths where this site's breakpoints are permitted.
		'code_roots' => [
			'/var/www/html',
		],
	],
	'site2.loc' => [
		'server_base' => '/var/www/html1',
		'project_base' => '',
		'match_roots' => [
			'/var/www/html1',
		],
		'code_roots' => [
			'/var/www/html1',
		],
	],
];

// Set to true temporarily if you need to troubleshoot fileuri routing.
define('DBG_LOG_ENABLED', false);
define('DBG_LOG_FILE', '/var/log/php-web-debugger.log');

// Optional tuning. Lazy variable expansion from v3 is retained.
define('DBG_MAX_CHILDREN', 32);
define('DBG_MAX_DATA', 4096);
define('DBG_PROPERTY_MAX_DATA', 4 * 1024 * 1024);
define('DBG_MAX_DBGP_PACKET_BYTES', 32 * 1024 * 1024);
define('DBG_MAX_HTTP_JSON_BYTES', 32 * 1024 * 1024);
