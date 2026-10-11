<?php

/**
 * @var $DBG_HOST
 *   Server IP for xdebug.
 * @var $DBG_PORT
 *   Server port for xdebug.
 * @var $HTTP_HOST
 *   Site IP for service.
 * @var $HTTP_PORT
 *   Site port for service.
 */

error_reporting(E_ALL);
set_time_limit(0);

require_once('config.php');

if (!function_exists('simplexml_load_string')) {
	fwrite(STDERR, "PHP extension simplexml is required for DBGp XML packets\n");
	exit(1);
}

function dlog($msg, array $ctx = []) {
	if (!defined('DBG_LOG_ENABLED') || !DBG_LOG_ENABLED) {
		return;
	}
	$suffix = $ctx ? ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
	$line = '[' . date('Y-m-d H:i:s') . '][' . getmypid() . '] ' . $msg . $suffix . "\n";
	if (defined('DBG_LOG_FILE') && DBG_LOG_FILE !== '') {
		@file_put_contents(DBG_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
	} else {
		@error_log(trim($line));
	}
}

/**
 * Encoding the DBGp argument value (URL-style).
 */
function dbgp_arg($v): string {
	$enc = rawurlencode($v);
	return strtr($enc, [
		'%2F' => '/',
		'%3A' => ':',
		'%24' => '$',
		'%5B' => '[',
		'%5D' => ']',
		'%27' => "'",
		'%22' => '"',
		'%5F' => '_',
		'%2D' => '-',
		'%2E' => '.',
		'%2C' => ','
	]);
}

function path_to_file_uri($path) {
	if (strpos($path, 'file://') === 0) {
		return $path;
	}
	if (preg_match('#^[A-Za-z]:\\\\#', $path)) {
		$path = str_replace('\\', '/', $path);
		return 'file:///' . $path;
	}
	if ($path !== '' && $path[0] === '/') {
		return 'file://' . $path;
	}
	return $path;
}

function file_uri_to_path($uri) {
	if (!$uri) {
		return '';
	}
	if (strpos($uri, 'file://') !== 0) {
		return $uri;
	}
	$p = substr($uri, 7);
	if (preg_match('#^/[A-Za-z]:/#', $p)) {
		$p = substr($p, 1);
	}
	return str_replace("\\", "/", $p);
}

function normalize_slashes($p) {
	return preg_replace('~\\\\+~', '/', (string)$p);
}

function parse_file_ref_and_map($raw, $q_line = 0): array {
	global $DBG_PROJECT_BASE, $DBG_SERVER_BASE;

	$s = trim((string)$raw);
	$line = (int)$q_line;

	if (strpos($s, 'file://') === 0) {
		$path = file_uri_to_path($s);
		if (preg_match('/^(.*?):(\d+)\s*$/', $path, $m)) {
			$path = $m[1];
			$line = (int)$m[2];
		}
		return [
			'file' => $path,
			'uri' => path_to_file_uri($path),
			'line' => $line
		];
	}

	if (preg_match('/^(.*?):(\d+)\s*$/', $s, $m)) {
		$s = $m[1];
		$line = (int)$m[2];
	}

	$path = normalize_slashes($s);

	if ($path !== '' && $path[0] === '/') {
		return [
			'file' => $path,
			'uri' => path_to_file_uri($path),
			'line' => $line
		];
	}

	if (preg_match('#^[A-Za-z]:/#', $path)) {
		return [
			'file' => $path,
			'uri' => path_to_file_uri($path),
			'line' => $line
		];
	}

	$rel = $path;

	if ($DBG_PROJECT_BASE !== '') {
		$pb = rtrim(normalize_slashes($DBG_PROJECT_BASE), '/');
		if (strpos($rel, $pb . '/') === 0) {
			$rel = substr($rel, strlen($pb) + 1);
		} elseif ($rel === $pb) {
			$rel = '';
		}
	}

	if ($DBG_SERVER_BASE !== '') {
		$sb = rtrim(normalize_slashes($DBG_SERVER_BASE), '/');
		$path = $rel !== '' ? ($sb . '/' . ltrim($rel, '/')) : $sb;
	} else {
		$path = $rel;
	}

	return ['file' => $path, 'uri' => path_to_file_uri($path), 'line' => $line];
}

function dbg_html_escape($s): string {
	return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Read only a small source window, never the whole source file into memory.
 */
function read_source_context($file_or_uri, $line, $above = 10, $below = 10): array {
	$path = file_uri_to_path($file_or_uri);
	$line = max(1, (int)$line);
	$above = min(20, max(0, (int)$above));
	$below = min(20, max(0, (int)$below));
	$failure = [
		'ok' => false,
		'file' => $path,
		'line' => $line,
		'start' => 0,
		'end' => 0,
		'lines' => [],
		'error' => 'file not readable'
	];
	if (!$path || !is_readable($path) || !is_file($path)) {
		return $failure;
	}
	$fh = @fopen($path, 'rb');
	if (!$fh) {
		return $failure;
	}
	$start = max(1, $line - $above);
	$end = $line + $below;
	$out = [];
	$i = 0;
	// Cap source scanning to prevent a malformed URI/line from monopolizing the event loop.
	$maxScan = cfg_limit('DBG_MAX_SOURCE_SCAN_LINES', 20000);
	$maxBytes = cfg_limit('DBG_MAX_SOURCE_SCAN_BYTES', 4 * 1024 * 1024);
	$bytesRead = 0;
	while (!feof($fh) && $i < $end && $i < $maxScan && $bytesRead < $maxBytes) {
		$raw = '';
		$got = false;
		do {
			$chunk = fgets($fh, 8192);
			if ($chunk === false) {
				break;
			}
			$bytesRead += strlen($chunk);
			$got = true;
			if (strlen($raw) < 4096) {
				$raw .= substr($chunk, 0, 4096 - strlen($raw));
			}
		} while (strpos($chunk, "\n") === false && !feof($fh) && $bytesRead < $maxBytes);
		if (!$got) {
			break;
		}
		$i++;
		if ($i >= $start) {
			$out[] = [
				'no' => $i,
				'cur' => ($i === $line),
				'text' => dbg_html_escape(rtrim($raw, "\r\n")),
			];
		}
	}
	fclose($fh);
	if ($i < $line) {
		return [
			'ok' => false,
			'file' => $path,
			'line' => $line,
			'start' => 0,
			'end' => 0,
			'lines' => [],
			'error' => ($i >= $maxScan || $bytesRead >= $maxBytes)
				? 'source scan limit exceeded' : 'line beyond end of file'
		];
	}
	return [
		'ok' => true,
		'file' => $path,
		'line' => $line,
		'start' => $out ? $out[0]['no'] : 0,
		'end' => $out ? $out[count($out) - 1]['no'] : 0,
		'lines' => $out
	];
}

/**
 * Preserve the actual DBGp scalar value, including leading/trailing whitespace.
 */
function prop_scalar_or_summary(SimpleXMLElement $p): string {
	$node = isset($p->value) ? $p->value : $p;
	$raw = (string)$node;
	$encoding = strtolower((string)($node['encoding'] ?? $p['encoding'] ?? ''));
	if ($encoding === 'base64') {
		$decoded = base64_decode($raw, true);
		return $decoded !== false ? $decoded : '';
	}
	return $raw;
}

function prop_fullname(SimpleXMLElement $p): string {
	if (isset($p['fullname']) && (string)$p['fullname'] !== '') {
		return (string)$p['fullname'];
	}

	if (isset($p->fullname)) {
		$f = $p->fullname;
		$val = (string)$f;
		if ($val !== '') {
			$enc = isset($f['encoding']) ? strtolower((string)$f['encoding']) : '';
			if ($enc === 'base64') {
				$decoded = base64_decode($val, true);
				if ($decoded !== false && $decoded !== '') {
					return $decoded;
				}
			} else {
				return $val;
			}
		}
	}

	if (isset($p['name']) && (string)$p['name'] !== '') {
		return (string)$p['name'];
	}
	if (isset($p->name)) {
		$n = $p->name;
		$val = (string)$n;
		if ($val !== '') {
			$enc = isset($n['encoding']) ? strtolower((string)$n['encoding']) : '';
			if ($enc === 'base64') {
				$decoded = base64_decode($val, true);
				if ($decoded !== false && $decoded !== '') {
					return $decoded;
				}
			} else {
				return $val;
			}
		}
	}

	return '';
}

function prop_name(SimpleXMLElement $p): string {
	if (isset($p['name']) && (string)$p['name'] !== '') {
		return (string)$p['name'];
	}

	if (isset($p->name)) {
		$n = $p->name;
		$val = (string)$n;

		if ($val !== '') {
			$enc = isset($n['encoding']) ? strtolower((string)$n['encoding']) : '';
			if ($enc === 'base64') {
				$decoded = base64_decode($val, true);
				if ($decoded !== false && $decoded !== '') {
					return $decoded;
				}
			} else {
				return $val;
			}
		}
	}

	if (isset($p['key']) && (string)$p['key'] !== '') {
		return (string)$p['key'];
	}

	$full = null;
	if (isset($p['fullname']) && (string)$p['fullname'] !== '') {
		$full = (string)$p['fullname'];
	} elseif (isset($p->fullname)) {
		$f = $p->fullname;
		$val = (string)$f;
		if ($val !== '') {
			$enc = isset($f['encoding']) ? strtolower((string)$f['encoding']) : '';
			if ($enc === 'base64') {
				$decoded = base64_decode($val, true);
				if ($decoded !== false && $decoded !== '') {
					$full = $decoded;
				}
			} else {
				$full = $val;
			}
		}
	}

	if ($full !== null) {
		if (preg_match('/\[(["\'])(.*?)\1]$/u', $full, $m)) {
			return $m[2];
		}
	}

	return '';
}

/**
 * Only the *initial payload* is small; no variables are discarded.
 * Large values and later array/object pages are fetched when opened in the UI.
 */
function cfg_limit($name, $default) {
	return defined($name) ? max(1, (int)constant($name)) : $default;
}

/**
 * Convert a DBGp <property> into a browsable node with its full DBGp address.
 */
function property_tree(SimpleXMLElement $p, int $context, int $generation, int $depth = 0, bool $fullValue = false, int $requestedPage = 0) {
	$type = (string)($p['type'] ?? '');
	$fullname = prop_fullname($p);
	$hasChildren = (int)($p['children'] ?? 0) === 1;
	$base = [
		'__type' => $type ?: 'unknown',
		'__fullname' => $fullname,
		'__context' => $context,
		'__generation' => $generation,
	];
	if (!$hasChildren) {
		$value = prop_scalar_or_summary($p);
		$size = max(0, (int)($p['size'] ?? strlen($value)));
		// Retain scalar compatibility: small values remain plain strings.
		if (!$fullValue && $size <= 4096 && strlen($value) <= 4096) {
			return $value;
		}
		$partial = $type === 'string' && $size > strlen($value);
		$base['__size'] = $size;
		$base['__summary'] = ($type ?: 'value') . ($type === 'string' ? '(' . $size . ')' : '');
		$base['__value'] = $fullValue ? $value : substr($value, 0, 512);
		$base['__truncated'] = $partial || (!$fullValue && strlen($value) > 512);
		$base['__lazy_value'] = !$fullValue && $fullname !== '';
		return $base;
	}

	$total = max(0, (int)($p['numchildren'] ?? 0));
	$page = max(0, (int)($p['page'] ?? $requestedPage));
	$pageSize = max(1, (int)($p['pagesize'] ?? cfg_limit('DBG_MAX_CHILDREN', 32)));
	$base['__summary'] = ($type ?: 'array') . '(' . $total . ')';
	$base['__numchildren'] = $total;
	$base['__pagesize'] = $pageSize;
	$base['__children'] = [];
	$loaded = 0;
	// context_get has only one level of children. A property_get response can
	// contain another level; all deeper nodes remain individually expandable.
	if ($depth < 1 && isset($p->property)) {
		foreach ($p->property as $child) {
			$name = prop_name($child);
			if ($name === '') {
				$name = '#' . ($loaded + 1 + $page * $pageSize);
			}
			// Avoid overwriting different properties with identical short names.
			$key = $name;
			$suffix = 2;
			while (array_key_exists($key, $base['__children'])) {
				$key = $name . ' #' . $suffix++;
			}
			$base['__children'][$key] = property_tree($child, $context, $generation, $depth + 1);
			$loaded++;
		}
	}
	// Not loading children here never makes them inaccessible: page 0 is
	// retrieved with property_get when the user expands the node.
	$base['__next_page'] = $total > 0 && ($loaded === 0 || ($page + 1) * $pageSize < $total)
		? ($loaded === 0 ? $page : $page + 1) : null;
	return $base;
}

function context_tree($xml, int $context, int $generation): array {
	$result = [];
	if ($xml === false || $xml === null || !isset($xml->property)) {
		return $result;
	}
	foreach ($xml->property as $prop) {
		$name = prop_name($prop);
		if ($name === '') {
			continue;
		}
		// Absolutely no global budget and no hard-coded $_SERVER/$_ENV/$GLOBALS skips.
		$result[$name] = property_tree($prop, $context, $generation);
	}
	return $result;
}

function filtered_snapshot(array $snap, array $skip): array {
	if (!$skip) {
		return $snap;
	}
	$skipSet = [];
	foreach (array_slice($skip, 0, 80) as $name) {
		$name = trim((string)$name);
		if ($name !== '') {
			$skipSet[$name] = true;
			$skipSet[ltrim($name, '$')] = true;
			$skipSet['$' . ltrim($name, '$')] = true;
		}
	}
	foreach (['locals', 'superglobals'] as $ctx) {
		foreach ($snap[$ctx] ?? [] as $key => $value) {
			if (!isset($skipSet[$key])) {
				continue;
			}
			if (is_array($value) && isset($value['__type'])) {
				$snap[$ctx][$key] = [
					'__type' => $value['__type'],
					'__summary' => $value['__summary'] ?? $value['__type'] . '(skipped)'
				];
			} else {
				$snap[$ctx][$key] = [
					'__type' => 'skipped',
					'__summary' => 'skipped'
				];
			}
		}
	}
	return $snap;
}

function log_disconnect($msg) {
	dlog('DBGp disconnected', ['reason' => $msg]);
}

function http_close($id) {
	global $httpClients, $stackWaitersBySite, $propertyWaitersBySite;

	if (!isset($httpClients[$id])) {
		return;
	}

	$site = $httpClients[$id]['site'];
	@fclose($httpClients[$id]['sock']);
	unset($httpClients[$id]);

	if ($site !== null) {
		unset($stackWaitersBySite[$site][$id]);
		unset($propertyWaitersBySite[$site][$id]);
	}
}

function http_response($id, $code, $body, $mime = 'application/json; charset=utf-8', $cache = false) {
	global $httpClients;
	if (!isset($httpClients[$id])) {
		return;
	}
	$reasons = [
		200 => 'OK',
		400 => 'Bad Request',
		421 => 'Misdirected Request',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		409 => 'Conflict',
		413 => 'Payload Too Large',
		429 => 'Too Many Requests',
		403 => 'Forbidden',
		500 => 'Internal Server Error',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Timeout'
	];
	$reason = $reasons[$code] ?? 'Error';
	$header = "HTTP/1.1 $code $reason\r\nContent-Type: $mime\r\n"
		. 'Content-Length: ' . strlen($body) . "\r\n"
		. 'Cache-Control: ' . ($cache ? 'public, max-age=300' : 'no-store') . "\r\n"
		. "X-Content-Type-Options: nosniff\r\nConnection: close\r\n\r\n";
	$httpClients[$id]['out'] = $header . $body;
	$httpClients[$id]['waiting'] = false;
	$httpClients[$id]['in'] = '';
	$httpClients[$id]['deadline'] = microtime(true) + 5.0;
}

function http_json($id, $value, $status = 200) {
	$str = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
	if ($str === false) {
		$str = '{"error":"json encoding failed"}';
		$status = 500;
	}
	if (strlen($str) > cfg_limit('DBG_MAX_HTTP_JSON_BYTES', 33554432)) {
		$str = '{"error":"response too large; adjust DBG_MAX_HTTP_JSON_BYTES"}';
		$status = 413;
	}
	http_response($id, $status, $str);
}

function render_template() {
	static $body = null, $mtime = null;
	$file = __DIR__ . '/index.tpl.php';
	$newMtime = @filemtime($file);
	if ($body !== null && $mtime === $newMtime) {
		return $body;
	}
	if ($newMtime === false) {
		return null;
	}
	ob_start();
	try {
		require $file;
		$body = ob_get_clean();
	} catch (Throwable $e) {
		ob_end_clean();
		dlog('template failed', ['error' => $e->getMessage()]);
		return null;
	}
	$mtime = $newMtime;
	return $body;
}

function serve_static($id, $path): bool {
	static $cache = [], $cacheSize = 0;
	$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	$mime = [
		'css' => 'text/css; charset=utf-8',
		'js' => 'text/javascript; charset=utf-8',
		'svg' => 'image/svg+xml',
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'ico' => 'image/x-icon',
		'webp' => 'image/webp',
		'woff2' => 'font/woff2'
	];
	if (!isset($mime[$ext])) {
		return false;
	}
	$base = realpath(__DIR__);
	$file = realpath(__DIR__ . '/' . ltrim(rawurldecode($path), '/'));
	if (!$file || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
		return false;
	}
	$size = filesize($file);
	if ($size === false || $size > 2 * 1024 * 1024) {
		return false;
	}
	$mtime = @filemtime($file);
	$hit = $cache[$file] ?? null;
	if ($hit !== null && $hit['size'] === $size && $hit['mtime'] === $mtime) {
		$content = $hit['body'];
	} else {
		$content = @file_get_contents($file);
		if ($content === false) {
			return false;
		}
		if ($hit !== null) {
			$cacheSize -= strlen($hit['body']);
			unset($cache[$file]);
		}
		// Assets <= 512 KiB are cached up to 4 MiB, with simple FIFO eviction.
		if ($size <= 512 * 1024) {
			while ($cache && $cacheSize + strlen($content) > 4 * 1024 * 1024) {
				$first = key($cache);
				$cacheSize -= strlen($cache[$first]['body']);
				unset($cache[$first]);
			}
			$cache[$file] = [
				'size' => $size,
				'mtime' => $mtime,
				'body' => $content
			];
			$cacheSize += strlen($content);
		}
	}
	http_response($id, 200, $content, $mime[$ext], true);
	return true;
}

function dbg_disconnect($reason = 'closed') {
	global $state, $stackWaiters, $propertyWaiters;
	if ($state['dbg']) {
		@fclose($state['dbg']);
	}
	$state['dbg'] = null;
	$state['status'] = 'idle';
	$state['rx'] = '';
	$state['rx_expected'] = null;
	$state['tx'] = '';
	$state['commands'] = [];
	$state['inflight'] = null;
	$state['applied_bps'] = [];
	$state['collecting'] = false;
	$state['connected_at'] = null;
	// A disconnect is a normal state change for the polling /api/stack endpoint.
	// Never turn a pending poll into an HTTP error when the PHP request finishes.
	foreach ($stackWaiters as $id => $skip) {
		http_json($id, [
			'status' => 'idle',
			'last_snapshot' => $state['last_snapshot']
		]);
	}
	$stackWaiters = [];
	foreach ($propertyWaiters as $id => $_) {
		http_json($id, [
			'ok' => false,
			'error' => 'debugger disconnected'
		], 409);
	}
	$propertyWaiters = [];
	log_disconnect($reason);
}

function dbg_queue($command, array $args = [], $callback = null, $resume = false, $data = null): bool {
	global $state;
	if (!$state['dbg']) {
		return false;
	}
	if (count($state['commands']) >= 512) {
		return false;
	}
	$state['commands'][] = [
		'command' => $command,
		'args' => $args,
		'callback' => $callback,
		'resume' => $resume,
		'data' => $data
	];
	dbg_pump();
	return true;
}

function dbg_pump() {
	global $state;
	if (!$state['dbg'] || $state['inflight'] !== null || !$state['commands']) {
		return;
	}
	$item = array_shift($state['commands']);
	$state['txn']++;
	$txn = $state['txn'];
	$line = $item['command'] . ' -i ' . $txn;
	foreach ($item['args'] as $key => $val) {
		$enc = $item['command'] === 'property_get' && $key === 'n'
			? '"' . str_replace(["\\", '"', "\0"], [
				"\\\\",
				'\\"',
				"\\0"
			], (string)$val) . '"'
			: dbgp_arg($val);
		$line .= ' -' . $key . ' ' . $enc;
	}

	if ($item['data'] !== null) {
		$line .= ' -- ' . base64_encode($item['data']);
	}

	$state['tx'] .= $line . "\0";
	$state['inflight'] = $item + [
			'txn' => $txn,
			// Continuation replies occur only when the script stops; no command timeout.
			'deadline' => $item['resume'] ? null : microtime(true) + 3.0
		];
	if ($item['resume']) {
		$state['status'] = 'running';
	}
}

/**
 * Synchronize all desired breakpoints with Xdebug when it is paused.
 * A breakpoint is identified by file and line in the UI. Changing its
 * condition replaces the old DBGp breakpoint rather than duplicating it.
 */
function dbg_sync_bps() {
	global $state;
	// Caller must only sync in starting/break state. During run, changes are staged.
	if (!in_array($state['status'], ['starting', 'break'], true)) {
		return;
	}

	foreach ($state['applied_bps'] as $key => $applied) {
		$desired = $state['desired_bps'][$key] ?? null;
		$matches = $desired !== null &&
			$applied['condition'] === ($desired['condition'] ?? '');

		if ($matches) {
			continue;
		}

		// If the engine has not yet answered breakpoint_set, its callback
		// will compare conditions and discard the obsolete breakpoint.
		if ($applied['pending']) {
			continue;
		}

		unset($state['applied_bps'][$key]);
		if ($applied['id'] !== null) {
			dbg_queue('breakpoint_remove', ['d' => $applied['id']]);
		}
	}

	foreach ($state['desired_bps'] as $key => $bp) {
		if (array_key_exists($key, $state['applied_bps'])) {
			continue;
		}

		$condition = $bp['condition'] ?? '';
		$generation = $state['generation'];
		$state['applied_bps'][$key] = [
			'id' => null,
			'condition' => $condition,
			'pending' => true,
			'error' => null
		];

		$args = [
			't' => $condition === '' ? 'line' : 'conditional',
			'f' => $bp['uri'],
			'n' => $bp['line']
		];

		$enqueued = dbg_queue(
			'breakpoint_set',
			$args,
			function ($xml) use ($generation, $key, $condition) {
				global $state;

				if ($generation !== $state['generation']) {
					return;
				}

				$id = null;
				$error = null;

				if ($xml !== false && $xml !== null && !isset($xml->error) && isset($xml['id'])) {
					$id = (string)$xml['id'];
				} elseif ($xml !== false && $xml !== null && isset($xml->error)) {
					$error = (string)($xml->error->message ?? 'Xdebug rejected breakpoint');
				} else {
					$error = 'Xdebug did not return a breakpoint ID';
				}

				$desired = $state['desired_bps'][$key] ?? null;
				$stillWanted = $desired !== null &&
					($desired['condition'] ?? '') === $condition;

				if (!$stillWanted) {
					// An outdated set command may finish after an edit or removal.
					unset($state['applied_bps'][$key]);

					if ($id !== null && $id !== '') {
						dbg_queue('breakpoint_remove', ['d' => $id]);
					}

					dbg_sync_bps();
					return;
				}

				if (!isset($state['applied_bps'][$key])) {
					return;
				}

				$state['applied_bps'][$key]['pending'] = false;
				if ($id !== null && $id !== '') {
					$state['applied_bps'][$key]['id'] = $id;
				} else {
					$state['applied_bps'][$key]['error'] = $error;
					dlog('DBGp breakpoint rejected', [
						'site' => $key,
						'condition' => $condition,
						'error' => $error
					]);
				}
			},
			false,
			$condition === '' ? null : $condition
		);

		if (!$enqueued) {
			$state['applied_bps'][$key]['pending'] = false;
			$state['applied_bps'][$key]['error'] = 'DBGp command queue full';
		}
	}
}

function dbg_resume($command): bool {
	global $state, $stackWaiters, $propertyWaiters;
	if ($state['status'] !== 'break' && $state['status'] !== 'starting') {
		return false;
	}
	dbg_sync_bps();
	$state['collecting'] = false;
	$state['cached_generation'] = -1;
	foreach ($stackWaiters as $id => $skip) {
		http_json($id, [
			'status' => 'running',
			'last_snapshot' => $state['last_snapshot']
		]);
	}
	$stackWaiters = [];
	// An inspector reply from a previous pause is never returned after resume.
	foreach ($propertyWaiters as $id => $_) {
		http_json($id, ['ok' => false, 'error' => 'execution resumed'], 409);
	}
	$propertyWaiters = [];
	$state['commands'] = array_values(array_filter($state['commands'],
		static function ($item) {
			return $item['command'] !== 'property_get';
		}));
	$state['status'] = 'running';
	dbg_queue($command, [], null, true);
	return true;
}

function dbg_init_session($xml) {
	global $state;
	$state['init'] = (string)$xml->asXML();
	$state['status'] = 'starting';
	// Initial context is shallow; every node can be expanded on demand.
	$features = [
		['max_children', min(256, cfg_limit('DBG_MAX_CHILDREN', 32))],
		['max_data', min(65536, cfg_limit('DBG_MAX_DATA', 4096))],
		['max_depth', 1],
		['extended_properties', 1],
		['show_hidden', 1],
		['resolved_breakpoints', 1],
	];
	foreach ($features as $feature) {
		dbg_queue('feature_set', [
			'n' => $feature[0],
			'v' => $feature[1]
		]);
	}
	dbg_resume('run');
	dlog('DBGp attached', ['fileuri' => (string)($xml['fileuri'] ?? '')]);
}

function dbg_publish_snapshot(array $snapshot, $generation) {
	global $state, $stackWaiters;
	if ($state['generation'] !== $generation || $state['status'] !== 'break') {
		return;
	}
	$state['last_snapshot'] = $snapshot;
	$state['cached_generation'] = $generation;
	$state['collecting'] = false;
	foreach ($stackWaiters as $id => $skip) {
		http_json($id, filtered_snapshot($snapshot, $skip));
	}
	$stackWaiters = [];
}

function dbg_collect_snapshot() {
	global $state;
	if ($state['status'] !== 'break' || $state['collecting']) {
		return;
	}
	$state['collecting'] = true;
	$generation = $state['generation'];
	$capture = [
		'frames' => [],
		'locals' => [],
		'superglobals' => [],
		'source' => null
	];
	dbg_queue('stack_get', [], function ($xml) use (&$capture, $generation) {
		global $state;
		if ($state['generation'] !== $generation || $state['status'] !== 'break') {
			return;
		}
		if ($xml !== false && $xml !== null && isset($xml->stack)) {
			foreach ($xml->stack as $f) {
				$capture['frames'][] = [
					'level' => (int)($f['level'] ?? 0),
					'file' => (string)($f['filename'] ?? ''),
					'line' => (int)($f['lineno'] ?? 0),
					'where' => (string)($f['where'] ?? ''),
					'type' => (string)($f['type'] ?? ''),
				];
				if (count($capture['frames']) >= 128) {
					break;
				}
			}
		}
		if ($capture['frames']) {
			$top = $capture['frames'][0];
			if ($top['file'] !== '' && $top['line'] > 0) {
				$capture['source'] = read_source_context($top['file'], $top['line'], 10, 10);
			}
		}
		dbg_queue('context_get', [
			'd' => 0,
			'c' => 0
		], function ($locals) use (&$capture, $generation) {
			global $state;
			if ($state['generation'] !== $generation || $state['status'] !== 'break') {
				return;
			}
			$capture['locals'] = context_tree($locals, 0, $generation);
			dbg_queue('context_get', [
				'd' => 0,
				'c' => 1
			], function ($globals) use (&$capture, $generation) {
				$capture['superglobals'] = context_tree($globals, 1, $generation);
				dbg_publish_snapshot([
					'status' => 'break',
					'generation' => $generation,
					'frames' => $capture['frames'],
					'locals' => $capture['locals'],
					'superglobals' => $capture['superglobals'],
					'source' => $capture['source'] ?? [
							'ok' => false,
							'error' => 'source unavailable'
						]
				], $generation);
			});
		});
	});
}

function dbg_packet($text) {
	global $state;
	$xml = @simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
	if ($xml === false) {
		dlog('DBGp malformed XML');
		return;
	}
	$node = $xml->getName();
	if ($node === 'init') {
		if ($state['status'] !== 'connecting') {
			dbg_disconnect('duplicate init');
			return;
		}
		dbg_init_session($xml);
		return;
	}
	if ($node === 'stream' || $node === 'notify') {
		return;
	}
	if ($node !== 'response') {
		return;
	}
	$inflight = $state['inflight'];
	if (!$inflight) {
		dlog('DBGp unsolicited response', ['command' => (string)($xml['command'] ?? '')]);
		return;
	}
	if ((int)($xml['transaction_id'] ?? -1) !== $inflight['txn']) {
		dlog('DBGp transaction mismatch', [
			'expected' => $inflight['txn'],
			'got' => (string)($xml['transaction_id'] ?? '')
		]);
		return;
	}
	$state['inflight'] = null;
	$status = (string)($xml['status'] ?? '');
	if ($inflight['resume']) {
		if ($status === 'break') {
			$state['status'] = 'break';
			$state['generation']++;
			$state['collecting'] = false;
			$state['cached_generation'] = -1;
		} elseif ($status === 'stopping' || $status === 'stopped') {
			$state['status'] = $status;
		} else {
			$state['status'] = $status ?: 'unknown';
		}
	}
	if (is_callable($inflight['callback'])) {
		($inflight['callback'])($xml);
	}
	if ($inflight['resume'] && $status === 'break') {
		dbg_sync_bps();
		global $stackWaiters;
		if ($stackWaiters) {
			dbg_collect_snapshot();
		}
	}
	// In DBGp 'stopping' is a protocol state, not yet a disconnected request.
	// Xdebug expects a final stop command to free the PHP worker.
	if ($inflight['resume'] && $status === 'stopping') {
		dbg_queue('stop');
	}
	if ($inflight['resume'] && $status === 'stopped') {
		dbg_disconnect('request completed');
		return;
	}
	dbg_pump();
}

/** Incremental parser: <ascii length>\0<XML body>\0, with a hard memory cap. */
function dbg_parse_buffer() {
	global $state;
	while (true) {
		if ($state['rx_expected'] === null) {
			$pos = strpos($state['rx'], "\0");
			if ($pos === false) {
				if (strlen($state['rx']) > 12) {
					dbg_disconnect('invalid header');
				}
				return;
			}
			$num = substr($state['rx'], 0, $pos);
			if ($num === '' || strlen($num) > 10 || !ctype_digit($num) || (float)$num > cfg_limit('DBG_MAX_DBGP_PACKET_BYTES', 33554432)) {
				dbg_disconnect('bad packet length');
				return;
			}
			$state['rx_expected'] = (int)$num;
			$state['rx'] = substr($state['rx'], $pos + 1);
		}
		$n = $state['rx_expected'];
		if (strlen($state['rx']) < $n + 1) {
			return;
		}
		if ($state['rx'][$n] !== "\0") {
			dbg_disconnect('missing packet terminator');
			return;
		}
		$packet = substr($state['rx'], 0, $n);
		$state['rx'] = substr($state['rx'], $n + 1);
		$state['rx_expected'] = null;
		dbg_packet($packet);
		if (!$state['dbg']) {
			return;
		}
	}
}

function dbg_read() {
	global $state;
	$chunk = @fread($state['dbg'], 65536);
	if ($chunk === false || $chunk === '') {
		if ($chunk === false || feof($state['dbg'])) {
			dbg_disconnect('eof');
		}
		return;
	}
	$state['rx'] .= $chunk;
	if (strlen($state['rx']) > cfg_limit('DBG_MAX_DBGP_PACKET_BYTES', 33554432) + 65536) {
		dbg_disconnect('input limit');
		return;
	}
	dbg_parse_buffer();
}

function reject_read($id) {
	global $rejects;
	if (!isset($rejects[$id])) {
		return;
	}
	$sock = $rejects[$id]['sock'];
	$bytes = @fread($sock, 8192);
	if ($bytes === false || $bytes === '') {
		if ($bytes === false || feof($sock)) {
			@fclose($sock);
			unset($rejects[$id]);
		}
		return;
	}
	$rejects[$id]['received'] += strlen($bytes);
	// DBGp allows to detach as soon as the connection is initialized.
	if ($rejects[$id]['received'] > 0 && !$rejects[$id]['sent']) {
		$rejects[$id]['out'] = "detach -i 1\0";
		$rejects[$id]['sent'] = true;
		$rejects[$id]['deadline'] = microtime(true) + 0.3;
	}
}

function parse_skip($raw) {
	if (is_array($raw)) {
		return array_slice(array_map('strval', $raw), 0, 80);
	}
	if (!is_string($raw)) {
		return [];
	}
	return array_slice(explode(',', $raw), 0, 80);
}

function handle_http($id, $request) {
	global $state, $stackWaiters, $httpClients;
	$requestLine = strtok($request, "\r\n");
	if (!preg_match('#^(GET|HEAD)\s+(\S+)\s+HTTP/1\.[01]$#', $requestLine, $match)) {
		http_json($id, ['error' => 'only GET/HEAD HTTP/1.x supported'], 400);
		return;
	}
	$method = $match[1];
	$target = $match[2];
	$path = parse_url($target, PHP_URL_PATH);
	if (!is_string($path)) {
		http_json($id, ['error' => 'bad url'], 400);
		return;
	}
	$query = parse_url($target, PHP_URL_QUERY);
	$params = [];
	if (is_string($query)) {
		parse_str($query, $params);
	}

	$site = site_from_http_request($request);
	if ($site === null) {
		http_json($id, ['error' => 'unknown Host; check Apache ProxyPreserveHost'], 421);
		return;
	}

	$httpClients[$id]['site'] = $site;
	select_site($site);
	$state =& $GLOBALS['state'];
	$stackWaiters =& $GLOBALS['stackWaiters'];

	// This existing endpoint is required by the service supervisor.
	// Do not remove: it applies changes by restarting the daemon.
	if (!empty($params['restart'])) {
		exit(1);
	}

	if ($path === '/dbg') {
		$path = '/';
	}
	if (strpos($path, '/dbg/') === 0) {
		$path = substr($path, 4);
	}

	if ($path === '/' || $path === '/index.tpl.php') {
		$html = render_template();
		if ($html === null) {
			http_json($id, ['error' => 'index.tpl.php missing'], 500);
		} else {
			http_response($id, 200, $method === 'HEAD' ? '' : $html, 'text/html; charset=utf-8');
		}
		return;
	}
	if (serve_static($id, $path)) {
		return;
	}
	if (strpos($path, '/api/') !== 0) {
		http_json($id, ['error' => 'not found'], 404);
		return;
	}
	if ($method === 'HEAD') {
		http_json($id, ['error' => 'HEAD not supported for API'], 405);
		return;
	}

	if ($path === '/api/status') {
		http_json($id, [
			'attached' => (bool)$state['dbg'],
			'engine_status' => $state['dbg'] ? $state['status'] : null,
			'property_max_data' => cfg_limit('DBG_PROPERTY_MAX_DATA', 4 * 1024 * 1024)
		]);
		return;
	}
	if ($path === '/api/bp/list') {
		$desired = [];
		foreach ($state['desired_bps'] as $key => $bp) {
			$applied = $state['applied_bps'][$key] ?? null;
			$bp['engine_id'] = $applied === null ? null : $applied['id'];
			$bp['engine_error'] = $applied === null ? null : $applied['error'];
			$desired[] = $bp;
		}

		http_json($id, ['desired' => $desired]);
		return;
	}
	if ($path === '/api/bp/set') {
		$raw = isset($params['file']) && is_string($params['file']) ? $params['file'] : '';
		$line = isset($params['line']) && is_scalar($params['line']) ? (int)$params['line'] : 0;
		$bp = parse_file_ref_and_map($raw, $line);
		if ($bp['file'] !== '' && !site_allows_source($site, $bp['file'])) {
			http_json($id, [
				'ok' => false,
				'error' => 'file is outside this site code roots'
			], 403);
			return;
		}
		if ($bp['file'] === '' || $bp['line'] <= 0 || $bp['line'] > 2000000 || strlen($bp['file']) > 4096) {
			http_json($id, ['ok' => false, 'error' => 'bad arguments'], 400);
			return;
		}
		$key = $bp['file'] . ':' . $bp['line'];
		if (!isset($state['desired_bps'][$key]) && count($state['desired_bps']) >= 200) {
			http_json($id, [
				'ok' => false,
				'error' => 'too many breakpoints'
			], 429);
			return;
		}
		$condition = $params['condition'] ?? '';
		if (!is_string($condition)) {
			http_json($id, [
				'ok' => false,
				'error' => 'condition must be a string'
			], 400);
			return;
		}

		$condition = trim($condition);
		if (strlen($condition) > 4096 || strpos($condition, "\0") !== false) {
			http_json($id, [
				'ok' => false,
				'error' => 'invalid breakpoint condition'
			], 400);
			return;
		}

		$bp['condition'] = $condition;
		$state['desired_bps'][$key] = $bp;
		dbg_sync_bps();

		$applied = $state['applied_bps'][$key] ?? null;
		http_json($id, [
			'ok' => true,
			'id' => $applied === null ? null : $applied['id'],
			'condition' => $condition,
			'staged' => $applied === null || $applied['pending'],
			'error' => $applied === null ? null : $applied['error']
		]);
		return;
	}
	if ($path === '/api/bp/clear') {
		$state['desired_bps'] = [];
		dbg_sync_bps();
		http_json($id, ['ok' => true]);
		return;
	}
	if ($path === '/api/kill') {
		if ($state['dbg']) {
			// Never wait for a continuation response from a running PHP request.
			@fwrite($state['dbg'], "detach -i 999999\0");
			dbg_disconnect('killed from UI');
		}
		http_json($id, ['ok' => true]);
		return;
	}
	// /api/stack is polled unconditionally by the existing frontend, even when
	// no Xdebug session is attached. Idle is not an API error.
	if ($path === '/api/stack') {
		if (!$state['dbg']) {
			http_json($id, [
				'status' => 'idle',
				'last_snapshot' => $state['last_snapshot']
			]);
			return;
		}
		if ($state['status'] !== 'break') {
			http_json($id, [
				'status' => $state['status'],
				'last_snapshot' => $state['last_snapshot']
			]);
			return;
		}
		$skip = parse_skip($params['skip'] ?? []);
		if ($state['cached_generation'] === $state['generation'] && $state['last_snapshot']) {
			http_json($id, filtered_snapshot($state['last_snapshot'], $skip));
			return;
		}
		if (count($stackWaiters) >= 64) {
			http_json($id, ['error' => 'too many snapshot requests'], 429);
			return;
		}
		$stackWaiters[$id] = $skip;
		global $httpClients;
		$httpClients[$id]['waiting'] = true;
		$httpClients[$id]['deadline'] = microtime(true) + 5.0;
		dbg_collect_snapshot();
		return;
	}
	// Lazy variable inspection. Query only when a node is actually expanded.
	// Full DBGp names are passed through unchanged (not evaluated as PHP code).
	if ($path === '/api/property') {
		global $httpClients, $propertyWaiters;
		if (!$state['dbg'] || $state['status'] !== 'break') {
			http_json($id, [
				'ok' => false,
				'error' => 'debugger is not paused'
			], 409);
			return;
		}
		$name = $params['name'] ?? null;
		$context = filter_var($params['ctx'] ?? null, FILTER_VALIDATE_INT);
		$page = filter_var($params['page'] ?? 0, FILTER_VALIDATE_INT);
		$generation = filter_var($params['generation'] ?? null, FILTER_VALIDATE_INT);
		$wantedBytes = filter_var($params['max_data'] ?? 4096, FILTER_VALIDATE_INT);
		$maxBytes = cfg_limit('DBG_PROPERTY_MAX_DATA', 4 * 1024 * 1024);
		if (!is_string($name) || $name === '' || strlen($name) > 8192 ||
			!in_array($context, [0, 1], true) || $page === false || $page < 0 ||
			$page > 10000000 || $generation === false ||
			$generation !== $state['generation'] || $wantedBytes === false ||
			$wantedBytes < 1 || $wantedBytes > $maxBytes) {
			http_json($id, [
				'ok' => false,
				'error' => 'invalid or stale property request'
			], 400);
			return;
		}
		if (count($propertyWaiters) >= 16) {
			http_json($id, [
				'ok' => false,
				'error' => 'too many property requests'
			], 429);
			return;
		}
		$propertyWaiters[$id] = true;
		$httpClients[$id]['waiting'] = true;
		$httpClients[$id]['deadline'] = microtime(true) + 6.0;
		$enqueued = dbg_queue('property_get', [
			'n' => $name,
			'd' => 0,
			'c' => $context,
			'p' => $page,
			'm' => $wantedBytes
		], function ($xml) use ($id, $generation, $context, $page) {
			global $state, $propertyWaiters;
			if (!isset($propertyWaiters[$id])) {
				return;
			}
			unset($propertyWaiters[$id]);
			if ($generation !== $state['generation'] || $state['status'] !== 'break') {
				http_json($id, [
					'ok' => false,
					'error' => 'pause has changed'
				], 409);
				return;
			}
			if (
				$xml === false ||
				$xml === null ||
				!isset($xml->property) ||
				isset($xml->error)
			) {
				http_json($id, [
					'ok' => false,
					'error' => 'property_get failed'
				], 502);
				return;
			}
			http_json($id, [
				'ok' => true,
				'generation' => $generation,
				'property' => property_tree($xml->property[0], $context, $generation, 0, true, $page)
			]);
		});
		if (!$enqueued) {
			unset($propertyWaiters[$id]);
			http_json($id, [
				'ok' => false,
				'error' => 'command queue full'
			], 503);
		}
		return;
	}
	if (!$state['dbg']) {
		http_json($id, ['error' => 'no dbg attached'], 409);
		return;
	}
	$commands = [
		'/api/run' => 'run',
		'/api/step_over' => 'step_over',
		'/api/step_into' => 'step_into',
		'/api/step_out' => 'step_out'
	];
	if (isset($commands[$path])) {
		if (!dbg_resume($commands[$path])) {
			http_json($id, [
				'ok' => false,
				'error' => 'debugger is not paused'
			], 409);
			return;
		}
		http_json($id, ['ok' => true]);
		return;
	}
	http_json($id, ['error' => 'unknown route: ' . $path], 404);
}


/**
 * Each entry in DBG_SITES represents one independent debugger session.
 * Domain is used for HTTP requests, while DBGp uses the initial fileuri.
 */
function build_site_configs(array $sites): array {
	$result = [];

	foreach ($sites as $host => $cfg) {
		$host = strtolower(rtrim(trim((string)$host), '.'));
		if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
			throw new RuntimeException('Invalid DBG_SITES host: ' . $host);
		}
		if (!is_array($cfg)) {
			throw new RuntimeException('Invalid DBG_SITES entry for ' . $host);
		}
		if (isset($result[$host])) {
			throw new RuntimeException('Duplicate DBG_SITES host: ' . $host);
		}

		$base = isset($cfg['server_base']) ? rtrim(normalize_slashes($cfg['server_base']), '/') : '';
		if ($base === '' || $base[0] !== '/' || $base === '/') {
			throw new RuntimeException('Absolute server_base required for ' . $host);
		}

		$roots = $cfg['match_roots'] ?? [$base];
		if (!is_array($roots) || !$roots) {
			throw new RuntimeException('Nonempty match_roots required for ' . $host);
		}

		$normalized = [];
		foreach ($roots as $root) {
			$root = rtrim(normalize_slashes((string)$root), '/');
			if ($root === '' || $root[0] !== '/' || $root === '/') {
				throw new RuntimeException('Invalid match_root for ' . $host);
			}
			$normalized[] = $root;
		}

		$codeRoots = $cfg['code_roots'] ?? [$base];
		if (!is_array($codeRoots) || !$codeRoots) {
			throw new RuntimeException('Nonempty code_roots required for ' . $host);
		}
		foreach ($codeRoots as $root) {
			if (!is_string($root) || $root === '' || $root[0] !== '/') {
				throw new RuntimeException('Invalid code_root for ' . $host);
			}
		}

		$result[$host] = [
			'server_base' => $base,
			'project_base' => isset($cfg['project_base']) ? (string)$cfg['project_base'] : '',
			'match_roots' => $normalized,
			'code_roots' => $codeRoots,
		];
	}
	if (!$result) {
		throw new RuntimeException('DBG_SITES must contain at least one site');
	}
	return $result;
}

/**
 * Match by directory boundary, never by a simple string prefix.
 * Resolve symlinks if paths exist, to support symlinked deployments.
 */
function clean_absolute_path($path): ?string {
	$path = normalize_slashes((string)$path);
	if ($path === '' || $path[0] !== '/') {
		return null;
	}

	$parts = [];
	foreach (explode('/', $path) as $part) {
		if ($part === '' || $part === '.') {
			continue;
		}
		if ($part === '..') {
			array_pop($parts);
		} else {
			$parts[] = $part;
		}
	}
	return '/' . implode('/', $parts);
}

function path_within_root($file, $root): bool {
	$file = clean_absolute_path($file);
	$root = clean_absolute_path($root);
	if ($file === null || $root === null) {
		return false;
	}

	$realFile = realpath($file);
	$realRoot = realpath($root);
	if ($realFile !== false && $realRoot !== false) {
		$file = clean_absolute_path($realFile);
		$root = clean_absolute_path($realRoot);
	}

	return $file === $root || strpos($file, rtrim($root, '/') . '/') === 0;
}

function site_from_fileuri($uri) {
	global $siteConfigs;

	if (!is_string($uri) || strpos($uri, 'file://') !== 0) {
		return null;
	}
	$file = normalize_slashes(rawurldecode(file_uri_to_path($uri)));
	$found = null;
	$bestLength = 0;
	$ambiguous = false;

	foreach ($siteConfigs as $site => $cfg) {
		foreach ($cfg['match_roots'] as $root) {
			if (!path_within_root($file, $root)) {
				continue;
			}
			$length = strlen($root);
			if ($length > $bestLength) {
				$bestLength = $length;
				$found = $site;
				$ambiguous = false;
			} elseif ($length === $bestLength && $found !== $site) {
				$ambiguous = true;
			}
		}
	}

	return $ambiguous ? null : $found;
}

function site_from_http_request($request): ?string {
	global $siteConfigs;

	if (!preg_match('/(?:^|\r?\n)Host:[ \t]*([^\r\n]+)/i', $request, $match)) {
		return null;
	}
	$host = strtolower(trim($match[1]));
	if (strpos($host, ':') !== false) {
		$host = explode(':', $host, 2)[0];
	}
	$host = rtrim($host, '.');
	return isset($siteConfigs[$host]) ? $host : null;
}

function site_allows_source($site, $path): bool {
	global $siteConfigs;
	foreach ($siteConfigs[$site]['code_roots'] as $root) {
		if (path_within_root(file_uri_to_path($path), $root)) {
			return true;
		}
	}
	return false;
}

/**
 * Shared DBGP functions operate on the *currently selected site's* state.
 * Site selection occurs before every socket event, HTTP handler and callback.
 * This keeps one event loop but prevents accidental sharing of breakpoints.
 */
function select_site($site) {
	global $siteConfigs, $states, $stackWaitersBySite, $propertyWaitersBySite;
	global $state, $stackWaiters, $propertyWaiters, $DBG_PROJECT_BASE, $DBG_SERVER_BASE;

	if (!isset($states[$site])) {
		throw new RuntimeException('Unknown site: ' . $site);
	}

	$GLOBALS['state'] =& $states[$site];
	$GLOBALS['stackWaiters'] =& $stackWaitersBySite[$site];
	$GLOBALS['propertyWaiters'] =& $propertyWaitersBySite[$site];
	$DBG_PROJECT_BASE = $siteConfigs[$site]['project_base'];
	$DBG_SERVER_BASE = $siteConfigs[$site]['server_base'];
}

function new_dbg_state(): array {
	return [
		'dbg' => null,
		'init' => null,
		'status' => 'idle',
		'rx' => '',
		'rx_expected' => null,
		'tx' => '',
		'txn' => 0,
		'commands' => [],
		'inflight' => null,
		'desired_bps' => [],
		'applied_bps' => [],
		'last_snapshot' => null,
		'cached_generation' => -1,
		'generation' => 0,
		'collecting' => false,
		'connected_at' => null,
	];
}

/**
 * The DBGp socket is initially unassigned. Route only after the init packet
 * has supplied the entry script's fileuri.
 */
function pending_dbg_read($id) {
	global $pendingDbg, $rejects, $states;

	if (!isset($pendingDbg[$id])) {
		return;
	}

	$sock = $pendingDbg[$id]['sock'];
	$chunk = @fread($sock, 16384);
	if ($chunk === false || $chunk === '') {
		if ($chunk === false || feof($sock)) {
			@fclose($sock);
			unset($pendingDbg[$id]);
		}
		return;
	}

	$pendingDbg[$id]['rx'] .= $chunk;
	$buffer = $pendingDbg[$id]['rx'];
	if (strlen($buffer) > 131072) {
		@fclose($sock);
		unset($pendingDbg[$id]);
		return;
	}

	$null = strpos($buffer, "\0");
	if ($null === false) {
		return;
	}
	$lengthText = substr($buffer, 0, $null);
	if ($lengthText === '' || strlen($lengthText) > 10 || !ctype_digit($lengthText)) {
		@fclose($sock);
		unset($pendingDbg[$id]);
		return;
	}
	$length = (int)$lengthText;
	if ($length < 1 || $length > 65536 || strlen($buffer) < $null + $length + 2) {
		if ($length > 65536) {
			@fclose($sock);
			unset($pendingDbg[$id]);
		}
		return;
	}
	if ($buffer[$null + $length + 1] !== "\0") {
		@fclose($sock);
		unset($pendingDbg[$id]);
		return;
	}

	$xmlText = substr($buffer, $null + 1, $length);
	$tail = substr($buffer, $null + $length + 2);
	$xml = @simplexml_load_string(
		$xmlText,
		'SimpleXMLElement',
		LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
	);
	$site = null;
	if ($xml !== false && $xml->getName() === 'init') {
		$site = site_from_fileuri((string)($xml['fileuri'] ?? ''));
	}

	unset($pendingDbg[$id]);
	if ($site === null) {
		dlog('DBGp unrecognized or ambiguous fileuri', ['init' => substr($xmlText, 0, 256)]);
		$rejects[$id] = [
			'sock' => $sock,
			'received' => strlen($xmlText),
			'sent' => true,
			'out' => "detach -i 1\0",
			'deadline' => microtime(true) + 0.3,
		];
		return;
	}

	select_site($site);
	if ($states[$site]['dbg']) {
		dlog('DBGp additional connection refused (site busy)', ['site' => $site]);
		$rejects[$id] = [
			'sock' => $sock,
			'received' => strlen($xmlText),
			'sent' => true,
			'out' => "detach -i 1\0",
			'deadline' => microtime(true) + 0.3,
		];
		return;
	}

	$states[$site]['dbg'] = $sock;
	$states[$site]['status'] = 'connecting';
	$states[$site]['rx'] = $tail;
	$states[$site]['rx_expected'] = null;
	$states[$site]['tx'] = '';
	$states[$site]['commands'] = [];
	$states[$site]['inflight'] = null;
	$states[$site]['applied_bps'] = [];
	$states[$site]['last_snapshot'] = null;
	$states[$site]['cached_generation'] = -1;
	$states[$site]['generation']++;
	$states[$site]['connected_at'] = microtime(true);
	$states[$site]['collecting'] = false;

	dbg_init_session($xml);
	if ($tail !== '') {
		dbg_parse_buffer();
	}
	dlog('DBGp routed', ['site' => $site]);
}

$dbgSock = @stream_socket_server("tcp://$DBG_HOST:$DBG_PORT", $errNo, $errStr);
if (!$dbgSock) {
	fwrite(STDERR, "DBGp listen: $errStr\n");
	exit(1);
}
$httpSock = @stream_socket_server("tcp://$HTTP_HOST:$HTTP_PORT", $errNo, $errStr);
if (!$httpSock) {
	fwrite(STDERR, "HTTP listen: $errStr\n");
	exit(1);
}
stream_set_blocking($dbgSock, false);
stream_set_blocking($httpSock, false);

if (!isset($DBG_SITES) || !is_array($DBG_SITES)) {
	fwrite(STDERR, "Missing \$DBG_SITES in config.php\n");
	exit(1);
}

try {
	$siteConfigs = build_site_configs($DBG_SITES);
} catch (RuntimeException $error) {
	fwrite(STDERR, "Invalid DBG_SITES: " . $error->getMessage() . "\n");
	exit(1);
}

$states = [];
$stackWaitersBySite = [];
$propertyWaitersBySite = [];

foreach ($siteConfigs as $site => $config) {
	$states[$site] = new_dbg_state();
	$stackWaitersBySite[$site] = [];
	$propertyWaitersBySite[$site] = [];
}

select_site(key($siteConfigs));

$httpClients = [];
$pendingDbg = [];
$rejects = [];

dlog('START multi-site debugger', [
	'DBGp' => "$DBG_HOST:$DBG_PORT",
	'HTTP' => "$HTTP_HOST:$HTTP_PORT",
	'sites' => array_keys($siteConfigs),
]);
echo "DBGp on $DBG_HOST:$DBG_PORT | Web UI on http://$HTTP_HOST:$HTTP_PORT\n";
echo "Sites: " . implode(', ', array_keys($siteConfigs)) . "\n";

while (true) {
	$read = [$dbgSock, $httpSock];
	$write = [];
	$dbgSocketSites = [];

	foreach ($states as $site => $siteState) {
		if (!$siteState['dbg']) {
			continue;
		}

		$socket = $siteState['dbg'];
		$dbgSocketSites[(int)$socket] = $site;
		$read[] = $socket;
		if ($siteState['tx'] !== '') {
			$write[] = $socket;
		}
	}

	foreach ($pendingDbg as $client) {
		$read[] = $client['sock'];
	}

	foreach ($httpClients as $client) {
		if ($client['out'] !== '') {
			$write[] = $client['sock'];
		} elseif (!$client['waiting']) {
			$read[] = $client['sock'];
		}
	}

	foreach ($rejects as $client) {
		$read[] = $client['sock'];
		if ($client['out'] !== '') {
			$write[] = $client['sock'];
		}
	}

	$except = null;
	$selected = @stream_select($read, $write, $except, 0, 200000);
	if ($selected === false) {
		usleep(10000);
		continue;
	}

	foreach ($read as $sock) {
		if ($sock === $dbgSock) {
			for ($i = 0; $i < 32; $i++) {
				$conn = @stream_socket_accept($dbgSock, 0);
				if (!$conn) {
					break;
				}

				stream_set_blocking($conn, false);
				if (count($pendingDbg) + count($rejects) >= 64) {
					@fclose($conn);
					continue;
				}

				$id = (int)$conn;
				$pendingDbg[$id] = [
					'sock' => $conn,
					'rx' => '',
					'deadline' => microtime(true) + 3.0,
				];
			}
			continue;
		}

		if ($sock === $httpSock) {
			for ($i = 0; $i < 32; $i++) {
				$conn = @stream_socket_accept($httpSock, 0);
				if (!$conn) {
					break;
				}

				stream_set_blocking($conn, false);
				if (count($httpClients) >= cfg_limit('DBG_MAX_HTTP_CLIENTS', 128)) {
					@fwrite(
						$conn,
						"HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nContent-Length: 0\r\n\r\n"
					);
					@fclose($conn);
					continue;
				}

				$httpClients[(int)$conn] = [
					'sock' => $conn,
					'in' => '',
					'out' => '',
					'waiting' => false,
					'deadline' => microtime(true) + 3.0,
					'site' => null,
				];
			}
			continue;
		}

		$id = (int)$sock;
		if (isset($dbgSocketSites[$id])) {
			select_site($dbgSocketSites[$id]);
			if ($state['dbg'] === $sock) {
				dbg_read();
			}
			continue;
		}

		if (isset($pendingDbg[$id])) {
			pending_dbg_read($id);
			continue;
		}

		if (isset($rejects[$id])) {
			reject_read($id);
			continue;
		}

		if (!isset($httpClients[$id])) {
			continue;
		}

		$data = @fread($sock, 16384);
		if ($data === false || $data === '') {
			if ($data === false || feof($sock)) {
				http_close($id);
			}
			continue;
		}

		$httpClients[$id]['in'] .= $data;
		if (strlen($httpClients[$id]['in']) > 16384) {
			http_json($id, ['error' => 'request headers too large'], 413);
			continue;
		}

		if (
			strpos($httpClients[$id]['in'], "\r\n\r\n") !== false ||
			strpos($httpClients[$id]['in'], "\n\n") !== false
		) {
			handle_http($id, $httpClients[$id]['in']);
		}
	}

	foreach ($write as $sock) {
		$id = (int)$sock;
		if (isset($dbgSocketSites[$id])) {
			select_site($dbgSocketSites[$id]);
			if ($state['dbg'] !== $sock || $state['tx'] === '') {
				continue;
			}

			$written = @fwrite($sock, substr($state['tx'], 0, 16384));
			if ($written === false) {
				dbg_disconnect('send failed');
			} elseif ($written > 0) {
				$state['tx'] = substr($state['tx'], $written);
			}
			continue;
		}

		if (isset($rejects[$id])) {
			$written = @fwrite($sock, $rejects[$id]['out']);
			if ($written === false) {
				@fclose($sock);
				unset($rejects[$id]);
			} elseif ($written > 0) {
				$rejects[$id]['out'] = substr($rejects[$id]['out'], $written);
			}
			continue;
		}

		if (!isset($httpClients[$id])) {
			continue;
		}

		$written = @fwrite($sock, substr($httpClients[$id]['out'], 0, 65536));
		if ($written === false) {
			http_close($id);
			continue;
		}
		if ($written > 0) {
			$httpClients[$id]['out'] = substr($httpClients[$id]['out'], $written);
			if ($httpClients[$id]['out'] === '') {
				http_close($id);
			}
		}
	}

	$now = microtime(true);
	foreach ($states as $site => $ignored) {
		select_site($site);
		if (
			$state['dbg'] &&
			$state['status'] === 'connecting' &&
			$state['connected_at'] !== null &&
			$now - $state['connected_at'] > 3
		) {
			dbg_disconnect('handshake timeout');
		}

		if (
			$state['inflight'] &&
			$state['inflight']['deadline'] !== null &&
			$state['inflight']['deadline'] < $now
		) {
			dbg_disconnect('DBGp command timeout: ' . $state['inflight']['command']);
		}
	}

	foreach ($pendingDbg as $id => $pending) {
		if ($pending['deadline'] < $now) {
			@fclose($pending['sock']);
			unset($pendingDbg[$id]);
		}
	}

	foreach ($httpClients as $id => $client) {
		if ($client['deadline'] >= $now) {
			continue;
		}
		if ($client['out'] !== '') {
			http_close($id);
			continue;
		}

		if ($client['site'] !== null) {
			unset($stackWaitersBySite[$client['site']][$id]);
			unset($propertyWaitersBySite[$client['site']][$id]);
		}
		http_json($id, ['error' => 'request timeout'], 504);
	}

	foreach ($rejects as $id => $reject) {
		if ($reject['deadline'] < $now) {
			@fclose($reject['sock']);
			unset($rejects[$id]);
		}
	}
}
