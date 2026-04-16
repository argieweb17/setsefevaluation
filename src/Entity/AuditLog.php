<?php

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks system activity for security and audit compliance.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Index(columns: ['action'], name: 'idx_audit_action')]
#[ORM\Index(columns: ['created_at'], name: 'idx_audit_date')]
class AuditLog
{
    // User management
    public const ACTION_CREATE_USER       = 'create_user';
    public const ACTION_EDIT_USER         = 'edit_user';
    public const ACTION_DELETE_USER       = 'delete_user';
    public const ACTION_CHANGE_ROLE       = 'change_role';
    public const ACTION_ACTIVATE_USER     = 'activate_user';
    public const ACTION_DEACTIVATE_USER   = 'deactivate_user';
    public const ACTION_RESET_PASSWORD    = 'reset_password';
    public const ACTION_BULK_UPLOAD       = 'bulk_upload';

    // Evaluation management
    public const ACTION_CREATE_EVALUATION = 'create_evaluation';
    public const ACTION_OPEN_EVALUATION   = 'open_evaluation';
    public const ACTION_CLOSE_EVALUATION  = 'close_evaluation';
    public const ACTION_LOCK_RESULTS      = 'lock_results';
    public const ACTION_SUBMIT_SET        = 'submit_set';
    public const ACTION_SUBMIT_SEF        = 'submit_sef';
    public const ACTION_SAVE_DRAFT        = 'save_draft';

    // Academic management
    public const ACTION_CREATE_DEPARTMENT = 'create_department';
    public const ACTION_CREATE_COURSE     = 'create_course';
    public const ACTION_CREATE_SUBJECT    = 'create_subject';
    public const ACTION_CREATE_CURRICULUM = 'create_curriculum';
    public const ACTION_ASSIGN_FACULTY    = 'assign_faculty';
    public const ACTION_ENROLL_STUDENT    = 'enroll_student';

    // Question management
    public const ACTION_CREATE_QUESTION   = 'create_question';
    public const ACTION_EDIT_QUESTION     = 'edit_question';
    public const ACTION_DELETE_QUESTION   = 'delete_question';

    // Reports
    public const ACTION_VIEW_RESULTS      = 'view_results';
    public const ACTION_EXPORT_REPORT     = 'export_report';

    // System
    public const ACTION_LOGIN             = 'login';
    public const ACTION_LOGOUT            = 'logout';
    public const ACTION_ENABLE_MAINTENANCE = 'enable_maintenance';
    public const ACTION_DISABLE_MAINTENANCE = 'disable_maintenance';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $performedBy = null;

    #[ORM\Column(length: 50)]
    private string $action;

    #[ORM\Column(length: 255)]
    private string $entityType;

    #[ORM\Column(nullable: true)]
    private ?int $entityId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getPerformedBy(): ?User { return $this->performedBy; }
    public function setPerformedBy(?User $v): static { $this->performedBy = $v; return $this; }

    public function getAction(): string { return $this->action; }
    public function setAction(string $v): static { $this->action = $v; return $this; }

    public function getEntityType(): string { return $this->entityType; }
    public function setEntityType(string $v): static { $this->entityType = $v; return $this; }

    public function getEntityId(): ?int { return $this->entityId; }
    public function setEntityId(?int $v): static { $this->entityId = $v; return $this; }

    public function getDetails(): ?string { return $this->details; }
    public function setDetails(?string $v): static { $this->details = $v; return $this; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $v): static { $this->ipAddress = $v; return $this; }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $v): static { $this->createdAt = $v; return $this; }

    public function getActionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_CREATE_USER       => 'Created User',
            self::ACTION_EDIT_USER         => 'Edited User',
            self::ACTION_DELETE_USER       => 'Deleted User',
            self::ACTION_CHANGE_ROLE       => 'Changed Role',
            self::ACTION_ACTIVATE_USER     => 'Activated User',
            self::ACTION_DEACTIVATE_USER   => 'Deactivated User',
            self::ACTION_RESET_PASSWORD    => 'Reset Password',
            self::ACTION_BULK_UPLOAD       => 'Bulk Upload Users',
            self::ACTION_CREATE_EVALUATION => 'Created Evaluation Period',
            self::ACTION_OPEN_EVALUATION   => 'Opened Evaluation',
            self::ACTION_CLOSE_EVALUATION  => 'Closed Evaluation',
            self::ACTION_LOCK_RESULTS      => 'Locked Results',
            self::ACTION_SUBMIT_SET        => 'Submitted SET',
            self::ACTION_SUBMIT_SEF        => 'Submitted SEF',
            self::ACTION_SAVE_DRAFT        => 'Saved Draft',
            self::ACTION_CREATE_DEPARTMENT => 'Created Department',
            self::ACTION_CREATE_SUBJECT    => 'Created Subject',
            self::ACTION_ASSIGN_FACULTY    => 'Assigned Faculty',
            self::ACTION_ENROLL_STUDENT    => 'Enrolled Student',
            self::ACTION_CREATE_QUESTION   => 'Created Question',
            self::ACTION_EDIT_QUESTION     => 'Edited Question',
            self::ACTION_DELETE_QUESTION   => 'Deleted Question',
            self::ACTION_VIEW_RESULTS      => 'Viewed Results',
            self::ACTION_EXPORT_REPORT     => 'Exported Report',
            self::ACTION_LOGIN             => 'Logged In',
            self::ACTION_LOGOUT            => 'Logged Out',
            self::ACTION_ENABLE_MAINTENANCE => 'Enabled Maintenance Mode',
            self::ACTION_DISABLE_MAINTENANCE => 'Disabled Maintenance Mode',
            default                        => ucfirst(str_replace('_', ' ', $this->action)),
        };
    }
}
