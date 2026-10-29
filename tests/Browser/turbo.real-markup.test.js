const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const test = require('node:test');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');

// Markup comes from the bundle's real Twig templates (scripts in <body> next to the widget).
const markup = JSON.parse(execFileSync('php', [path.join(__dirname, 'render-markup.php')], {
    encoding: 'utf8',
    env: { ...process.env, XDEBUG_MODE: 'off' },
}));

const assets = {
    '/bundles/softavisturnstile/turnstile.js': 'public/turnstile.js',
    '/bundles/softavisturnstile/turnstile-turbo.js': 'public/turnstile-turbo.js',
    '/turbo.js': 'node_modules/@hotwired/turbo/dist/turbo.es2017-umd.js',
};

const page = (body) => `<!doctype html><html><head><meta charset="utf-8"><meta name="turbo-prefetch" content="false"><script src="/turbo.js" defer></script></head><body>${body}</body></html>`;
const pages = {
    '/start': page('<a id="to-form" href="/form">Form</a><a id="to-helper" href="/helper">Helper</a><a id="to-start" href="/start">Start</a>'),
    '/form': page(`<a id="to-start" href="/start">Start</a>
        <form id="form-one" action="/validation" method="post">${markup.form[0]}<button>Go</button></form>
        <form id="form-two" action="/validation" method="post">${markup.form[1]}</form>`),
    '/helper': page(`<a id="to-start" href="/start">Start</a>
        <form id="helper-form" action="/validation" method="post">${markup.helper[0]}${markup.helper[1]}</form>`),
};

test('real Twig markup with body-placed scripts under real Turbo', async (t) => {
    let gate;
    const server = http.createServer(async (request, response) => {
        const { pathname } = new URL(request.url, 'http://127.0.0.1');
        if (assets[pathname]) {
            response.writeHead(200, { 'content-type': 'text/javascript; charset=utf-8' });
            response.end(fs.readFileSync(path.join(root, assets[pathname])));
            return;
        }
        if (gate && request.method === 'GET' && pathname === '/form') {
            const current = gate;
            gate = undefined;
            current.reached();
            await current.release;
        }
        response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        response.end(pages[pathname] ?? pages['/start']);
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({ headless: true, channel: 'chromium' });
    t.after(async () => {
        await browser.close();
        await new Promise((resolve) => server.close(resolve));
    });

    const context = await browser.newContext();
    await context.addInitScript(() => {
        let nextId = 0;
        const widgets = new Map();
        const stats = { renders: 0, removes: 0, previewRenders: 0 };
        window.__stats = stats;
        window.__active = () => widgets.size;
        window.__generated = () => document.querySelectorAll('[data-mock-generated]').length;
        window.__containers = () => document.querySelectorAll('[data-softavis-turnstile]').length;
        window.turnstile = {
            render(container, options) {
                const id = `mock-${++nextId}`;
                const generated = document.createElement('span');
                generated.dataset.mockGenerated = '';
                container.append(generated);
                widgets.set(id, { container, options });
                container.dataset.mockWidget = id;
                stats.renders += 1;
                if (document.documentElement.hasAttribute('data-turbo-preview')) {
                    stats.previewRenders += 1;
                }
                return id;
            },
            reset() {},
            remove(id) {
                stats.removes += 1;
                widgets.delete(id);
            },
        };
        window.__issue = (inputId, token) => {
            const container = document.querySelector(`[data-token-input="${inputId}"]`);
            widgets.get(container.dataset.mockWidget).options.callback(token);
        };
    });
    const tab = await context.newPage();
    const settled = async (count) => {
        await tab.waitForFunction((n) => window.__active() === n, count);
        await tab.waitForTimeout(150);
        assert.equal(await tab.evaluate(() => window.__active()), count);
        assert.equal(await tab.evaluate(() => window.__generated()), count);
    };
    const tokens = (ids) => tab.evaluate((list) => list.map((id) => document.getElementById(id)?.value), ids);

    await tab.goto(`${base}/start`);
    await settled(0);

    await t.test('first Turbo visit from a page without a widget, with repeated scripts', async () => {
        await tab.click('#to-form');
        await settled(2);
        assert.equal(await tab.evaluate(() => window.__stats.previewRenders), 0);
        assert.equal(await tab.evaluate(() => window.__stats.renders), 2);
        assert.equal(await tab.evaluate(() => Boolean(window.__softavisTurnstileTurboAdapter)), true, 'adapter active');
        await tab.evaluate(() => {
            window.__issue('token_one', 'used-one');
            window.__issue('token_two', 'used-two');
        });
        assert.deepEqual(await tokens(['token_one', 'token_two']), ['used-one', 'used-two']);
    });

    await t.test('leaving caches a clean snapshot; revisit shows a preview without rendering', async () => {
        await tab.click('#to-start');
        await settled(0);
        let reached;
        const reachedPromise = new Promise((resolve) => { reached = resolve; });
        let release;
        gate = { reached, release: new Promise((resolve) => { release = resolve; }) };
        await tab.evaluate(() => document.getElementById('to-form').click());
        await reachedPromise;
        await tab.waitForFunction(() => document.documentElement.hasAttribute('data-turbo-preview'));
        await tab.waitForTimeout(150);
        assert.equal(await tab.evaluate(() => window.__containers()), 2);
        assert.equal(await tab.evaluate(() => window.__generated()), 0, 'no Cloudflare DOM in preview');
        assert.equal(await tab.evaluate(() => window.__active()), 0);
        assert.deepEqual(await tokens(['token_one', 'token_two']), ['', ''], 'no stale token in cached snapshot');
        assert.equal(await tab.evaluate(() => window.__stats.previewRenders), 0);
        release();
        await tab.waitForFunction(() => !document.documentElement.hasAttribute('data-turbo-preview'));
        await settled(2);
        assert.equal(await tab.evaluate(() => window.__stats.previewRenders), 0);
        assert.deepEqual(await tokens(['token_one', 'token_two']), ['', '']);
    });

    await t.test('back/forward restores fresh widgets without duplicates', async () => {
        await tab.click('#to-start');
        await settled(0);
        await tab.goBack();
        await settled(2);
        await tab.goForward();
        await settled(0);
        await tab.goBack();
        await settled(2);
        assert.equal(await tab.evaluate(() => window.__containers()), 2);
        assert.equal(await tab.evaluate(() => window.__stats.previewRenders), 0);
    });

    await t.test('Twig helper markup with repeated scripts', async () => {
        await tab.click('#to-start');
        await settled(0);
        await tab.click('#to-helper');
        await settled(2);
        await tab.click('#to-start');
        await settled(0);
        await tab.click('#to-helper');
        await settled(2);
        assert.equal(await tab.evaluate(() => window.__stats.previewRenders), 0);
        assert.equal(await tab.evaluate(() => document.querySelectorAll('script[src$="turnstile.js"]').length), 2);
    });
});
