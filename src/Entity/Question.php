<?php

namespace App\Entity;

use App\Repository\QuestionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\EvaluationResponse;

#[ORM\Entity(repositoryClass: QuestionRepository::class)]
class Question
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $questionText;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(length: 10)]
    private string $evaluationType = 'SET'; // SET or SEF

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $weight = 1.0;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private bool $isRequired = true;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $evidenceItems = [];

    #[ORM\OneToMany(mappedBy: 'question', targetEntity: EvaluationResponse::class, cascade: ['persist', 'remove'])]
    private Collection $responses;

    public function __construct()
    {
        $this->responses = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getQuestionText(): string { return $this->questionText; }
    public function setQuestionText(string $v): static { $this->questionText = $v; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $v): static { $this->category = $v; return $this; }

    public function getEvaluationType(): string { return $this->evaluationType; }
    public function setEvaluationType(string $v): static { $this->evaluationType = $v; return $this; }

    public function getWeight(): ?float { return $this->weight; }
    public function setWeight(?float $v): static { $this->weight = $v; return $this; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $v): static { $this->sortOrder = $v; return $this; }

    public function isRequired(): bool { return $this->isRequired; }
    public function setIsRequired(bool $v): static { $this->isRequired = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    /** @return string[] */
    public function getEvidenceItems(): array
    {
        return array_values(array_filter($this->evidenceItems ?? [], static fn ($item): bool => is_string($item) && trim($item) !== ''));
    }

    /** @param string[] $v */
    public function setEvidenceItems(array $v): static
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
        $this->evidenceItems = array_values(array_unique($items));
        return $this;
    }

    /** @return Collection<int, EvaluationResponse> */
    public function getResponses(): Collection { return $this->responses; }
}
