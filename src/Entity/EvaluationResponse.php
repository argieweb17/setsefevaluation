<?php

namespace App\Entity;

use App\Repository\EvaluationResponseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EvaluationResponseRepository::class)]
#[ORM\Index(columns: ['evaluation_period_id', 'faculty_id'], name: 'idx_eval_faculty')]
#[ORM\Index(columns: ['evaluator_id'], name: 'idx_evaluator')]
class EvaluationResponse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EvaluationPeriod::class, inversedBy: 'responses')]
    #[ORM\JoinColumn(nullable: false)]
    private EvaluationPeriod $evaluationPeriod;

    #[ORM\ManyToOne(targetEntity: Question::class, inversedBy: 'responses')]
    #[ORM\JoinColumn(nullable: false)]
    private Question $question;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $evaluator = null; // null if anonymous

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $faculty; // the faculty being evaluated

    #[ORM\ManyToOne(targetEntity: Subject::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Subject $subject = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $section = null;

    #[ORM\Column]
    private int $rating = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $submittedAt;

    #[ORM\Column]
    private bool $isDraft = false;

    public function __construct()
    {
        $this->submittedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getEvaluationPeriod(): EvaluationPeriod { return $this->evaluationPeriod; }
    public function setEvaluationPeriod(EvaluationPeriod $v): static { $this->evaluationPeriod = $v; return $this; }

    public function getQuestion(): Question { return $this->question; }
    public function setQuestion(Question $v): static { $this->question = $v; return $this; }

    public function getEvaluator(): ?User { return $this->evaluator; }
    public function setEvaluator(?User $v): static { $this->evaluator = $v; return $this; }

    public function getFaculty(): User { return $this->faculty; }
    public function setFaculty(User $v): static { $this->faculty = $v; return $this; }

    public function getSubject(): ?Subject { return $this->subject; }
    public function setSubject(?Subject $v): static { $this->subject = $v; return $this; }

    public function getSection(): ?string { return $this->section; }
    public function setSection(?string $v): static { $this->section = $v; return $this; }

    public function getRating(): int { return $this->rating; }
    public function setRating(int $v): static { $this->rating = $v; return $this; }

    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $v): static { $this->comment = $v; return $this; }

    public function getSubmittedAt(): \DateTimeInterface { return $this->submittedAt; }
    public function setSubmittedAt(\DateTimeInterface $v): static { $this->submittedAt = $v; return $this; }

    public function isDraft(): bool { return $this->isDraft; }
    public function setIsDraft(bool $v): static { $this->isDraft = $v; return $this; }
}
