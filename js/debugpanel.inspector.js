(function () {
    'use strict';

    function createElement(tag, className, text) {
        const element = document.createElement(tag);

        if (className) {
            element.className = className;
        }

        if (text !== undefined) {
            element.textContent = String(text);
        }

        return element;
    }

    function getSummary(value) {
        if (value.__summary) {
            return String(value.__summary);
        }

        const type = value.__type || 'value';
        const count = Number(value.__numchildren);

        if (Number.isFinite(count)) {
            return type + '(' + count + ')';
        }

        return type;
    }

    function enableVariableInspector() {
        if (window.__DBG_INSPECTOR_INSTALLED) {
            return;
        }

        if (typeof window.renderVarNode !== 'function') {
            console.error('debugpanel.js must be loaded before debugpanel.inspector.js');
            return;
        }

        window.__DBG_INSPECTOR_INSTALLED = true;

        const originalRenderVarNode = window.renderVarNode;

        window.renderVarNode = function (key, value, depth = 0) {
            if (!value || typeof value !== 'object' || !('__type' in value)) {
                return originalRenderVarNode(key, value, depth);
            }

            const root = createElement('div');
            const details = createElement('details');
            const summary = createElement('summary');
            const content = createElement('div');

            summary.appendChild(createElement('span', 'var-key', key));
            summary.appendChild(document.createTextNode(': '));
            summary.appendChild(createElement('span', 'var-type', value.__type));
            summary.appendChild(document.createTextNode(' '));
            summary.appendChild(createElement('span', 'var-summary', getSummary(value)));

            details.appendChild(summary);
            details.appendChild(content);
            root.appendChild(details);

            const fullname = value.__fullname;
            let busy = false;
            let initialized = false;

            async function requestProperty(page, maxData) {
                const params = new URLSearchParams({
                    name: fullname,
                    ctx: value.__context,
                    generation: value.__generation,
                    page: page,
                    max_data: maxData
                });
                const response = await fetch('/dbg/api/property?' + params.toString(), {
                    cache: 'no-store'
                });
                const result = await response.json();

                if (!response.ok || !result.ok) {
                    throw new Error(result.error || 'Cannot fetch property');
                }

                return result.property;
            }

            const isContainer = value.__children && typeof value.__children === 'object';

            if (isContainer) {
                const total = Number(value.__numchildren) || 0;
                const seen = new Set();
                let loaded = 0;
                let nextPage = value.__next_page;
                let moreButton = null;

                function appendChildren(children) {
                    for (const [childKey, childValue] of Object.entries(children || {})) {
                        const childId = childValue && childValue.__fullname
                            ? childValue.__fullname
                            : childKey;

                        if (seen.has(childId)) {
                            continue;
                        }

                        seen.add(childId);
                        content.appendChild(window.renderVarNode(childKey, childValue, depth + 1));
                        loaded++;
                    }
                }

                function updateMoreButton() {
                    if (moreButton) {
                        moreButton.remove();
                    }

                    moreButton = null;
                    if (nextPage === null || nextPage === undefined || !fullname) {
                        return;
                    }

                    const label = loaded
                        ? 'Загрузить ещё (' + loaded + ' из ' + total + ')'
                        : 'Загрузить элементы (' + total + ')';

                    moreButton = createElement('button', 'dbg-load-more', label);
                    moreButton.type = 'button';
                    moreButton.style.cssText = 'margin:6px 0;padding:4px 8px;cursor:pointer';
                    moreButton.addEventListener('click', async function (event) {
                        event.preventDefault();
                        event.stopPropagation();

                        if (busy) {
                            return;
                        }

                        busy = true;
                        const button = moreButton;
                        button.disabled = true;
                        const page = nextPage;

                        try {
                            const result = await requestProperty(page, 4096);
                            appendChildren(result.__children);
                            nextPage = result.__next_page;

                            if (nextPage === page) {
                                nextPage = null;
                            }

                            updateMoreButton();
                        } catch (error) {
                            button.disabled = false;
                            button.textContent = 'Повторить: ' + error.message;
                        } finally {
                            busy = false;
                        }
                    });
                    content.appendChild(moreButton);
                }

                details.addEventListener('toggle', function () {
                    if (!details.open || initialized) {
                        return;
                    }

                    initialized = true;
                    appendChildren(value.__children);
                    updateMoreButton();
                });
            } else {
                // Long values are retrieved only on demand, not on each refresh.
                const defaultMaxData = 4 * 1024 * 1024;
                const configuredMaxData = Number(window.__DBG_PROPERTY_MAX_DATA) || defaultMaxData;
                let requestedBytes = Math.min(65536, configuredMaxData);

                const output = createElement(
                    'pre',
                    'var-scalar',
                    value.__value === undefined ? '' : value.__value
                );
                output.style.cssText = 'white-space:pre-wrap;overflow-wrap:anywhere;max-height:50vh;overflow:auto';

                const loadButton = createElement('button', 'dbg-load-full', 'Показать значение');
                loadButton.type = 'button';
                loadButton.style.cssText = 'margin:6px 0;padding:4px 8px;cursor:pointer';
                content.appendChild(output);

                if (value.__lazy_value && fullname) {
                    content.appendChild(loadButton);
                }

                async function fetchValue() {
                    if (busy) {
                        return;
                    }

                    busy = true;
                    loadButton.disabled = true;

                    try {
                        const result = await requestProperty(0, requestedBytes);
                        output.textContent = result.__value === undefined ? '' : result.__value;

                        if (result.__truncated) {
                            if (requestedBytes < configuredMaxData) {
                                requestedBytes = Math.min(requestedBytes * 4, configuredMaxData);
                                loadButton.textContent = 'Загрузить больше (до ' +
                                    Math.round(requestedBytes / 1024) + ' КБ)';
                                loadButton.disabled = false;
                            } else {
                                loadButton.textContent = 'Часть значения; увеличьте DBG_PROPERTY_MAX_DATA';
                            }
                        } else {
                            loadButton.remove();
                        }
                    } catch (error) {
                        loadButton.textContent = 'Повторить: ' + error.message;
                        loadButton.disabled = false;
                    } finally {
                        busy = false;
                    }
                }

                loadButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    fetchValue();
                });

                details.addEventListener('toggle', function () {
                    if (!initialized && details.open) {
                        initialized = true;

                        if (value.__lazy_value && fullname) {
                            fetchValue();
                        }
                    }
                });
            }

            return root;
        };
    }

    enableVariableInspector();
})();
