<?php

namespace App\Repository;

use App\Entity\EvaluationMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvaluationMessage>
 */
class EvaluationMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationMessage::class);
    }

    /** @return EvaluationMessage[] */
    public function findBySender(int $userId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.sender = :uid')
            ->setParameter('uid', $userId)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return EvaluationMessage[] */
    public function findAllMessages(): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.sender', 's')
            ->addSelect('s')
            ->leftJoin('m.evaluationPeriod', 'e')
            ->addSelect('e')
            ->andWhere('m.parentMessage IS NULL')
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return EvaluationMessage[] */
    public function findRepliesForMessage(int $parentId): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.sender', 's')
            ->addSelect('s')
            ->andWhere('m.parentMessage = :parentId')
            ->setParameter('parentId', $parentId)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.status = :status')
            ->setParameter('status', EvaluationMessage::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return EvaluationMessage[] */
    public function findAttachmentsForUser(int $userId): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.sender = :uid')
            ->andWhere('m.attachment IS NOT NULL')
            ->setParameter('uid', $userId)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
