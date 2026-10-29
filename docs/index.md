# softavis/turnstile-bundle documentation

This bundle connects Symfony Forms, Validator, Twig and optional Security login flows to [`softavis/turnstile`](https://github.com/softavis/turnstile), our framework-independent verification core. Siteverify requests and response semantics stay in the core library ([README](https://github.com/softavis/turnstile#readme)); this bundle does not copy or reinterpret that protocol.

The quick setup lives in the [README](../README.md#getting-started). This page holds the details.

- [Requirements](#requirements)
- [Installation notes](#installation-notes)
- [Configuration reference](#configuration-reference)
- [Forms and the validator](#forms-and-the-validator)
- [Twig widget](#twig-widget)
- [Login protection](#login-protection)
- [Action, hostname and client IP policy](#action-hostname-and-client-ip-policy)
- [Assets and browser behavior](#assets-and-browser-behavior)
- [Turbo integration](#turbo-integration)
- [Content Security Policy](#content-security-policy)
- [Templates and translations](#templates-and-translations)
- [Errors](#errors)
- [Disabled mode and test keys](#disabled-mode-and-test-keys)
- [Advanced and development](#advanced-and-development)

## Requirements

| Symfony | PHP |
|---------|-----|
| 7.4 LTS | 8.2+ |
| 8.1     | 8.4.1+ |

The bundle itself declares `php: ^8.2`; Symfony 8.1 components require PHP 8.4.1 or later, so Composer selects Symfony 7.4 on older PHP versions. CI runs PHP 8.2 (Symfony 7.4, lowest and current dependencies), 8.3 (7.4), 8.4 and 8.5 (8.1).

**Symfony 8.0 is excluded.** It is a non-LTS release that has reached the end of upstream support (see the [Symfony releases page](https://symfony.com/releases/8.0)), and its latest release remains affected by advisories whose fixes were published only for other branches, for example [CVE-2026-108082](https://symfony.com/cve-2026-108082) (`symfony/http-foundation`, fixed in 7.4.19 and 8.1.7) and [CVE-2026-109413](https://symfony.com/cve-2026-109413) (`symfony/yaml`, fixed in 7.4.18 and 8.1.6). Upgrade to Symfony 8.1 or stay on 7.4 LTS.

Required components: Config, DependencyInjection, Form, HttpClient, HttpFoundation, HttpKernel, OptionsResolver, Translation, Twig Bridge, Validator, Asset, Twig and Guzzle PSR-7. Guzzle PSR-7 is used only as an explicit PSR-17 factory; Symfony's `Psr18Client` supplies PSR-18. No factory discovery is used. Symfony Security (`symfony/security-bundle`) is optional and needed only for login protection.

## Installation notes

```sh
composer require softavis/turnstile-bundle
```

Symfony Flex can automatically register a recognized Symfony bundle without a dedicated recipe. A recipe provides additional setup such as configuration files and environment placeholders; **no recipe for this package has been published yet**, so you must configure those yourself by following the [README steps](../README.md#getting-started). Check `config/bundles.php` and add the entry manually if it is missing, including when you do not use Flex:

```php
Softavis\TurnstileBundle\SoftavisTurnstileBundle::class => ['all' => true],
```

## Configuration reference

```yaml
# config/packages/softavis_turnstile.yaml
softavis_turnstile:
    enabled: true
    site_key: '%env(TURNSTILE_SITE_KEY)%'
    secret: '%env(TURNSTILE_SECRET)%'
    expected_hostnames: ['www.example.com', 'example.com']   # exact hostnames; omit to skip the check
    forward_client_ip: false
    asset_package: null
    turbo:
        enabled: false
    widget:
        theme: auto               # auto, light, dark
        size: normal              # normal, compact, flexible
        language: auto            # auto or a Cloudflare-supported language code
        appearance: always        # always, execute, interaction-only
        default_action: null
    login:
        enabled: false
        action: null              # required when login.enabled is true
        token_field: cf-turnstile-response
        firewalls: []             # empty: every firewall
    transport:
        http_client_service: null
        psr18_client_service: null
        psr17_factory_service: null
    verifier_service: null
```

With `enabled: true` (the default) both `site_key` and `secret` are required; blank values fail configuration. Environment placeholders resolve at runtime. Keep the secret in server-side configuration, never in Twig, HTML, JavaScript or a public variable.

## Forms and the validator

`TurnstileType` (`Softavis\TurnstileBundle\Form\TurnstileType`) is unmapped, not required, has no label and validates its token once with the `Turnstile` constraint. Options: `turnstile_action` (kept distinct from Symfony's `action` URL option), `site_key`, `theme`, `size`, `language`, `appearance` (defaults come from `widget`). The widget uses the field's `full_name`, so nested forms and custom names work. The built-in form theme is appended to your existing themes. The token is never echoed back after an invalid submission, so the re-rendered widget fetches a fresh one.

If you pass your own `Turnstile` constraint in `constraints`, there must be exactly one, and its `expectedAction` must match `turnstile_action` when both are set.

### Standalone constraint / DTO

```php
use Softavis\TurnstileBundle\Validator\Constraints\Turnstile;

final class SignupInput
{
    #[Turnstile(expectedAction: 'signup')]
    public ?string $turnstileToken = null;
}
```

The validator checks the exact value it receives; it does not read a fixed request parameter. `expectedHostnames` on the constraint overrides the global allowlist; without it the global list applies. CSRF protection and rate limiting remain separate, independent protections.

## Twig widget

For a plain HTML form:

```twig
{{ turnstile_widget('cf-turnstile-response', {action: 'login'}) }}
```

Signature: `turnstile_widget(name = 'cf-turnstile-response', options = {})`. Options: `action`, `site_key`, `theme`, `size`, `language`, `appearance`. It emits a hidden response input, the widget container and the bundle script. Multiple calls get distinct IDs; the field name must match what the receiving code reads.

## Login protection

Login protection is explicitly opt-in (`login.enabled: true` with a non-blank `login.action`). It is implemented as a `CheckPassportEvent` listener; it does not replace your authenticator and does not protect every POST.

```yaml
softavis_turnstile:
    login:
        enabled: true
        action: login
        token_field: cf-turnstile-response
        firewalls: [main]
```

```twig
{{ turnstile_widget('cf-turnstile-response', {action: 'login'}) }}
```

`login.token_field` must equal the widget's field name, and `login.action` the widget's `action`. Automatic protection applies to authenticators extending `AbstractLoginFormAuthenticator` whose passport has `PasswordCredentials`, which includes standard `form_login`. If `firewalls` is set, only those firewalls are covered.

### Custom authenticators: explicit opt-in

Other authenticators opt in by adding `Softavis\TurnstileBundle\Security\TurnstileBadge` (constructor: `string $response`, `string $action`) to their passport:

```php
use Softavis\TurnstileBundle\Security\TurnstileBadge;

$passport->addBadge(new TurnstileBadge(
    (string) $request->request->get('cf-turnstile-response', ''),
    'login',
));
```

The badge is resolved only after successful verification. If the authenticator also qualifies for automatic protection, the badge values are used and verification runs once. Defer user lookup to passport checking; the bundle cannot undo an eager lookup in `authenticate()`.

### Flows not protected by default

OAuth callbacks, `remember_me`, `http_basic`, `json_login`, `login_link`, API token/JWT authentication and programmatic `Security::login()` are not protected automatically. A custom flow can opt in with the badge.

## Action, hostname and client IP policy

Expected action and hostnames are server-controlled; never derive them from submitted data. The core library performs exact matching.

```yaml
softavis_turnstile:
    expected_hostnames: ['shop.example.com']
    widget:
        default_action: checkout
```

Client IP forwarding is off by default. With `forward_client_ip: true` the bundle sends only `Request::getClientIp()`, which honours Symfony's trusted proxy settings; configure `framework.trusted_proxies` and `framework.trusted_headers` correctly. The bundle never reads `X-Forwarded-For` itself.

## Assets and browser behavior

```sh
php bin/console assets:install public
```

The script URL is built with Symfony's `asset()`, so the application's base path applies, and it normally resolves to `/bundles/softavisturnstile/turnstile.js`. Set `asset_package` to a named package from `framework.assets.packages` to serve it from that package (otherwise the default package is used). The script loads Cloudflare's `https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit` directly; it is not proxied or bundled.

Browser lifecycle as implemented: widgets are rendered explicitly; the token is stored in the matching hidden input on success and cleared on expiry or error (`expired`/`error` events are dispatched on the container); a `MutationObserver` mounts widgets inserted later and removes those whose nodes are removed. `window.__softavisTurnstileBundle` exposes `scan(root)`, `reset(container)` and `remove(container)`; `reset` clears the token and asks Cloudflare for a new one. A normal failed form response renders a new widget and token. The script does not disable submit buttons. Turbo navigation remains unsupported unless the opt-in adapter below is enabled.

## Turbo integration

Turbo Drive support is opt-in and disabled by default:

```yaml
softavis_turnstile:
    turbo:
        enabled: true
```

Turbo remains an application dependency: the bundle neither installs nor loads Turbo, Stimulus, Symfony UX, or a frontend build pipeline. With the option enabled, both the Form theme and `turnstile_widget()` emit the normal `turnstile.js` runtime followed by the additional `turnstile-turbo.js` adapter. Repeated script tags are safe; the runtime and adapter each initialize only once. The existing `asset_package` setting applies to both URLs.

Run the normal asset installation command after enabling the option or upgrading the bundle:

```sh
php bin/console assets:install public
```

The adapter coordinates the runtime with Turbo's navigation lifecycle:

- It tears down Cloudflare-generated widget DOM and clears tokens on `turbo:before-cache`; the empty widget containers remain in the snapshot.
- Cached previews stay unmounted until the final `turbo:load`; normal navigation, cached back/forward restoration, and re-rendered validation responses then scan and mount fresh widgets.
- On `turbo:submit-end`, it resets only widgets in a form that is still connected. This covers network/HTTP failures and responses that leave the submitted form in place; it does not assume that every error response replaces the page.
- Browser `pagehide` and persisted `pageshow` use the shared runtime teardown/remount path for native bfcache restoration.
- Turbo's built-in morph lifecycle is supported: affected widgets are removed on `turbo:before-morph-element` and rescanned on `turbo:morph`. The adapter does not cancel morphs, so updated widget attributes and token-input references are applied before remounting.

The browser suite uses Turbo 8.0.23 and a deterministic mock of Cloudflare's API. It covers navigation and Turbo history restoration, previews, morph refreshes, validation responses, an aborted submission that leaves the form present, form isolation, repeated loads, and a plain page without Turbo. The runtime's persisted `pageshow` path is tested directly; the headless Chromium runner used here performed a full reload rather than a native bfcache restore. No live Cloudflare smoke test was possible because `challenges.cloudflare.com` did not resolve in this environment.

This is not general "Turbo/Stimulus" support: Stimulus itself needs no Turnstile adapter. Standalone Idiomorph changes do not emit Turbo morph events and are not covered. FrankenPHP live-reload/hot-reload behavior is not claimed or tested.

## Content Security Policy

Allow `https://challenges.cloudflare.com` in the directives the widget needs (at least `script-src`, `frame-src` and `connect-src`), and allow the bundle's script under your own `script-src`. The bundle adds no inline JavaScript and does not need `'unsafe-inline'`.

## Templates and translations

The Twig template namespace is `@SoftavisTurnstile`; the application override directory uses the bundle class name, `templates/bundles/SoftavisTurnstileBundle/` (see [Symfony's bundle override documentation](https://symfony.com/doc/current/bundles/override.html)).

- Plain widget: `@SoftavisTurnstile/widget.html.twig`, overridable at `templates/bundles/SoftavisTurnstileBundle/widget.html.twig`.
- Form theme: `@SoftavisTurnstile/form/turnstile_widget.html.twig` (block `turnstile_widget`), overridable at `templates/bundles/SoftavisTurnstileBundle/form/turnstile_widget.html.twig`, or by supplying your own form theme with that block.

Messages use the `validators` domain (English and Bulgarian included). Override `softavis_turnstile.invalid`, `softavis_turnstile.unavailable` and `softavis_turnstile.authentication_failed` in your catalogs.

## Errors

Users only see generic messages; transport details are never included. In forms, a rejected token (`softavis_turnstile.invalid`) is distinct from an operational failure (`softavis_turnstile.unavailable`). Login protection gives both the same `softavis_turnstile.authentication_failed` error. Missing or blank tokens are rejected. A failed login follows Symfony's normal authentication-failure path.

## Disabled mode and test keys

`enabled: false` allows missing credentials, renders only an empty hidden input, skips form validation and makes no verification request. Automatic login protection is skipped, and an explicit `TurnstileBadge` is marked resolved without a verifier call.

For development and automated tests you can use Cloudflare's official [test keys](https://developers.cloudflare.com/turnstile/troubleshooting/testing/). They are not production credentials, and the dummy tokens they produce are not real challenge results; see that page for their behavior.

## Advanced and development

### Transport and verifier customization

The default HTTP client uses a 5-second inactivity timeout and 10-second maximum duration and does not change application-wide HTTP aliases. Under `transport`, set `http_client_service` (an `HttpClientInterface` service ID), `psr18_client_service` and/or `psr17_factory_service` (implementing the request, response and stream factories). The bundle's defaults are `softavis_turnstile.http_client`, `softavis_turnstile.psr18_client` and `softavis_turnstile.psr17_factory`. To replace the verifier entirely, set `verifier_service` to a service implementing `Softavis\Turnstile\TurnstileVerifier`; it replaces the interface alias. Throw `Softavis\Turnstile\Exception\TurnstileException` for operational failures so the generic unavailable message is used.

### Listener ordering

The login listener runs on `CheckPassportEvent` at priority 384: after CSRF validation (512) and before user checking and password credentials (256/0). The user-provider listener (1024) only configures a lazy loader. Login throttling (2080) therefore runs before Turnstile.

### Development commands

```sh
composer install
composer test
composer analyse
composer style
npm ci
npx playwright install chromium
npm test
```

### Test architecture and limitations

PHP tests stub `TurnstileVerifier` and include functional HTTP authentication tests on the supported Symfony series; no live Cloudflare requests are made. The JavaScript lifecycle test uses a Node VM with a local Turnstile API stub. The project has not been validated against live Cloudflare or real CSP deployments. The opt-in Turbo behavior is exercised in a local browser using Turbo and a mocked Cloudflare API. One browser test renders the bundle's real Twig templates (Form theme and `turnstile_widget()`, with `<script>` tags in `<body>` and the hidden input after them) to cover a first visit from a page without a widget, cached previews, back/forward and repeated script tags; it does not establish support for other SPA or hot-reload integrations.
