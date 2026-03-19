<?php

namespace App\Repository;

use App\Entity\EvaluationResponse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvaluationResponse>
 */
class EvaluationResponseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationResponse::class);
    }

    /**
     * Check if a student has already submitted for a given evaluation period + faculty + subject + section.
     */
    public function hasSubmitted(int $evaluatorId, int $evaluationPeriodId, int $facultyId, ?int $subjectId = null, ?string $section = null): bool
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.evaluator = :eid')
            ->andWhere('r.evaluationPeriod = :epid')
            ->andWhere('r.faculty = :fid')
            ->andWhere('r.isDraft = false')
            ->setParameter('eid', $evaluatorId)
            ->setParameter('epid', $evaluationPeriodId)
            ->setParameter('fid', $facultyId);

        if ($subjectId) {
            $qb->andWhere('r.subject = :sid')->setParameter('sid', $subjectId);
        }

        if ($section !== null) {
            $qb->andWhere('r.section = :section')->setParameter('section', $section);
        } else {
            $qb->andWhere('r.section IS NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Get average rating per question for a faculty in an evaluation period.
     * @return array<int, array{average: float, count: int}>
     */
    public function getAverageRatingsByFaculty(int $facultyId, int $evaluationPeriodId): array
    {
        $results = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.question) as questionId, AVG(r.rating) as avgRating, COUNT(r.id) as totalResponses')
            ->where('r.faculty = :fid')
            ->andWhere('r.evaluationPeriod = :epid')
            ->andWhere('r.isDraft = false')
            ->setParameter('fid', $facultyId)
            ->setParameter('epid', $evaluationPeriodId)
            ->groupBy('r.question')
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
     * Get overall average rating for a faculty in an evaluation period.
     */
    public function getOverallAverage(int $facultyId, int $evaluationPeriodId): float
    {
        $result = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) as avgRating')
            ->where('r.faculty = :fid')
            ->andWhere('r.evaluationPeriod = :epid')
            ->andWhere('r.isDraft = false')
            ->setParameter('fid', $facultyId)
            ->setParameter('epid', $evaluationPeriodId)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) ($result ?? 0), 2);
    }

    /**
     * Get overall average rating for a faculty across ALL evaluation periods.
     */
    public function getOverallAverageAll(int $facultyId): float
    {
        $result = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) as avgRating')
            ->where('r.faculty = :fid')
            ->andWhere('r.isDraft = false')
            ->setParameter('fid', $facultyId)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) ($result ?? 0), 2);
    }

    /**
     * Get all comments for a faculty in an evaluation period.
     * @return string[]
     */
    public function getComments(int $facultyId, int $evaluationPeriodId): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.comment')
            ->where('r.faculty = :fid')
            ->andWhere('r.evaluationPeriod = :epid')
            ->andWhere('r.comment IS NOT NULL')
            ->andWhere('r.comment != :empty')
            ->andWhere('r.isDraft = false')
            ->setParameter('fid', $facultyId)
            ->setParameter('epid', $evaluationPeriodId)
            ->setParameter('empty', '')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Count total unique evaluators for a faculty in an evaluation period.
     */
    public function countEvaluators(int $facultyId, int $evaluationPeriodId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.evaluator)')
            ->where('r.faculty = :fid')
            ->andWhere('r.evaluationPeriod = :epid')
            ->andWhere('r.isDraft = false')
            ->setParameter('fid', $facultyId)
            ->setParameter('epid', $evaluationPeriodId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count total unique evaluators for a faculty across ALL evaluation periods.
     */
    public function countEvaluatorsAll(int $facultyId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.evaluator)')
            ->where('r.faculty = :fid')
            ->andWhere('r.isDraft = false')
            ->setParameter('fid', $facultyId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get department average for all faculty in a department.
     */
    public function getDepartmentAverage(int $departmentId, int $evaluationPeriodId): float
    {
        $result = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) as avgRating')
            ->join('r.faculty', 'f')
            ->where('f.department = :did')
            ->andWhere('r.evaluationPeriod = :epid')
            ->andWhere('r.isDraft = false')
            ->setParameter('did', $departmentId)
            ->setParameter('epid', $evaluationPeriodId)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) ($result ?? 0), 2);
    }

    /**
     * Get participation rate for an evaluation period.
     */
    public function getParticipationRate(int $evaluationPeriodId, int $totalExpected): array
    {
        $submitted = (int) $this->createQueryBuilder('r')
            ->select('COUNT(DISTINCT r.evaluator)')
            ->where('r.evaluationPeriod = :epid')
            ->andWhere('r.isDraft = false')
            ->setParameter('epid', $evaluationPeriodId)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'submitted' => $submitted,
            'total' => $totalExpected,
            'rate' => $totalExpected > 0 ? round($submitted / $totalExpected * 100, 1) : 0,
        ];
    }

    /**
     * Get draft responses for a student for a specific faculty/period.
     * @return EvaluationResponse[]
     */
    public function findDrafts(int $evaluatorId, int $evaluationPeriodId, int $facultyId, ?int $subjectId = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.evaluator = :eid')
            ->andWhere('r.evaluationPeriod = :epid')
            ->andWhere('r.faculty = :fid')
            ->andWhere('r.isDraft = true')
            ->setParameter('eid', $evaluatorId)
            ->setParameter('epid', $evaluationPeriodId)
            ->setParameter('fid', $facultyId);

        if ($subjectId) {
            $qb->andWhere('r.subject = :sid')->setParameter('sid', $subjectId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get all faculty with their average rating for ranking.
     */
    public function getFacultyRankings(int $evaluationPeriodId): array
    {
        return $this->createQueryBuilder('r')
            ->select('IDENTITY(r.faculty) as facultyId, AVG(r.rating) as avgRating, COUNT(DISTINCT r.evaluator) as evaluatorCount')
            ->where('r.evaluationPeriod = :epid')
            ->andWhere('r.isDraft = false')
            ->setParameter('epid', $evaluationPeriodId)
            ->groupBy('r.faculty')
            ->orderBy('avgRating', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count unique evaluators per evaluation period.
     * @return array<int, int>  evaluationPeriodId => count
     */
    public function countEvaluatorsByPeriod(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.evaluationPeriod) as epId, COUNT(DISTINCT r.evaluator) as cnt')
            ->where('r.isDraft = false')
            ->groupBy('r.evaluationPeriod')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['epId']] = (int) $row['cnt'];
        }
        return $map;
    }

    /**
     * Get all evaluation periods where a faculty has been evaluated, with their average rating.
     * @return array<array{evaluationPeriodId: int, avgRating: float, evaluatorCount: int}>
     */
    public function getEvaluationsByFaculty(int $facultyId): array
    {
        return $this->createQueryBuilder('r')
            ->select('IDENTITY(r.evaluationPeriod) as evaluationPeriodId, AVG(r.rating) as avgRating, COUNT(DISTINCT r.evaluator) as evaluatorCount')
            ->where('r.faculty = :fid')
            ->andWhere('r.isDraft = false')
            ->setParameter('fid', $facultyId)
            ->groupBy('r.evaluationPeriod')
            ->orderBy('avgRating', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get distinct subjects evaluated for a faculty, with average rating per subject+section.
     * @return array<array{subjectId: int, subjectCode: string, subjectName: string, section: string|null, evaluationPeriodId: int, evaluatorCount: int, avgRating: float}>
     */
    public function getEvaluatedSubjectsWithRating(int $facultyId): array
    {
        return $this->createQueryBuilder('r')
            ->select(
                'IDENTITY(r.subject) as subjectId',
                's.subjectCode',
                's.subjectName',
                'r.section',
                'IDENTITY(r.evaluationPeriod) as evaluationPeriodId',
                'COUNT(DISTINCT r.evaluator) as evaluatorCount',
                'AVG(r.rating) as avgRating'
            )
            ->join('r.subject', 's')
            ->where('r.faculty = :fid')
            ->andWhere('r.isDraft = false')
            ->andWhere('r.subject IS NOT NULL')
            ->setParameter('fid', $facultyId)
            ->groupBy('r.subject, r.section, r.evaluationPeriod')
            ->orderBy('s.subjectCode', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get distinct subjects evaluated for a faculty, grouped by evaluation period.
     * @return array<array{subjectId: int, subjectCode: string, subjectName: string, section: string|null, evaluationPeriodId: int, evaluatorCount: int}>
     */
    public function getEvaluatedSubjects(int $facultyId): array
    {
        return $this->createQueryBuilder('r')
            ->select(
                'IDENTITY(r.subject) as subjectId',
                's.subjectCode',
                's.subjectName',
                'r.section',
                'IDENTITY(r.evaluationPeriod) as evaluationPeriodId',
                'COUNT(DISTINCT r.evaluator) as evaluatorCount'
            )
            ->join('r.subject', 's')
            ->where('r.faculty = :fid')
            ->andWhere('r.isDraft = false')
            ->andWhere('r.subject IS NOT NULL')
            ->setParameter('fid', $facultyId)
            ->groupBy('r.subject, r.section, r.evaluationPeriod')
            ->orderBy('s.subjectCode', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get a student's submitted evaluations grouped by period+faculty+subject.
     * @return array<array{evaluationPeriodId: int, facultyId: int, subjectId: int|null, subjectCode: string|null, subjectName: string|null, facultyName: string, semester: string, schoolYear: string, submittedAt: string, avgRating: float}>
     */
    public function getStudentHistory(int $evaluatorId): array
    {
        return $this->createQueryBuilder('r')
            ->select(
                'IDENTITY(r.evaluationPeriod) as evaluationPeriodId',
                'IDENTITY(r.faculty) as facultyId',
                'IDENTITY(r.subject) as subjectId',
                's.subjectCode',
                's.subjectName',
                "CONCAT(f.firstName, ' ', f.lastName) as facultyName",
                'ep.semester',
                'ep.schoolYear',
                'MAX(r.submittedAt) as submittedAt',
                'AVG(r.rating) as avgRating'
            )
            ->join('r.evaluationPeriod', 'ep')
            ->join('r.faculty', 'f')
            ->leftJoin('r.subject', 's')
            ->where('r.evaluator = :eid')
            ->andWhere('r.isDraft = false')
            ->andWhere('ep.evaluationType = :type')
            ->setParameter('eid', $evaluatorId)
            ->setParameter('type', 'SET')
            ->groupBy('r.evaluationPeriod, r.faculty, r.subject')
            ->orderBy('MAX(r.submittedAt)', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
