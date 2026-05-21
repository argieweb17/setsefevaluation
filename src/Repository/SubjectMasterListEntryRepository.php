<?php

namespace App\Repository;

use App\Entity\SubjectMasterListEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubjectMasterListEntry>
 */
class SubjectMasterListEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubjectMasterListEntry::class);
    }

    public static function normalizeSection(?string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', strtoupper(trim((string) $value)));
        return is_string($normalized) ? trim($normalized) : '';
    }

    public static function normalizeSchoolId(string $value): string
    {
        $normalized = preg_replace('/[^A-Z0-9]/u', '', strtoupper(trim($value)));
        return is_string($normalized) ? $normalized : '';
    }

    public function countByFacultySubject(int $facultyId, int $subjectId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('IDENTITY(m.faculty) = :facultyId')
            ->andWhere('IDENTITY(m.subject) = :subjectId')
            ->setParameter('facultyId', $facultyId)
            ->setParameter('subjectId', $subjectId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByFacultySubjectSection(int $facultyId, int $subjectId, ?string $section): int
    {
        $section = self::normalizeSection($section);

        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('IDENTITY(m.faculty) = :facultyId')
            ->andWhere('IDENTITY(m.subject) = :subjectId')
            ->andWhere('m.section = :section')
            ->setParameter('facultyId', $facultyId)
            ->setParameter('subjectId', $subjectId)
            ->setParameter('section', $section)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param int[] $subjectIds
     * @return array<string, int> map key format: "subjectId|SECTION"
     */
    public function getCountMapForFaculty(int $facultyId, array $subjectIds): array
    {
        $subjectIds = array_values(array_unique(array_filter(array_map('intval', $subjectIds), static fn (int $id): bool => $id > 0)));
        if (empty($subjectIds)) {
            return [];
        }

        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.subject) AS subjectId, m.section AS section, COUNT(m.id) AS total')
            ->where('IDENTITY(m.faculty) = :facultyId')
            ->andWhere('IDENTITY(m.subject) IN (:subjectIds)')
            ->setParameter('facultyId', $facultyId)
            ->setParameter('subjectIds', $subjectIds)
            ->groupBy('subjectId, m.section')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $subjectId = (int) ($row['subjectId'] ?? 0);
            if ($subjectId <= 0) {
                continue;
            }

            $section = self::normalizeSection((string) ($row['section'] ?? ''));
            $map[$subjectId . '|' . $section] = (int) ($row['total'] ?? 0);
        }

        return $map;
    }

    /**
     * @return SubjectMasterListEntry[]
     */
    public function findByFacultySubjectSection(int $facultyId, int $subjectId, ?string $section): array
    {
        $section = self::normalizeSection($section);

        return $this->createQueryBuilder('m')
            ->where('IDENTITY(m.faculty) = :facultyId')
            ->andWhere('IDENTITY(m.subject) = :subjectId')
            ->andWhere('m.section = :section')
            ->setParameter('facultyId', $facultyId)
            ->setParameter('subjectId', $subjectId)
            ->setParameter('section', $section)
            ->orderBy('m.studentSchoolId', 'ASC')
            ->addOrderBy('m.studentName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function removeByFacultySubjectSection(int $facultyId, int $subjectId, ?string $section): int
    {
        $section = self::normalizeSection($section);

        return (int) $this->getEntityManager()->createQueryBuilder()
            ->delete(SubjectMasterListEntry::class, 'm')
            ->where('IDENTITY(m.faculty) = :facultyId')
            ->andWhere('IDENTITY(m.subject) = :subjectId')
            ->andWhere('m.section = :section')
            ->setParameter('facultyId', $facultyId)
            ->setParameter('subjectId', $subjectId)
            ->setParameter('section', $section)
            ->getQuery()
            ->execute();
    }

    public function isStudentAllowedForSubject(int $facultyId, int $subjectId, string $schoolId, ?string $section = null): bool
    {
        $totalEntries = $this->countByFacultySubject($facultyId, $subjectId);
        if ($totalEntries === 0) {
            return false;
        }

        $normalizedSchoolId = self::normalizeSchoolId($schoolId);
        if ($normalizedSchoolId === '') {
            return false;
        }

        $normalizedSection = self::normalizeSection($section);

        if ($normalizedSection !== '') {
            $sectionEntries = $this->countByFacultySubjectSection($facultyId, $subjectId, $normalizedSection);
            if ($sectionEntries > 0) {
                return $this->existsEntry($facultyId, $subjectId, $normalizedSchoolId, $normalizedSection);
            }
        }

        $subjectWideEntries = $this->countByFacultySubjectSection($facultyId, $subjectId, '');
        if ($subjectWideEntries > 0) {
            return $this->existsEntry($facultyId, $subjectId, $normalizedSchoolId, '');
        }

        if ($normalizedSection === '') {
            return $this->existsEntryAnySection($facultyId, $subjectId, $normalizedSchoolId);
        }

        return false;
    }

    private function existsEntry(int $facultyId, int $subjectId, string $schoolId, string $section): bool
    {
        $id = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('IDENTITY(m.faculty) = :facultyId')
            ->andWhere('IDENTITY(m.subject) = :subjectId')
            ->andWhere('m.section = :section')
            ->andWhere('m.studentSchoolId = :schoolId')
            ->setParameter('facultyId', $facultyId)
            ->setParameter('subjectId', $subjectId)
            ->setParameter('section', self::normalizeSection($section))
            ->setParameter('schoolId', self::normalizeSchoolId($schoolId))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $id !== null;
    }

    private function existsEntryAnySection(int $facultyId, int $subjectId, string $schoolId): bool
    {
        $id = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('IDENTITY(m.faculty) = :facultyId')
            ->andWhere('IDENTITY(m.subject) = :subjectId')
            ->andWhere('m.studentSchoolId = :schoolId')
            ->setParameter('facultyId', $facultyId)
            ->setParameter('subjectId', $subjectId)
            ->setParameter('schoolId', self::normalizeSchoolId($schoolId))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $id !== null;
    }
}
