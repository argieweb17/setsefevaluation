<?php

namespace App\Entity;

use App\Repository\SubjectMasterListEntryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubjectMasterListEntryRepository::class)]
#[ORM\Table(name: 'subject_master_list_entry')]
#[ORM\UniqueConstraint(name: 'uniq_master_list_student', columns: ['faculty_id', 'subject_id', 'section', 'student_school_id'])]
#[ORM\Index(name: 'idx_master_list_subject_section', columns: ['faculty_id', 'subject_id', 'section'])]
#[ORM\Index(name: 'idx_master_list_student_school_id', columns: ['student_school_id'])]
class SubjectMasterListEntry
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

    #[ORM\Column(length: 50, options: ['default' => ''])]
    private string $section = '';

    #[ORM\Column(length: 50)]
    private string $studentSchoolId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $studentName = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

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

    public function getFaculty(): User
    {
        return $this->faculty;
    }

    public function setFaculty(User $faculty): static
    {
        $this->faculty = $faculty;
        return $this;
    }

    public function getSubject(): Subject
    {
        return $this->subject;
    }

    public function setSubject(Subject $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    public function getSection(): string
    {
        return $this->section;
    }

    public function setSection(?string $section): static
    {
        $normalized = preg_replace('/\s+/u', ' ', strtoupper(trim((string) $section)));
        $this->section = is_string($normalized) ? trim($normalized) : '';
        return $this;
    }

    public function getStudentSchoolId(): string
    {
        return $this->studentSchoolId;
    }

    public function setStudentSchoolId(string $studentSchoolId): static
    {
        $this->studentSchoolId = strtoupper(trim($studentSchoolId));
        return $this;
    }

    public function getStudentName(): ?string
    {
        return $this->studentName;
    }

    public function setStudentName(?string $studentName): static
    {
        $value = trim((string) $studentName);
        $this->studentName = $value !== '' ? $value : null;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
