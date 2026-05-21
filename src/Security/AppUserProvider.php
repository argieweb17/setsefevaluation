<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads a user by email OR schoolId, allowing students to log in with their Student ID.
 */
class AppUserProvider implements UserProviderInterface
{
    public function __construct(private UserRepository $userRepo) {}

    private function normalizeSchoolId(string $identifier): string
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return '';
        }

        // Keep alpha-containing IDs untouched; only collapse separators for numeric student IDs.
        if (preg_match('/[A-Za-z]/', $trimmed) === 1) {
            return $trimmed;
        }

        return preg_replace('/\D+/', '', $trimmed) ?? $trimmed;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $identifier = trim($identifier);

        // Try email first
        $user = $this->userRepo->findOneBy(['email' => $identifier]);

        // Then try schoolId
        if (!$user) {
            $user = $this->userRepo->findOneBy(['schoolId' => $identifier]);
        }

        if (!$user) {
            $normalizedSchoolId = $this->normalizeSchoolId($identifier);
            if ($normalizedSchoolId !== '' && $normalizedSchoolId !== $identifier) {
                $user = $this->userRepo->findOneBy(['schoolId' => $normalizedSchoolId]);
            }
        }

        if (!$user) {
            $e = new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
            $e->setUserIdentifier($identifier);
            throw $e;
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new \InvalidArgumentException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        // Reload by primary key to avoid issues when email is null (students)
        $refreshed = $this->userRepo->find($user->getId());

        if (!$refreshed) {
            $e = new UserNotFoundException(sprintf('User with ID "%s" not found.', $user->getId()));
            $e->setUserIdentifier($user->getUserIdentifier());
            throw $e;
        }

        return $refreshed;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}
