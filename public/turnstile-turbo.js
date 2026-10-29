(() => {
    const adapterKey = '__softavisTurnstileTurboAdapter';
    const runtime = window.__softavisTurnstileBundle;

    if (window[adapterKey] || !runtime) {
        return;
    }

    window[adapterKey] = true;

    document.addEventListener('turbo:before-cache', () => {
        runtime.prepareForCache();
    });

    document.addEventListener('turbo:render', () => {
        if (document.documentElement.hasAttribute('data-turbo-preview')) {
            runtime.pause();
        }
    });

    document.addEventListener('turbo:load', () => {
        runtime.resume(document);
    });

    document.addEventListener('turbo:submit-end', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.isConnected) {
            return;
        }

        form.querySelectorAll('[data-softavis-turnstile]').forEach((container) => {
            runtime.reset(container);
        });
    });

    document.addEventListener('turbo:before-morph-element', (event) => {
        const element = event.target;
        if (!(element instanceof Element)) {
            return;
        }

        const container = element.closest('[data-softavis-turnstile]');
        if (container) {
            runtime.remove(container);
        }
        element.querySelectorAll('[data-softavis-turnstile]').forEach((widget) => {
            runtime.remove(widget);
        });
    });

    // Stream and frame morphs have no page-level turbo:morph. Preserved containers
    // emptied by the teardown above get no DOM mutation, so rescan once per morph.
    let rescanQueued = false;
    const scheduleRescan = () => {
        if (rescanQueued) {
            return;
        }
        rescanQueued = true;
        queueMicrotask(() => {
            rescanQueued = false;
            runtime.scan(document);
        });
    };

    ['turbo:morph', 'turbo:morph-element', 'turbo:frame-render'].forEach((name) => {
        document.addEventListener(name, scheduleRescan);
    });
})();
