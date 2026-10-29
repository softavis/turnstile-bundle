const assert = require('node:assert/strict');
const fs = require('node:fs');
const test = require('node:test');
const vm = require('node:vm');

class FakeElement {
    constructor(dataset = {}) {
        this.dataset = dataset;
        this.children = [];
        this.events = [];
        this.isConnected = true;
        this.parentElement = null;
    }

    matches(selector) {
        return selector === '[data-softavis-turnstile]' && this.dataset.softavisTurnstile === 'true';
    }

    querySelectorAll(selector) {
        return this.children.filter((child) => child.matches(selector));
    }

    contains(element) {
        return this.children.includes(element);
    }

    replaceChildren(...children) {
        this.children = children;
        children.forEach((child) => {
            child.parentElement = this;
        });
    }

    dispatchEvent(event) {
        this.events.push(event.type);
        return true;
    }
}

function startRuntime(widgets, { deferApi = false } = {}) {
    const callbacks = new Map();
    const inputs = new Map();
    const scripts = [];
    const apiScripts = [];
    const calls = { render: 0, reset: [], remove: [] };
    const api = {
        render(container, options) {
            calls.render += 1;
            callbacks.set(container, options);
            return `widget-${calls.render}`;
        },
        reset(id) {
            calls.reset.push(id);
        },
        remove(id) {
            calls.remove.push(id);
        },
    };
    const documentElement = new FakeElement();
    const document = {
        head: {
            appendChild(script) {
                scripts.push(script.src);
                apiScripts.push(script);
                if (!deferApi) {
                    window.turnstile = api;
                    script.onload();
                }
            },
        },
        documentElement,
        createElement() {
            return {};
        },
        getElementById(id) {
            return inputs.get(id);
        },
        querySelectorAll(selector) {
            return widgets.filter((widget) => widget.matches(selector));
        },
    };
    const observers = [];
    class MutationObserver {
        constructor(callback) {
            this.callback = callback;
            observers.push(this);
        }

        observe() {
        }
    }
    const windowListeners = new Map();
    const window = {
        addEventListener(name, listener) {
            if (!windowListeners.has(name)) {
                windowListeners.set(name, []);
            }
            windowListeners.get(name).push(listener);
        },
    };
    const context = {
        window,
        document,
        Element: FakeElement,
        MutationObserver,
        CustomEvent: class CustomEvent {
            constructor(type) {
                this.type = type;
            }
        },
        Event: class Event {
            constructor(type) {
                this.type = type;
            }
        },
        Promise,
        Map,
    };
    for (const widget of widgets) {
        inputs.set(widget.dataset.tokenInput, { value: '', isConnected: true, dispatchEvent() {} });
    }

    vm.runInNewContext(fs.readFileSync('public/turnstile.js', 'utf8'), context);

    return { window, windowListeners, document, inputs, scripts, apiScripts, callbacks, calls, observers, api };
}

test('shares the Cloudflare loader and owns success, expiry, reset, and removal', async () => {
    const first = new FakeElement({
        softavisTurnstile: 'true',
        tokenInput: 'token-one',
        sitekey: 'public',
        theme: 'auto',
        size: 'normal',
        language: 'auto',
        appearance: 'always',
        action: 'login',
    });
    const second = new FakeElement({
        softavisTurnstile: 'true',
        tokenInput: 'token-two',
        sitekey: 'public',
        theme: 'auto',
        size: 'normal',
        language: 'auto',
        appearance: 'always',
    });
    const runtime = startRuntime([first, second]);
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(runtime.scripts.length, 1);
    assert.match(runtime.scripts[0], /challenges\.cloudflare\.com\/turnstile\/v0\/api\.js\?render=explicit/);
    assert.equal(runtime.calls.render, 2);
    assert.equal(runtime.callbacks.get(first).action, 'login');

    runtime.callbacks.get(first).callback('fresh-token');
    assert.equal(runtime.inputs.get('token-one').value, 'fresh-token');
    runtime.callbacks.get(first)['expired-callback']();
    assert.equal(runtime.inputs.get('token-one').value, '');
    runtime.callbacks.get(second)['error-callback']();
    assert.equal(runtime.inputs.get('token-two').value, '');

    runtime.window.__softavisTurnstileBundle.reset(first);
    runtime.window.__softavisTurnstileBundle.remove(first);
    assert.deepEqual(runtime.calls.reset, ['widget-1']);
    assert.deepEqual(runtime.calls.remove, ['widget-1']);
});

