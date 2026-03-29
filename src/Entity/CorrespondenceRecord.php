<?php

namespace App\Entity;

use App\Repository\CorrespondenceRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CorrespondenceRecordRepository::class)]
#[ORM\Index(columns: ['evaluation_type', 'created_at'], name: 'idx_corr_eval_created')]
#[ORM\Index(columns: ['correspondence_id'], name: 'idx_corr_id')]
class CorrespondenceRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $correspondenceId;

    #[ORM\Column(length: 10)]
    private string $evaluationType = 'SET';

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $printScope = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCorrespondenceId(): string
    {
        return $this->correspondenceId;
    }

    public function setCorrespondenceId(string $correspondenceId): static
    {
        $this->correspondenceId = $correspondenceId;

        return $this;
    }

    public function getEvaluationType(): string
    {
        return $this->evaluationType;
    }

    public function setEvaluationType(string $evaluationType): static
    {
        $this->evaluationType = strtoupper($evaluationType) === 'SEF' ? 'SEF' : 'SET';

        return $this;
    }

    public function getPrintScope(): ?string
    {
        return $this->printScope;
    }

    public function setPrintScope(?string $printScope): static
    {
        $this->printScope = $printScope;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

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
}
