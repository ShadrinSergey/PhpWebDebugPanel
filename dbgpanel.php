<?php

error_reporting(E_ALL);
set_time_limit(0);

const DBG_LOG_FILE = '/var/log/debugpanel.log';
const DBG_LOG_ENABLED = false;

$DBG_PROJECT_BASE = getenv('DBG_PROJECT_BASE') ?: '';
$DBG_SERVER_BASE = getenv('DBG_SERVER_BASE') ?: '/var/www/html';

function dlog($msg, array $ctx = [])
{
    if (DBG_LOG_ENABLED) {
        $ts = date('Y-m-d H:i:s');
        $pid = getmypid();
        if (!empty($ctx)) {
            $ctx = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line = "[$ts][$pid] $msg | $ctx\n";
        } else {
            $line = "[$ts][$pid] $msg\n";
        }
        @file_put_contents(DBG_LOG_FILE, $line, FILE_APPEND);
        @error_log($line);
    }
}


// ---------- helpers ----------
function starts_with($h, $n)
{
    return strpos($h, $n) === 0;
}

/**
 * Кодирование значения аргумента DBGp (URL-style).
 */
function dbgp_arg($v)
{
    $enc = rawurlencode($v);
    return strtr($enc, [
        '%2F' => '/', '%3A' => ':', '%24' => '$', '%5B' => '[', '%5D' => ']',
        '%5F' => '_', '%2D' => '-', '%2E' => '.', '%2C' => ','
    ]);
}

/**
 * Windows C:\path → file:///C:/path ; Unix /path → file:///path
 */
function path_to_file_uri($path)
{
    if (strpos($path, 'file://') === 0) return $path;
    if (preg_match('#^[A-Za-z]:\\\\#', $path)) {
        $path = str_replace('\\', '/', $path);
        return 'file:///' . $path;
    }
    if ($path !== '' && $path[0] === '/') return 'file://' . $path;
    return $path;
}

function normalize_file_uri($s)
{
    if (!$s) return '';
    return (strpos($s, 'file://') === 0) ? $s : path_to_file_uri($s);
}

