<?php

namespace App\Entity;

use App\Repository\EvaluationMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EvaluationMessageRepository::class)]
#[ORM\Index(columns: ['created_at'], name: 'idx_eval_msg_date')]
class EvaluationMessage
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_RESOLVED = 'resolved';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $sender = null;

    #[ORM\ManyToOne(targetEntity: EvaluationPeriod::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?EvaluationPeriod $evaluationPeriod = null;

    #[ORM\Column(length: 255)]
    private ?string $subject = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $message = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminReply = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $repliedBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $repliedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $attachment = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $attachmentOriginalName = null;

    #[ORM\ManyToOne(targetEntity: EvaluationMessage::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?EvaluationMessage $parentMessage = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $senderType = null; // 'faculty' or 'admin'

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getSender(): ?User { return $this->sender; }
    public function setSender(?User $sender): static { $this->sender = $sender; return $this; }

    public function getEvaluationPeriod(): ?EvaluationPeriod { return $this->evaluationPeriod; }
    public function setEvaluationPeriod(?EvaluationPeriod $evaluationPeriod): static { $this->evaluationPeriod = $evaluationPeriod; return $this; }

    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(string $subject): static { $this->subject = $subject; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(string $message): static { $this->message = $message; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getAdminReply(): ?string { return $this->adminReply; }
    public function setAdminReply(?string $adminReply): static { $this->adminReply = $adminReply; return $this; }

    public function getRepliedBy(): ?User { return $this->repliedBy; }
    public function setRepliedBy(?User $repliedBy): static { $this->repliedBy = $repliedBy; return $this; }

    public function getRepliedAt(): ?\DateTimeInterface { return $this->repliedAt; }
    public function setRepliedAt(?\DateTimeInterface $repliedAt): static { $this->repliedAt = $repliedAt; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getAttachment(): ?string { return $this->attachment; }
    public function setAttachment(?string $attachment): static { $this->attachment = $attachment; return $this; }

    public function getAttachmentOriginalName(): ?string { return $this->attachmentOriginalName; }
    public function setAttachmentOriginalName(?string $name): static { $this->attachmentOriginalName = $name; return $this; }

    public function getParentMessage(): ?EvaluationMessage { return $this->parentMessage; }
    public function setParentMessage(?EvaluationMessage $parent): static { $this->parentMessage = $parent; return $this; }

    public function getSenderType(): ?string { return $this->senderType; }
    public function setSenderType(?string $type): static { $this->senderType = $type; return $this; }
}