test('scans dynamically added widgets and removes detached widgets', async () => {
    const initial = new FakeElement({
        softavisTurnstile: 'true',
        tokenInput: 'initial',
        sitekey: 'public',
    });
    const runtime = startRuntime([initial]);
    await Promise.resolve();
    await Promise.resolve();

    const added = new FakeElement({
        softavisTurnstile: 'true',
        tokenInput: 'added',
        sitekey: 'public',
    });
    runtime.inputs.set('added', { value: '', isConnected: true, dispatchEvent() {} });
    runtime.observers[0].callback([{ addedNodes: [added], removedNodes: [] }]);
    await Promise.resolve();

    assert.equal(runtime.calls.render, 2);
    added.isConnected = false;
    runtime.observers[0].callback([{ addedNodes: [], removedNodes: [added] }]);
    assert.deepEqual(runtime.calls.remove, ['widget-2']);
    assert.equal(runtime.scripts.length, 1);
});

test('discards a pending mount during cache teardown and mounts a fresh widget on resume', async () => {
    const widget = new FakeElement({
        softavisTurnstile: 'true',
        tokenInput: 'token',
        sitekey: 'public',
    });
    const runtime = startRuntime([widget], { deferApi: true });
    const input = runtime.inputs.get('token');

    input.value = 'stale-token';
    runtime.window.__softavisTurnstileBundle.prepareForCache();
    runtime.window.turnstile = runtime.api;
    runtime.apiScripts[0].onload();
    await Promise.resolve();

    assert.equal(widget.isConnected, true);
    assert.equal(input.value, '');
    assert.equal(runtime.calls.render, 0);

    runtime.window.__softavisTurnstileBundle.resume(runtime.document);
    await Promise.resolve();

    assert.equal(runtime.calls.render, 1);
    assert.deepEqual(widget.children, []);
});

test('does not render a widget removed while the Cloudflare API is pending', async () => {
    const widget = new FakeElement({
        softavisTurnstile: 'true',
        tokenInput: 'token',
        sitekey: 'public',
    });
    const runtime = startRuntime([widget], { deferApi: true });

    widget.isConnected = false;
    runtime.observers[0].callback([{ addedNodes: [], removedNodes: [widget] }]);
    runtime.window.turnstile = runtime.api;
    runtime.apiScripts[0].onload();
    await Promise.resolve();

    assert.equal(runtime.calls.render, 0);
    assert.deepEqual(runtime.calls.remove, []);
});

test('re-executing the runtime script does not add another widget', async () => {
    const widget = new FakeElement({
        softavisTurnstile: 'true',
        tokenInput: 'token',
        sitekey: 'public',
    });
    const runtime = startRuntime([widget]);
    await Promise.resolve();
    await Promise.resolve();

    vm.runInNewContext(fs.readFileSync('public/turnstile.js', 'utf8'), {
        window: runtime.window,
        document: runtime.document,
    });

    assert.equal(runtime.calls.render, 1);
    assert.equal(runtime.observers.length, 1);
});

test('cleans and remounts a widget when the browser restores a bfcache page', async () => {
    const widget = new FakeElement({
        softavisTurnstile: 'true',
        tokenInput: 'token',
        sitekey: 'public',
    });
    const runtime = startRuntime([widget]);
    await Promise.resolve();
    await Promise.resolve();
    runtime.inputs.get('token').value = 'used-token';

    runtime.windowListeners.get('pagehide')[0]();
    runtime.callbacks.get(widget).callback('late-token');
    runtime.windowListeners.get('pageshow')[0]({ persisted: true });
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(runtime.inputs.get('token').value, '');
    assert.deepEqual(runtime.calls.remove, ['widget-1']);
    assert.equal(runtime.calls.render, 2);
    assert.equal(widget.isConnected, true);
});
