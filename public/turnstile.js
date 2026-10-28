(() => {
    const runtimeKey = '__softavisTurnstileBundle';

    if (window[runtimeKey]) {
        window[runtimeKey].scan(document);
        return;
    }

    const mounted = new Map();
    let apiPromise;

    function loadApi() {
        if (window.turnstile) {
            return Promise.resolve(window.turnstile);
        }
        if (apiPromise) {
            return apiPromise;
        }

        apiPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.defer = true;
            script.onload = () => window.turnstile ? resolve(window.turnstile) : reject(new Error('Turnstile API unavailable'));
            script.onerror = () => {
                apiPromise = undefined;
                reject(new Error('Turnstile API failed to load'));
            };
            document.head.appendChild(script);
        });

        return apiPromise;
    }

    function dispatch(container, name) {
        container.dispatchEvent(new CustomEvent(`softavis:turnstile:${name}`, { bubbles: true }));
    }

    function unmount(container, widgetId) {
        if (window.turnstile) {
            window.turnstile.remove(widgetId);
        }
        mounted.delete(container);
    }

    function reset(container) {
        const widgetId = mounted.get(container);
        const tokenInput = document.getElementById(container.dataset.tokenInput);
        if (tokenInput) {
            tokenInput.value = '';
        }
        if (window.turnstile && widgetId !== undefined && widgetId !== 'pending') {
            window.turnstile.reset(widgetId);
        }
    }

    function remove(container) {
        const widgetId = mounted.get(container);
        if (widgetId !== undefined && widgetId !== 'pending') {
            unmount(container, widgetId);
        } else {
            mounted.delete(container);
        }
    }

    function mount(container) {
        if (mounted.has(container) || !container.isConnected) {
            return;
        }

        const tokenInput = document.getElementById(container.dataset.tokenInput);
        if (!tokenInput || !container.dataset.sitekey) {
            dispatch(container, 'error');
            return;
        }

        mounted.set(container, 'pending');
        loadApi().then((api) => {
            if (!container.isConnected || !mounted.has(container)) {
                return;
            }

            const options = {
                sitekey: container.dataset.sitekey,
                theme: container.dataset.theme,
                size: container.dataset.size,
                language: container.dataset.language,
                appearance: container.dataset.appearance,
                'response-field': false,
                callback(token) {
                    tokenInput.value = token;
                    tokenInput.dispatchEvent(new Event('input', { bubbles: true }));
                    tokenInput.dispatchEvent(new Event('change', { bubbles: true }));
                    dispatch(container, 'success');
                },
                'expired-callback'() {
                    tokenInput.value = '';
                    dispatch(container, 'expired');
                },
                'error-callback'() {
                    tokenInput.value = '';
                    dispatch(container, 'error');
                },
            };
            if (container.dataset.action) {
                options.action = container.dataset.action;
            }

            try {
                mounted.set(container, api.render(container, options));
            } catch (error) {
                mounted.delete(container);
                dispatch(container, 'error');
                throw error;
            }
        }).catch(() => {
            if (container.isConnected) {
                mounted.delete(container);
                dispatch(container, 'error');
            }
        });
    }

    function scan(root) {
        if (root.matches?.('[data-softavis-turnstile]')) {
            mount(root);
        }
        root.querySelectorAll?.('[data-softavis-turnstile]').forEach(mount);
    }

    const runtime = { render: mount, scan, reset, remove };
    window[runtimeKey] = runtime;
    scan(document);

    new MutationObserver((records) => {
        records.forEach((record) => {
            record.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    scan(node);
                }
            });
            record.removedNodes.forEach((node) => {
                if (!(node instanceof Element)) {
                    return;
                }
                mounted.forEach((widgetId, container) => {
                    if (container === node || node.contains(container)) {
                        if (widgetId !== 'pending') {
                            unmount(container, widgetId);
                        } else {
                            mounted.delete(container);
                        }
                    }
                });
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });
})();
