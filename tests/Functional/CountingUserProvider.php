<?php

declare(strict_types=1);

namespace Softavis\TurnstileBundle\Tests\Functional;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class CountingUserProvider implements UserProviderInterface
{
    public int $loads = 0;

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        ++$this->loads;
        if ($identifier !== 'alice') {
            throw new UserNotFoundException();
        }

        return new InMemoryUser('alice', password_hash('correct-password', PASSWORD_BCRYPT), ['ROLE_USER']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return is_a(InMemoryUser::class, $class, true);
    }
}
