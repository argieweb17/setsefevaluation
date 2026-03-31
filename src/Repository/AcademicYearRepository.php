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

    /**
     * Returns the academic term that covers the provided calendar date.
     *
     * Calendar mapping:
     * - 1st Semester: August 1 to December 31
     * - 2nd Semester: January 1 to May 31
     * - Summer: June 1 to July 31
     *
     * @return array{yearLabel: string, semester: string, startDate: \DateTimeImmutable, endDate: \DateTimeImmutable}
     */
    public function getCalendarTermForDate(?\DateTimeInterface $date = null): array
    {
        $date ??= new \DateTimeImmutable('today');

        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        if ($month >= 8) {
            $yearLabel = $year . '-' . ($year + 1);
            return [
                'yearLabel' => $yearLabel,
                'semester' => '1st Semester',
                'startDate' => new \DateTimeImmutable($year . '-08-01'),
                'endDate' => new \DateTimeImmutable($year . '-12-31'),
            ];
        }

        if ($month >= 6) {
            $yearLabel = ($year - 1) . '-' . $year;
            return [
                'yearLabel' => $yearLabel,
                'semester' => 'Summer',
                'startDate' => new \DateTimeImmutable($year . '-06-01'),
                'endDate' => new \DateTimeImmutable($year . '-07-31'),
            ];
        }

        $yearLabel = ($year - 1) . '-' . $year;
        return [
            'yearLabel' => $yearLabel,
            'semester' => '2nd Semester',
            'startDate' => new \DateTimeImmutable($year . '-01-01'),
            'endDate' => new \DateTimeImmutable($year . '-05-31'),
        ];
    }

    /**
     * Returns date ranges for all standard terms of an academic year label.
     *
     * @return array{
     *   1st Semester: array{start: \DateTimeImmutable, end: \DateTimeImmutable},
     *   2nd Semester: array{start: \DateTimeImmutable, end: \DateTimeImmutable},
     *   Summer: array{start: \DateTimeImmutable, end: \DateTimeImmutable}
     * }
     */
    public function getSemesterDateRangesForYearLabel(string $yearLabel): array
    {
        [$startYear, $endYear] = $this->resolveAcademicYearBounds($yearLabel);

        return [
            '1st Semester' => [
                'start' => new \DateTimeImmutable($startYear . '-08-01'),
                'end' => new \DateTimeImmutable($startYear . '-12-31'),
            ],
            '2nd Semester' => [
                'start' => new \DateTimeImmutable($endYear . '-01-01'),
                'end' => new \DateTimeImmutable($endYear . '-05-31'),
            ],
            'Summer' => [
                'start' => new \DateTimeImmutable($endYear . '-06-01'),
                'end' => new \DateTimeImmutable($endYear . '-07-31'),
            ],
        ];
    }

    public function findCalendarCurrentTerm(?\DateTimeInterface $date = null): ?AcademicYear
    {
        $term = $this->getCalendarTermForDate($date);

        return $this->findOneBy([
            'yearLabel' => $term['yearLabel'],
            'semester' => $term['semester'],
        ]);
    }

    public function syncCurrentToCalendar(?\DateTimeInterface $date = null): ?AcademicYear
    {
        $target = $this->findCalendarCurrentTerm($date);
        if (!$target) {
            return $this->findCurrent();
        }

        $current = $this->findCurrent();
        if ($current && $current->getId() === $target->getId()) {
            return $current;
        }

        $this->clearCurrent();
        $target->setIsCurrent(true);
        $this->getEntityManager()->persist($target);
        $this->getEntityManager()->flush();

        return $target;
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

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveAcademicYearBounds(string $yearLabel): array
    {
        if (preg_match('/^(\d{4})\s*-\s*(\d{4})$/', trim($yearLabel), $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        $fallback = $this->getCalendarTermForDate();
        preg_match('/^(\d{4})-(\d{4})$/', $fallback['yearLabel'], $m);

        return [(int) ($m[1] ?? date('Y')), (int) ($m[2] ?? ((int) date('Y') + 1))];
    }
}
