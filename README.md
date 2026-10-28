# softavis/turnstile-bundle

[![CI](https://github.com/softavis/turnstile-bundle/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/softavis/turnstile-bundle/actions/workflows/ci.yml) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)](https://www.php.net/) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.1-black.svg)](https://symfony.com/) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
<!-- Packagist badges: enable after the bundle is published.
[![Latest Stable Version](https://poser.pugx.org/softavis/turnstile-bundle/v/stable)](https://packagist.org/packages/softavis/turnstile-bundle)
[![Total Downloads](https://poser.pugx.org/softavis/turnstile-bundle/downloads)](https://packagist.org/packages/softavis/turnstile-bundle)
-->

Symfony Forms, Twig and (optionally) password-login integration for Cloudflare Turnstile, backed by [`softavis/turnstile`](https://github.com/softavis/turnstile). The core library owns Siteverify HTTP, response parsing, hostname/action matching and rejection classification; this bundle supplies Symfony integration and the browser widget.

> This bundle is not yet published to Packagist. It uses the released `softavis/turnstile` 1.x API; see [installation](docs/index.md#installation) to install this repository before publication.

## Requirements

PHP 8.2+; Symfony 7.4 LTS and Symfony 8.1. Symfony 8.0 is excluded because Composer security advisories currently block its available releases. Symfony Security is optional. CI exercises Symfony 7.4 and 8.1, including a lowest-dependency job.

## Quick start

Set `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET` in your environment, register the bundle if Flex has not done so, and configure:

```yaml
# config/packages/softavis_turnstile.yaml
softavis_turnstile:
    site_key: '%env(TURNSTILE_SITE_KEY)%'
    secret: '%env(TURNSTILE_SECRET)%'
    expected_hostnames: ['example.com']
```

Add a widget to a Symfony form:

```php
use Softavis\TurnstileBundle\Form\TurnstileType;

$builder->add('turnstile', TurnstileType::class, ['turnstile_action' => 'signup']);
```

The field is unmapped and validates its submitted token once. Its generated Symfony field name supports nested forms and custom field names. For an ordinary Twig form, use `{{ turnstile_widget('cf-turnstile-response', {action: 'login'}) }}`.

The password-login integration is **off by default**. See the [complete guide](docs/index.md) for credentials, firewall scoping, authenticators, trusted client IPs, templates, translations, CSP and limitations.

## Related integrations

- [vuillaume-agency/symfony-turnstile](https://github.com/vuillaume-agency/symfony-turnstile)
- [pixelopen/cloudflare-turnstile-bundle](https://github.com/pixelopen/cloudflare-turnstile-bundle)
