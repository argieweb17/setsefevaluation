<?php

namespace App\Entity;

use App\Repository\SuperiorEvaluationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SuperiorEvaluationRepository::class)]
#[ORM\Index(columns: ['evaluator_id'], name: 'idx_sup_evaluator')]
#[ORM\Index(columns: ['evaluatee_id'], name: 'idx_sup_evaluatee')]
#[ORM\Index(columns: ['evaluation_period_id'], name: 'idx_sup_period')]
class SuperiorEvaluation
{
    public const TYPE_DEAN = 'dean';
    public const TYPE_DEPARTMENT_HEAD = 'department_head';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EvaluationPeriod::class)]
    #[ORM\JoinColumn(nullable: false)]
    private EvaluationPeriod $evaluationPeriod;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $evaluator;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $evaluatee;

    #[ORM\Column(length: 30)]
    private string $evaluateeRole = self::TYPE_DEPARTMENT_HEAD;

    #[ORM\ManyToOne(targetEntity: Question::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Question $question;

    #[ORM\Column]
    private int $rating = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $submittedAt;

    #[ORM\Column]
    private bool $isDraft = false;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $verificationSelections = [];

    public function __construct()
    {
        $this->submittedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getEvaluationPeriod(): EvaluationPeriod { return $this->evaluationPeriod; }
    public function setEvaluationPeriod(EvaluationPeriod $v): static { $this->evaluationPeriod = $v; return $this; }

    public function getEvaluator(): User { return $this->evaluator; }
    public function setEvaluator(User $v): static { $this->evaluator = $v; return $this; }

    public function getEvaluatee(): User { return $this->evaluatee; }
    public function setEvaluatee(User $v): static { $this->evaluatee = $v; return $this; }

    public function getEvaluateeRole(): string { return $this->evaluateeRole; }
    public function setEvaluateeRole(string $v): static { $this->evaluateeRole = $v; return $this; }

    public function getQuestion(): Question { return $this->question; }
    public function setQuestion(Question $v): static { $this->question = $v; return $this; }

    public function getRating(): int { return $this->rating; }
    public function setRating(int $v): static { $this->rating = $v; return $this; }

    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $v): static { $this->comment = $v; return $this; }

    public function getSubmittedAt(): \DateTimeInterface { return $this->submittedAt; }
    public function setSubmittedAt(\DateTimeInterface $v): static { $this->submittedAt = $v; return $this; }

    public function isDraft(): bool { return $this->isDraft; }
    public function setIsDraft(bool $v): static { $this->isDraft = $v; return $this; }

    /** @return string[] */
    public function getVerificationSelections(): array
    {
        return array_values(array_filter($this->verificationSelections ?? [], static fn ($item): bool => is_string($item) && trim($item) !== ''));
    }

    /** @param string[] $v */
    public function setVerificationSelections(array $v): static
    {
        $items = [];
        foreach ($v as $item) {
            if (!is_string($item)) {
                continue;
            }
            $clean = trim($item);
            if ($clean !== '') {
                $items[] = $clean;
            }
        }

        $this->verificationSelections = array_values(array_unique($items));
        return $this;
    }
}
