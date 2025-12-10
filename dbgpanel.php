<?php

error_reporting(E_ALL);
set_time_limit(0);

const DBG_LOG_FILE = '/var/log/debugpanel.log';
const DBG_LOG_ENABLED = false;

$DBG_PROJECT_BASE = getenv('DBG_PROJECT_BASE') ?: '';
$DBG_SERVER_BASE = getenv('DBG_SERVER_BASE') ?: '/var/www/html';

function dlog($msg, array $ctx = []) {
    if (DBG_LOG_ENABLED) {
        $ts = date('Y-m-d H:i:s');
        $pid = getmypid();
        if (!empty($ctx)) {
            $ctx = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line = "[$ts][$pid] $msg | $ctx\n";
        }
        else {
            $line = "[$ts][$pid] $msg\n";
        }
        @file_put_contents(DBG_LOG_FILE, $line, FILE_APPEND);
        @error_log($line);
    }
}


function starts_with($h, $n) {
    return strpos($h, $n) === 0;
}

/**
 * Кодирование значения аргумента DBGp (URL-style).
 */
function dbgp_arg($v) {
    $enc = rawurlencode($v);
    return strtr($enc, [
        '%2F' => '/', '%3A' => ':',
        '%24' => '$', '%5B' => '[', '%5D' => ']',
        '%27' => "'", '%22' => '"',
        '%5F' => '_', '%2D' => '-', '%2E' => '.', '%2C' => ','
    ]);
}

function dbgp_arg_fullname($v) {
    $enc = rawurlencode($v);
    return strtr($enc, [
        '%24' => '$',
        '%5B' => '[', '%5D' => ']',
        '%27' => "'", '%22' => '"',
    ]);
}

/**
 * Windows C:\path → file:///C:/path
 * Unix /path → file:///path
 */
function path_to_file_uri($path) {
    if (strpos($path, 'file://') === 0) return $path;
    if (preg_match('#^[A-Za-z]:\\\\#', $path)) {
        $path = str_replace('\\', '/', $path);
        return 'file:///' . $path;
    }
    if ($path !== '' && $path[0] === '/') return 'file://' . $path;
    return $path;
}

function normalize_file_uri($s) {
    if (!$s) return '';
    return (strpos($s, 'file://') === 0) ? $s : path_to_file_uri($s);
}

function is_our_break(array $frames, array $desired_bps): bool {
    if (empty($frames)) return false;
    $top = $frames[0];
    $uri = normalize_file_uri($top['file'] ?? '');
    $line = (int)($top['line'] ?? 0);
    foreach ($desired_bps as $bp) {
        $bp_uri = normalize_file_uri($bp['uri'] ?? $bp['file'] ?? '');
        if ($bp_uri && $bp_uri === $uri && (int)$bp['line'] === $line) return true;
    }
    return false;
}

function file_uri_to_path($uri) {
    if (!$uri) return '';
    if (strpos($uri, 'file://') !== 0) return $uri;
    $p = substr($uri, 7);
    if (preg_match('#^/[A-Za-z]:/#', $p)) $p = substr($p, 1);
    return str_replace("\\", "/", $p);
}

function normalize_slashes($p) {
    return preg_replace('~\\\\+~', '/', (string)$p);
}

function parse_file_ref_and_map($raw, $q_line = 0) {
    global $DBG_PROJECT_BASE, $DBG_SERVER_BASE;

    $s = trim((string)$raw);
    $line = (int)$q_line;

    if (strpos($s, 'file://') === 0) {
        $path = file_uri_to_path($s);
        if (preg_match('/^(.*?):(\d+)\s*$/', $path, $m)) {
            $path = $m[1];
            $line = (int)$m[2];
        }
        return ['file' => $path, 'uri' => path_to_file_uri($path), 'line' => $line];
    }

    if (preg_match('/^(.*?):(\d+)\s*$/', $s, $m)) {
        $s = $m[1];
        $line = (int)$m[2];
    }

    $path = normalize_slashes($s);

    if ($path !== '' && $path[0] === '/') {
        return ['file' => $path, 'uri' => path_to_file_uri($path), 'line' => $line];
    }

    if (preg_match('#^[A-Za-z]:/#', $path)) {
        return ['file' => $path, 'uri' => path_to_file_uri($path), 'line' => $line];
    }

    $rel = $path;

    if ($DBG_PROJECT_BASE !== '') {
        $pb = rtrim(normalize_slashes($DBG_PROJECT_BASE), '/');
        if (strpos($rel, $pb . '/') === 0) {
            $rel = substr($rel, strlen($pb) + 1);
        }
        elseif ($rel === $pb) {
            $rel = '';
        }
    }

    if ($DBG_SERVER_BASE !== '') {
        $sb = rtrim(normalize_slashes($DBG_SERVER_BASE), '/');
        $path = $rel !== '' ? ($sb . '/' . ltrim($rel, '/')) : $sb;
    }
    else {
        $path = $rel;
    }

    return ['file' => $path, 'uri' => path_to_file_uri($path), 'line' => $line];
}

