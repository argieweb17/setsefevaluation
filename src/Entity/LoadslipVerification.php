<?php

namespace App\Entity;

use App\Repository\LoadslipVerificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LoadslipVerificationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_loadslip_verification_school_id', columns: ['school_id'])]
#[ORM\Index(name: 'idx_loadslip_verification_updated_at', columns: ['updated_at'])]
class LoadslipVerification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $schoolId = '';

    #[ORM\Column(length: 32)]
    private string $studentNumber = '';

    #[ORM\Column(type: Types::JSON)]
    private array $codes = [];

    #[ORM\Column(name: 'loadslip_rows', type: Types::JSON)]
    private array $rows = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $previewPath = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $schoolYear = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $semester = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $verified = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchoolId(): string
    {
        return $this->schoolId;
    }

    public function setSchoolId(string $schoolId): static
    {
        $this->schoolId = $schoolId;

        return $this;
    }

    public function getStudentNumber(): string
    {
        return $this->studentNumber;
    }

    public function setStudentNumber(string $studentNumber): static
    {
        $this->studentNumber = $studentNumber;

        return $this;
    }

    public function getCodes(): array
    {
        return $this->codes;
    }

    public function setCodes(array $codes): static
    {
        $this->codes = array_values($codes);

        return $this;
    }

    public function getRows(): array
    {
        return $this->rows;
    }

    public function setRows(array $rows): static
    {
        $this->rows = array_values($rows);

        return $this;
    }

    public function getPreviewPath(): ?string
    {
        return $this->previewPath;
    }

    public function setPreviewPath(?string $previewPath): static
    {
        $this->previewPath = $previewPath;

        return $this;
    }

    public function getSchoolYear(): ?string
    {
        return $this->schoolYear;
    }

    public function setSchoolYear(?string $schoolYear): static
    {
        $this->schoolYear = $schoolYear;

        return $this;
    }

    public function getSemester(): ?string
    {
        return $this->semester;
    }

    public function setSemester(?string $semester): static
    {
        $this->semester = $semester;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function setVerified(bool $verified): static
    {
        $this->verified = $verified;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}