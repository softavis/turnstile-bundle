<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Form;

use Softavis\TurnstileBundle\Validator\Constraints\Turnstile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraint;

final class TurnstileType extends AbstractType
{
    /**
     * @param array{theme: string, size: string, language: string, appearance: string, default_action: ?string} $widgetDefaults
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly ?string $siteKey,
        private readonly array $widgetDefaults,
        private readonly ?string $assetPackage,
        private readonly bool $turboEnabled,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'mapped' => false,
            'compound' => false,
            'required' => false,
            'label' => false,
            'site_key' => $this->siteKey,
            'theme' => $this->widgetDefaults['theme'],
            'size' => $this->widgetDefaults['size'],
            'language' => $this->widgetDefaults['language'],
            'appearance' => $this->widgetDefaults['appearance'],
            'turnstile_action' => $this->widgetDefaults['default_action'],
            'constraints' => [],
        ]);
        $resolver->setAllowedTypes('site_key', ['null', 'string']);
        $resolver->setAllowedTypes('turnstile_action', ['null', 'string']);
        $resolver->setAllowedTypes('theme', 'string');
        $resolver->setAllowedTypes('size', 'string');
        $resolver->setAllowedTypes('language', 'string');
        $resolver->setAllowedTypes('appearance', 'string');
        $resolver->setAllowedValues('turnstile_action', static fn (?string $action): bool => $action === null || trim($action) !== '');
        $resolver->setAllowedValues('theme', ['auto', 'light', 'dark']);
        $resolver->setAllowedValues('size', ['normal', 'compact', 'flexible']);
        $resolver->setAllowedValues('appearance', ['always', 'execute', 'interaction-only']);
        $resolver->setNormalizer('constraints', static function (Options $options, mixed $constraints): array {
            if ($constraints instanceof Constraint) {
                $constraints = [$constraints];
            }
            if (!is_array($constraints)) {
                throw new \InvalidArgumentException('Turnstile form constraints must be an array or a Constraint.');
            }

            $turnstileConstraints = array_filter($constraints, static fn (mixed $constraint): bool => $constraint instanceof Turnstile);
            if (count($turnstileConstraints) > 1) {
                throw new \InvalidArgumentException('A Turnstile form field must have exactly one Turnstile constraint.');
            }
            if ($turnstileConstraints !== []) {
                $turnstileConstraint = reset($turnstileConstraints);
                if ($options['turnstile_action'] !== null && $turnstileConstraint->expectedAction !== $options['turnstile_action']) {
                    throw new \InvalidArgumentException('The widget and Turnstile constraint actions must match.');
                }

                return $constraints;
            }

            $constraints[] = new Turnstile(expectedAction: $options['turnstile_action']);

            return $constraints;
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        parent::buildView($view, $form, $options);
        foreach (['site_key', 'theme', 'size', 'language', 'appearance'] as $option) {
            $view->vars[$option] = $options[$option];
        }
        $view->vars['enabled'] = $this->enabled;
        $view->vars['asset_package'] = $this->assetPackage;
        $view->vars['turbo_enabled'] = $this->turboEnabled;
        $action = $options['turnstile_action'];
        if ($action === null) {
            foreach ($options['constraints'] as $constraint) {
                if ($constraint instanceof Turnstile) {
                    $action = $constraint->expectedAction;

                    break;
                }
            }
        }
        $view->vars['turnstile_action'] = $action;
    }

    public function getBlockPrefix(): string
    {
        return 'turnstile';
    }
}
