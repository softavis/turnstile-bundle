(() => {
    const runtimeKey = '__softavisTurnstileBundle';

    if (window[runtimeKey]) {
        window[runtimeKey].scan(document);
        return;
    }

    const mounted = new Map();
    let apiPromise;
    let paused = false;

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
            script.onload = () => {
                if (window.turnstile) {
                    resolve(window.turnstile);
                } else {
                    apiPromise = undefined;
                    reject(new Error('Turnstile API unavailable'));
                }
            };
            script.onerror = () => {
                apiPromise = undefined;
                reject(new Error('Turnstile API failed to load'));
            };
            document.head.appendChild(script);
        });

        return apiPromise;
    }

    function dispatch(container, name, detail = undefined) {
        container.dispatchEvent(new CustomEvent(`softavis:turnstile:${name}`, { bubbles: true, detail }));
    }

    function tokenInputFor(container) {
        return document.getElementById(container.dataset.tokenInput);
    }

    function clearToken(container, tokenInput = tokenInputFor(container)) {
        if (tokenInput) {
            tokenInput.value = '';
        }
    }

    function unmount(container) {
        const state = mounted.get(container);
        mounted.delete(container);
        clearToken(container);

        try {
            if (state && !state.pending && window.turnstile) {
                window.turnstile.remove(state.widgetId);
            }
        } finally {
            container.replaceChildren();
        }
    }

    function reset(container) {
        clearToken(container);
        const state = mounted.get(container);
        if (window.turnstile && state && !state.pending) {
            window.turnstile.reset(state.widgetId);
        }
    }

    function remove(container) {
        unmount(container);
    }

    function pause() {
        paused = true;
        mounted.forEach((state, container) => unmount(container));
        document.querySelectorAll('[data-softavis-turnstile]').forEach((container) => {
            clearToken(container);
            container.replaceChildren();
        });
    }

    function resume(root = document) {
        paused = false;
        scan(root);
    }

    function mount(container) {
        if (paused || mounted.has(container) || !container.isConnected) {
            return;
        }

        const tokenInput = tokenInputFor(container);
        if (!tokenInput || !container.dataset.sitekey) {
            dispatch(container, 'error');
            return;
        }

        const state = { pending: true, widgetId: undefined };
        mounted.set(container, state);
        loadApi().then((api) => {
            if (
                paused
                || mounted.get(container) !== state
                || !container.isConnected
                || !tokenInput.isConnected
                || tokenInputFor(container) !== tokenInput
            ) {
                if (mounted.get(container) === state) {
                    mounted.delete(container);
                }

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
                    if (mounted.get(container) !== state || !container.isConnected || tokenInputFor(container) !== tokenInput) {
                        return;
                    }
                    tokenInput.value = token;
                    tokenInput.dispatchEvent(new Event('input', { bubbles: true }));
                    tokenInput.dispatchEvent(new Event('change', { bubbles: true }));
                    dispatch(container, 'success');
                },
                'expired-callback'() {
                    if (mounted.get(container) === state && tokenInputFor(container) === tokenInput) {
                        clearToken(container, tokenInput);
                        dispatch(container, 'expired');
                    }
                },
                'error-callback'() {
                    if (mounted.get(container) === state && tokenInputFor(container) === tokenInput) {
                        clearToken(container, tokenInput);
                        dispatch(container, 'error');
                    }
                },
            };
            if (container.dataset.action) {
                options.action = container.dataset.action;
            }

            try {
                state.widgetId = api.render(container, options);
                state.pending = false;
            } catch (error) {
                mounted.delete(container);
                clearToken(container, tokenInput);
                container.replaceChildren();
                dispatch(container, 'error', { error });
            }
        }, (error) => {
            if (mounted.get(container) === state) {
                mounted.delete(container);
                if (container.isConnected) {
                    dispatch(container, 'error', { error });
                }
            }
        });
    }

    function scan(root) {
        if (paused) {
            return;
        }
        if (root.matches?.('[data-softavis-turnstile]')) {
            mount(root);
        }
        root.querySelectorAll?.('[data-softavis-turnstile]').forEach(mount);
    }

    const runtime = {
        render: mount,
        scan,
        reset,
        remove,
        pause,
        resume,
        prepareForCache: pause,
    };
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
                mounted.forEach((state, container) => {
                    if ((container === node || node.contains(container)) && !container.isConnected) {
                        unmount(container);
                    }
                });
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });

    window.addEventListener('pagehide', pause);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            resume(document);
        }
    });
})();
