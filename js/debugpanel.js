function setCookie(name, value) {
    document.cookie = name + '=' + value + '; path=/';
}

function deleteCookie(name) {
    document.cookie = name + '=; Max-Age=0; path=/';
}

function hasCookie(name) {
    return document.cookie.split(';').some(c => c.trim().startsWith(name + '='));
}

function updateToggleUI() {
    const toggle = document.getElementById('xdebug-toggle');
    const trigger = document.getElementById('xdebug-trigger');
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

updateToggleUI();

async function api(p) {
    const r = await fetch(p);
    return r.json()
}

let $autoContinueCount = 0;
const urlParams = new URLSearchParams(window.location.search);
const skipParams = new URLSearchParams();
for (const v of urlParams.getAll('skip')) skipParams.append('skip', v);
for (const v of urlParams.getAll('skip[]')) skipParams.append('skip[]', v);
const STACK_SKIP_QS = skipParams.toString() ? ('?' + skipParams.toString()) : '';
let refreshTimeout = null;

async function refresh() {
    try {
        if (refreshTimeout) {
            clearTimeout(refreshTimeout);
            refreshTimeout = null;
        }
        const s = await api('/dbg/api/status');
        document.getElementById('status').textContent = s.attached ? ('attached / ' + (s.engine_status || 'unknown')) : 'waiting…';

        if (s.attached && s.engine_status === 'stopping') {
            $autoContinueCount++;
            if ($autoContinueCount >= 3) {
                $autoContinueCount = 0;
                await api('/dbg/api/run');
                return;
            } else {
                refreshTimeout = setTimeout(refresh, 300);
            }
        }

        const st = await api('/dbg/api/stack' + STACK_SKIP_QS);
        const localsEl = document.getElementById('vars-locals');
        const superEl = document.getElementById('vars-super');
        renderStack(st);

        if (st && st.status === 'break' && st.source) {

            renderVarTree(localsEl, st.locals);
            renderVarTree(superEl, st.superglobals);
            renderCode(st.source);
        } else {
            localsEl.innerHTML = '';
            superEl.innerHTML = '';
            renderCode(null);
        }
    } catch (e) {
        document.getElementById('status').textContent = 'error';
    }
}

document.getElementById('bpForm').onsubmit = async (e) => {
    e.preventDefault();
    const f = new FormData(e.target);
    await api('/dbg/api/bp/set?file=' + encodeURIComponent(f.get('file')) + '&line=' + encodeURIComponent(f.get('line')));
    await refresh();
};
document.getElementById('btnList').onclick = () => api('/dbg/api/bp/list').then(x => alert(JSON.stringify(x, null, 2)));
document.getElementById('btnClear').onclick = () => api('/dbg/api/bp/clear').then(refresh);

document.getElementById('btnCont').onclick = () => api('/dbg/api/run').then(refresh);
document.getElementById('btnStepOver').onclick = () => api('/dbg/api/step_over').then(refresh);
document.getElementById('btnStepInto').onclick = () => api('/dbg/api/step_into').then(refresh);
document.getElementById('btnStepOut').onclick = () => api('/dbg/api/step_out').then(refresh);

setInterval(refresh, 5000);
refresh();

document.getElementById('btnKill').onclick = async () => {
    try {
        await fetch('/dbg/api/kill', {cache: 'no-store'});
    } catch (e) {
    }
    setTimeout(refresh, 100);
};

document.getElementById('btnRestart').onclick = async () => {
    try {
        await fetch('/dbg/?restart=1', {cache: 'no-store'});
    } catch (e) {
    }
    setTimeout(window.location.reload, 500);
};

let __lastStackSig = null;

function renderStack(st) {
    const el = document.getElementById('stack-body');
    const view = (() => {
        if (!st || typeof st !== 'object') return st;

        const v = {
            status: st.status,
            frames: Array.isArray(st.frames) ? st.frames.slice() : undefined,
            source: st.source
        };

        if (Array.isArray(v.frames)) {
            v.frames.sort((a, b) => (a.level | 0) - (b.level | 0));
        }

        return v;
    })();

    const sig = stableSig(view);
    if (sig === __lastStackSig) return;
    __lastStackSig = sig;

    el.textContent = JSON.stringify(view, null, 2);
}

let __lastRenderCodeSig = null;

function renderCode(ctx) {
    const hdr = document.getElementById('codehdr');
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
    const lines = document.createElement('div');
    lines.className = 'lines';

    ctx.lines.forEach(row => {
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

function escHtml(s) {
    return s.replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[m]));
}

function renderVarNode(key, val, depth = 0) {
    const container = document.createElement('div');

    if (val === null || typeof val === 'string' || typeof val === 'number' || typeof val === 'boolean') {
        const line = document.createElement('div');
        line.className = 'var-kv';
        line.innerHTML =
            '<span class="var-key">' + escHtml(String(key)) + '</span>' +
            ': <span class="var-scalar">' + escHtml(String(val)) + '</span>';
        container.appendChild(line);
        return container;
    }

    const isTree = val && typeof val === 'object' && ('__type' in val);
    if (!isTree) {
        const line = document.createElement('div');
        line.className = 'var-kv';
        line.innerHTML =
            '<span class="var-key">' + escHtml(String(key)) + '</span>' +
            ': <span class="var-scalar">' + escHtml(JSON.stringify(val)) + '</span>';
        container.appendChild(line);
        return container;
    }

    const type = String(val.__type || 'array');
    const summary = ('__summary' in val) ? String(val.__summary) : (type + (val.__children ? '(' + Object.keys(val.__children).length + ')' : '()'));

    const det = document.createElement('details');
    det.open = false;
    const sum = document.createElement('summary');
    sum.innerHTML =
        '<span class="var-key">' + escHtml(String(key)) + '</span>' +
        ': <span class="var-type">' + escHtml(type) + '</span> ' +
        (val.__children ? '' : '<span class="var-summary">' + escHtml(summary) + '</span>');
    det.appendChild(sum);

    if (val.__children) {
        const keys = Object.keys(val.__children);
        for (let i = 0; i < keys.length; i++) {
            const k = keys[i];
            const child = renderVarNode(k, val.__children[k], depth + 1);
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

function renderVarTree(rootEl, dataObj) {
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

function stableSig(obj) {
    function norm(x) {
        if (x === null || typeof x !== 'object') return x;
        if (Array.isArray(x)) return x.map(norm);
        const keys = Object.keys(x).sort();
        const y = {};
        for (let k of keys) y[k] = norm(x[k]);
        return y;
    }

    try {
        return JSON.stringify(norm(obj));
    } catch {
        return String(obj);
    }
}

window.addEventListener("load", function () {
    let setBpBtn = document.getElementById("set-bp");
    setBpBtn.addEventListener("click", function () {
        window.localStorage.setItem('php_debug_bp', document.getElementById('bp-filename').value);
    });

    if (window.localStorage.getItem('php_debug_bp')) {
        document.getElementById('bp-filename').value = window.localStorage.getItem('php_debug_bp');
    }
});
