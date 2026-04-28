<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'loadslip_verification_row')]
#[ORM\Index(name: 'idx_loadslip_verification_row_verification_id', columns: ['verification_id'])]
class LoadslipVerificationRow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LoadslipVerification::class, inversedBy: 'rowItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?LoadslipVerification $verification = null;

    #[ORM\Column(length: 50)]
    private string $code = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $section = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $schedule = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $units = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVerification(): ?LoadslipVerification
    {
        return $this->verification;
    }

    public function setVerification(?LoadslipVerification $verification): static
    {
        $this->verification = $verification;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = trim($code);

        return $this;
    }

    public function getSection(): ?string
    {
        return $this->section;
    }

    public function setSection(?string $section): static
    {
        $this->section = self::normalizeNullableString($section);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = self::normalizeNullableString($description);

        return $this;
    }

    public function getSchedule(): ?string
    {
        return $this->schedule;
    }

    public function setSchedule(?string $schedule): static
    {
        $this->schedule = self::normalizeNullableString($schedule);

        return $this;
    }

    public function getUnits(): ?string
    {
        return $this->units;
    }

    public function setUnits(?string $units): static
    {
        $this->units = self::normalizeNullableString($units);

        return $this;
    }

    public function toPayload(): array
    {
        return array_filter([
            'code' => $this->code,
            'section' => $this->section,
            'description' => $this->description,
            'schedule' => $this->schedule,
            'units' => $this->units,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public static function fromPayload(array $payload): self
    {
        return (new self())
            ->setCode(trim((string) ($payload['code'] ?? '')))
            ->setSection(self::normalizeNullableString($payload['section'] ?? null))
            ->setDescription(self::normalizeNullableString($payload['description'] ?? null))
            ->setSchedule(self::normalizeNullableString($payload['schedule'] ?? null))
            ->setUnits(self::normalizeNullableString($payload['units'] ?? null));
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}