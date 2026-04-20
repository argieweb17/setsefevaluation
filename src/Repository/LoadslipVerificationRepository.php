<?php

namespace App\Repository;

use App\Entity\LoadslipVerification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @extends ServiceEntityRepository<LoadslipVerification>
 */
class LoadslipVerificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private KernelInterface $kernel)
    {
        parent::__construct($registry, LoadslipVerification::class);
    }

    public function findOneBySchoolId(string $schoolId): ?LoadslipVerification
    {
        $normalizedSchoolId = $this->normalizeStudentNumber($schoolId);
        if ($normalizedSchoolId === '') {
            return null;
        }

        return $this->findOneBy(['schoolId' => $normalizedSchoolId]);
    }

    public function findPayloadBySchoolId(string $schoolId): ?array
    {
        $verification = $this->findOneBySchoolId($schoolId);
        if ($verification === null) {
            $verification = $this->importLegacyJsonRecord($schoolId);
        }
        if ($verification === null) {
            return null;
        }

        return [
            'schoolId' => $verification->getSchoolId(),
            'studentNumber' => $verification->getStudentNumber(),
            'codes' => $verification->getCodes(),
            'rows' => $verification->getRows(),
            'previewPath' => $verification->getPreviewPath(),
            'schoolYear' => $verification->getSchoolYear(),
            'semester' => $verification->getSemester(),
            'verified' => $verification->isVerified(),
        ];
    }

    public function save(LoadslipVerification $verification, bool $flush = false): void
    {
        $this->getEntityManager()->persist($verification);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(LoadslipVerification $verification, bool $flush = false): void
    {
        $this->getEntityManager()->remove($verification);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function deleteBySchoolId(string $schoolId): void
    {
        $legacyPath = $this->getLegacyFilePath($schoolId);
        $verification = $this->findOneBySchoolId($schoolId);
        if ($verification !== null) {
            $this->remove($verification, true);
        }

        if ($legacyPath !== null && is_file($legacyPath)) {
            @unlink($legacyPath);
        }
    }

    private function importLegacyJsonRecord(string $schoolId): ?LoadslipVerification
    {
        $legacyPath = $this->getLegacyFilePath($schoolId);
        if ($legacyPath === null || !is_file($legacyPath)) {
            return null;
        }

        $raw = @file_get_contents($legacyPath);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $repaired = (string) preg_replace('/\[\s*,/u', '[', $raw);
            $repaired = (string) preg_replace('/,\s*\]/u', ']', $repaired);
            $data = json_decode($repaired, true);
        }
        if (!is_array($data)) {
            return null;
        }

        $normalizedSchoolId = $this->normalizeStudentNumber($schoolId);
        $studentNumber = $this->normalizeStudentNumber((string) ($data['studentNumber'] ?? ''));
        if ($normalizedSchoolId === '' || $studentNumber === '' || $studentNumber !== $normalizedSchoolId) {
            return null;
        }

        $verification = new LoadslipVerification();
        $verification
            ->setSchoolId($normalizedSchoolId)
            ->setStudentNumber($studentNumber)
            ->setCodes(array_values((array) ($data['codes'] ?? [])))
            ->setRows(array_values(array_filter((array) ($data['rows'] ?? []), static fn ($row): bool => is_array($row))))
            ->setPreviewPath($this->normalizeNullableString($data['previewPath'] ?? null))
            ->setSchoolYear($this->normalizeNullableString($data['schoolYear'] ?? null))
            ->setSemester($this->normalizeNullableString($data['semester'] ?? null))
            ->setVerified((bool) ($data['verified'] ?? true));

        $updatedAt = $this->parseLegacyTimestamp((string) ($data['updatedAt'] ?? ''));
        if ($updatedAt !== null) {
            $verification->setCreatedAt($updatedAt)->setUpdatedAt($updatedAt);
        }

        $this->save($verification, true);
        @unlink($legacyPath);

        return $verification;
    }

    private function getLegacyFilePath(string $schoolId): ?string
    {
        $normalizedSchoolId = $this->normalizeStudentNumber($schoolId);
        if ($normalizedSchoolId === '') {
            return null;
        }

        return rtrim($this->kernel->getProjectDir(), '\/')
            . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'loadslip-verifications'
            . DIRECTORY_SEPARATOR . $normalizedSchoolId . '.json';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function parseLegacyTimestamp(string $value): ?\DateTimeImmutable
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($normalized);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeStudentNumber(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = str_replace(
            ['O', 'Q', 'D', 'I', 'L', '|', '!', 'S', 'B', 'Z', 'G'],
            ['0', '0', '0', '1', '1', '1', '1', '5', '8', '2', '6'],
            $value
        );

        return (string) preg_replace('/[^0-9]/', '', $value);
    }
}