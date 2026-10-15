'use strict';

function setCookie(name, value) {
    document.cookie = name + '=' + value + '; path=/';
}

function deleteCookie(name) {
    document.cookie = name + '=; Max-Age=0; path=/';
}

function hasCookie(name) {
    return document.cookie.split(';').some(function (cookie) {
        return cookie.trim().startsWith(name + '=');
    });
}

function updateToggleUI() {
    const toggle = document.getElementById('xdebug-toggle');
    const trigger = document.getElementById('xdebug-trigger');

    if (!toggle || !trigger) {
        return;
    }

    if (hasCookie('XDEBUG_TRIGGER')) {
        toggle.style.background = '#4caf50';
        trigger.style.left = '38px';
    } else {
        toggle.style.background = '#ccc';
        trigger.style.left = '2px';
    }
}

function toggleXdebug() {
    if (hasCookie('XDEBUG_TRIGGER')) {
        deleteCookie('XDEBUG_TRIGGER');
        deleteCookie('XDEBUG_CMD');
    } else {
        setCookie('XDEBUG_TRIGGER', 1);
        setCookie('XDEBUG_CMD', 1);
    }

    updateToggleUI();
}

async function api(path) {
    const response = await fetch(path, {
        cache: 'no-store'
    });
    const result = await response.json();

    if (!response.ok) {
        throw new Error(result.error || 'HTTP ' + response.status);
    }

    return result;
}

const urlParams = new URLSearchParams(window.location.search);
const skipParams = new URLSearchParams();

for (const value of urlParams.getAll('skip')) {
    skipParams.append('skip', value);
}

for (const value of urlParams.getAll('skip[]')) {
    skipParams.append('skip[]', value);
}

const STACK_SKIP_QS = skipParams.toString() ? '?' + skipParams.toString() : '';

// Only one status/stack polling cycle may run at a time. An overlapping
// request schedules one additional cycle instead of racing its DOM changes.
let refreshPromise = null;
let refreshQueued = false;
let renderEpoch = 0;
let lastStackSignature = null;
let lastCodeSignature = null;

function resetVariableTree(element) {
    element.replaceChildren();
    element.__lastSig = null;
}

function resetPausedView(status) {
    const locals = document.getElementById('vars-locals');
    const superglobals = document.getElementById('vars-super');

    resetVariableTree(locals);
    resetVariableTree(superglobals);
    renderCode(null);
    renderStack({
        status: status || 'running',
        frames: [],
        source: null
    });
}

function beginDebuggerAction() {
    // A reply from a refresh initiated before this command must not repaint
    // the breakpoint and its variable trees after execution has resumed.
    renderEpoch++;
    resetPausedView('running');
    document.getElementById('status').textContent = 'working…';
}

async function refreshOnce() {
    const epoch = renderEpoch;

    try {
        const session = await api('/dbg/api/status');

        if (epoch !== renderEpoch) {
            return;
        }

        window.__DBG_PROPERTY_MAX_DATA = Number(session.property_max_data) || (4 * 1024 * 1024);

        const snapshot = await api('/dbg/api/stack' + STACK_SKIP_QS);

        if (epoch !== renderEpoch) {
            return;
        }

        // The status can change between these two requests. Prefer the stack
        // response for the state displayed alongside the captured variables.
        const status = session.attached
            ? (snapshot.status || session.engine_status || 'unknown')
            : 'waiting…';
        document.getElementById('status').textContent = session.attached
            ? 'attached / ' + status
            : status;

        renderSafely('stack', function () {
            renderStack(snapshot);
        });

        if (snapshot && snapshot.status === 'break') {
            // Source availability must not determine whether locals or
            // superglobals are rendered. Each pane is independent.
            renderSafely('source', function () {
                renderCode(snapshot.source || null);
            });

            renderSafely('locals', function () {
                renderVarTree(
                    document.getElementById('vars-locals'),
                    snapshot.locals,
                    snapshot.generation
                );
            });

            renderSafely('superglobals', function () {
                renderVarTree(
                    document.getElementById('vars-super'),
                    snapshot.superglobals,
                    snapshot.generation
                );
            });
        } else {
            resetVariableTree(document.getElementById('vars-locals'));
            resetVariableTree(document.getElementById('vars-super'));
            renderCode(null);
        }
    } catch (error) {
        if (epoch === renderEpoch) {
            document.getElementById('status').textContent = 'error: ' + error.message;
            console.error('Debugger refresh failed:', error);
        }
    }
}

