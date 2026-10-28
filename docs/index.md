# softavis/turnstile-bundle

This bundle connects Symfony Forms, Validator, Twig and optional Security login flows to the framework-independent [`softavis/turnstile`](https://github.com/softavis/turnstile) package. Siteverify requests and response semantics stay in the core library; this bundle does not copy or reinterpret that protocol.

## Installation

**Publication status:** this bundle is not yet on Packagist. The core library is available as the stable `softavis/turnstile` 1.0.0 release. To try this repository before bundle publication, add its VCS source and require the PR branch:

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/softavis/turnstile-bundle"}
  ],
  "require": {
    "softavis/turnstile-bundle": "dev-feat/turnstile-bundle@dev"
  }
}
```

Composer's package stability flag is scoped to this dependency; do not lower your application's global `minimum-stability`. When the bundle is released to Packagist, use `composer require softavis/turnstile-bundle`. No core VCS repository declaration is needed; the bundle requires `softavis/turnstile:^1.0`.

With Symfony Flex, allow it to register the bundle. Otherwise add it to `config/bundles.php`:

```php
return [
    // ...
    Softavis\TurnstileBundle\SoftavisTurnstileBundle::class => ['all' => true],
];
```

The bundle needs Symfony Config, DependencyInjection, Form, HttpClient, HttpFoundation, HttpKernel, OptionsResolver, Translation, Twig Bridge, Validator, Twig and Guzzle PSR-7. Guzzle PSR-7 is used only as an explicit PSR-17 factory implementation; Symfony HttpClient's `Psr18Client` bridge supplies PSR-18. No factory discovery is used. Symfony Security is optional.

## Credentials and configuration

Keep the secret in server-side configuration, never in Twig, HTML, JavaScript, or a public environment variable. Configure secrets with Symfony's normal environment/configuration mechanisms (for example `.env.local`, deployment secrets, or vault-backed `%env(...)%`):

```dotenv
TURNSTILE_SITE_KEY=your-public-site-key
TURNSTILE_SECRET=your-private-secret
```

```yaml
# config/packages/softavis_turnstile.yaml
softavis_turnstile:
    enabled: true
    site_key: '%env(TURNSTILE_SITE_KEY)%'
    secret: '%env(TURNSTILE_SECRET)%'
    expected_hostnames: ['www.example.com', 'example.com']
    forward_client_ip: false
    asset_package: null
    widget:
        theme: auto             # auto, light, dark
        size: normal             # normal, compact, flexible
        language: auto           # auto or a Cloudflare-supported language code
        appearance: always       # always, execute, interaction-only
        default_action: null
    login:
        enabled: false
        action: null
        token_field: cf-turnstile-response
        firewalls: []
    transport:
        http_client_service: null
        psr18_client_service: null
        psr17_factory_service: null
```

Verification is enabled by default. It requires both `site_key` and `secret`; missing or blank explicit values fail configuration with an actionable message. Environment placeholders resolve at runtime, so an undefined environment variable is reported by Symfony when the value is first needed. Setting `enabled: false` permits missing credentials, renders only an empty hidden input, skips form validation and makes no verification request. Automatic Security protection is skipped. An explicitly attached `TurnstileBadge` is marked resolved as a bypass without a verifier call, allowing normal authentication checks to proceed.

The defaults use a 5-second Symfony HttpClient inactivity timeout and a 10-second maximum request duration. This bundle does not change application-wide HTTP aliases. To customize transport wiring, set `transport.http_client_service` to a Symfony `HttpClientInterface` service ID, `transport.psr18_client_service` to a PSR-18 client service ID, and/or `transport.psr17_factory_service` to a PSR-17 factory service implementing the request, response and stream factory interfaces. The bundle-specific defaults are `softavis_turnstile.http_client`, `softavis_turnstile.psr18_client` and `softavis_turnstile.psr17_factory`. For a complete custom verifier, set `verifier_service` to an existing service ID implementing `Softavis\Turnstile\TurnstileVerifier`; that service replaces the interface alias. Keep operational failures represented as `Softavis\Turnstile\Exception\TurnstileException` so the bundle can return its generic unavailable message.

Asset URLs use Symfony's `asset()` function. By default, the configured default asset package is used; set `asset_package` to a configured named package when the bundle's static asset uses one.

## Symfony Forms and Validator

Add `TurnstileType` to any form. It is unmapped, does not alter the rest of the form data, and validates the token value supplied to the Validator:

```php
use Softavis\TurnstileBundle\Form\TurnstileType;

