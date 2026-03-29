<?php

namespace App\Repository;

use App\Entity\CorrespondenceRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorrespondenceRecord>
 */
class CorrespondenceRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorrespondenceRecord::class);
    }

    /**
     * @return CorrespondenceRecord[]
     */
    public function findRecentByEvaluationType(string $evaluationType, int $limit = 300): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.createdBy', 'u')
            ->addSelect('u')
            ->where('c.evaluationType = :evaluationType')
            ->setParameter('evaluationType', strtoupper($evaluationType) === 'SEF' ? 'SEF' : 'SET')
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