function is_our_break(array $frames, array $desired_bps): bool
{
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

function file_uri_to_path($uri)
{
    if (!$uri) return '';
    if (strpos($uri, 'file://') !== 0) return $uri;
    // file:///C:/...  или file:///var/...
    $p = substr($uri, 7);
    // windows-диск: /C:/path → C:/path
    if (preg_match('#^/[A-Za-z]:/#', $p)) $p = substr($p, 1);
    // нормализуем слэши
    return str_replace("\\", "/", $p);
}

function normalize_slashes($p)
{
    return preg_replace('~\\\\+~', '/', (string)$p);
}

function parse_file_ref_and_map($raw, $q_line = 0)
{
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

function html_escape($s)
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Читает файл и возвращает список строк для рендера вокруг $line.
 *
 * @return array{ok:bool, file:string, start:int, end:int, line:int, lines:array<int,array{no:int,cur:bool,text:string}>, error?:string}
 */
function read_source_context($file_or_uri, $line, $above = 10, $below = 10)
{
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

function dbgp_read_packet($sock, $timeout_sec = 3.0)
{
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

function dbgp_cmd_safe($sock, $cmd, $args = [], $data = '', $timeout_sec = 3.0, $retries = 1)
{
    static $txn = 0;
    do {
        $txn++;
        $argStr = '';
        foreach ($args as $k => $v) {
            $argStr .= ' -' . $k . ' ' . dbgp_arg($v);
        }
        $line = $cmd . ' -i ' . $txn . $argStr;
        if ($data !== '') $line .= ' -- ' . base64_encode($data);
        @fwrite($sock, $line . "\0");
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

function prop_scalar_or_summary(SimpleXMLElement $p)
{
    if (isset($p['value'])) return (string)$p['value'];

    if (isset($p['encoding']) && strtolower((string)$p['encoding']) === 'base64') {
        $raw = base64_decode((string)$p, true);
        return $raw !== false ? $raw : '';
    }

    $txt = trim((string)$p);
    if ($txt !== '') return $txt;

    $children = isset($p['children']) ? (int)$p['children'] : 0;
    if ($children === 1) {
        $t = isset($p['type']) ? (string)$p['type'] : 'array';
        $n = isset($p['numchildren']) ? (int)$p['numchildren'] : 0;
        return $t . '(' . $n . ')';
    }

    return '';
}

function prop_fetch($sock, SimpleXMLElement $p, $expand_children = true, $max_children = 64, $ctx_id = 0)
{
    $val = prop_scalar_or_summary($p);
    $has_children = isset($p['children']) ? ((int)$p['children'] === 1) : false;

    if (!$has_children && $val === '' && isset($p['fullname'])) {
        $resp = dbgp_cmd_safe($sock, 'property_get', [
            'n' => (string)$p['fullname'], 'd' => 0, 'c' => $ctx_id
        ], '', 2.0, 1);
        if ($resp && isset($resp->property)) $val = prop_scalar_or_summary($resp->property);
    }

    if ($expand_children && $has_children && isset($p['fullname'])) {
        $out = [];
        $resp = dbgp_cmd_safe($sock, 'property_get', [
            'n' => (string)$p['fullname'], 'd' => 0, 'c' => $ctx_id
        ], '', 2.0, 1);
        if ($resp && isset($resp->property->property)) {
            foreach ($resp->property->property as $child) {
                $name = isset($child['name']) ? (string)$child['name'] : '';
                $out[$name] = prop_scalar_or_summary($child);
            }
            return $out;
        }
    }
    return $val;
}

// ---------- sockets ----------
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

// ---------- state ----------
$state = ['dbg' => null, 'init' => null, 'bps' => [], 'desired_bps' => [], 'last_snapshot' => null, 'stepping' => false];
echo "DBGp on $DBG_HOST:$DBG_PORT | Web UI on http://$HTTP_HOST:$HTTP_PORT\n";

// ---------- http helpers ----------
function http_reply($c, $code, $body, $ctype = 'application/json; charset=utf-8')
{
    fwrite($c, "HTTP/1.1 $code OK\r\nContent-Type: $ctype\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
}

function http_json($c, $obj)
{
    http_reply($c, 200, json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ---------- main loop ----------
$clients = [$dbgSock, $httpSock];

while (true) {
    $r = $clients;
    $w = $e = null;
    stream_select($r, $w, $e, null);

    foreach ($r as $ready) {

        // New attach.
        dlog("DBGp: ATTACH");
        dlog("DBGp: init", ['head' => substr($init ?? '', 0, 200)]);
        if ($ready === $dbgSock) {
            $conn = stream_socket_accept($dbgSock, 0);
            if ($conn) {
                stream_set_blocking($conn, true);
                $init = dbgp_read_packet($conn, 3.0);
                $state['dbg'] = $conn;
                $state['init'] = $init;
                $state['last_snapshot'] = null;
                echo "[DBGp] attached\n";
                $clients[] = $conn;

                foreach ([['max_children', 256], ['max_data', 8192], ['max_depth', 5], ['extended_properties', 1], ['show_hidden', 1]] as $f) {
                    dbgp_cmd_safe($conn, 'feature_set', ['n' => $f[0], 'v' => $f[1]], '', 1.0, 0);
                    dlog("DBGp: features set");
                }
                dbgp_cmd_safe($conn, 'feature_set', ['n' => 'resolved_breakpoints', 'v' => 1], '', 1.0, 0);

                $state['bps'] = [];
                foreach ($state['desired_bps'] as $bp) {
                    $uri = $bp['uri'] ?? path_to_file_uri($bp['file']);
                    $resp = dbgp_cmd_safe($conn, 'breakpoint_set', ['t' => 'line', 'f' => $uri, 'n' => $bp['line']], '', 1.5, 1);
                    if ($resp && isset($resp['id'])) {
                        $id = (string)$resp['id'];
                        if ($id !== '') $state['bps'][$id] = ['file' => $bp['file'], 'uri' => $uri, 'line' => $bp['line']];
                    }
                }
                dlog("DBGp: desired_bps applied", ['count' => count($state['bps']), 'desired' => count($state['desired_bps'])]);
                dbgp_cmd_safe($conn, 'run', [], '', 1.0, 0);
                dlog("DBGp: send run");
            }
            continue;
        }

        // ---- http ----
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
                if ($queryString !== '') parse_str($queryString, $q);
                if (!empty($q['restart'])) {
                    exit(1);
                }
                dlog("HTTP request", [
                    'raw' => trim(strtok($req, "\r")),
                    'path' => $path,
                    'query' => $queryString,
                    'orig' => $requestTarget
                ]);
                dlog("HTTP request", ['raw' => trim(strtok($req, "\r")), 'path' => $path]);
                if (strpos($path, '/dbg/') === 0) {
                    $path = substr($path, 4);
                }

                if ($path === '/' || $path === '/index.html') {
                    $html = <<<HTML
<!doctype html><meta charset="utf-8">
<title>PHP Web Debug (Xdebug + DBGp)</title>
<style>
body{font:16px/1.45 system-ui;margin:24px}
input,button{font:inherit}
pre{background:#111;color:#ddd;padding:12px;border-radius:6px;overflow:auto;max-height:60vh}
#codewrap{margin-top:12px;border:1px solid #2a2a2a;border-radius:8px;overflow:hidden;background:#1e1f22}
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
</style>
<h1>PHP Web Debug (Xdebug + DBGp)</h1>
<p>Статус DBGp: <b id="status">…</b></p>
<form id="bpForm" style="margin-bottom:8px">
  <input name="file" placeholder="C:\\\\Apache24\\\\htdocs\\\\server.php" size="120" required>
  <input name="line" type="number" placeholder="5" min="1">
  <button>Set breakpoint</button>
  <button type="button" id="btnList">List</button>
  <button type="button" id="btnClear">Clear all</button>
</form>
<p>
  <button id="btnCont">Continue</button>
  <button id="btnStepOver">Step over</button>
  <button id="btnStepInto">Step into</button>
  <button id="btnStepOut">Step out</button>
</p>
<div id="codewrap">
  <div id="codehdr"></div>
  <div id="codeview" class="codepane"></div>
</div>
<pre id="stack"></pre>
<script>
async function api(p){const r=await fetch(p);return r.json()}
async function refresh(){
  try{
    const s = await api('/dbg/api/status');
    document.getElementById('status').textContent = s.attached? ('attached / '+(s.engine_status||'unknown')) : 'waiting…';

    const st = await api('/dbg/api/stack');
    document.getElementById('stack').textContent = JSON.stringify(st, null, 2);

    if (st && st.status === 'break' && st.source) {
      renderCode(st.source);
    } else {
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

setInterval(refresh, 900); refresh();

function renderCode(ctx){
  const hdr  = document.getElementById('codehdr');
  const view = document.getElementById('codeview');
  if (!ctx || !ctx.ok) {
    hdr.textContent = '—';
    view.innerHTML = '';
    return;
  }
  
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
    l.innerHTML = row.text;
    lines.appendChild(l);
  });

  view.innerHTML = '';
  view.appendChild(gutter);
  view.appendChild(lines);
}
</script>
HTML;
                    http_reply($conn, 200, $html, 'text/html; charset=utf-8');
                    fclose($conn);
                    continue;
                }

                // API
                $dbg = $state['dbg'];
                if ($path === '/api/status') {
                    dlog("API status", ['attached' => (bool)$dbg]);
                    $engine_status = null;
                    $auto = null;

                    if ($dbg) {
                        $st = dbgp_cmd_safe($dbg, 'status', [], '', 1.0, 0);
                        if ($st && isset($st['status'])) $engine_status = (string)$st['status'];

                        if ($engine_status === 'stopping' && empty($state['stepping'])) {
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
                            if (!is_our_break($frames, array_values($state['desired_bps']))) {
                                dlog("API status: auto-continue (not our stop)", ['top' => $frames[0] ?? null]);
                                dbgp_cmd_safe($dbg, 'run', [], '', 1.0, 0);
                                $engine_status = 'running';
                                $auto = ['auto_continued' => true, 'skipped_top' => $frames[0] ?? null];
                            } else {
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

                    // Take snapshot.
                    $locals = [];
                    $super = [];

                    $ctx0 = dbgp_cmd_safe($dbg, 'context_get', ['d' => 0, 'c' => 0], '', 1.0, 1);
                    if ($ctx0) foreach ($ctx0->property as $p) {
                        $name = isset($p['name']) ? (string)$p['name'] : '';
                        $locals[$name] = prop_fetch($dbg, $p, true, 64, 0);
                    }

                    $ctx1 = dbgp_cmd_safe($dbg, 'context_get', ['d' => 0, 'c' => 1], '', 1.0, 1);
                    if ($ctx1) foreach ($ctx1->property as $p) {
                        $name = isset($p['name']) ? (string)$p['name'] : '';
                        $super[$name] = prop_fetch($dbg, $p, true, 64, 1);
                    }

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

        // ---- DBGp async events ----
        if ($ready === $state['dbg']) {
            $xml = dbgp_read_packet($state['dbg'], 0.5);
            if ($xml === null) {
                dlog("DBGp async: idle", []);
                continue;
            } else {
                $sx = @simplexml_load_string($xml);
                if ($sx) {
                    $name = $sx->getName();
                    $status = isset($sx['status']) ? (string)$sx['status'] : '';
                    $command = isset($sx['command']) ? (string)$sx['command'] : '';
                    dlog("DBGp async: packet", ['name' => $name, 'status' => $status, 'command' => $command, 'head' => substr($xml, 0, 120)]);
                } else {
                    dlog("DBGp async: non-XML", ['head' => substr($xml, 0, 120)]);
                }
            }
            $xml = dbgp_read_packet($state['dbg'], 0.5);
            if ($xml === null) {
                $idx = array_search($state['dbg'], $clients, true);
                if ($idx !== false) unset($clients[$idx]);
                $state['dbg'] = null;
                dlog("DBGp: CLOSED");
                echo "[DBGp] closed\n";
            }
        }
    }
}
