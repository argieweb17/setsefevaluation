<?php

namespace App\Repository;

use App\Entity\AcademicYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AcademicYear>
 */
class AcademicYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcademicYear::class);
    }

    /**
     * Get the currently active academic year.
     */
    public function findCurrent(): ?AcademicYear
    {
        return $this->findOneBy(['isCurrent' => true]);
    }

    /**
     * @return AcademicYear[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('ay')
            ->orderBy('ay.yearLabel', 'DESC')
            ->addOrderBy('ay.semester', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Clear any existing "current" flags.
     */
    public function clearCurrent(): void
    {
        $this->createQueryBuilder('ay')
            ->update()
            ->set('ay.isCurrent', ':false')
            ->setParameter('false', false)
            ->getQuery()
            ->execute();
    }

    public function findLatestBySequence(): ?AcademicYear
    {
        return $this->createQueryBuilder('ay')
            ->addSelect("CASE
                WHEN ay.semester = '1st Semester' THEN 1
                WHEN ay.semester = '2nd Semester' THEN 2
                WHEN ay.semester = 'Summer' THEN 3
                ELSE 0
            END AS HIDDEN semesterOrder")
            ->orderBy('ay.yearLabel', 'DESC')
            ->addOrderBy('semesterOrder', 'DESC')
            ->addOrderBy('ay.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Returns the next academic term using this sequence:
     * 1st Semester -> 2nd Semester -> Summer -> next AY 1st Semester.
     *
     * @return array{yearLabel: string, semester: string}
     */
    public function getNextAcademicTerm(?AcademicYear $base = null): array
    {
        $base ??= $this->findCurrent() ?? $this->findLatestBySequence();

        if (!$base) {
            $startYear = (int) date('Y');
            return [
                'yearLabel' => $startYear . '-' . ($startYear + 1),
                'semester' => '1st Semester',
            ];
        }

        $yearLabel = $base->getYearLabel();
        $semester = $this->normalizeSemester($base->getSemester());

        return match ($semester) {
            '1st Semester' => ['yearLabel' => $yearLabel, 'semester' => '2nd Semester'],
            '2nd Semester' => ['yearLabel' => $yearLabel, 'semester' => 'Summer'],
            'Summer' => ['yearLabel' => $this->incrementYearLabel($yearLabel), 'semester' => '1st Semester'],
            default => ['yearLabel' => $yearLabel, 'semester' => '1st Semester'],
        };
    }

    private function normalizeSemester(?string $semester): ?string
    {
        if ($semester === null) {
            return null;
        }

        return match (mb_strtolower(trim($semester))) {
            '1st semester' => '1st Semester',
            '2nd semester' => '2nd Semester',
            'summer' => 'Summer',
            default => null,
        };
    }

    private function incrementYearLabel(string $yearLabel): string
    {
        if (preg_match('/^(\d{4})\s*-\s*(\d{4})$/', trim($yearLabel), $m) === 1) {
            $start = (int) $m[1] + 1;
            $end = (int) $m[2] + 1;
            return $start . '-' . $end;
        }

        $startYear = (int) date('Y');
        return $startYear . '-' . ($startYear + 1);
    }
}
