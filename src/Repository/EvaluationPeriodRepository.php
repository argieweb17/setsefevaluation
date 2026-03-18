<?php

namespace App\Repository;

use App\Entity\EvaluationPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvaluationPeriod>
 */
class EvaluationPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationPeriod::class);
    }

    /**
     * Find currently open evaluation periods.
     * @return EvaluationPeriod[]
     */
    public function findOpen(?string $type = null): array
    {
        $qb = $this->createQueryBuilder('ep')
            ->where('ep.status = true')
            ->andWhere('ep.startDate <= :now')
            ->andWhere('ep.endDate >= :now')
            ->setParameter('now', new \DateTime())
            ->orderBy('ep.startDate', 'DESC');

        if ($type) {
            $qb->andWhere('ep.evaluationType = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find active (status=true) evaluation periods regardless of date range.
     * @return EvaluationPeriod[]
     */
    public function findActive(?string $type = null): array
    {
        $qb = $this->createQueryBuilder('ep')
            ->where('ep.status = true')
            ->orderBy('ep.startDate', 'DESC');

        if ($type) {
            $qb->andWhere('ep.evaluationType = :type')->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find the latest open evaluation period for a given type.
     */
    public function findLatestOpen(string $type): ?EvaluationPeriod
    {
        return $this->createQueryBuilder('ep')
            ->where('ep.status = true')
            ->andWhere('ep.startDate <= :now')
            ->andWhere('ep.endDate >= :now')
            ->andWhere('ep.evaluationType = :type')
            ->setParameter('now', new \DateTime())
            ->setParameter('type', $type)
            ->orderBy('ep.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return EvaluationPeriod[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('ep')
            ->orderBy('ep.startDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find evaluation periods relevant to a specific faculty member.
     * Only returns SET/SEF periods matching the faculty's department (or unscoped).
     *
     * @return EvaluationPeriod[]
     */
    /**
     * Check if a duplicate evaluation period exists for the same faculty, subject, and school year.
     */
    public function findDuplicate(string $evaluationType, ?string $faculty, ?string $subject, ?string $schoolYear, ?string $section): ?EvaluationPeriod
    {
        $qb = $this->createQueryBuilder('ep')
            ->where('ep.evaluationType = :type')
            ->setParameter('type', $evaluationType);

        if ($faculty) {
            $qb->andWhere('ep.faculty = :faculty')->setParameter('faculty', $faculty);
        }
        if ($subject) {
            $qb->andWhere('ep.subject = :subject')->setParameter('subject', $subject);
        }
        if ($schoolYear) {
            $qb->andWhere('ep.schoolYear = :sy')->setParameter('sy', $schoolYear);
        }
        if ($section) {
            $qb->andWhere('ep.section = :section')->setParameter('section', $section);
        }

        return $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function findForFaculty(?int $departmentId, ?string $facultyName = null): array
    {
        $qb = $this->createQueryBuilder('ep')
            ->where('ep.evaluationType IN (:types)')
            ->setParameter('types', ['SET', 'SEF'])
            ->orderBy('ep.startDate', 'DESC');

        if ($departmentId) {
            $qb->andWhere('ep.department IS NULL OR ep.department = :dept')
               ->setParameter('dept', $departmentId);
        }

        if ($facultyName) {
            $qb->andWhere('ep.faculty IS NULL OR ep.faculty = :facultyName')
                ->setParameter('facultyName', $facultyName);
        }

        return $qb->getQuery()->getResult();
    }
}
