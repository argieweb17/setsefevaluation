<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $firstName = null;

    #[ORM\Column(length: 255)]
    private ?string $lastName = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 50)]
    private string $accountStatus = 'active'; // active, inactive

    #[ORM\ManyToOne(targetEntity: Department::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Department $department = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $employmentStatus = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $position = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $academicRank = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $campus = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $profilePicture = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $schoolId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $yearLevel = null;

    #[ORM\ManyToOne(targetEntity: Course::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Course $course = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastLogin = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email; return $this; }

    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(string $firstName): static { $this->firstName = $firstName; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(string $lastName): static { $this->lastName = $lastName; return $this; }

    public function getUserIdentifier(): string { return (string) ($this->email ?? $this->schoolId); }

    /** @return list<string> */
    public function getRoles(): array
    {
        // Superior is a derived capability reserved for department-head/chair faculty.
        $hasLegacySuperiorRole = in_array('ROLE_SUPERIOR', $this->roles, true);
        $roles = array_values(array_filter($this->roles, static fn ($role): bool => $role !== 'ROLE_SUPERIOR'));

        // Backward compatibility: old registrations may have stored ROLE_SUPERIOR only.
        if ($hasLegacySuperiorRole && !in_array('ROLE_FACULTY', $roles, true)) {
            $roles[] = 'ROLE_FACULTY';
        }

        if ($this->isDepartmentHeadFaculty()) {
            $roles[] = 'ROLE_SUPERIOR';
        } elseif ($hasLegacySuperiorRole) {
            // Preserve explicitly assigned Superior accounts.
            $roles[] = 'ROLE_SUPERIOR';
        }

        $hasPrivilegedRole = false;
        foreach (['ROLE_ADMIN', 'ROLE_SUPERIOR', 'ROLE_STAFF', 'ROLE_FACULTY'] as $privilegedRole) {
            if (in_array($privilegedRole, $roles, true)) {
                $hasPrivilegedRole = true;
                break;
            }
        }

        // Students are represented by absence of privileged roles; expose explicit ROLE_STUDENT for API clients.
        if (!$hasPrivilegedRole && !in_array('ROLE_STUDENT', $roles, true)) {
            $roles[] = 'ROLE_STUDENT';
        }

        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function eraseCredentials(): void {}

    public function getAccountStatus(): string { return $this->accountStatus; }
    public function setAccountStatus(string $v): static { $this->accountStatus = $v; return $this; }

    public function getDepartment(): ?Department { return $this->department; }
    public function setDepartment(?Department $v): static { $this->department = $v; return $this; }

    public function getEmploymentStatus(): ?string { return $this->employmentStatus; }
    public function setEmploymentStatus(?string $v): static { $this->employmentStatus = $v; return $this; }

    public function getPosition(): ?string { return $this->position; }
    public function setPosition(?string $v): static { $this->position = $v; return $this; }

    public function getAcademicRank(): ?string { return $this->academicRank; }
    public function setAcademicRank(?string $v): static { $this->academicRank = $v; return $this; }

    public function getProfilePicture(): ?string { return $this->profilePicture; }
    public function setProfilePicture(?string $v): static { $this->profilePicture = $v; return $this; }
    public function getProfilePictureOrDefault(): string { return $this->profilePicture ? 'profiles/' . ltrim($this->profilePicture, '/') : 'default-profile.svg'; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }

    public function getLastLogin(): ?\DateTimeInterface { return $this->lastLogin; }
    public function setLastLogin(?\DateTimeInterface $v): static { $this->lastLogin = $v; return $this; }

    public function getSchoolId(): ?string { return $this->schoolId; }
    public function setSchoolId(?string $v): static { $this->schoolId = $v; return $this; }

    public function getYearLevel(): ?string { return $this->yearLevel; }
    public function setYearLevel(?string $v): static { $this->yearLevel = $v; return $this; }

    public function getCourse(): ?Course { return $this->course; }
    public function setCourse(?Course $v): static { $this->course = $v; return $this; }

    public function getCampus(): ?string { return $this->campus; }
    public function setCampus(?string $v): static { $this->campus = $v; return $this; }

    public function getFullName(): string { return $this->firstName . ' ' . $this->lastName; }

    public function isAdmin(): bool { return in_array('ROLE_ADMIN', $this->roles); }

    public function isSuperior(): bool { return in_array('ROLE_SUPERIOR', $this->getRoles(), true); }

    public function hasAssignedRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isDepartmentHeadFaculty(): bool
    {
        if (!in_array('ROLE_FACULTY', $this->roles, true)) {
            return false;
        }

        $employmentStatus = mb_strtolower(trim((string) $this->employmentStatus));
        if ($employmentStatus === '') {
            return false;
        }

        return str_contains($employmentStatus, 'head') || str_contains($employmentStatus, 'chair');
    }

    public function isStaff(): bool { return in_array('ROLE_STAFF', $this->roles); }

    public function isFaculty(): bool { return in_array('ROLE_FACULTY', $this->roles); }

    public function isStudent(): bool
    {
        return !$this->isAdmin() && !$this->isSuperior() && !$this->isStaff() && !$this->isFaculty();
    }

    public function isActive(): bool { return $this->accountStatus === 'active'; }
}
