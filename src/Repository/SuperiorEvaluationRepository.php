<?php

namespace App\Repository;

use App\Entity\SuperiorEvaluation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SuperiorEvaluation>
 */
class SuperiorEvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SuperiorEvaluation::class);
    }

    public function hasSubmitted(int $evaluatorId, int $evaluationPeriodId, int $evaluateeId): bool
    {
        $count = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.evaluator = :eid')
            ->andWhere('s.evaluationPeriod = :epid')
            ->andWhere('s.evaluatee = :evid')
            ->andWhere('s.isDraft = false')
            ->setParameter('eid', $evaluatorId)
            ->setParameter('epid', $evaluationPeriodId)
            ->setParameter('evid', $evaluateeId)
            ->getQuery()->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function findDrafts(int $evaluatorId, int $evaluationPeriodId, int $evaluateeId): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.evaluator = :eid')
            ->andWhere('s.evaluationPeriod = :epid')
            ->andWhere('s.evaluatee = :evid')
            ->andWhere('s.isDraft = true')
            ->setParameter('eid', $evaluatorId)
            ->setParameter('epid', $evaluationPeriodId)
            ->setParameter('evid', $evaluateeId)
            ->getQuery()->getResult();
    }

    public function getEvaluateeRankings(int $evaluationPeriodId, ?string $evaluateeRole = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select(
                'IDENTITY(s.evaluatee) as evaluateeId',
                'AVG(s.rating) as avgRating',
                'COUNT(DISTINCT s.evaluator) as evaluatorCount'
            )
            ->where('s.evaluationPeriod = :epid')
            ->andWhere('s.isDraft = false')
            ->setParameter('epid', $evaluationPeriodId)
            ->groupBy('s.evaluatee')
            ->orderBy('avgRating', 'DESC');

        if ($evaluateeRole) {
            $qb->andWhere('s.evaluateeRole = :role')->setParameter('role', $evaluateeRole);
        }

        return $qb->getQuery()->getResult();
    }

    public function getOverallAverage(int $evaluateeId, int $evaluationPeriodId): float
    {
        $result = $this->createQueryBuilder('s')
            ->select('AVG(s.rating)')
            ->where('s.evaluatee = :evid')
            ->andWhere('s.evaluationPeriod = :epid')
            ->andWhere('s.isDraft = false')
            ->setParameter('evid', $evaluateeId)
            ->setParameter('epid', $evaluationPeriodId)
            ->getQuery()->getSingleScalarResult();

        return round((float) ($result ?? 0), 2);
    }

    public function getOverallAverageAll(int $evaluateeId): float
    {
        $result = $this->createQueryBuilder('s')
            ->select('AVG(s.rating)')
            ->where('s.evaluatee = :evid')
            ->andWhere('s.isDraft = false')
            ->setParameter('evid', $evaluateeId)
            ->getQuery()->getSingleScalarResult();

        return round((float) ($result ?? 0), 2);
    }

    public function countEvaluatorsAll(int $evaluateeId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT s.evaluator)')
            ->where('s.evaluatee = :evid')
            ->andWhere('s.isDraft = false')
            ->setParameter('evid', $evaluateeId)
            ->getQuery()->getSingleScalarResult();
    }

    public function countEvaluators(int $evaluateeId, int $evaluationPeriodId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT s.evaluator)')
            ->where('s.evaluatee = :evid')
            ->andWhere('s.evaluationPeriod = :epid')
            ->andWhere('s.isDraft = false')
            ->setParameter('evid', $evaluateeId)
            ->setParameter('epid', $evaluationPeriodId)
            ->getQuery()->getSingleScalarResult();
    }

    public function countEvaluatorsByPeriod(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.evaluationPeriod) as epId, COUNT(DISTINCT s.evaluator) as cnt')
            ->where('s.isDraft = false')
            ->groupBy('s.evaluationPeriod')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['epId']] = (int) $row['cnt'];
        }
        return $map;
    }

    public function getEvaluatorAverage(int $evaluatorId, ?int $evaluationPeriodId = null): float
    {
        $qb = $this->createQueryBuilder('s')
            ->select('AVG(s.rating)')
            ->where('s.evaluator = :eid')
            ->andWhere('s.isDraft = false')
            ->setParameter('eid', $evaluatorId);

        if ($evaluationPeriodId !== null) {
            $qb->andWhere('s.evaluationPeriod = :epid')
                ->setParameter('epid', $evaluationPeriodId);
        }

        $result = $qb->getQuery()->getSingleScalarResult();
        return round((float) ($result ?? 0), 2);
    }

    public function countEvaluateesByEvaluator(int $evaluatorId, ?int $evaluationPeriodId = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT s.evaluatee)')
            ->where('s.evaluator = :eid')
            ->andWhere('s.isDraft = false')
            ->setParameter('eid', $evaluatorId);

        if ($evaluationPeriodId !== null) {
            $qb->andWhere('s.evaluationPeriod = :epid')
                ->setParameter('epid', $evaluationPeriodId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getComments(int $evaluateeId, int $evaluationPeriodId): array
    {
        return $this->createQueryBuilder('s')
            ->select('s.comment')
            ->where('s.evaluatee = :evid')
            ->andWhere('s.evaluationPeriod = :epid')
            ->andWhere('s.isDraft = false')
            ->andWhere('s.comment IS NOT NULL')
            ->andWhere('s.comment != :empty')
            ->setParameter('evid', $evaluateeId)
            ->setParameter('epid', $evaluationPeriodId)
            ->setParameter('empty', '')
            ->getQuery()->getResult();
    }

    public function getCategoryAverages(int $evaluateeId, int $evaluationPeriodId): array
    {
        return $this->createQueryBuilder('s')
            ->select('q.category, AVG(s.rating) as avgRating, COUNT(s.id) as responseCount')
            ->join('s.question', 'q')
            ->where('s.evaluatee = :evid')
            ->andWhere('s.evaluationPeriod = :epid')
            ->andWhere('s.isDraft = false')
            ->setParameter('evid', $evaluateeId)
            ->setParameter('epid', $evaluationPeriodId)
            ->groupBy('q.category')
            ->orderBy('q.category', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Get all evaluation periods where an evaluatee has been evaluated, with their average rating.
     * @return array<array{evaluationPeriodId: int, avgRating: float, evaluatorCount: int}>
     */
    public function getEvaluationsByEvaluatee(int $evaluateeId): array
    {
        return $this->createQueryBuilder('s')
            ->select('IDENTITY(s.evaluationPeriod) as evaluationPeriodId, AVG(s.rating) as avgRating, COUNT(DISTINCT s.evaluator) as evaluatorCount')
            ->where('s.evaluatee = :evid')
            ->andWhere('s.isDraft = false')
            ->setParameter('evid', $evaluateeId)
            ->groupBy('s.evaluationPeriod')
            ->orderBy('avgRating', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all evaluation periods where an evaluator has submitted SEF responses.
     * @return array<array{evaluationPeriodId: int, avgRating: float, evaluateeCount: int}>
     */
    public function getEvaluationsByEvaluator(int $evaluatorId): array
    {
        return $this->createQueryBuilder('s')
            ->select('IDENTITY(s.evaluationPeriod) as evaluationPeriodId, AVG(s.rating) as avgRating, COUNT(DISTINCT s.evaluatee) as evaluateeCount')
            ->where('s.evaluator = :eid')
            ->andWhere('s.isDraft = false')
            ->setParameter('eid', $evaluatorId)
            ->groupBy('s.evaluationPeriod')
            ->orderBy('avgRating', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<array{evaluateeId: int, avgRating: float, responseCount: int}>
     */
    public function getEvaluateesByEvaluator(int $evaluatorId, int $evaluationPeriodId): array
    {
        return $this->createQueryBuilder('s')
            ->select('IDENTITY(s.evaluatee) as evaluateeId, AVG(s.rating) as avgRating, COUNT(s.id) as responseCount')
            ->where('s.evaluator = :eid')
            ->andWhere('s.evaluationPeriod = :epid')
            ->andWhere('s.isDraft = false')
            ->setParameter('eid', $evaluatorId)
            ->setParameter('epid', $evaluationPeriodId)
            ->groupBy('s.evaluatee')
            ->orderBy('avgRating', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get average rating per question for an evaluatee in an evaluation period.
     * @return array<int, array{average: float, count: int}>
     */
    public function getAverageRatingsByEvaluatee(int $evaluateeId, int $evaluationPeriodId): array
    {
        $results = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.question) as questionId, AVG(s.rating) as avgRating, COUNT(s.id) as totalResponses')
            ->where('s.evaluatee = :evid')
            ->andWhere('s.evaluationPeriod = :epid')
            ->andWhere('s.isDraft = false')
            ->setParameter('evid', $evaluateeId)
            ->setParameter('epid', $evaluationPeriodId)
            ->groupBy('s.question')
            ->getQuery()
            ->getResult();

        $averages = [];
        foreach ($results as $row) {
            $averages[$row['questionId']] = [
                'average' => round((float) $row['avgRating'], 2),
                'count' => (int) $row['totalResponses'],
            ];
        }
        return $averages;
    }

    /**
     * @return array<int, array{average: float, count: int}>
     */
    public function getAverageRatingsByEvaluator(int $evaluatorId, int $evaluationPeriodId): array
    {
        $results = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.question) as questionId, AVG(s.rating) as avgRating, COUNT(s.id) as totalResponses')
            ->where('s.evaluator = :eid')
            ->andWhere('s.evaluationPeriod = :epid')
            ->andWhere('s.isDraft = false')
            ->setParameter('eid', $evaluatorId)
            ->setParameter('epid', $evaluationPeriodId)
            ->groupBy('s.question')
            ->getQuery()
            ->getResult();

        $averages = [];
        foreach ($results as $row) {
            $averages[$row['questionId']] = [
                'average' => round((float) $row['avgRating'], 2),
                'count' => (int) $row['totalResponses'],
            ];
        }

        return $averages;
    }

    public function getCommentsByEvaluator(int $evaluatorId, int $evaluationPeriodId): array
    {
        return $this->createQueryBuilder('s')
            ->select('s.comment')
            ->where('s.evaluator = :eid')
            ->andWhere('s.evaluationPeriod = :epid')
            ->andWhere('s.isDraft = false')
            ->andWhere('s.comment IS NOT NULL')
            ->andWhere('s.comment != :empty')
            ->setParameter('eid', $evaluatorId)
            ->setParameter('epid', $evaluationPeriodId)
            ->setParameter('empty', '')
            ->getQuery()
            ->getResult();
    }
}
