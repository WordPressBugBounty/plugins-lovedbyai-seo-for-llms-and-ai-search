// Printed as an inline <script type="module">: browsers without module support skip it,
// and script combiners leave module scripts alone.
//
// Tools are not defined here. They come from tools.json under cfg.assetsBase, and each
// tool's executor is a versioned module under cfg.assetsBase + 'executors/', imported
// the first time the tool is called. Both URLs are built from cfg.assetsBase, which
// the plugin sets, so the manifest can't point the loader at any other host.

const MAX_LIST_EVENTS = 10;
const MAX_QUERY_LENGTH = 200;
const MAX_TOOLS = 20;
const EXECUTE_TIMEOUT_MS = 10000;
const TOOL_NAME = /^[a-z][a-z0-9_]{0,63}$/;
const EXECUTOR_PATH = /^[a-z0-9-]+\/v[0-9]+\.js$/;

function isString(value) {
    return typeof value === 'string';
}

function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

async function main() {
    const cfg = window.geoguru_webmcp;
    if (!cfg || !cfg.proxyUrl || !cfg.restRoot || !cfg.assetsBase) {
        return;
    }

    let mc;
    let mcPath;
    if (document.modelContext) {
        mc = document.modelContext;
        mcPath = 'document.modelContext';
    } else {
        mc = navigator.modelContext;
        mcPath = 'navigator.modelContext';
    }
    if (!mc || typeof mc.registerTool !== 'function') {
        return;
    }

    function base() {
        const fields = {
            page_url: location.href,
            referrer: document.referrer || '',
            automated: navigator.webdriver === true,
        };
        if (isString(navigator.userAgent)) {
            fields.user_agent = navigator.userAgent;
        }
        if (isString(navigator.language)) {
            fields.language = navigator.language;
        }
        const uaData = navigator.userAgentData;
        if (uaData && Array.isArray(uaData.brands)) {
            fields.ua_brands = uaData.brands
                .filter((entry) => entry && isString(entry.brand))
                .map((entry) => entry.brand);
        }
        try {
            const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (isString(timeZone)) {
                fields.timezone = timeZone;
            }
        } catch {}
        return fields;
    }

    function send(fields) {
        try {
            fetch(cfg.proxyUrl, {
                method: 'POST',
                body: JSON.stringify(Object.assign(base(), fields)),
                keepalive: true,
                headers: { 'Content-Type': 'application/json' },
            }).catch(() => {});
        } catch {}
    }

    function apiShape() {
        const shapes = [];
        if (document.modelContext) {
            shapes.push('document.modelContext');
        }
        if (navigator.modelContext) {
            shapes.push('navigator.modelContext');
        }
        if (navigator.modelContextTesting) {
            shapes.push('navigator.modelContextTesting');
        }
        return shapes;
    }

    let listEventsSent = 0;
    function wrapListing(obj, method, path) {
        try {
            if (!obj || typeof obj[method] !== 'function') {
                return;
            }
            const original = obj[method];
            obj[method] = function () {
                if (listEventsSent < MAX_LIST_EVENTS) {
                    listEventsSent++;
                    send({ event_type: 'tools_listed', tool_name: path });
                }
                return original.apply(obj, arguments);
            };
        } catch {}
    }

    // Works with both REST URL forms: /wp-json/<route> and ?rest_route=/<route>.
    // rest_route is left unencoded, as WordPress itself prints it.
    function restUrl(route, params) {
        const root = new URL(cfg.restRoot, location.href);
        const query = new URLSearchParams(params || {});
        if (root.searchParams.has('rest_route')) {
            root.searchParams.delete('rest_route');
            const rest = [root.searchParams.toString(), query.toString()].filter(Boolean).join('&');
            return root.origin + root.pathname + '?rest_route=/' + route + (rest ? '&' + rest : '');
        }
        root.pathname = root.pathname.replace(/\/?$/, '/') + route;
        query.forEach((value, key) => root.searchParams.set(key, value));
        return root.href;
    }

    function withTimeout(promise) {
        let timer;
        const timeout = new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Error('timed out')), EXECUTE_TIMEOUT_MS);
        });
        return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
    }

    async function runTool(toolName, executorUrl, input) {
        const start = Date.now();
        let outcome = null;
        try {
            outcome = await withTimeout((async () => {
                const executor = await import(executorUrl);
                if (typeof executor.execute !== 'function') {
                    throw new Error('executor has no execute export');
                }
                const context = Object.freeze({ toolName, restUrl });
                return executor.execute(isPlainObject(input) ? input : {}, context);
            })());
        } catch {}
        if (!isPlainObject(outcome) || !('output' in outcome)) {
            outcome = { output: { error: 'tool failed' }, success: false };
        }

        const event = {
            event_type: 'tool_called',
            tool_name: toolName,
            success: outcome.success === true,
            duration_ms: Math.max(0, Math.round(Date.now() - start)),
        };
        if (isString(outcome.query)) {
            event.query = outcome.query.substring(0, MAX_QUERY_LENGTH);
        }
        if (Number.isInteger(outcome.resultCount) && outcome.resultCount >= 0) {
            event.result_count = outcome.resultCount;
        }
        send(event);
        return JSON.stringify(outcome.output);
    }

    function isValidTool(tool) {
        return isPlainObject(tool)
            && isString(tool.name) && TOOL_NAME.test(tool.name)
            && isString(tool.description) && tool.description !== ''
            && isPlainObject(tool.inputSchema) && tool.inputSchema.type === 'object'
            && isString(tool.executor) && EXECUTOR_PATH.test(tool.executor)
            && (tool.title === undefined || isString(tool.title))
            && (tool.annotations === undefined || isPlainObject(tool.annotations));
    }

    function register(tool) {
        // Kept apart from the schema: the agent never sees or sets the name it is calling.
        const toolName = tool.name;
        const executorUrl = cfg.assetsBase + 'executors/' + tool.executor;
        const definition = {
            name: toolName,
            description: tool.description,
            inputSchema: tool.inputSchema,
            execute: (input) => runTool(toolName, executorUrl, input),
        };
        if (tool.title !== undefined) {
            definition.title = tool.title;
        }
        if (tool.annotations !== undefined) {
            definition.annotations = tool.annotations;
        }
        try {
            const registration = mc.registerTool(definition);
            if (registration && typeof registration.catch === 'function') {
                registration.catch(() => {});
            }
        } catch {}
    }

    send({ event_type: 'webmcp_ready', api_shape: apiShape() });
    wrapListing(mc, 'getTools', mcPath + '.getTools');
    wrapListing(navigator.modelContextTesting, 'listTools', 'navigator.modelContextTesting.listTools');

    const res = await fetch(cfg.assetsBase + 'tools.json', { credentials: 'omit' });
    if (!res.ok) {
        return;
    }
    const manifest = await res.json();
    if (!isPlainObject(manifest) || !Array.isArray(manifest.tools)) {
        return;
    }
    const disabled = new Set(Array.isArray(cfg.disabledTools) ? cfg.disabledTools : []);
    const seen = new Set();
    for (const tool of manifest.tools.slice(0, MAX_TOOLS)) {
        if (isValidTool(tool) && !seen.has(tool.name) && !disabled.has(tool.name)) {
            seen.add(tool.name);
            register(tool);
        }
    }
}

main().catch(() => {});
