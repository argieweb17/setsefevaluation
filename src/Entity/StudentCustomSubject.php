<?php

namespace App\Entity;

use App\Repository\StudentCustomSubjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StudentCustomSubjectRepository::class)]
#[ORM\Table(name: 'student_custom_subject')]
class StudentCustomSubject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $student = null;

    #[ORM\Column(length: 50)]
    private string $code = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $schedule = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $section = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $units = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getStudent(): ?User { return $this->student; }
    public function setStudent(?User $student): static { $this->student = $student; return $this; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = $code; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getSchedule(): ?string { return $this->schedule; }
    public function setSchedule(?string $schedule): static { $this->schedule = $schedule; return $this; }

    public function getSection(): ?string { return $this->section; }
    public function setSection(?string $section): static { $this->section = $section; return $this; }

    public function getUnits(): ?string { return $this->units; }
    public function setUnits(?string $units): static { $this->units = $units; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
}
