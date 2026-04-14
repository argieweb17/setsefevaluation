<?php

namespace App\Entity;

use App\Repository\FacultySubjectLoadRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FacultySubjectLoadRepository::class)]
#[ORM\Table(name: 'faculty_subject_load')]
#[ORM\UniqueConstraint(name: 'unique_faculty_subject_section_schedule_ay', columns: ['faculty_id', 'subject_id', 'section', 'schedule', 'academic_year_id'])]
class FacultySubjectLoad
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $faculty;

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Subject $subject;

    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AcademicYear $academicYear = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $section = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $schedule = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getFaculty(): User { return $this->faculty; }
    public function setFaculty(User $v): static { $this->faculty = $v; return $this; }

    public function getSubject(): Subject { return $this->subject; }
    public function setSubject(Subject $v): static { $this->subject = $v; return $this; }

    public function getAcademicYear(): ?AcademicYear { return $this->academicYear; }
    public function setAcademicYear(?AcademicYear $v): static { $this->academicYear = $v; return $this; }

    public function getSection(): ?string { return $this->section; }
    public function setSection(?string $v): static { $this->section = $v !== null ? strtoupper(trim($v)) : null; return $this; }

    public function getSchedule(): ?string { return $this->schedule; }
    public function setSchedule(?string $v): static { $this->schedule = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
