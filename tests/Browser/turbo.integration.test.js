const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const test = require('node:test');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');

test('real Turbo navigation, cache, submission, and plain-page lifecycle', { timeout: 90000 }, async (t) => {
    let morphMode = false;
    let nextFormRequestGate;

    const server = http.createServer(async (request, response) => {
        const url = new URL(request.url, 'http://127.0.0.1');
        if (request.method === 'POST' && url.pathname === '/validation') {
            response.writeHead(422, { 'content-type': 'text/html; charset=utf-8' });
            response.end(pageHtml('/form', { validationError: true, morphMode }));
            return;
        }

        if (request.method === 'GET' && url.pathname === '/form' && nextFormRequestGate) {
            const gate = nextFormRequestGate;
            nextFormRequestGate = undefined;
            gate.reached();
            await gate.response;
        }

        const assets = {
            '/turnstile.js': 'public/turnstile.js',
            '/turnstile-turbo.js': 'public/turnstile-turbo.js',
            '/turbo.js': 'node_modules/@hotwired/turbo/dist/turbo.es2017-umd.js',
        };
        if (assets[url.pathname]) {
            response.writeHead(200, {
                'content-type': url.pathname.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'text/plain',
            });
            response.end(fs.readFileSync(path.join(root, assets[url.pathname])));
            return;
        }

        if (url.pathname === '/plain-away') {
            response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
            response.end(pageHtml('/plain-away', { turbo: false }));
            return;
        }
        if (url.pathname === '/plain') {
            response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
            response.end(pageHtml('/plain', { turbo: false, plain: true }));
            return;
        }

        response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        response.end(pageHtml(url.pathname, { morphMode }));
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const address = server.address();
    const baseUrl = `http://127.0.0.1:${address.port}`;
    t.after(async () => {
        await new Promise((resolve) => server.close(resolve));
    });
    const browser = await chromium.launch({ headless: true, channel: 'chromium', timeout: 30000 });
    t.after(async () => {
        await browser.close();
    });

    const context = await browser.newContext();
    context.setDefaultTimeout(10000);
    const page = await context.newPage();
    await context.addInitScript(() => {
        window.__turnstileMetrics = { snapshots: [], submitEnds: [], renders: [], morphs: 0, persistedPageshows: [] };
        window.addEventListener('pageshow', (event) => {
            window.__turnstileMetrics.persistedPageshows.push(event.persisted);
        });
        document.addEventListener('turbo:before-cache', () => {
            queueMicrotask(() => {
                window.__turnstileMetrics.snapshots.push({
                    widgets: [...document.querySelectorAll('[data-softavis-turnstile]')].map((container) => ({
                        generated: container.querySelectorAll('[data-mock-generated]').length,
                        token: document.getElementById(container.dataset.tokenInput)?.value,
                    })),
                });
            });
        });
        document.addEventListener('turbo:submit-end', (event) => {
            window.__turnstileMetrics.submitEnds.push({
                form: event.target.id,
                success: event.detail.success,
                connected: event.target.isConnected,
            });
        });
        document.addEventListener('turbo:render', () => {
            window.__turnstileMetrics.renders.push({
                preview: document.documentElement.hasAttribute('data-turbo-preview'),
                widgetCount: document.querySelectorAll('[data-softavis-turnstile]').length,
            });
        });
        document.addEventListener('turbo:morph', () => {
            window.__turnstileMetrics.morphs += 1;
        });

        let nextId = 0;
        const widgets = new Map();
        const counters = { renders: 0, resets: 0, removes: 0 };
        window.turnstile = {
            render(container, options) {
                const id = `mock-${++nextId}`;
                const generated = document.createElement('span');
                generated.dataset.mockGenerated = '';
                container.append(generated);
                container.dataset.mockWidget = id;
                widgets.set(id, { container, options });
                counters.renders += 1;
                return id;
            },
            reset(id) {
                counters.resets += 1;
                const widget = widgets.get(id);
                if (widget) {
                    widget.options['expired-callback']();
                }
            },
            remove(id) {
                counters.removes += 1;
                widgets.delete(id);
            },
        };
        window.__turnstileMock = {
            counters,
            activeCount: () => widgets.size,
            issue(tokenInputId, token) {
                const container = [...document.querySelectorAll('[data-softavis-turnstile]')]
                    .find((element) => element.dataset.tokenInput === tokenInputId);
                const widget = widgets.get(container?.dataset.mockWidget);
                widget?.options.callback(token);
            },
            sitekey(tokenInputId) {
                const container = [...document.querySelectorAll('[data-softavis-turnstile]')]
                    .find((element) => element.dataset.tokenInput === tokenInputId);
                return widgets.get(container?.dataset.mockWidget)?.options.sitekey;
            },
        };
    });

    await page.goto(`${baseUrl}/`);
    await page.locator('#to-form').click();
    await page.waitForURL('**/form');
    await page.waitForFunction(() => window.__turnstileMock.activeCount() === 2);
    await page.evaluate(() => {
        window.__turnstileMock.issue('token-one', 'old-token-one');
        window.__turnstileMock.issue('token-two', 'old-token-two');
    });
    await page.locator('#to-other').click();
    await page.waitForURL('**/other');

    const snapshot = await page.evaluate(() => window.__turnstileMetrics.snapshots.at(-1));
    assert.deepEqual(snapshot.widgets, [
        { generated: 0, token: '' },
        { generated: 0, token: '' },
    ]);

    let reachFormRequest;
    let releaseFormResponse;
    const interceptedFormRequest = new Promise((resolve) => {
        reachFormRequest = resolve;
    });
    const releaseResponse = new Promise((resolve) => {
        releaseFormResponse = resolve;
    });
    nextFormRequestGate = { reached: reachFormRequest, response: releaseResponse };
    const clickBackToForm = page.locator('#to-form').click();
    await interceptedFormRequest;
    await page.waitForFunction(() => document.documentElement.hasAttribute('data-turbo-preview'));
    await page.waitForTimeout(20);

    const preview = await page.evaluate(() => ({
        renders: window.__turnstileMock.counters.renders,
        active: window.__turnstileMock.activeCount(),
        generated: document.querySelectorAll('[data-mock-generated]').length,
    }));
    assert.deepEqual(preview, { renders: 2, active: 0, generated: 0 });

    releaseFormResponse();
    await clickBackToForm;
    await page.waitForFunction(() => !document.documentElement.hasAttribute('data-turbo-preview'));
    await page.waitForFunction(() => window.__turnstileMock.activeCount() === 2);
    assert.equal(await page.locator('[data-mock-generated]').count(), 2);
    assert.deepEqual(await page.locator('#token-one').inputValue(), '');
    assert.deepEqual(await page.locator('#token-two').inputValue(), '');

    await page.locator('#to-other').click();
    await page.waitForURL('**/other');
    await page.goBack();
    await page.waitForURL('**/form');
    await page.waitForFunction(() => window.__turnstileMock.activeCount() === 2);
    await page.goForward();
    await page.waitForURL('**/other');
    await page.goBack();
    await page.waitForURL('**/form');
    await page.waitForFunction(() => window.__turnstileMock.activeCount() === 2);

    await page.locator('#submit-validation').click();
    await page.waitForURL('**/form');
    await page.locator('[data-validation-error]').waitFor();
    await page.waitForFunction(() => window.__turnstileMock.activeCount() === 2);
    assert.equal(await page.locator('#token-one').inputValue(), '');
    assert.equal(await page.locator('[data-mock-generated]').count(), 2);

    morphMode = true;
    const previousMorphCount = await page.evaluate(() => window.__turnstileMetrics.morphs);
    await page.evaluate(() => window.Turbo.renderStreamMessage('<turbo-stream action="refresh"></turbo-stream>'));
    await page.waitForFunction((count) => window.__turnstileMetrics.morphs > count, previousMorphCount);
    await page.waitForFunction(() => window.__turnstileMock.activeCount() === 2);
    assert.equal(await page.evaluate(() => window.__turnstileMock.sitekey('token-one-morphed')), 'changed-site-key');
    assert.equal(await page.locator('#token-one-morphed').inputValue(), '');
    assert.equal(await page.locator('[data-mock-generated]').count(), 2);

    await page.evaluate(() => {
        window.__turnstileMock.issue('token-one-morphed', 'used-before-network-error');
        window.__turnstileMock.issue('token-two', 'unrelated-form-token');
        document.querySelector('#form-one').action = '/failed';
    });
    await page.route('**/failed', (route) => route.abort());
    const submitEndsBeforeFailure = await page.evaluate(() => window.__turnstileMetrics.submitEnds.length);
    await page.locator('#submit-failure').click();
    await page.waitForFunction(
        (count) => window.__turnstileMetrics.submitEnds.length > count,
        submitEndsBeforeFailure,
    );
    assert.equal(await page.locator('#token-one-morphed').inputValue(), '');
    assert.equal(await page.locator('#token-two').inputValue(), 'unrelated-form-token');
    assert.equal(await page.locator('[data-mock-generated]').count(), 2);
    assert.equal(await page.evaluate(() => window.__turnstileMock.activeCount()), 2);

    const plainPage = await context.newPage();
    await plainPage.goto(`${baseUrl}/plain`);
    await plainPage.waitForFunction(() => window.__turnstileMock.activeCount() === 1);
    assert.equal(await plainPage.evaluate(() => typeof window.Turbo), 'undefined');
    assert.equal(await plainPage.locator('[data-mock-generated]').count(), 1);
    assert.equal(await plainPage.locator('script[src="/turnstile-turbo.js"]').count(), 0);

    const bfcachePage = await context.newPage();
    await bfcachePage.goto(`${baseUrl}/plain`);
    await bfcachePage.waitForFunction(() => window.__turnstileMock.activeCount() === 1);
    await bfcachePage.evaluate(() => window.__turnstileMock.issue('token-one', 'bfcache-stale-token'));
    await bfcachePage.locator('#to-away').click();
    await bfcachePage.goBack();
    await bfcachePage.waitForFunction(() => window.__turnstileMock.activeCount() === 1);
    const bfcacheState = await bfcachePage.evaluate(() => ({
        persisted: window.__turnstileMetrics.persistedPageshows,
        notRestoredReasons: performance.getEntriesByType('navigation')[0]?.notRestoredReasons,
    }));
    assert.equal(typeof bfcacheState.persisted.at(-1), 'boolean');
    assert.equal(await bfcachePage.locator('#token-one').inputValue(), '');
    assert.equal(await bfcachePage.locator('[data-mock-generated]').count(), 1);
});

function pageHtml(pathname, { turbo = true, plain = false, validationError = false, morphMode = false } = {}) {
    const assets = turbo
        ? '<script src="/turnstile.js" defer></script><script src="/turnstile-turbo.js" defer></script><script src="/turbo.js" defer></script>'
        : '<script src="/turnstile.js" defer></script>';
    const links = turbo ? '<a id="to-form" href="/form">Form</a><a id="to-other" href="/other">Other</a>' : '';
    const firstTokenId = morphMode ? 'token-one-morphed' : 'token-one';
    const body = pathname === '/form' || validationError
        ? `${validationError ? '<p data-validation-error>Validation failed</p>' : ''}
            <form id="form-one" action="/validation" method="post">
                <input id="${firstTokenId}" type="hidden" name="${firstTokenId}">
                <div data-softavis-turnstile data-sitekey="${morphMode ? 'changed-site-key' : 'test-site-key'}" data-token-input="${firstTokenId}"></div>
                <button id="submit-validation">Validate</button>
                <button id="submit-failure" formaction="/failed">Fail</button>
            </form>
            <form id="form-two" action="/validation" method="post">
                <input id="token-two" type="hidden" name="token-two">
                <div data-softavis-turnstile data-sitekey="test-site-key" data-token-input="token-two"></div>
            </form>`
        : plain
            ? `<a id="to-away" href="/plain-away">Away</a><input id="token-one" type="hidden"><div data-softavis-turnstile data-sitekey="test-site-key" data-token-input="token-one"></div>`
            : '<main>Other page</main>';

    return `<!doctype html>
        <html><head><meta charset="utf-8"><meta name="turbo-refresh-method" content="morph">${assets}</head>
        <body>${links}${body}</body></html>`;
}
