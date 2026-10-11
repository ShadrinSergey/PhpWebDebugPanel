<!doctype html>
<meta charset="utf-8">
<title>PHP Web Debug (Xdebug + DBGp)</title>
<h1>PHP Web Xdebug</h1>
<p>Статус DBGp: <b id="status">…</b></p>
<div id="xdebug-toggle" onclick="toggleXdebug()">
    <div id="xdebug-trigger"></div>
</div>

<form id="bpForm" style="margin-bottom:8px">
    <label for="bp-filename">
        <input id="bp-filename" name="file" placeholder="/var/www/html/index.php:3" size="120" required>
    </label>
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
<link rel="stylesheet" href="css/debugpanel.css"/>
<script src="js/debugpanel.js"></script>
