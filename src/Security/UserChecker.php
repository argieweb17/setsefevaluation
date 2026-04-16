<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->getAccountStatus() === 'pending') {
            throw new CustomUserMessageAccountStatusException(
                'Your account is pending admin approval. Please wait for an administrator to approve your registration.'
            );
        }

        if ($user->getAccountStatus() === 'inactive') {
            throw new CustomUserMessageAccountStatusException(
                'Your account has been deactivated. Please contact an administrator.'
            );
        }

        if (!$this->hasAllowedEmailDomain($user)) {
            throw new CustomUserMessageAccountStatusException(
                'Email address must end with @norsu.edu.ph.'
            );
        }

        if ($this->requiresInstitutionalCredentials($user) && !$this->hasValidInstitutionalCredentials($user)) {
            throw new CustomUserMessageAccountStatusException(
                'Faculty, Staff, and Superior accounts must have an @norsu.edu.ph email and a registered ID. Please contact an administrator.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // No post-auth checks needed
    }

    private function requiresInstitutionalCredentials(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array('ROLE_FACULTY', $roles, true)
            || in_array('ROLE_STAFF', $roles, true)
            || in_array('ROLE_SUPERIOR', $roles, true);
    }

    private function hasValidInstitutionalCredentials(User $user): bool
    {
        $email = trim((string) $user->getEmail());
        $schoolId = trim((string) $user->getSchoolId());

        if ($schoolId === '') {
            return false;
        }

        return str_ends_with(mb_strtolower($email), '@norsu.edu.ph');
    }

    private function hasAllowedEmailDomain(User $user): bool
    {
        $email = trim((string) $user->getEmail());

        if ($email === '') {
            return true;
        }

        return str_ends_with(mb_strtolower($email), '@norsu.edu.ph');
    }
}