$builder
    ->add('email')
    ->add('turnstile', TurnstileType::class, ['turnstile_action' => 'signup']);
```

The action on the widget and the server-side expected action are taken from the same `turnstile_action` option (kept distinct from Symfony's built-in form `action` URL option). The field uses Symfony's actual `full_name`, so nested forms and custom field names submit correctly. The built-in form theme is appended to the application's existing themes; it does not replace them. The token is not echoed back into the HTML after an invalid submission, allowing the freshly-rendered widget to issue a fresh token.

Use the reusable constraint directly on a DTO property or value when appropriate:

```php
use Softavis\TurnstileBundle\Validator\Constraints\Turnstile;

final class SignupInput
{
    #[Turnstile(expectedAction: 'signup')]
    public ?string $turnstileToken = null;
}
```

The validator checks that exact value; it does not reach into a fixed request parameter. Explicit constraint hostnames override the global hostname allowlist. Without a per-constraint list, the global list is applied. CSRF protection and rate limiting remain independent application protections.

## Twig and standard `form_login`

For a plain HTML form, the Twig function emits a hidden response input, escaped widget attributes, and the local lifecycle loader:

```twig
<form method="post">
    {# Include your independent CSRF and rate-limit protections. #}
    {{ turnstile_widget('cf-turnstile-response', {action: 'login'}) }}
    <button type="submit">Sign in</button>
</form>
```

Choose the exact request field expected by the receiving form/authenticator. Multiple calls receive distinct IDs; the Cloudflare `api.js` loader is shared and uses explicit rendering, not global callback names. Install the bundle's asset into the application's public directory using Symfony's normal asset installation:

```sh
php bin/console assets:install public
```

The browser script URL is generated with Symfony's `asset()` function, respecting the configured base path and asset package. After `assets:install`, it normally resolves under `/bundles/softavisturnstile/turnstile.js`. It loads Cloudflare's official `https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit` directly; this bundle does not proxy or bundle Cloudflare's script. Plain Twig markup is rendered from `templates/widget.html.twig`, which can be overridden at `templates/bundles/SoftavisTurnstile/widget.html.twig`.

The script exposes `window.__softavisTurnstileBundle` with `scan(root)`, `reset(container)` and `remove(container)` methods. It keeps tokens in the matching hidden input, clears them on expiry/error, and cleans up Turnstile widgets when their DOM nodes are removed. A normal failed form response renders a new widget/token. The DOM observer supports widgets inserted or removed by partial DOM updates, but this is **not** a Turbo/Stimulus integration and is not a claim of full SPA navigation support. No submit-button disabling is performed.

### Content Security Policy

Permit Cloudflare's `https://challenges.cloudflare.com` origin in the directives required by the widget (at minimum `script-src`, `frame-src`, and `connect-src`). Also permit the bundle asset under your own application's script policy. The bundle adds no inline JavaScript and does not require `'unsafe-inline'`; adapt policies to your own CSP nonce/hash and Cloudflare setup.

## Expected action, hostname and client IP

Set a server-controlled expected action on a form or constraint, and configure only exact hostnames that should be accepted:

```yaml
softavis_turnstile:
    expected_hostnames: ['shop.example.com']
    widget:
        default_action: checkout
```

The core library performs exact action and hostname matching. It is not safe to derive expected values from submitted form data. Setting no hostname allowlist disables this extra hostname check.

Client IP forwarding is off by default. If required, set `forward_client_ip: true`. The bundle uses only Symfony `Request::getClientIp()`, which respects Symfony's trusted proxy configuration; configure `framework.trusted_proxies` and `framework.trusted_headers` correctly. Do not independently read `X-Forwarded-For`.

## Optional Security login protection

Security integration is explicitly opt-in. It uses `CheckPassportEvent`; it does not install a replacement authenticator or protect every POST/password-bearing passport.

### Standard `form_login` and login-form authenticators

Enable the feature with an action value and optionally restrict automatic detection to named firewall(s):

```yaml
softavis_turnstile:
    login:
        enabled: true
        action: login
        token_field: cf-turnstile-response
        firewalls: [main]
```

Automatic protection applies only to authenticators extending Symfony's `AbstractLoginFormAuthenticator` whose passport contains `PasswordCredentials`. This includes standard `form_login` and custom login-form authenticators following that base class. The token is read from the configured root request field. Use the same `login` action in the Twig widget.

Ordering is exercised through real HTTP authentication tests on Symfony 7.4 and 8.1: the Turnstile listener runs at priority **384**, after CSRF validation (512) and before user checking/password credentials (256/0). The user-provider listener at 1024 configures a lazy user loader but does not itself call it. Login throttling (2080) therefore still runs before Turnstile, and failed verification follows the normal authentication-failure path.

Missing/invalid tokens and operational errors reject login with the same translated generic authentication message; transport details are not included. Other failures and success handlers remain Symfony's responsibility.

### Explicit custom-authenticator opt-in

For another custom authenticator, opt in only by attaching `TurnstileBadge` to its passport:

```php
use Softavis\TurnstileBundle\Security\TurnstileBadge;

$passport->addBadge(new TurnstileBadge(
    (string) $request->request->get('cf-turnstile-response', ''),
    'login',
));
```

The badge carries both token and expected action. It is resolved only after a successful verification. If the authenticator also qualifies for automatic protection, a single listener invocation uses the badge values and verifies once. Custom authenticators must defer user lookup until passport checking; the bundle cannot undo an eager lookup performed inside `authenticate()`.

OAuth callbacks, `remember_me`, `http_basic`, `json_login`, `login_link`, API token/JWT authentication and programmatic `Security::login()` are not automatically protected. A custom flow can opt in explicitly with the badge.

## Templates, translations and errors

The form theme lives at `templates/form/turnstile_widget.html.twig` and is registered additively. Override it in your application by copying the block into an application-owned theme and selecting it after/before other themes as appropriate. The plain Twig helper is `turnstile_widget(name, options)`.

Messages use Symfony's `validators` translation domain; English and Bulgarian catalogs are included. Override `softavis_turnstile.invalid`, `softavis_turnstile.unavailable` and `softavis_turnstile.authentication_failed` in your application catalogs. Token rejection is distinct from an operational verification failure in form validation. Security login intentionally gives both the same generic authentication error.

## Disabled mode and Cloudflare test keys

Cloudflare provides official test sitekeys and secrets in its [Turnstile testing documentation](https://developers.cloudflare.com/turnstile/troubleshooting/testing/). Use only those official test values in isolated development/testing environments. They are not production credentials. Alternatively set `enabled: false` to test non-widget application behavior; in disabled mode no verification call is made.

## Development

```sh
composer install
composer test
composer analyse
composer style
```

Bundle-level verification tests stub `TurnstileVerifier`; there are no live Cloudflare requests in this repository. The JavaScript lifecycle has a focused Node VM test using a local Turnstile API stub; it is not a real browser or Cloudflare validation. CI pins each Symfony matrix job to its declared minor series and runs a lowest-supported dependency job. Symfony 8.0 is not supported because Composer security advisories block its available releases. Live credentials, CSP deployments, and full SPA/Turbo behavior are outside the automated test scope.

## Alternatives

- [vuillaume-agency/symfony-turnstile](https://github.com/vuillaume-agency/symfony-turnstile)
- [pixelopen/cloudflare-turnstile-bundle](https://github.com/pixelopen/cloudflare-turnstile-bundle)
