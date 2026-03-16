<?php

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Get recent audit logs with optional filters.
     * @return AuditLog[]
     */
    public function findRecent(int $limit = 50, ?string $action = null, ?int $userId = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($action) {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }
        if ($userId) {
            $qb->andWhere('a.performedBy = :uid')->setParameter('uid', $userId);
        }

        return $qb->getQuery()->getResult();
    }

    public function deleteAll(): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->getQuery()
            ->execute();
    }

    /**
     * Count logs by action type within a date range.
     */
    public function countByAction(string $action, ?\DateTimeInterface $since = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.action = :action')
            ->setParameter('action', $action);

        if ($since) {
            $qb->andWhere('a.createdAt >= :since')->setParameter('since', $since);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