function html_escape($s) {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Читает файл и возвращает список строк для рендера вокруг $line.
 *
 * @return array{ok:bool, file:string, start:int, end:int, line:int, lines:array<int,array{no:int,cur:bool,text:string}>, error?:string}
 */
function read_source_context($file_or_uri, $line, $above = 10, $below = 10) {
    $path = file_uri_to_path($file_or_uri);
    if ($path === '' || !is_readable($path)) {
        return ['ok' => false, 'file' => $path, 'line' => $line, 'start' => 0, 'end' => 0, 'lines' => [], 'error' => 'file not readable'];
    }
    $all = @file($path, FILE_IGNORE_NEW_LINES);
    if ($all === false) {
        return ['ok' => false, 'file' => $path, 'line' => $line, 'start' => 0, 'end' => 0, 'lines' => [], 'error' => 'failed to read'];
    }
    $n = count($all);
    $line = max(1, min((int)$line, $n));
    $start = max(1, $line - $above);
    $end = min($n, $line + $below);

    $out = [];
    for ($i = $start; $i <= $end; $i++) {
        $raw = $all[$i - 1];
        $out[] = ['no' => $i, 'cur' => ($i === $line), 'text' => html_escape($raw)];
    }
    return ['ok' => true, 'file' => $path, 'line' => $line, 'start' => $start, 'end' => $end, 'lines' => $out];
}

function dbgp_read_packet($sock, $timeout_sec = 3.0) {
    dlog("dbgp_read_packet: wait", ['timeout' => $timeout_sec]);

    $r = [$sock];
    $w = $e = null;
    if (stream_select($r, $w, $e, (int)$timeout_sec, (int)(($timeout_sec - (int)$timeout_sec) * 1e6)) === 0) {
        dlog("dbgp_read_packet: timeout");
        return null;
    }
    $len = '';
    while (!feof($sock)) {
        $c = fgetc($sock);
        if ($c === false) {
            dlog("dbgp_read_packet: fgetc=false");
            return null;
        }
        if ($c === "\0") break;
        $len .= $c;
        if (strlen($len) > 12) {
            dlog("dbgp_read_packet: bad length header", ['len' => $len]);
            return null;
        }
    }
    if ($len === '' || !ctype_digit($len)) {
        dlog("dbgp_read_packet: invalid length", ['len' => $len]);
        return null;
    }
    $to = (int)$len;
    $payload = '';
    while ($to > 0 && !feof($sock)) {
        $chunk = fread($sock, $to);
        if ($chunk === false) break;
        $payload .= $chunk;
        $to -= strlen($chunk);
    }
    fgetc($sock);

    dlog("dbgp_read_packet: got", ['len' => $len, 'head' => substr($payload, 0, 200)]);
    return $payload;
}

function dbgp_cmd_safe($sock, $cmd, $args = [], $data = '', $timeout_sec = 3.0, $retries = 1) {
    static $txn = 0;
    $txn = $txn % 1000000;
    do {
        $txn++;
        $argStr = '';
        foreach ($args as $k => $v) {
            if ($cmd === 'property_get' && $k === 'n') {
                $argStr .= ' -' . $k . ' ' . dbgp_arg_fullname($v);
            }
            else {
                $argStr .= ' -' . $k . ' ' . dbgp_arg($v);
            }
        }
        $line = $cmd . ' -i ' . $txn . $argStr;
        @fwrite($sock, $line . "\0");

        if ($cmd === 'property_get' && isset($args['n'])) {
            dlog('DBGp send property_get', ['n_raw' => (string)$args['n'], 'line' => $line]);
        }

        $resp = dbgp_read_packet($sock, $timeout_sec);
        if ($resp !== null) {
            if (strpos($resp, '<response') === false) {
                $maybe = dbgp_read_packet($sock, $timeout_sec);
                if ($maybe !== null) $resp = $maybe;
            }
            if ($resp !== null) return @simplexml_load_string($resp) ?: null;
        }
    } while ($retries-- > 0);
    return null;
}

function prop_scalar_or_summary(SimpleXMLElement $p) {
    $type = isset($p['type']) ? (string)$p['type'] : '';
    $size = isset($p['size']) ? (int)$p['size'] : null;
    $max_preview_size = 8192;

    if ($type === 'string' && $size !== null && $size > $max_preview_size) {
        return 'string(' . $size . ')';
    }

    if (isset($p['value'])) {
        return (string)$p['value'];
    }

    if (isset($p->value)) {
        $v = $p->value;
        $raw = (string)$v;
        $enc = isset($v['encoding']) ? strtolower((string)$v['encoding']) : '';

        if ($enc === 'base64') {
            $size = strlen($raw);
            if ($size > $max_preview_size * 2) {
                return 'string(' . $size . ')';
            }
            $decoded = base64_decode($raw, true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        $txt = trim($raw);
        if ($txt !== '') {
            return $txt;
        }
    }

    // Fallback.
    if (isset($p['encoding']) && strtolower((string)$p['encoding']) === 'base64') {
        $raw = base64_decode((string)$p, true);
        $size = strlen($raw);
        if ($size > $max_preview_size * 2) {
            return 'string(' . $size . ')';
        }
        return $raw !== false ? $raw : '';
    }

    $txt = trim((string)$p);
    if ($txt !== '') {
        return $txt;
    }

    $children = isset($p['children']) ? (int)$p['children'] : 0;
    if ($children === 1) {
        $t = isset($p['type']) ? (string)$p['type'] : 'array';
        $n = isset($p['numchildren']) ? (int)$p['numchildren'] : 0;
        return $t . '(' . $n . ')';
    }

    return '';
}


function prop_tree_from_property(SimpleXMLElement $p, $sock, int $ctx_id, int $depth_left, int $max_children = 64) {
    $type = isset($p['type']) ? (string)$p['type'] : '';

    if ($type === 'resource') {
        return prop_scalar_or_summary($p);
    }

    if ($type === 'array') {
        $numchildren = isset($p['numchildren']) ? (int)$p['numchildren'] : 0;
        if ($numchildren > $max_children * 4) {
            return [
                '__type' => $type,
                '__summary' => $type . '(' . $numchildren . ')',
            ];
        }
    }

    $val = prop_scalar_or_summary($p);
    $has_children = isset($p['children']) && (int)$p['children'] === 1;
    $fullname = prop_fullname($p);

    if (!$has_children) {
        if ($val === '' && $fullname !== '') {
            $resp = dbgp_cmd_safe($sock, 'property_get', [
                'n' => $fullname, 'd' => 0, 'c' => $ctx_id
            ], '', 2.0, 1);
            if ($resp && isset($resp->property)) {
                $val = prop_scalar_or_summary($resp->property);
            }
        }
        return $val;
    }

    $type = isset($p['type']) ? (string)$p['type'] : 'array';
    $out = ['__type' => $type, '__children' => []];

    if ($depth_left <= 0) {
        $n = isset($p['numchildren']) ? (int)$p['numchildren'] : 0;
        $out['__summary'] = $type . '(' . $n . ')';
        return $out;
    }

    $childrenSource = null;

    if (isset($p->property)) {
        $childrenSource = $p->property;
    }
    elseif ($fullname !== '') {
        $resp = dbgp_cmd_safe($sock, 'property_get', [
            'n' => $fullname,
            'd' => 0,
            'c' => $ctx_id
        ], '', 2.0, 1);

        if ($resp && isset($resp->property->property)) {
            $childrenSource = $resp->property->property;
        }
    }

    if ($childrenSource) {
        $i = 0;
        foreach ($childrenSource as $child) {
            if ($i++ >= $max_children) break;
            $name = prop_name($child);
            $out['__children'][$name] =
                prop_tree_from_property($child, $sock, $ctx_id, $depth_left - 1, $max_children);
        }
    }
    else {
        $n = isset($p['numchildren']) ? (int)$p['numchildren'] : 0;
        $out['__summary'] = $type . '(' . $n . ')';
    }

    return $out;
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
            }
            else {
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
            }
            else {
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
            }
            else {
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
    }
    elseif (isset($p->fullname)) {
        $f = $p->fullname;
        $val = (string)$f;
        if ($val !== '') {
            $enc = isset($f['encoding']) ? strtolower((string)$f['encoding']) : '';
            if ($enc === 'base64') {
                $decoded = base64_decode($val, true);
                if ($decoded !== false && $decoded !== '') {
                    $full = $decoded;
                }
            }
            else {
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

function collect_context_tree($sock, int $ctx_id, int $depth = 5, int $max_children = 64, array $skip = []) {
    $vars = [];
    $skipSet = [];
    foreach ($skip as $s) {
        $s = (string)$s;
        if ($s === '') {
            continue;
        }
        $skipSet[$s] = true;
        if ($s[0] !== '$') {
            $skipSet['$' . $s] = true;
        }
    }

    $ctx = dbgp_cmd_safe($sock, 'context_get', ['d' => 0, 'c' => $ctx_id], '', 1.2, 1);
    if ($ctx && isset($ctx->property)) {
        foreach ($ctx->property as $p) {
            $name = prop_name($p);
            if (isset($skipSet[$name])) {
                $type = isset($p['type']) ? (string)$p['type'] : 'array';
                $numchildren = isset($p['numchildren']) ? (int)$p['numchildren'] : 0;
                $vars[$name] = [
                    '__type' => $type,
                    '__summary' => $type . '(' . $numchildren . ')',
                ];
                continue;
            }

            $vars[$name] = prop_tree_from_property($p, $sock, $ctx_id, $depth, $max_children);
        }
    }
    return $vars;
}

$DBG_HOST = '127.0.0.1';
$DBG_PORT = 9003;
$HTTP_HOST = '127.0.0.1';
$HTTP_PORT = 8088;

$dbgSock = stream_socket_server("tcp://$DBG_HOST:$DBG_PORT", $e1, $e1s);
if (!$dbgSock) die("DBGp listen error\n");
stream_set_blocking($dbgSock, false);

$httpSock = stream_socket_server("tcp://$HTTP_HOST:$HTTP_PORT", $e2, $e2s);
if (!$httpSock) die("HTTP listen error\n");
stream_set_blocking($httpSock, false);

dlog("START panel", ['DBGp' => "$DBG_HOST:$DBG_PORT", 'HTTP' => "$HTTP_HOST:$HTTP_PORT"]);

$state = ['dbg' => null, 'init' => null, 'bps' => [], 'desired_bps' => [], 'last_snapshot' => null, 'stepping' => false];
echo "DBGp on $DBG_HOST:$DBG_PORT | Web UI on http://$HTTP_HOST:$HTTP_PORT\n";

function http_reply($c, $code, $body, $ctype = 'application/json; charset=utf-8') {
    $hdr = "HTTP/1.1 $code OK\r\n"
        . "Content-Type: $ctype\r\n"
        . "Content-Length: " . strlen($body) . "\r\n"
        . "Connection: close\r\n\r\n";
    fwrite($c, $hdr . $body);
}

function http_json($c, $obj) {
    http_reply($c, 200, json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$clients = [$dbgSock, $httpSock];

while (true) {
    $r = $clients;
    $w = $e = null;
    stream_select($r, $w, $e, null);

    foreach ($r as $ready) {
        // New attach.
        if ($ready === $dbgSock) {
            $conn = stream_socket_accept($dbgSock, 0);
            if ($conn) {
                dlog("DBGp: ATTACH");
                stream_set_blocking($conn, true);

                if ($state['dbg']) {
                    $cur = dbgp_cmd_safe($state['dbg'], 'status', [], '', 0.6, 0);
                    $cur_status = ($cur && isset($cur['status'])) ? (string)$cur['status'] : null;

                    if ($cur_status === 'break' || $cur_status === 'starting' || $cur_status === 'running') {
                        // Skip new connection.
                        $init_probe = dbgp_read_packet($conn, 0.5);
                        @fwrite($conn, "detach -i 1\0");
                        @fclose($conn);
                        dlog("DBGp: new attach rejected (active session: $cur_status)");
                        continue;
                    }

                    // Any other status (stopping / stopped / ошибка) — drop old session.
                    $old = $state['dbg'];
                    $idx = array_search($old, $clients, true);
                    if ($idx !== false) {
                        unset($clients[$idx]);
                    }
                    @fclose($old);
                    $state['dbg'] = null;
                    $state['last_snapshot'] = null;
                }

                $init = dbgp_read_packet($conn, 3.0);
                $state['dbg'] = $conn;
                $state['init'] = $init;
                $state['last_snapshot'] = null;
                echo "[DBGp] attached\n";
                $clients[] = $conn;

                foreach ([['max_children', 256], ['max_data', 8192], ['max_depth', 5],
                             ['extended_properties', 1], ['show_hidden', 1],
                             ['resolved_breakpoints', 1],
                         ] as $f) {
                    dbgp_cmd_safe($conn, 'feature_set', ['n' => $f[0], 'v' => $f[1]], '', 1.0, 0);
                }

                $state['bps'] = [];
                foreach ($state['desired_bps'] as $bp) {
                    $uri = $bp['uri'] ?? path_to_file_uri($bp['file']);
                    $resp = dbgp_cmd_safe($conn, 'breakpoint_set',
                        ['t' => 'line', 'f' => $uri, 'n' => $bp['line']], '', 1.2, 1);
                    if ($resp && isset($resp['id'])) {
                        $id = (string)$resp['id'];
                        if ($id !== '') $state['bps'][$id] = ['file' => $bp['file'], 'uri' => $uri, 'line' => $bp['line']];
                    }
                }

                dbgp_cmd_safe($conn, 'run', [], '', 1.0, 0);
            }
            continue;
        }

        if ($ready === $httpSock) {
            $conn = stream_socket_accept($httpSock, 0);
            if ($conn) {
                stream_set_blocking($conn, true);
                $req = '';
                while (($line = fgets($conn)) !== false) {
                    $req .= $line;
                    if (rtrim($line) === '') break;
                }
                if (!preg_match('#^(\w+)\s+([^\s]+)#', $req, $m)) {
                    fclose($conn);
                    continue;
                }
                $requestTarget = $m[2];
                $path = parse_url($requestTarget, PHP_URL_PATH) ?: '/';
                $queryString = parse_url($requestTarget, PHP_URL_QUERY) ?? '';
                $q = [];
                if ($queryString !== '') {
                    parse_str($queryString, $q);
                };
                if (!empty($q['restart'])) {
                    exit(1);
                }
                dlog("HTTP request", [
                    'raw' => trim(strtok($req, "\r")),
                    'path' => $path,
                    'query' => $queryString,
                    'orig' => $requestTarget
                ]);
                if (strpos($path, '/dbg/') === 0) {
                    $path = substr($path, 4);
                }

                if ($path === '/' || $path === '/index.html') {
                    $html = <<<'HTML'
<!doctype html><meta charset="utf-8">
<title>PHP Web Debug (Xdebug + DBGp)</title>
<style>
body{font:16px/1.45 system-ui;margin:24px;background:#2b2d30;color:#c8ccd4}
input,button{font:inherit;background:#c8ccd4}
button{color:black}
pre{background:#111;color:#ddd;padding:12px;border-radius:6px;overflow:auto;max-height:60vh}
.code-panel{display: flex;flex-direction: row;justify-content: space-between;}
#stack{width: 50%;position:relative;margin: 12px 0 0 0;border:1px solid #404040;padding:0;}
#stack-body{height: auto;width: 100%;padding:12px;}
#codewrap{margin-top:12px;border:1px solid #404040;border-radius:8px;overflow:hidden;background:#1e1f22;flex:1;width:50%;height: fit-content}
#codehdr{padding:8px 12px;font:500 13px/1.4 system-ui;color:#c8ccd4;background:#2b2d30;border-bottom:1px solid #2a2a2a}
.codepane{font:13px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, "DejaVu Sans Mono", monospace; display:flex; max-height:60vh; overflow:auto; color:#d7dae0}
.codepane .gutter{background:#2b2d30;color:#80868f;user-select:none;padding:8px 0}
.codepane .gutter div{padding:0 12px;text-align:right;min-width:46px}
.codepane .lines{flex:1;white-space:pre;tab-size:4;padding:8px 12px}
.codepane .line{white-space:pre}
.codepane .cur{background:#42464d}
.codepane .cur-num{color:#e2e5e9;font-weight:600}
.codepane .cur .tok-kw{color:#c792ea}
.codepane .tok-kw{color:#c792ea}
.codepane .tok-str{color:#ecc48d}
.codepane .tok-num{color:#f78c6c}
.codepane .tok-var{color:#82aaff}
#vars{display:flex;margin-top:12px}
#vars-locals{border:1px solid #404040}
#vars-super{border:1px solid #404040}
.block-title{font-weight:600;margin-bottom:6px;color:#c8ccd4}
.var-tree{font:13px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, "DejaVu Sans Mono", monospace;color:#d7dae0;background:#111;padding:8px 10px;border-radius:6px;max-height:50vh;overflow:auto;border:1px solid #2a2a2a}
.var-kv{margin-left:0.25rem}
details .var-kv{margin-left:0.9rem}
.var-key{color:#82aaff}
.var-type{color:#c792ea}
.var-scalar{color:#e2e5e9;white-space:pre-wrap}
.var-summary{color:#80868f}
.var-tree > div > details { margin-left: 0.25rem; } 
.vars-col {width: 50%}
details { margin-left: 0.75rem }
summary{cursor:pointer}
</style>
<h1>PHP Web Xdebug</h1>
<p>Статус DBGp: <b id="status">…</b></p>
<form id="bpForm" style="margin-bottom:8px">
  <input id="bp-filename" name="file" placeholder="C:\\\\Apache24\\\\htdocs\\\\server.php" size="120" required>
  <input name="line" type="number" placeholder="5" min="1">
  <button id="set-bp">Set breakpoint</button>
  <button type="button" id="btnList">List</button>
  <button type="button" id="btnClear">Clear all</button>
</form>
<p>
  <button id="btnCont">Continue</button>
  <button id="btnStepOver">Step over</button>
  <button id="btnStepInto">Step into</button>
  <button id="btnStepOut">Step out</button>
  <button id="btnKill" style="float:right">Kill DBG session</button>
  <button id="btnRestart" style="float:right">Restart service</button>
</p>
<div class="code-panel">
    <div id="codewrap">
      <div id="codehdr"></div>
      <div id="codeview" class="codepane"></div>
    </div>
    <pre id="stack"><div id="stack-body"></div></pre>
</div>
<div id="vars">
  <div class="vars-col">
    <div class="block-title">Locals</div>
    <div id="vars-locals"></div>
  </div>
  <div class="vars-col">
    <div class="block-title">Superglobals</div>
    <div id="vars-super"></div>
  </div>
</div>
<script>
async function api(p){const r=await fetch(p);return r.json()}
let $autoContinueCount = 0;
const urlParams = new URLSearchParams(window.location.search);
const skipParams = new URLSearchParams();
for (const v of urlParams.getAll('skip')) skipParams.append('skip', v);
for (const v of urlParams.getAll('skip[]')) skipParams.append('skip[]', v);
const STACK_SKIP_QS = skipParams.toString() ? ('?' + skipParams.toString()) : '';
async function refresh(){
  try{
    const s = await api('/dbg/api/status');
    document.getElementById('status').textContent = s.attached? ('attached / '+(s.engine_status||'unknown')) : 'waiting…';
    
    if (s.attached && s.engine_status === 'stopping') {
        $autoContinueCount++;
        if ($autoContinueCount >= 3) {
            $autoContinueCount = 0;
            await api('/dbg/api/run');
            return;
        }
        else {
            setTimeout(refresh, 300);
        }
    }

    const st = await api('/dbg/api/stack' + STACK_SKIP_QS);
    const localsEl = document.getElementById('vars-locals');
    const superEl  = document.getElementById('vars-super');
    renderStack(st);

    if (st && st.status === 'break' && st.source) {
      
      renderVarTree(localsEl, st.locals);
      renderVarTree(superEl,  st.superglobals);
      renderCode(st.source);
    } else {
      localsEl.innerHTML = '';
      superEl.innerHTML = '';
      renderCode(null);
    }
  }catch(e){
    document.getElementById('status').textContent='error';
  }
}
document.getElementById('bpForm').onsubmit = async (e)=>{e.preventDefault();
  const f=new FormData(e.target);
  await api('/dbg/api/bp/set?file='+encodeURIComponent(f.get('file'))+'&line='+encodeURIComponent(f.get('line')));
  refresh();
};
document.getElementById('btnList').onclick = ()=>api('/dbg/api/bp/list').then(x=>alert(JSON.stringify(x,null,2)));
document.getElementById('btnClear').onclick= ()=>api('/dbg/api/bp/clear').then(refresh);

document.getElementById('btnCont').onclick    = ()=>api('/dbg/api/run').then(refresh);
document.getElementById('btnStepOver').onclick= ()=>api('/dbg/api/step_over').then(refresh);
document.getElementById('btnStepInto').onclick= ()=>api('/dbg/api/step_into').then(refresh);
document.getElementById('btnStepOut').onclick = ()=>api('/dbg/api/step_out').then(refresh);

setInterval(refresh, 1500); refresh();

document.getElementById('btnKill').onclick = async () => {
  try {
    await fetch('/dbg/api/kill', { cache: 'no-store' });
  } catch (e) {}
  setTimeout(refresh, 100);
};

document.getElementById('btnRestart').onclick = async () => {
  try {
    await fetch('/dbg/?restart=1', { cache: 'no-store' });
  } catch (e) {}
  setTimeout(window.location.reload, 500);
};

let __lastStackSig = null;

function renderStack(st){
  const el = document.getElementById('stack-body');
  const view = (() => {
    if (!st || typeof st !== 'object') return st;

    const v = {
      status: st.status,
      frames: Array.isArray(st.frames) ? st.frames.slice() : undefined,
      source: st.source
    };

    if (Array.isArray(v.frames)) {
      v.frames.sort((a,b)=> (a.level|0) - (b.level|0));
    }

    return v;
  })();

  const sig = stableSig(view);
  if (sig === __lastStackSig) return;
  __lastStackSig = sig;

  el.textContent = JSON.stringify(view, null, 2);
}
let __lastRenderCodeSig = null;
function renderCode(ctx){
  const hdr  = document.getElementById('codehdr');
  const view = document.getElementById('codeview');
  if (!ctx || !ctx.ok) {
    if (hdr.textContent !== '—') {
        hdr.textContent = '—';
    }
    view.innerHTML = '';
    return;
  }
  
  const sig = ctx.file + ':' + ctx.line + '|' + ctx.start + '..' + ctx.end + '|' +
  ctx.lines
    .map(l => `${l.no}:${l.cur ? '*' : ''}:${l.text}`)
    .join('\n');
  if (sig === __lastRenderCodeSig) {
      return;
  }
  __lastRenderCodeSig = sig;
  hdr.textContent = ctx.file + ':' + ctx.line + ' (lines ' + ctx.start + '..' + ctx.end + ')';

  const gutter = document.createElement('div');
  gutter.className = 'gutter';
  const lines  = document.createElement('div');
  lines.className = 'lines';

  ctx.lines.forEach(row=>{
    const n = document.createElement('div');
    n.className = row.cur ? 'cur-num' : '';
    n.textContent = row.no;
    gutter.appendChild(n);

    const l = document.createElement('div');
    l.className = 'line' + (row.cur ? ' cur' : '');
    l.innerHTML = row.text.trim() === "" ? "&nbsp;" : row.text;
    lines.appendChild(l);
  });

  view.innerHTML = '';
  view.appendChild(gutter);
  view.appendChild(lines);
}

function escHtml(s){
  return s.replace(/[&<>"']/g, m => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  }[m]));
}

function renderVarNode(key, val, depth=0){
  const container = document.createElement('div');

  if (val === null || typeof val === 'string' || typeof val === 'number' || typeof val === 'boolean') {
    const line = document.createElement('div');
    line.className = 'var-kv';
    line.innerHTML =
      '<span class="var-key">'+escHtml(String(key))+'</span>' +
      ': <span class="var-scalar">'+escHtml(String(val))+'</span>';
    container.appendChild(line);
    return container;
  }

  const isTree = val && typeof val === 'object' && ('__type' in val);
  if (!isTree) {
    const line = document.createElement('div');
    line.className = 'var-kv';
    line.innerHTML =
      '<span class="var-key">'+escHtml(String(key))+'</span>' +
      ': <span class="var-scalar">'+escHtml(JSON.stringify(val))+'</span>';
    container.appendChild(line);
    return container;
  }

  const type = String(val.__type || 'array');
  const summary = ('__summary' in val) ? String(val.__summary) : (type + (val.__children ? '('+Object.keys(val.__children).length+')' : '()'));

  const det = document.createElement('details');
  if (depth <= 1) det.open = true;
  const sum = document.createElement('summary');
  sum.innerHTML =
    '<span class="var-key">'+escHtml(String(key))+'</span>' +
    ': <span class="var-type">'+escHtml(type)+'</span> ' +
    (val.__children ? '' : '<span class="var-summary">'+escHtml(summary)+'</span>');
  det.appendChild(sum);

  if (val.__children) {
    const keys = Object.keys(val.__children);
    for (let i=0;i<keys.length;i++){
      const k = keys[i];
      const child = renderVarNode(k, val.__children[k], depth+1);
      det.appendChild(child);
    }
  } else if (val.__summary) {
    const line = document.createElement('div');
    line.className = 'var-summary';
    line.textContent = val.__summary;
    det.appendChild(line);
  }

  container.appendChild(det);
  return container;
}

function renderVarTree(rootEl, dataObj){
  if (!dataObj || typeof dataObj !== 'object') {
    if (rootEl.__lastSig !== 'null') {
      rootEl.className = 'var-tree';
      rootEl.textContent = '—';
      rootEl.__lastSig = 'null';
    }
    return;
  }

  const sig = stableSig(dataObj);
  if (rootEl.__lastSig === sig) return;
  rootEl.__lastSig = sig;

  rootEl.className = 'var-tree';
  const frag = document.createDocumentFragment();

  const names = Object.keys(dataObj).sort();
  for (let i = 0; i < names.length; i++) {
    const k = names[i];
    frag.appendChild(renderVarNode(k, dataObj[k], 0));
  }
  rootEl.replaceChildren(frag);
}

function stableSig(obj){
  function norm(x){
    if (x === null || typeof x !== 'object') return x;
    if (Array.isArray(x)) return x.map(norm);
    const keys = Object.keys(x).sort();
    const y = {};
    for (let k of keys) y[k] = norm(x[k]);
    return y;
  }
  try { return JSON.stringify(norm(obj)); } catch { return String(obj); }
}
window.addEventListener("load", function(){
    let setBpBtn = document.getElementById("set-bp");
    setBpBtn.addEventListener("click", function(){
        window.localStorage.setItem('php_debug_bp', document.getElementById('bp-filename').value);
    });
    
    if (window.localStorage.getItem('php_debug_bp')) {
        document.getElementById('bp-filename').value = window.localStorage.getItem('php_debug_bp');
    }
});
</script>
HTML;
                    http_reply($conn, 200, $html, 'text/html; charset=utf-8');
                    fclose($conn);
                    continue;
                }

                // API
                $dbg = $state['dbg'];
                if ($path === '/api/kill') {
                    dlog('API kill: start');
                    // Close active session.
                    if ($state['dbg']) {
                        // Try to detach.
                        @fwrite($state['dbg'], "detach -i 1\0");
                        // Close socket.
                        @stream_set_blocking($state['dbg'], false);
                        @fclose($state['dbg']);
                        dlog('API kill: detached & closed active dbg');
                        $state['dbg'] = null;
                    }

                    // Close the remaining connections.
                    $kept = [];
                    foreach ($clients as $h) {
                        if ($h === $dbgSock || $h === $httpSock) {
                            $kept[] = $h;
                            continue;
                        }
                        @fclose($h);
                    }
                    $clients = $kept;

                    $state['last_snapshot'] = null;
                    http_json($conn, ['ok' => true]);
                    fclose($conn);
                    continue;
                }
                if ($path === '/api/status') {
                    if (!$dbg) {
                        http_json($conn, ['attached' => false, 'engine_status' => null]);
                        fclose($conn);
                        continue;
                    }
                    dlog("API status", ['attached' => (bool)$dbg]);
                    $engine_status = null;
                    $auto = null;

                    if ($dbg) {
                        $st = dbgp_cmd_safe($dbg, 'status', [], '', 1.0, 0);
                        if ($st && isset($st['status'])) $engine_status = (string)$st['status'];

                        if ($engine_status === 'stopping' && empty($state['stepping'])) {
                            $frames = [];
                            $stack = dbgp_cmd_safe($dbg, 'stack_get', [], '', 1.0, 1);
                            if ($stack && isset($stack->stack)) {
                                foreach ($stack->stack as $f) {
                                    $frames[] = [
                                        'level' => (int)$f['level'],
                                        'file' => (string)$f['filename'],
                                        'line' => (int)$f['lineno'],
                                        'where' => (string)$f['where'],
                                        'type' => (string)$f['type'],
                                    ];
                                }
                            }

                            if (!$frames) {
                                dlog("API status: stopping without stack, assume script is finishing");
                            }
                            elseif (!is_our_break($frames, array_values($state['desired_bps']))) {
                                dlog("API status: auto-continue (not our stop)", ['top' => $frames[0] ?? null]);
                                dbgp_cmd_safe($dbg, 'run', [], '', 1.0, 0);
                                $engine_status = 'running';
                                $auto = ['auto_continued' => true, 'skipped_top' => $frames[0] ?? null];
                            }
                            else {
                                // Our stop - stay.
                                $state['stepping'] = false;
                            }
                        }
                    }

                    $payload = ['attached' => (bool)$dbg, 'engine_status' => $engine_status];
                    if ($auto) $payload += $auto;
                    http_json($conn, $payload);
                    fclose($conn);
                    continue;
                }

                if ($path === '/api/bp/list') {
                    dlog("API bp/list", ['desired' => count($state['desired_bps'])]);
                    http_json($conn, ['desired' => array_values($state['desired_bps'])]);
                    fclose($conn);
                    continue;
                }
                if (starts_with($path, '/api/bp/clear')) {
                    dlog("API bp/clear");
                    $state['desired_bps'] = [];
                    if ($dbg) {
                        foreach ($state['bps'] as $id => $_) {
                            dbgp_cmd_safe($dbg, 'breakpoint_remove', ['d' => $id], '', 1.0, 0);
                        }
                    }
                    $state['bps'] = [];
                    http_json($conn, ['ok' => true]);
                    fclose($conn);
                    continue;
                }

                if ($path === '/api/bp/set') {
                    $fileRaw = isset($q['file']) ? (string)$q['file'] : '';
                    $lineRaw = isset($q['line']) ? (int)$q['line'] : 0;

                    $parsed = parse_file_ref_and_map($fileRaw, $lineRaw);
                    $file = $parsed['file'];
                    $line = (int)$parsed['line'];
                    $uri = $parsed['uri'];

                    if ($file === '' || $line < 1) {
                        dlog("API bp/set INVALID", ['file' => $file, 'line' => $line, 'qs' => $queryString]);
                        http_json($conn, ['ok' => false, 'error' => 'bad arguments']);
                        fclose($conn);
                        continue;
                    }

                    $uri = path_to_file_uri($file);

                    // Add in queue (even w/out attach)
                    $state['desired_bps'][$file . ':' . $line] = ['file' => $file, 'uri' => $uri, 'line' => $line];

                    $id = null;
                    if ($state['dbg']) {
                        $resp = dbgp_cmd_safe($state['dbg'], 'breakpoint_set', ['t' => 'line', 'f' => $uri, 'n' => $line], '', 1.0, 1);
                        if ($resp && isset($resp['id'])) $id = (string)$resp['id'];
                    }

                    dlog("API bp/set OK", ['file' => $file, 'line' => $line, 'uri' => $uri, 'id' => $id, 'staged' => !$state['dbg']]);
                    http_json($conn, ['ok' => true, 'id' => $id, 'staged' => !$state['dbg']]);
                    fclose($conn);
                    continue;
                }

                if (!$dbg) {
                    http_json($conn, ['error' => 'no dbg attached']);
                    fclose($conn);
                    continue;
                }

                if ($path === '/api/stack') {
                    $skip = [];
                    if (isset($q['skip'])) {
                        if (is_array($q['skip'])) {
                            foreach ($q['skip'] as $v) {
                                $v = trim((string)$v);
                                if ($v !== '') {
                                    $skip[] = $v;
                                }
                            }
                        } else {
                            $parts = explode(',', (string)$q['skip']);
                            foreach ($parts as $v) {
                                $v = trim($v);
                                if ($v !== '') {
                                    $skip[] = $v;
                                }
                            }
                        }
                    }

                    $st = dbgp_cmd_safe($dbg, 'status', [], '', 1.0, 0);
                    $engine_status = ($st && isset($st['status'])) ? (string)$st['status'] : 'unknown';
                    if ($engine_status !== 'break') {
                        http_json($conn, ['status' => $engine_status, 'last_snapshot' => $state['last_snapshot']]);
                        fclose($conn);
                        continue;
                    }

                    $frames = [];
                    $stack = dbgp_cmd_safe($dbg, 'stack_get', [], '', 1.0, 1);
                    if ($stack) {
                        foreach ($stack->stack as $f) {
                            $frames[] = [
                                'level' => (int)$f['level'],
                                'file' => (string)$f['filename'],
                                'line' => (int)$f['lineno'],
                                'where' => (string)$f['where'],
                                'type' => (string)$f['type'],
                            ];
                        }
                    }

                    // No breakpoint and no step -> skip.
                    if (empty($state['stepping']) && !is_our_break($frames, array_values($state['desired_bps']))) {
                        dlog("AUTO-CONT (stack)", ['top' => $frames[0] ?? null]);
                        dbgp_cmd_safe($dbg, 'run', [], '', 1.0, 0);
                        http_json($conn, ['status' => 'running', 'auto_continued' => true, 'skipped_top' => $frames[0] ?? null]);
                        fclose($conn);
                        continue;
                    }

                    $source_ctx = null;
                    if (!empty($frames)) {
                        $top_file = $frames[0]['file'] ?? '';
                        $top_line = (int)($frames[0]['line'] ?? 0);
                        if ($top_file && $top_line > 0) {
                            $source_ctx = read_source_context($top_file, $top_line, 10, 10);
                        }
                    }

                    $locals = collect_context_tree($dbg, 0, 5, 64, $skip);
                    $super = collect_context_tree($dbg, 1, 5, 64, $skip);

                    $snap = [
                        'status' => 'break',
                        'frames' => $frames,
                        'locals' => $locals,
                        'superglobals' => $super,
                        'source' => $source_ctx,
                    ];

                    $state['last_snapshot'] = $snap;

                    // Skip next.
                    if (is_our_break($frames, array_values($state['desired_bps']))) {
                        $state['stepping'] = false;
                    }

                    http_json($conn, $snap);
                    fclose($conn);
                    dlog("API stack: status", ['engine_status' => $engine_status, 'has_snapshot' => $state['last_snapshot'] ? true : false]);
                    continue;
                }

                if ($path === '/api/run') {
                    dbgp_cmd_safe($dbg, 'run', [], '', 1.0, 0);
                    http_json($conn, ['ok' => true]);
                    fclose($conn);
                    continue;
                }
                if ($path === '/api/step_over') {
                    $state['stepping'] = true;
                    dbgp_cmd_safe($dbg, 'step_over', [], '', 1.0, 0);
                    http_json($conn, ['ok' => true]);
                    fclose($conn);
                    continue;
                }
                if ($path === '/api/step_into') {
                    $state['stepping'] = true;
                    dbgp_cmd_safe($dbg, 'step_into', [], '', 1.0, 0);
                    http_json($conn, ['ok' => true]);
                    fclose($conn);
                    continue;
                }
                if ($path === '/api/step_out') {
                    $state['stepping'] = true;
                    dbgp_cmd_safe($dbg, 'step_out', [], '', 1.0, 0);
                    http_json($conn, ['ok' => true]);
                    fclose($conn);
                    continue;
                }

                dlog("API unknown route", ['path' => $path]);
                http_json($conn, ['error' => 'unknown route: ' . $path]);
                fclose($conn);
                continue;
            }
        }

        if ($ready === $state['dbg']) {
            $xml = dbgp_read_packet($state['dbg'], 0.1);

            if ($xml !== null) {
                $sx = @simplexml_load_string($xml);
                if ($sx) {
                    $name = $sx->getName();
                    $status = isset($sx['status']) ? (string)$sx['status'] : '';
                    $command = isset($sx['command']) ? (string)$sx['command'] : '';
                    dlog("DBGp async: packet", ['name' => $name, 'status' => $status, 'command' => $command]);
                }
                else {
                    dlog("DBGp async: non-XML", ['head' => substr($xml, 0, 120)]);
                }
            }

            $meta = stream_get_meta_data($state['dbg']);
            if (!empty($meta['eof'])) {
                $idx = array_search($state['dbg'], $clients, true);
                if ($idx !== false) unset($clients[$idx]);
                @fclose($state['dbg']);
                $state['dbg'] = null;
                dlog("DBGp: CLOSED (eof=1)");
                echo "[DBGp] closed\n";
            }
        }
    }
}