function renderSafely(name, callback) {
    try {
        callback();
    } catch (error) {
        // One broken pane must never prevent other panes from being painted.
        console.error('Debugger rendering failed (' + name + '):', error);
    }
}

function refresh() {
    if (refreshPromise !== null) {
        refreshQueued = true;
        return refreshPromise;
    }

    refreshPromise = (async function () {
        try {
            do {
                refreshQueued = false;
                await refreshOnce();
            } while (refreshQueued);
        } finally {
            refreshPromise = null;

            // Handle the edge case where a call queued a refresh just as the
            // previous polling cycle was finishing.
            if (refreshQueued) {
                refresh();
            }
        }
    })();

    return refreshPromise;
}

function renderStack(snapshot) {
    const element = document.getElementById('stack-body');
    let view = snapshot;

    if (snapshot && typeof snapshot === 'object') {
        view = {
            status: snapshot.status,
            frames: Array.isArray(snapshot.frames) ? snapshot.frames.slice() : undefined,
            source: snapshot.source
        };

        if (Array.isArray(view.frames)) {
            view.frames.sort(function (a, b) {
                return (a.level | 0) - (b.level | 0);
            });
        }
    }

    const signature = JSON.stringify(view);

    if (signature === lastStackSignature && element.textContent !== '') {
        return;
    }

    element.textContent = JSON.stringify(view, null, 2);
    lastStackSignature = signature;
}

function renderCode(context) {
    const header = document.getElementById('codehdr');
    const panel = document.getElementById('codeview');

    if (!context || !context.ok || !Array.isArray(context.lines)) {
        header.textContent = '—';
        panel.replaceChildren();
        lastCodeSignature = null;
        return;
    }

    const signature = JSON.stringify([
        context.file,
        context.line,
        context.start,
        context.end,
        context.lines
    ]);

    if (signature === lastCodeSignature && panel.childNodes.length > 0) {
        return;
    }

    const gutter = document.createElement('div');
    gutter.className = 'gutter';
    const lines = document.createElement('div');
    lines.className = 'lines';

    for (const row of context.lines) {
        const number = document.createElement('div');
        number.className = row.cur ? 'cur-num' : '';
        number.textContent = String(row.no);
        gutter.appendChild(number);

        const text = document.createElement('div');
        text.className = 'line' + (row.cur ? ' cur' : '');

        // Source text is HTML-escaped in read_source_context() on the backend.
        const escapedSource = String(row.text === undefined ? '' : row.text);
        text.innerHTML = escapedSource.trim() === '' ? '&nbsp;' : escapedSource;
        lines.appendChild(text);
    }

    panel.replaceChildren(gutter, lines);
    header.textContent = context.file + ':' + context.line +
        ' (lines ' + context.start + '..' + context.end + ')';
    lastCodeSignature = signature;
}

function escHtml(value) {
    return String(value).replace(/[&<>"']/g, function (character) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[character];
    });
}

// Kept as a fallback: debugpanel.inspector.js replaces this renderer for
// expandable DBGp properties when loaded after debugpanel.js.
function renderVarNode(key, value, depth = 0) {
    const container = document.createElement('div');

    if (value === null || typeof value !== 'object') {
        const line = document.createElement('div');
        line.className = 'var-kv';
        line.innerHTML = '<span class="var-key">' + escHtml(key) + '</span>' +
            ': <span class="var-scalar">' + escHtml(value) + '</span>';
        container.appendChild(line);
        return container;
    }

    if (!('__type' in value)) {
        const line = document.createElement('div');
        line.className = 'var-kv';
        line.innerHTML = '<span class="var-key">' + escHtml(key) + '</span>' +
            ': <span class="var-scalar">' + escHtml(JSON.stringify(value)) + '</span>';
        container.appendChild(line);
        return container;
    }

    const details = document.createElement('details');
    const summary = document.createElement('summary');
    const type = String(value.__type || 'array');
    const label = Object.prototype.hasOwnProperty.call(value, '__summary')
        ? String(value.__summary)
        : type + (value.__children ? '(' + Object.keys(value.__children).length + ')' : '()');

    summary.innerHTML = '<span class="var-key">' + escHtml(key) + '</span>' +
        ': <span class="var-type">' + escHtml(type) + '</span> ' +
        (value.__children ? '' : '<span class="var-summary">' + escHtml(label) + '</span>');
    details.appendChild(summary);

    if (value.__children) {
        for (const childKey of Object.keys(value.__children)) {
            details.appendChild(renderVarNode(childKey, value.__children[childKey], depth + 1));
        }
    } else if (value.__summary) {
        const line = document.createElement('div');
        line.className = 'var-summary';
        line.textContent = String(value.__summary);
        details.appendChild(line);
    }

    container.appendChild(details);
    return container;
}

