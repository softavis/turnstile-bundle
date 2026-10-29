# softavis/turnstile-bundle

[![Latest stable version](https://img.shields.io/packagist/v/softavis/turnstile-bundle?style=flat-square)](https://packagist.org/packages/softavis/turnstile-bundle)
[![CI](https://img.shields.io/github/actions/workflow/status/softavis/turnstile-bundle/ci.yml?branch=master&label=CI&style=flat-square)](https://github.com/softavis/turnstile-bundle/actions/workflows/ci.yml)
[![Total downloads](https://img.shields.io/packagist/dt/softavis/turnstile-bundle?style=flat-square)](https://packagist.org/packages/softavis/turnstile-bundle/stats)
[![PHP](https://img.shields.io/packagist/php-v/softavis/turnstile-bundle?style=flat-square)](composer.json)
[![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.1-informational?style=flat-square)](docs/index.md#requirements)
[![License](https://img.shields.io/packagist/l/softavis/turnstile-bundle?style=flat-square)](LICENSE)

Symfony Forms, Twig and optional `form_login` integration for [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/).

This bundle exists to provide Symfony integration on top of [`softavis/turnstile`](https://github.com/softavis/turnstile), our reusable, framework-independent verification core. The core owns the Siteverify protocol and verification policy (see its [README](https://github.com/softavis/turnstile#readme)); this bundle adds the form type, validator constraint, Twig widget, browser script and optional Security listener.

## Requirements

- PHP 8.2+ with Symfony 7.4 LTS, or PHP 8.4.1+ with Symfony 8.1 (Symfony's own requirement).
- Symfony 8.0 is not supported. See [Requirements](docs/index.md#requirements).
- Symfony Security is optional (only for login protection).

## Getting started

### 1. Install

```sh
composer require softavis/turnstile-bundle
```

### 2. Register the bundle

Symfony Flex can automatically register a recognized Symfony bundle even when it has no dedicated recipe. A recipe provides additional setup, such as configuration files and environment placeholders; **no recipe for this package has been published yet**, so you must add that setup yourself. Check `config/bundles.php` and add the bundle if it is missing (including when you do not use Flex):

```php
// config/bundles.php
return [
    // ...
    Softavis\TurnstileBundle\SoftavisTurnstileBundle::class => ['all' => true],
];
```

### 3. Get Cloudflare keys

In the Cloudflare dashboard, open **Turnstile**, add a widget for your hostname(s) and copy the **site key** (public) and **secret key** (private). Store them as environment variables; keep the secret out of version control, Twig and JavaScript:

```dotenv
# .env.local
TURNSTILE_SITE_KEY=your-public-site-key
TURNSTILE_SECRET=your-private-secret
```

### 4. Configure the bundle

```yaml
# config/packages/softavis_turnstile.yaml
softavis_turnstile:
    site_key: '%env(TURNSTILE_SITE_KEY)%'
    secret: '%env(TURNSTILE_SECRET)%'
    expected_hostnames: ['www.example.com']
```

`expected_hostnames` is a server-controlled allowlist of exact hostnames; tokens issued for other hostnames are rejected. Without it, no hostname check is made.

### 5. Install the public assets

```sh
php bin/console assets:install public
```

For applications using Turbo Drive, the optional adapter can be enabled in bundle configuration:

```yaml
softavis_turnstile:
    turbo:
        enabled: true
```

It is disabled by default. When enabled, the bundle loads its `turnstile-turbo.js` adapter after the normal `turnstile.js` runtime; it does not install or require Turbo. Run `assets:install public` again after enabling it or upgrading the bundle so both public files are available.

### 6. Add the field to a form

```php
use Softavis\TurnstileBundle\Form\TurnstileType;

$builder
    ->add('email')
    ->add('turnstile', TurnstileType::class, ['turnstile_action' => 'signup']);
```

Render the form as usual (`{{ form(form) }}` or `{{ form_row(form.turnstile) }}`). The field is unmapped; the widget action and the server-side expected action both come from `turnstile_action`.

### 7. Protect `form_login` (optional)

Login protection is **off by default** and requires Symfony Security. Enable it explicitly:

```yaml
# config/packages/softavis_turnstile.yaml
softavis_turnstile:
    # site_key, secret, expected_hostnames as above
    login:
        enabled: true
        action: login
        token_field: cf-turnstile-response
        firewalls: [main]
```

Render the widget in your login template with the same field name and action:

```twig
<form method="post" action="{{ path('app_login') }}">
    {# also include your CSRF token field #}
    <input type="text" name="_username">
    <input type="password" name="_password">
    {{ turnstile_widget('cf-turnstile-response', {action: 'login'}) }}
    <button type="submit">Sign in</button>
</form>
```

Missing or invalid tokens reject the login with a generic message.

## Learn more

The [documentation](docs/index.md) covers standalone validation, custom authenticators, disabled mode and test keys, trusted proxies, assets, template/translation overrides, CSP, limitations and development.

## Alternatives

- [vuillaume-agency/symfony-turnstile](https://github.com/vuillaume-agency/symfony-turnstile)
- [pixelopen/cloudflare-turnstile-bundle](https://github.com/pixelopen/cloudflare-turnstile-bundle)

## License

MIT. See [LICENSE](LICENSE).
