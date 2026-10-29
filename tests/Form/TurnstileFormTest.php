<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Form;

use PHPUnit\Framework\TestCase;
use Softavis\TurnstileBundle\Tests\StubVerifier;
use Softavis\TurnstileBundle\Tests\Functional\TestKernel;
use Softavis\TurnstileBundle\Validator\Constraints\Turnstile;
use Symfony\Component\Form\Extension\Core\Type\FormType;

final class TurnstileFormTest extends TestCase
{
    public function testManualConstructionWithoutTurboArgumentKeepsTurboDisabled(): void
    {
        $type = new \Softavis\TurnstileBundle\Form\TurnstileType(true, 'key', [
            'theme' => 'auto',
            'size' => 'normal',
            'language' => 'auto',
            'appearance' => 'always',
            'default_action' => null,
        ], null);
        $view = new \Symfony\Component\Form\FormView();
        $form = $this->createMock(\Symfony\Component\Form\FormInterface::class);
        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $resolver->setDefaults(['constraints' => []]);
        $type->configureOptions($resolver);
        $type->buildView($view, $form, $resolver->resolve(['site_key' => 'key']));

        self::assertFalse($view->vars['turbo_enabled']);
    }

    public function testNestedFormUsesItsRealFieldNameAndVerifiesOnlyOnce(): void
    {
        $kernel = new TestKernel([
            'enabled' => true,
            'site_key' => 'public-key',
            'secret' => 'private-secret',
        ]);
        $kernel->boot();
        try {
            $services = $kernel->getContainer()->get('test.service_container');
            $factory = $services->get('test.form_factory');
            $builder = $factory->createNamedBuilder('registration', FormType::class, null, ['action' => '']);
            $nested = $factory->createNamedBuilder('account', FormType::class, null, ['action' => '']);
            $nested->add('human_check', \Softavis\TurnstileBundle\Form\TurnstileType::class, [
                'constraints' => new Turnstile(expectedAction: 'signup'),
            ]);
            $builder->add($nested)->add('display_name');
            $form = $builder->getForm();
            $form->submit([
                'account' => ['human_check' => 'nested-token'],
                'display_name' => 'Ada',
            ]);

            $verifier = $services->get('test.turnstile_verifier');
            self::assertInstanceOf(StubVerifier::class, $verifier);
            self::assertCount(1, $verifier->requests);
            self::assertSame('nested-token', $verifier->requests[0]->response);
            self::assertSame('signup', $verifier->requests[0]->expectedAction);
            self::assertSame('Ada', $form->get('display_name')->getData());
            self::assertSame('registration[account][human_check]', $form->get('account')->get('human_check')->createView()->vars['full_name']);
            self::assertSame('signup', $form->get('account')->get('human_check')->createView()->vars['turnstile_action']);
            self::assertTrue($form->isValid());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testRejectedTokenDoesNotDiscardOtherFormData(): void
    {
        $kernel = new TestKernel([
            'enabled' => true,
            'site_key' => 'public-key',
            'secret' => 'private-secret',
        ]);
        $kernel->boot();
        try {
            $services = $kernel->getContainer()->get('test.service_container');
            $factory = $services->get('test.form_factory');
            $verifier = $services->get('test.turnstile_verifier');
            $verifier->response = new \Softavis\Turnstile\VerificationResult(false);
            $form = $factory->createBuilder(FormType::class, null, ['action' => ''])
                ->add('captcha', \Softavis\TurnstileBundle\Form\TurnstileType::class)
                ->add('name')
                ->getForm();
            $form->submit(['captcha' => 'rejected', 'name' => 'Grace']);

            self::assertCount(1, $verifier->requests);
            self::assertSame('Grace', $form->get('name')->getData());
            self::assertFalse($form->isValid());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDisabledModeCompilesWithoutCredentialsAndDoesNotVerify(): void
    {
        $kernel = new TestKernel(['enabled' => false], false, false);
        $kernel->boot();
        try {
            $services = $kernel->getContainer()->get('test.service_container');
            $form = $services->get('test.form_factory')
                ->createBuilder(FormType::class, null, ['action' => ''])
                ->add('captcha', \Softavis\TurnstileBundle\Form\TurnstileType::class)
                ->getForm();
            $form->submit(['captcha' => 'ignored']);

            self::assertInstanceOf(
                \Softavis\TurnstileBundle\Service\DisabledTurnstileVerifier::class,
                $services->get('test.verifier_inspection'),
            );
            self::assertTrue($form->isValid());
        } finally {
            $kernel->shutdown();
        }
    }
}