function renderVarTree(element, data, generation) {
    if (!data || typeof data !== 'object') {
        element.textContent = '—';
        element.__lastSig = null;
        return;
    }

    // Every captured pause has its own generation. Using it avoids recursively
    // sorting/stringifying potentially huge trees at every 5s poll.
    const signature = Number.isInteger(generation)
        ? 'generation:' + generation
        : 'legacy:' + JSON.stringify(data);

    if (element.__lastSig === signature && element.childNodes.length > 0) {
        return;
    }

    const fragment = document.createDocumentFragment();
    const names = Object.keys(data).sort();

    if (names.length === 0) {
        const empty = document.createElement('span');
        empty.textContent = '—';
        fragment.appendChild(empty);
    } else {
        for (const name of names) {
            fragment.appendChild(window.renderVarNode(name, data[name], 0));
        }
    }

    // Set the cache only after a successful, complete DOM replacement.
    element.replaceChildren(fragment);
    element.__lastSig = signature;
}

async function executeDebuggerCommand(path) {
    beginDebuggerAction();

    try {
        await api(path);
    } catch (error) {
        console.error('Debugger command failed:', error);
        document.getElementById('status').textContent = 'error: ' + error.message;
    }

    await refresh();
}

async function setBreakpoint(event) {
    event.preventDefault();

    const form = new FormData(event.target);
    const params = new URLSearchParams();
    const message = document.getElementById('bp-message');

    params.set('file', form.get('file'));
    params.set('condition', form.get('condition') || '');

    if (form.get('line')) {
        params.set('line', form.get('line'));
    }

    message.textContent = '';

    try {
        const result = await api('/dbg/api/bp/set?' + params.toString());

        if (!result.ok) {
            throw new Error(result.error || 'Breakpoint was not saved');
        }

        if (result.error) {
            message.textContent = 'Xdebug: ' + result.error;
        } else {
            message.textContent = result.condition
                ? 'Conditional breakpoint saved'
                : 'Breakpoint saved';
        }

        await refresh();
    } catch (error) {
        message.textContent = 'Error: ' + error.message;
    }
}

function initializeDebugPanel() {
    updateToggleUI();

    document.getElementById('bpForm').addEventListener('submit', setBreakpoint);

    document.getElementById('btnList').addEventListener('click', async function () {
        try {
            const list = await api('/dbg/api/bp/list');
            alert(JSON.stringify(list, null, 2));
        } catch (error) {
            console.error(error);
        }
    });

    document.getElementById('btnClear').addEventListener('click', async function () {
        try {
            await api('/dbg/api/bp/clear');
            await refresh();
        } catch (error) {
            console.error(error);
        }
    });

    const buttons = {
        btnCont: '/dbg/api/run',
        btnStepOver: '/dbg/api/step_over',
        btnStepInto: '/dbg/api/step_into',
        btnStepOut: '/dbg/api/step_out'
    };

    for (const id of Object.keys(buttons)) {
        document.getElementById(id).addEventListener('click', function () {
            executeDebuggerCommand(buttons[id]);
        });
    }

    document.getElementById('btnKill').addEventListener('click', async function () {
        beginDebuggerAction();

        try {
            await api('/dbg/api/kill');
        } catch (error) {
            console.error(error);
        }

        await refresh();
    });

    document.getElementById('btnRestart').addEventListener('click', async function () {
        beginDebuggerAction();

        try {
            await fetch('/dbg/?restart=1', {
                cache: 'no-store'
            });
        } catch (error) {
            // The expected restart closes the server socket before replying.
        }

        setTimeout(function () {
            window.location.reload();
        }, 2000);
    });

    const filename = document.getElementById('bp-filename');
    const savedFilename = window.localStorage.getItem('php_debug_bp');

    if (savedFilename) {
        filename.value = savedFilename;
    }

    document.getElementById('set-bp').addEventListener('click', function () {
        window.localStorage.setItem('php_debug_bp', filename.value);
    });

    // Start only after both scripts have been evaluated.
    refresh();
    setInterval(refresh, 5000);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeDebugPanel, {
        once: true
    });
} else {
    initializeDebugPanel();
}
