<?php

namespace App\Controller;

use App\Entity\EvaluationMessage;
use App\Entity\FacultyNotificationRead;
use App\Entity\FacultySubjectLoad;
use App\Entity\MessageNotification;
use App\Entity\Subject;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\AuditLogRepository;
use App\Repository\EvaluationMessageRepository;
use App\Repository\CurriculumRepository;
use App\Repository\FacultyNotificationReadRepository;
use App\Repository\FacultySubjectLoadRepository;
use App\Repository\DepartmentRepository;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\EvaluationResponseRepository;
use App\Repository\MessageNotificationRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;
use App\Repository\SuperiorEvaluationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class HomeController extends AbstractController
{
    /**
     * Returns true when there is a currently active SET evaluation that applies
     * to the given faculty + subject (+ optional section).
     */
    private function hasActiveSetEvaluationForLoad(
        User $faculty,
        Subject $subject,
        ?string $section,
        EvaluationPeriodRepository $evalRepo
    ): bool {
        $now = new \DateTime();

        $facultyName = mb_strtolower(trim($faculty->getFirstName() . ' ' . $faculty->getLastName()));
        $facultyLastFirst = mb_strtolower(trim($faculty->getLastName() . ', ' . $faculty->getFirstName()));
        $facultyLastName = mb_strtolower(trim($faculty->getLastName()));

        $normalizeCode = static function (?string $value): string {
            $raw = mb_strtoupper(trim((string) $value));
            $normalized = preg_replace('/[^A-Z0-9]/u', '', $raw);
            return $normalized ?? '';
        };

        $normalizeText = static function (?string $value): string {
            $raw = mb_strtolower(trim((string) $value));
            $normalized = preg_replace('/\s+/u', ' ', $raw);
            return $normalized ?? '';
        };

        $normalizeSection = static function (?string $value): string {
            $raw = mb_strtoupper(trim((string) $value));
            $normalized = preg_replace('/\s+/u', ' ', $raw);
            return $normalized ?? '';
        };

        $subjectCode = $normalizeCode($subject->getSubjectCode());
        $subjectName = $normalizeText($subject->getSubjectName());
        $targetSection = $normalizeSection($section);

        $activeEvals = $evalRepo->findBy(['evaluationType' => 'SET', 'status' => true]);
        foreach ($activeEvals as $eval) {
            if ($eval->getStartDate() > $now || $eval->getEndDate() < $now) {
                continue;
            }

            $evalFaculty = mb_strtolower(trim((string) ($eval->getFaculty() ?? '')));
            if (
                $evalFaculty !== '' &&
                $evalFaculty !== $facultyName &&
                $evalFaculty !== $facultyLastFirst &&
                !str_contains($evalFaculty, $facultyLastName)
            ) {
                continue;
            }

            $evalSection = $normalizeSection($eval->getSection());
            if ($evalSection !== '' && $evalSection !== $targetSection) {
                continue;
            }

            $evalSubject = trim((string) ($eval->getSubject() ?? ''));
            if ($evalSubject === '') {
                // Faculty-wide active evaluation applies.
                return true;
            }

            $parts = preg_split('/\s*[—-]\s*/u', $evalSubject, 2);
            $evalCode = $normalizeCode($parts[0] ?? $evalSubject);
            $evalSubjectNorm = $normalizeText($evalSubject);

            $matchesByCode = $evalCode !== '' && $evalCode === $subjectCode;
            $matchesByText = ($evalSubjectNorm !== '' && (
                str_contains($evalSubjectNorm, $subjectCode) ||
                str_contains($evalSubjectNorm, $subjectName)
            ));

            if ($matchesByCode || $matchesByText) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scope faculty views to all departments in the same college.
     * Fallback to own department when college is not set.
     *
     * @param array<int, \App\Entity\Department> $allDepartments
     * @return array<int, \App\Entity\Department>
     */
    private function getFacultyScopedDepartments(User $user, array $allDepartments): array
    {
        $facultyDept = $user->getDepartment();
        if (!$facultyDept) {
            return $allDepartments;
        }

        $collegeName = $facultyDept->getCollegeName();
        if (!$collegeName) {
            return [$facultyDept];
        }

        $scoped = array_values(array_filter(
            $allDepartments,
            fn($d) => $d->getCollegeName() === $collegeName
        ));

        return $scoped ?: [$facultyDept];
    }

    private function normalizeAcademicSemester(?string $semester): ?string
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

    private function subjectAllowedForActiveAcademicSemester(Subject $subject, ?string $activeSemester): bool
    {
        if ($activeSemester === null) {
            return true;
        }

        $subjectSemester = $this->normalizeAcademicSemester($subject->getSemester());
        if ($subjectSemester === null) {
            return true;
        }

        return $subjectSemester === $activeSemester;
    }

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->render('home/welcome.html.twig');
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function dashboard(
        UserRepository $userRepo,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        SubjectRepository $subjectRepo,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
        QuestionRepository $questionRepo,
        DepartmentRepository $deptRepo,
        AuditLogRepository $auditRepo,
        CurriculumRepository $curriculumRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->adminDashboard($userRepo, $evalRepo, $responseRepo, $deptRepo, $subjectRepo, $questionRepo, $auditRepo, $curriculumRepo);
        }

        if ($user->hasAssignedRole('ROLE_SUPERIOR')) {
            return $this->superiorDashboard($user, $evalRepo, $responseRepo, $userRepo, $deptRepo, $superiorEvalRepo);
        }

        if ($this->isGranted('ROLE_FACULTY')) {
            return $this->facultyDashboard($user, $subjectRepo, $evalRepo, $responseRepo, $ayRepo, $fslRepo);
        }

        if ($this->isGranted('ROLE_SUPERIOR')) {
            return $this->superiorDashboard($user, $evalRepo, $responseRepo, $userRepo, $deptRepo, $superiorEvalRepo);
        }

        if ($this->isGranted('ROLE_STAFF')) {
            return $this->staffDashboard($evalRepo, $responseRepo, $userRepo, $deptRepo, $subjectRepo);
        }

        // Student (ROLE_USER)
        return $this->studentDashboard($user, $curriculumRepo, $evalRepo, $responseRepo, $userRepo);
    }

    private function adminDashboard(
        UserRepository $userRepo,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        DepartmentRepository $deptRepo,
        SubjectRepository $subjectRepo,
        QuestionRepository $questionRepo,
        AuditLogRepository $auditRepo,
        CurriculumRepository $curriculumRepo,
    ): Response {
        $allUsers = $userRepo->findAll();
        $totalUsers = count($allUsers);
        $openEvals = $evalRepo->findOpen();
        $departments = $deptRepo->findAllOrdered();

        // Role distribution
        $roleCounts = ['admin' => 0, 'superior' => 0, 'faculty' => 0, 'staff' => 0, 'student' => 0];
        $pendingCount = 0;
        $activeCount = 0;
        foreach ($allUsers as $u) {
            if ($u->isAdmin()) $roleCounts['admin']++;
            elseif ($u->isSuperior()) $roleCounts['superior']++;
            elseif ($u->isFaculty()) $roleCounts['faculty']++;
            elseif ($u->isStaff()) $roleCounts['staff']++;
            else $roleCounts['student']++;

            if ($u->getAccountStatus() === 'pending') $pendingCount++;
            if ($u->getAccountStatus() === 'active') $activeCount++;
        }

        // Subject counts
        $totalSubjects = count($subjectRepo->findAll());

        // Curriculum counts
        $totalCurricula = count($curriculumRepo->findAll());

        // Question counts
        $setQuestions = count($questionRepo->findByType('SET'));
        $sefQuestions = count($questionRepo->findByType('SEF'));

        // Response stats per open evaluation
        $evalStats = [];
        foreach ($openEvals as $ev) {
            $responses = $responseRepo->createQueryBuilder('r')
                ->select('COUNT(DISTINCT r.evaluator)')
                ->where('r.evaluationPeriod = :ep')
                ->andWhere('r.isDraft = false')
                ->setParameter('ep', $ev)
                ->getQuery()->getSingleScalarResult();

            $evalStats[] = [
                'evaluation' => $ev,
                'respondents' => (int) $responses,
            ];
        }

        // All evaluation periods (for historical table)
        $allEvals = $evalRepo->findAllOrdered();

        // Recent audit activity
        $recentLogs = $auditRepo->findRecent(10);

        // ── Completed student evaluation submissions ──
        $submissions = $responseRepo->createQueryBuilder('r')
            ->select(
                'IDENTITY(r.evaluationPeriod) as epId',
                'IDENTITY(r.faculty) as facultyId',
                'IDENTITY(r.subject) as subjectId',
                'COUNT(DISTINCT r.evaluator) as evaluatorCount',
                'MAX(r.section) as responseSection',
                'MAX(r.submittedAt) as lastSubmitted'
            )
            ->where('r.isDraft = false')
            ->groupBy('r.evaluationPeriod, r.faculty, r.subject')
            ->orderBy('MAX(r.submittedAt)', 'DESC')
            ->getQuery()
            ->getResult();

        $epIds = array_unique(array_filter(array_column($submissions, 'epId')));
        $facIds = array_unique(array_filter(array_column($submissions, 'facultyId')));
        $subIds = array_unique(array_filter(array_column($submissions, 'subjectId')));

        $epMap = [];
        foreach ($epIds ? $evalRepo->findBy(['id' => $epIds]) : [] as $e) {
            $epMap[$e->getId()] = $e;
        }
        $userMap = [];
        foreach ($facIds ? $userRepo->findBy(['id' => $facIds]) : [] as $u) {
            $userMap[$u->getId()] = $u;
        }
        $subMap = [];
        foreach ($subIds ? $subjectRepo->findBy(['id' => $subIds]) : [] as $s) {
            $subMap[$s->getId()] = $s;
        }

        $completedEvals = [];
        foreach ($submissions as $sub) {
            $ep   = $epMap[$sub['epId']] ?? null;
            $fac  = $userMap[$sub['facultyId']] ?? null;
            $subj = isset($sub['subjectId']) ? ($subMap[$sub['subjectId']] ?? null) : null;

            $completedEvals[] = [
                'subject'       => $subj ? $subj->getSubjectName() : ($ep ? ($ep->getSubject() ?? '—') : '—'),
                'faculty'       => $fac ? $fac->getFullName() : ($ep ? ($ep->getFaculty() ?? '—') : '—'),
                'time'          => $ep ? ($ep->getTime() ?? '—') : '—',
                'section'       => $ep ? ($ep->getSection() ?? '—') : '—',
                'submittedAt'   => $sub['lastSubmitted'],
                'college'       => ($ep && $ep->getDepartment()) ? ($ep->getDepartment()->getCollegeName() ?? '—') : '—',
                'department'    => ($ep && $ep->getDepartment()) ? $ep->getDepartment()->getDepartmentName() : '—',
                'responseCount' => (int) $sub['evaluatorCount'],
                'evalId'        => $sub['epId'],
                'facultyId'     => $sub['facultyId'],
            ];
        }

        $completedEvals = array_values(array_filter($completedEvals, static function (array $row): bool {
            $subject = mb_strtolower(trim((string) ($row['subject'] ?? '')));
            $faculty = mb_strtolower(trim((string) ($row['faculty'] ?? '')));

            return !($subject === 'capstone project 2' && $faculty === 'ryan escorial');
        }));

        // ── Monthly Evaluation Trends (for chart) ──
        $currentYear = (int) date('Y');
        $monthlyTrends = ['SET' => array_fill(0, 12, 0), 'SEF' => array_fill(0, 12, 0)];

        // Fetch all submissions for current year and group in PHP
        $yearStart = new \DateTime("$currentYear-01-01 00:00:00");
        $yearEnd = new \DateTime("$currentYear-12-31 23:59:59");

        $trendData = $responseRepo->createQueryBuilder('r')
            ->select('r.submittedAt', 'ep.evaluationType as type', 'IDENTITY(r.evaluator) as evaluatorId', 'IDENTITY(r.faculty) as facultyId', 'IDENTITY(r.evaluationPeriod) as epId')
            ->join('r.evaluationPeriod', 'ep')
            ->where('r.isDraft = false')
            ->andWhere('r.submittedAt BETWEEN :start AND :end')
            ->setParameter('start', $yearStart)
            ->setParameter('end', $yearEnd)
            ->getQuery()
            ->getResult();

        // Group by month and type, counting unique evaluator+faculty+period combinations
        $seen = [];
        foreach ($trendData as $row) {
            $type = $row['type'] ?? 'SET';
            $month = (int) $row['submittedAt']->format('n') - 1; // 0-indexed
            $key = $type . '-' . $month . '-' . ($row['evaluatorId'] ?? 'anon') . '-' . $row['facultyId'] . '-' . $row['epId'];

            if (!isset($seen[$key]) && $month >= 0 && $month < 12 && isset($monthlyTrends[$type])) {
                $monthlyTrends[$type][$month]++;
                $seen[$key] = true;
            }
        }

        return $this->render('home/admin_dashboard.html.twig', [
            'totalUsers'      => $totalUsers,
            'openEvaluations' => $openEvals,
            'departments'     => $departments,
            'roleCounts'      => $roleCounts,
            'pendingCount'    => $pendingCount,
            'activeCount'     => $activeCount,
            'totalSubjects'   => $totalSubjects,
            'totalCurricula'  => $totalCurricula,
            'setQuestions'    => $setQuestions,
            'sefQuestions'    => $sefQuestions,
            'evalStats'       => $evalStats,
            'allEvals'        => $allEvals,
            'completedEvals'  => $completedEvals,
            'recentLogs'      => $recentLogs,
            'monthlyTrends'   => $monthlyTrends,
            'studentCount'    => $roleCounts['student'],
            'facultyCount'    => $roleCounts['faculty'],
            'staffCount'      => $roleCounts['staff'],
            'adminCount'      => $roleCounts['admin'],
        ]);
    }

    private function staffDashboard(
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
        SubjectRepository $subjectRepo,
    ): Response {
        $openEvals = $evalRepo->findOpen();
        $allEvals = $evalRepo->findAllOrdered();

        // ── Key Counts ──
        $allUsers = $userRepo->findAll();
        $facultyCount = 0;
        $studentCount = 0;
        foreach ($allUsers as $u) {
            if ($u->isFaculty()) $facultyCount++;
            elseif (!$u->isAdmin() && !$u->isSuperior() && !$u->isStaff()) $studentCount++;
        }

        $departments = $deptRepo->findAllOrdered();
        $totalSubjects = count($subjectRepo->findAll());
        $totalDepartments = count($departments);

        // ── Evaluation stats with respondent count ──
        $evalStats = [];
        foreach ($openEvals as $ev) {
            $respondents = (int) $responseRepo->createQueryBuilder('r')
                ->select('COUNT(DISTINCT r.evaluator)')
                ->where('r.evaluationPeriod = :ep')
                ->andWhere('r.isDraft = false')
                ->setParameter('ep', $ev)
                ->getQuery()->getSingleScalarResult();

            $evalStats[] = [
                'evaluation' => $ev,
                'respondents' => $respondents,
            ];
        }

        // ── History eval stats (respondent counts for all evals) ──
        $historyStats = [];
        foreach ($allEvals as $ev) {
            $respondents = (int) $responseRepo->createQueryBuilder('r')
                ->select('COUNT(DISTINCT r.evaluator)')
                ->where('r.evaluationPeriod = :ep')
                ->andWhere('r.isDraft = false')
                ->setParameter('ep', $ev)
                ->getQuery()->getSingleScalarResult();

            $totalResponsesForEval = (int) $responseRepo->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.evaluationPeriod = :ep')
                ->andWhere('r.isDraft = false')
                ->setParameter('ep', $ev)
                ->getQuery()->getSingleScalarResult();

            $historyStats[$ev->getId()] = [
                'respondents' => $respondents,
                'responses' => $totalResponsesForEval,
            ];
        }

        // ── Monthly submission trend (last 6 months) ──
        $monthlyData = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = new \DateTime("first day of -$i months midnight");
            $end = (clone $start)->modify('last day of this month 23:59:59');
            $label = $start->format('M Y');

            $count = (int) $responseRepo->createQueryBuilder('r')
                ->select('COUNT(DISTINCT r.evaluator)')
                ->where('r.submittedAt >= :start')
                ->andWhere('r.submittedAt <= :end')
                ->andWhere('r.isDraft = false')
                ->setParameter('start', $start)
                ->setParameter('end', $end)
                ->getQuery()->getSingleScalarResult();

            $monthlyData[] = ['label' => $label, 'count' => $count];
        }

        // ── Performance distribution across all evaluations ──
        $perfDistribution = ['Excellent' => 0, 'Very Good' => 0, 'Good' => 0, 'Fair' => 0, 'Poor' => 0];
        foreach ($allEvals as $ev) {
            $rankings = $responseRepo->getFacultyRankings($ev->getId());
            foreach ($rankings as $r) {
                $avg = round((float) $r['avgRating'], 2);
                $level = match (true) {
                    $avg >= 4.5 => 'Excellent',
                    $avg >= 3.5 => 'Very Good',
                    $avg >= 2.5 => 'Good',
                    $avg >= 1.5 => 'Fair',
                    default => 'Poor',
                };
                $perfDistribution[$level]++;
            }
        }

        // ── Top 5 faculty (latest evaluation) ──
        $topFaculty = [];
        $latestEval = $allEvals[0] ?? null;
        if ($latestEval) {
            $rankings = $responseRepo->getFacultyRankings($latestEval->getId());
            $rank = 0;
            foreach ($rankings as $r) {
                if ($rank >= 5) break;
                $faculty = $userRepo->find($r['facultyId']);
                if ($faculty) {
                    $topFaculty[] = [
                        'faculty' => $faculty,
                        'average' => round((float) $r['avgRating'], 2),
                        'evaluators' => (int) $r['evaluatorCount'],
                    ];
                    $rank++;
                }
            }
        }

        // ── Completed student evaluation submissions ──
        $submissions = $responseRepo->createQueryBuilder('r')
            ->select(
                'IDENTITY(r.evaluationPeriod) as epId',
                'IDENTITY(r.faculty) as facultyId',
                'IDENTITY(r.subject) as subjectId',
                'COUNT(DISTINCT r.evaluator) as evaluatorCount',
                'MAX(r.submittedAt) as lastSubmitted'
            )
            ->where('r.isDraft = false')
            ->groupBy('r.evaluationPeriod, r.faculty, r.subject')
            ->orderBy('MAX(r.submittedAt)', 'DESC')
            ->getQuery()
            ->getResult();

        $epIds = array_unique(array_filter(array_column($submissions, 'epId')));
        $facIds = array_unique(array_filter(array_column($submissions, 'facultyId')));
        $subIds = array_unique(array_filter(array_column($submissions, 'subjectId')));

        $epMap = [];
        foreach ($epIds ? $evalRepo->findBy(['id' => $epIds]) : [] as $e) {
            $epMap[$e->getId()] = $e;
        }
        $uMap = [];
        foreach ($facIds ? $userRepo->findBy(['id' => $facIds]) : [] as $u) {
            $uMap[$u->getId()] = $u;
        }
        $sMap = [];
        foreach ($subIds ? $subjectRepo->findBy(['id' => $subIds]) : [] as $s) {
            $sMap[$s->getId()] = $s;
        }

        $completedEvalMap = [];
        $now = new \DateTimeImmutable();
        foreach ($submissions as $sub) {
            $ep   = $epMap[$sub['epId']] ?? null;
            if (!$ep || !$ep->isStatus() || $ep->getStartDate() > $now || $ep->getEndDate() < $now) {
                continue;
            }
            $fac  = $uMap[$sub['facultyId']] ?? null;
            $subj = isset($sub['subjectId']) ? ($sMap[$sub['subjectId']] ?? null) : null;

            $evaluationTime = $ep ? trim((string) ($ep->getTime() ?? '')) : '';
            $subjectSchedule = $subj ? trim((string) ($subj->getSchedule() ?? '')) : '';
            $evaluationSection = $ep ? trim((string) ($ep->getSection() ?? '')) : '';
            $responseSection = trim((string) ($sub['responseSection'] ?? ''));
            $subjectSection = $subj ? trim((string) ($subj->getSection() ?? '')) : '';

            $entry = [
                'subject'       => $subj ? $subj->getSubjectName() : ($ep ? ($ep->getSubject() ?? '—') : '—'),
                'faculty'       => $fac ? $fac->getFullName() : ($ep ? ($ep->getFaculty() ?? '—') : '—'),
                'time'          => $evaluationTime !== '' ? $evaluationTime : ($subjectSchedule !== '' ? $subjectSchedule : '—'),
                'section'       => $evaluationSection !== '' ? $evaluationSection : ($responseSection !== '' ? $responseSection : ($subjectSection !== '' ? $subjectSection : '—')),
                'submittedAt'   => $sub['lastSubmitted'],
                'college'       => ($ep && $ep->getDepartment()) ? ($ep->getDepartment()->getCollegeName() ?? '—') : '—',
                'department'    => ($ep && $ep->getDepartment()) ? $ep->getDepartment()->getDepartmentName() : '—',
                'responseCount' => (int) $sub['evaluatorCount'],
                'evalId'        => $sub['epId'],
                'facultyId'     => $sub['facultyId'],
            ];

            $subjectName = mb_strtolower(trim((string) ($entry['subject'] ?? '')));
            $facultyName = mb_strtolower(trim((string) ($entry['faculty'] ?? '')));
            if ($subjectName === 'capstone project 2' && $facultyName === 'ryan escorial') {
                continue;
            }

            // Keep only the latest submission per subject+faculty to avoid duplicate rows.
            $subjectKey = $sub['subjectId'] !== null
                ? 's:' . (string) $sub['subjectId']
                : 'sn:' . strtolower(trim((string) $entry['subject']));
            $facultyKey = $sub['facultyId'] !== null
                ? 'f:' . (string) $sub['facultyId']
                : 'fn:' . strtolower(trim((string) $entry['faculty']));
            $dedupeKey = $subjectKey . '|' . $facultyKey;

            if (!isset($completedEvalMap[$dedupeKey])) {
                $completedEvalMap[$dedupeKey] = $entry;
                continue;
            }

            $existingTs = strtotime((string) $completedEvalMap[$dedupeKey]['submittedAt']) ?: 0;
            $currentTs = strtotime((string) $entry['submittedAt']) ?: 0;
            if ($currentTs > $existingTs) {
                $completedEvalMap[$dedupeKey] = $entry;
            }
        }

        $completedEvals = array_values($completedEvalMap);
        usort($completedEvals, static function (array $a, array $b): int {
            $aTs = strtotime((string) $a['submittedAt']) ?: 0;
            $bTs = strtotime((string) $b['submittedAt']) ?: 0;
            return $bTs <=> $aTs;
        });

        // Keep total responses consistent with rows currently shown on the dashboard.
        $totalResponses = array_sum(array_map(
            static fn(array $row): int => (int) ($row['responseCount'] ?? 0),
            $completedEvals
        ));

        return $this->render('home/staff_dashboard.html.twig', [
            'openEvaluations'  => $openEvals,
            'allEvals'         => $allEvals,
            'evalStats'        => $evalStats,
            'historyStats'     => $historyStats,
            'facultyCount'     => $facultyCount,
            'studentCount'     => $studentCount,
            'totalResponses'   => $totalResponses,
            'totalSubjects'    => $totalSubjects,
            'totalDepartments' => $totalDepartments,
            'departments'      => $departments,
            'monthlyData'      => $monthlyData,
            'perfDistribution' => $perfDistribution,
            'topFaculty'       => $topFaculty,
            'latestEval'       => $latestEval,
            'completedEvals'   => $completedEvals,
        ]);
    }

    private function facultyDashboard(
        User $user,
        SubjectRepository $subjectRepo,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
    ): Response {
        $currentAY = $ayRepo->findCurrent();

        // Subject loads are primarily tracked in FacultySubjectLoad. Merge with legacy direct links.
        $subjectsById = [];

        $savedLoads = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY?->getId());
        foreach ($savedLoads as $load) {
            $subject = $load->getSubject();
            $subjectsById[$subject->getId()] = $subject;
        }

        $directSubjects = $subjectRepo->findByFaculty($user->getId());
        foreach ($directSubjects as $subject) {
            $subjectsById[$subject->getId()] = $subject;
        }

        /** @var Subject[] $subjects */
        $subjects = array_values($subjectsById);
        usort($subjects, static fn(Subject $a, Subject $b) => strcmp($a->getSubjectCode(), $b->getSubjectCode()));

        $deptId = $user->getDepartment() ? $user->getDepartment()->getId() : null;
        $facultyName = $user->getFullName();
        $allOpenEvals = $evalRepo->findOpen();

        // Filter open evaluations to only those relevant to this faculty's department
        $openEvals = [];
        foreach ($allOpenEvals as $eval) {
            $type = $eval->getEvaluationType();
            if ($type === 'SUPERIOR') {
                continue;
            }
            $evalDept = $eval->getDepartment();
            if ($evalDept === null || ($deptId && $evalDept->getId() === $deptId)) {
                if ($eval->getFaculty() !== null && $eval->getFaculty() !== $facultyName) {
                    continue;
                }
                $openEvals[] = $eval;
            }
        }

        $evalResults = [];
        foreach ($openEvals as $eval) {
            if ($eval->isResultsLocked()) {
                continue; // Only show unlocked results
            }
            $avg = $responseRepo->getOverallAverage($user->getId(), $eval->getId());
            $count = $responseRepo->countEvaluators($user->getId(), $eval->getId());
            $comments = $responseRepo->getComments($user->getId(), $eval->getId());

            if ($count > 0) {
                $evalResults[] = [
                    'evaluation' => $eval,
                    'average' => $avg,
                    'evaluators' => $count,
                    'comments' => $comments,
                ];
            }
        }

        return $this->render('home/faculty_dashboard.html.twig', [
            'subjects' => $subjects,
            'openEvaluations' => $openEvals,
            'evalResults' => $evalResults,
        ]);
    }

    private function superiorDashboard(
        User $user,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
    ): Response {
        $latestEval = $evalRepo->findLatestOpen('SUPERIOR');
        $openEvals = $latestEval ? [$latestEval] : [];
        $allEvals = $evalRepo->findAllOrdered();
        $departments = $deptRepo->findAllOrdered();

        // Get only the faculty assigned to each open SUPERIOR evaluation
        $deans = [];
        $deptHeads = [];
        foreach ($openEvals as $eval) {
            $facultyName = $eval->getFaculty();
            if (!$facultyName) continue;
            foreach ($userRepo->findAll() as $u) {
                if (!$u->isActive()) continue;
                if ($u->getFullName() === $facultyName) {
                    $empStatus = strtolower($u->getEmploymentStatus() ?? '');
                    if (str_contains($empStatus, 'dean')) {
                        $deans[] = $u;
                    } else {
                        $deptHeads[] = $u;
                    }
                    break;
                }
            }
        }

        // Superior's own evaluation submissions
        $myEvaluations = [];
        foreach ($allEvals as $eval) {
            $rankings = $superiorEvalRepo->getEvaluateeRankings($eval->getId());
            foreach ($rankings as $r) {
                $evaluatee = $userRepo->find($r['evaluateeId']);
                if ($evaluatee) {
                    $myEvaluations[] = [
                        'evaluation' => $eval,
                        'evaluatee' => $evaluatee,
                        'average' => round((float) $r['avgRating'], 2),
                        'evaluators' => (int) $r['evaluatorCount'],
                    ];
                }
            }
        }

        // Check which personnel the superior has already evaluated per open eval
        $evaluated = [];
        foreach ($openEvals as $eval) {
            foreach (array_merge($deans, $deptHeads) as $person) {
                if ($superiorEvalRepo->hasSubmitted($user->getId(), $eval->getId(), $person->getId())) {
                    $evaluated[$eval->getId() . '-' . $person->getId()] = true;
                }
            }
        }

        return $this->render('home/superior_dashboard.html.twig', [
            'openEvaluations' => $openEvals,
            'allEvals' => $allEvals,
            'departments' => $departments,
            'deans' => $deans,
            'deptHeads' => $deptHeads,
            'myEvaluations' => $myEvaluations,
            'evaluated' => $evaluated,
        ]);
    }

    private function buildAllDeptGroups(array $departments, ?string $semesterFilter, SubjectRepository $subjectRepo): array
    {
        $deptGroups = [];
        $allSemesters = [];
        foreach ($departments as $dept) {
            $allSubjects = $subjectRepo->findByDepartment($dept->getId());
            $subjects = [];
            foreach ($allSubjects as $s) {
                if ($s->getSemester()) {
                    $allSemesters[$s->getSemester()] = true;
                }
                if ($semesterFilter && $s->getSemester() !== $semesterFilter) {
                    continue;
                }
                $subjects[] = $s;
            }
            // Sort subjects by code
            usort($subjects, fn($a, $b) => strcmp($a->getSubjectCode(), $b->getSubjectCode()));
            $deptGroups[] = [
                'id' => $dept->getId(),
                'name' => $dept->getDepartmentName(),
                'subjects' => $subjects,
            ];
        }
        return ['groups' => $deptGroups, 'semesters' => array_keys($allSemesters)];
    }

    #[Route('/faculty/subjects', name: 'faculty_subjects')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultySubjects(
        Request $request,
        DepartmentRepository $deptRepo,
        SubjectRepository $subjectRepo,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
        EvaluationPeriodRepository $evalPeriodRepo,
        EvaluationResponseRepository $evalRespRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        /** @var User $user */
        $user = $this->getUser();
        $facultyDept = $user->getDepartment();
        $allDepartments = $deptRepo->findAllOrdered();
        $departments = $this->getFacultyScopedDepartments($user, $allDepartments);
        $semesterFilter = $request->query->get('semester');
        $currentAY = $ayRepo->findCurrent();

        // Default to the current academic year's semester if no filter selected
        if ($semesterFilter === null && $currentAY && $currentAY->getSemester()) {
            $semesterFilter = $currentAY->getSemester();
        }

        $data = $this->buildAllDeptGroups($departments, $semesterFilter, $subjectRepo);
        $allSemesters = $subjectRepo->findDistinctSemesters();
        $allYearLevels = $subjectRepo->findDistinctYearLevels();
        $allYearLevels = $subjectRepo->findDistinctYearLevels();
        $allYearLevels = $subjectRepo->findDistinctYearLevels();

        // Get loaded subject IDs from both Subject.faculty and FacultySubjectLoad table
        $loadedSubjects = $subjectRepo->findByFaculty($user->getId());
        $loadedIds = array_map(fn($s) => $s->getId(), $loadedSubjects);
        $fslEntries = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY?->getId());
        // Build per-faculty section/schedule map from FSL table
        $fslDataMap = [];
        foreach ($fslEntries as $fsl) {
            $sid = $fsl->getSubject()->getId();
            if (!isset($fslDataMap[$sid])) {
                $fslDataMap[$sid] = [];
            }
            $fslDataMap[$sid][] = [
                'id' => $fsl->getId(),
                'section' => $fsl->getSection(),
                'schedule' => $fsl->getSchedule(),
            ];
            if (!in_array($sid, $loadedIds)) {
                $loadedIds[] = $sid;
            }
        }

        // Build map of subjectId => faculty name for subjects loaded by other faculty
        $subjectFacultyMap = [];
        foreach ($data['groups'] as $group) {
            foreach ($group['subjects'] as $s) {
                $f = $s->getFaculty();
                if ($f && $f->getId() !== $user->getId()) {
                    $subjectFacultyMap[$s->getId()] = $f->getFirstName() . ' ' . $f->getLastName();
                }
            }
        }

        // Get active evaluations for SET
        $now = new \DateTime();
        $activeEvals = $evalPeriodRepo->findBy(['evaluationType' => 'SET', 'status' => true]);
        $activeEvalMap = [];
        foreach ($activeEvals as $eval) {
            $isActive = ($eval->getStartDate() <= $now && $eval->getEndDate() >= $now);
            if ($isActive) {
                $activeEvalMap[$eval->getId()] = [
                    'id' => $eval->getId(),
                    'name' => $eval->getLabel(),
                    'faculty' => $eval->getFaculty(),
                    'subject' => $eval->getSubject(),
                    'section' => $eval->getSection(),
                    'schoolYear' => $eval->getSchoolYear() ?? $eval->getLabel(),
                    'isActive' => true,
                    'startDate' => $eval->getStartDate(),
                    'endDate' => $eval->getEndDate(),
                ];
            }
        }

        // Keep departments collapsed on initial load; user expands manually.
        $selectedDepartment = null;
        $deptSubjects = [];

        return $this->render('home/faculty_subjects.html.twig', [
            'deptGroups' => $data['groups'],
            'semesters' => $allSemesters,
            'yearLevels' => $allYearLevels,
            'selectedSemester' => $semesterFilter,
            'selectedSubject' => null,
            'selectedDepartment' => $selectedDepartment,
            'deptSubjects' => $deptSubjects,
            'activeDeptId' => null,
            'facultyDept' => $facultyDept,
            'loadedSubjectIds' => $loadedIds,
            'loadedSubjects' => $loadedSubjects,
            'currentAY' => $currentAY,
            'departments' => $departments,
            'subjectFacultyMap' => $subjectFacultyMap,
            'fslDataMap' => $fslDataMap,
            'activeEvalMap' => $activeEvalMap,
        ]);
    }

    #[Route('/faculty/loaded-subjects', name: 'faculty_loaded_subjects', methods: ['GET'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyLoadedSubjects(
        SubjectRepository $subjectRepo,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        /** @var User $user */
        $user = $this->getUser();
        $currentAY = $ayRepo->findCurrent();
        $savedLoads = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY?->getId());

        // Build a flat list of loaded items from FSL entries (each section is a separate row)
        $loadedItems = [];
        $loadedSubjectIds = [];
        $totalUnits = 0;
        $countedSubjectIds = []; // track unique subjects for units calculation
        foreach ($savedLoads as $fsl) {
            $subject = $fsl->getSubject();
            $sid = $subject->getId();
            $loadedSubjectIds[] = $sid;
            $loadedItems[] = [
                'fslId' => $fsl->getId(),
                'subject' => $subject,
                'section' => $fsl->getSection(),
                'schedule' => $fsl->getSchedule(),
            ];
            if (!in_array($sid, $countedSubjectIds)) {
                $totalUnits += $subject->getUnits() ?? 0;
                $countedSubjectIds[] = $sid;
            }
        }

        // Also include direct Subject.faculty subjects without FSL entries
        $directLoaded = $subjectRepo->findByFaculty($user->getId());
        foreach ($directLoaded as $subject) {
            if (!in_array($subject->getId(), $loadedSubjectIds)) {
                $loadedItems[] = [
                    'fslId' => null,
                    'subject' => $subject,
                    'section' => $subject->getSection(),
                    'schedule' => $subject->getSchedule(),
                ];
                $totalUnits += $subject->getUnits() ?? 0;
                $loadedSubjectIds[] = $subject->getId();
            }
        }

        // Only show previous loads that are NOT currently loaded (current AY)
        $previousLoads = array_filter($savedLoads, fn($fsl) => !in_array($fsl->getSubject()->getId(), $loadedSubjectIds));

        // Past academic year loads grouped by AY
        $pastLoadsRaw = $fslRepo->findPastLoadsByFaculty($user->getId(), $currentAY?->getId());
        $pastLoadsByAY = [];
        foreach ($pastLoadsRaw as $fsl) {
            $ay = $fsl->getAcademicYear();
            $key = $ay->getId();
            if (!isset($pastLoadsByAY[$key])) {
                $label = $ay->getYearLabel();
                if ($ay->getSemester()) {
                    $label .= ' · ' . $ay->getSemester();
                }
                $pastLoadsByAY[$key] = [
                    'label' => $label,
                    'ayId' => $ay->getId(),
                    'loads' => [],
                ];
            }
            $pastLoadsByAY[$key]['loads'][] = $fsl;
        }

        // Determine if the current semester has ended
        $semesterEnded = false;
        if ($currentAY && $currentAY->getEndDate()) {
            $semesterEnded = new \DateTime() > $currentAY->getEndDate();
        }

        // Find active SET evaluations matching the faculty's subjects
        $openEvals = $evalRepo->findActive('SET');
        $facultyName = mb_strtolower(trim($user->getFirstName() . ' ' . $user->getLastName()));
        $facultyLastFirst = mb_strtolower(trim($user->getLastName() . ', ' . $user->getFirstName()));
        $facultyLastName = mb_strtolower(trim($user->getLastName()));

        $normalizeCode = static function (?string $value): string {
            $raw = mb_strtoupper(trim((string) $value));
            $normalized = preg_replace('/[^A-Z0-9]/u', '', $raw);
            return $normalized ?? '';
        };

        $normalizeSection = static function (?string $value): string {
            $raw = mb_strtoupper(trim((string) $value));
            $normalized = preg_replace('/\s+/u', ' ', $raw);
            return $normalized ?? '';
        };

        $subjectEvalMap = [];
        foreach ($openEvals as $eval) {
            $evalFaculty = mb_strtolower(trim((string) ($eval->getFaculty() ?? '')));
            if (
                $evalFaculty !== '' &&
                $evalFaculty !== $facultyName &&
                $evalFaculty !== $facultyLastFirst &&
                !str_contains($evalFaculty, $facultyLastName)
            ) {
                continue;
            }

            $evalSection = $normalizeSection($eval->getSection());
            $evalSubjectStr = trim((string) ($eval->getSubject() ?? ''));

            // Faculty-wide active evaluation (no specific subject) applies to all subjects.
            if ($evalSubjectStr === '') {
                $subjectEvalMap['*|' . $evalSection] = $eval;
                if (!isset($subjectEvalMap['*|'])) {
                    $subjectEvalMap['*|'] = $eval;
                }
                continue;
            }

            $parts = preg_split('/\s*[—-]\s*/u', $evalSubjectStr, 2);
            $code = $normalizeCode($parts[0] ?? $evalSubjectStr);
            if ($code === '') {
                continue;
            }

            // Key by normalized code+section for exact match, and code-only as fallback.
            $subjectEvalMap[$code . '|' . $evalSection] = $eval;
            if (!isset($subjectEvalMap[$code . '|'])) {
                $subjectEvalMap[$code . '|'] = $eval;
            }
        }

        // Attach evaluation to each loaded item (exact section match first, then fallback)
        foreach ($loadedItems as &$item) {
            $code = $normalizeCode($item['subject']->getSubjectCode());
            $section = $normalizeSection((string) ($item['section'] ?? ''));
            $item['evaluation'] = $subjectEvalMap[$code . '|' . $section]
                ?? $subjectEvalMap[$code . '|']
                ?? $subjectEvalMap['*|' . $section]
                ?? $subjectEvalMap['*|']
                ?? null;
        }
        unset($item);

        // Build active evaluations map for real-time polling (same as Class Schedule)
        $now = new \DateTime();
        $activeEvals = $evalRepo->findBy(['evaluationType' => 'SET', 'status' => true]);
        $activeEvalMap = [];
        foreach ($activeEvals as $eval) {
            $isActive = ($eval->getStartDate() <= $now && $eval->getEndDate() >= $now);
            if ($isActive) {
                $activeEvalMap[$eval->getId()] = [
                    'id' => $eval->getId(),
                    'name' => $eval->getLabel(),
                    'faculty' => $eval->getFaculty(),
                    'subject' => $eval->getSubject(),
                    'section' => $eval->getSection(),
                    'schoolYear' => $eval->getSchoolYear() ?? $eval->getLabel(),
                    'isActive' => true,
                    'startDate' => $eval->getStartDate(),
                    'endDate' => $eval->getEndDate(),
                ];
            }
        }

        // Build previous SET evaluation history for this faculty.
        $historyRows = $responseRepo->getEvaluatedSubjectsWithRating($user->getId());
        $historyPeriodIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['evaluationPeriodId'] ?? 0),
            $historyRows
        ))));

        $historyPeriodMap = [];
        if (!empty($historyPeriodIds)) {
            foreach ($evalRepo->findBy(['id' => $historyPeriodIds]) as $period) {
                $historyPeriodMap[$period->getId()] = $period;
            }
        }

        $previousEvaluations = [];
        foreach ($historyRows as $row) {
            $periodId = (int) ($row['evaluationPeriodId'] ?? 0);
            $period = $historyPeriodMap[$periodId] ?? null;

            // Keep only previous (ended) evaluations on this page.
            if ($period && $period->getEndDate() >= $now) {
                continue;
            }

            $subjectCode = trim((string) ($row['subjectCode'] ?? ''));
            $subjectName = trim((string) ($row['subjectName'] ?? ''));
            $sectionRaw = trim((string) ($row['section'] ?? ''));

            $previousEvaluations[] = [
                'periodId' => $periodId,
                'subjectId' => isset($row['subjectId']) ? (int) $row['subjectId'] : null,
                'subjectCode' => $subjectCode !== '' ? $subjectCode : 'N/A',
                'subjectName' => $subjectName !== '' ? $subjectName : 'Unknown Subject',
                'section' => $sectionRaw !== '' ? strtoupper($sectionRaw) : 'N/A',
                'semester' => $period?->getSemester() ?: 'N/A',
                'schoolYear' => $period?->getSchoolYear() ?: 'N/A',
                'evaluatorCount' => (int) ($row['evaluatorCount'] ?? 0),
                'avgRating' => round((float) ($row['avgRating'] ?? 0), 2),
                'periodEnd' => $period?->getEndDate()?->format('Y-m-d H:i:s') ?? '',
            ];
        }

        usort($previousEvaluations, static function (array $a, array $b): int {
            return strcmp((string) ($b['periodEnd'] ?? ''), (string) ($a['periodEnd'] ?? ''));
        });

        return $this->render('admin/loaded_subjects.html.twig', [
            'subjects' => $loadedItems,
            'totalUnits' => $totalUnits,
            'previousLoads' => array_values($previousLoads),
            'pastLoadsByAY' => array_values($pastLoadsByAY),
            'currentAY' => $currentAY,
            'semesterEnded' => $semesterEnded,
            'activeEvalMap' => $activeEvalMap,
            'previousEvaluations' => $previousEvaluations,
        ]);
    }

    #[Route('/faculty/loaded-subjects/restore', name: 'faculty_restore_load', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyRestoreLoad(
        Request $request,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
        SubjectRepository $subjectRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('restore_load', $token)) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('faculty_loaded_subjects');
        }

        // Determine which academic year to restore from
        $ayId = $request->request->get('ay_id');
        if ($ayId) {
            $targetAY = $ayRepo->find((int) $ayId);
            $savedLoads = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $targetAY?->getId());
        } else {
            $currentAY = $ayRepo->findCurrent();
            $savedLoads = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY?->getId());
        }

        $count = 0;
        foreach ($savedLoads as $fsl) {
            $subject = $fsl->getSubject();
            // Only restore if not already assigned to another faculty
            $currentFaculty = $subject->getFaculty();
            if ($currentFaculty === null || $currentFaculty->getId() === $user->getId()) {
                $subject->setFaculty($user);
                $subject->setSection($fsl->getSection());
                $subject->setSchedule($fsl->getSchedule());
                $count++;
            }
        }
        $em->flush();

        if ($count > 0) {
            $this->addFlash('success', $count . ' subject' . ($count !== 1 ? 's' : '') . ' restored from previous load.');
        } else {
            $this->addFlash('info', 'No subjects to restore.');
        }

        return $this->redirectToRoute('faculty_loaded_subjects');
    }

    #[Route('/faculty/subjects/unload-all', name: 'faculty_unload_all_subjects', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyUnloadAllSubjects(
        Request $request,
        SubjectRepository $subjectRepo,
        EntityManagerInterface $em,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
        EvaluationPeriodRepository $evalRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('unload_all_subjects', $token)) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('faculty_loaded_subjects');
        }

        // Unload subjects where this user is the direct owner
        $loadedSubjects = $subjectRepo->findByFaculty($user->getId());
        $blockedCodes = [];
        $unloadedCount = 0;
        foreach ($loadedSubjects as $subject) {
            if ($this->hasActiveSetEvaluationForLoad($user, $subject, $subject->getSection(), $evalRepo)) {
                $blockedCodes[] = $subject->getSubjectCode();
                continue;
            }
            $subject->setFaculty(null);
            $subject->setSchedule(null);
            $subject->setSection(null);
            $unloadedCount++;
        }

        // Also remove all from faculty_subject_load for current AY (includes shared subjects)
        $currentAY = $ayRepo->findCurrent();
        if ($currentAY) {
            $loads = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY->getId());
            foreach ($loads as $fsl) {
                if ($this->hasActiveSetEvaluationForLoad($user, $fsl->getSubject(), $fsl->getSection(), $evalRepo)) {
                    $blockedCodes[] = $fsl->getSubject()->getSubjectCode();
                    continue;
                }
                $em->remove($fsl);
                $unloadedCount++;
            }
        }

        $em->flush();

        if (!empty($blockedCodes)) {
            $blockedCodes = array_values(array_unique($blockedCodes));
            $this->addFlash('warning', 'Some subjects were not unloaded because evaluation is active: ' . implode(', ', $blockedCodes) . '.');
        }

        if ($unloadedCount > 0) {
            $this->addFlash('success', $unloadedCount . ' subject' . ($unloadedCount !== 1 ? 's' : '') . ' unloaded.');
        } else {
            $this->addFlash('info', 'No subjects were unloaded.');
        }
        return $this->redirectToRoute('faculty_loaded_subjects');
    }

    #[Route('/faculty/subjects/unload/{id}', name: 'faculty_subject_unload', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultySubjectUnload(
        int $id,
        Request $request,
        SubjectRepository $subjectRepo,
        EntityManagerInterface $em,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
        EvaluationPeriodRepository $evalRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('unload_subject_' . $id, $token)) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('faculty_loaded_subjects');
        }

        $subject = $subjectRepo->find($id);
        if (!$subject) {
            $this->addFlash('danger', 'Subject not found.');
            return $this->redirectToRoute('faculty_loaded_subjects');
        }

        // Check if user owns the subject directly or via FSL
        $currentAY = $ayRepo->findCurrent();
        $isDirectOwner = $subject->getFaculty() && $subject->getFaculty()->getId() === $user->getId();
        $fslEntries = $currentAY ? $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY->getId()) : [];
        $hasFslEntry = false;
        foreach ($fslEntries as $fsl) {
            if ($fsl->getSubject()->getId() === $subject->getId()) {
                $hasFslEntry = true;
                break;
            }
        }

        if (!$isDirectOwner && !$hasFslEntry) {
            $this->addFlash('danger', 'Subject not assigned to you.');
            return $this->redirectToRoute('faculty_loaded_subjects');
        }

        // Block unload while a matching active SET evaluation exists.
        $effectiveSection = $subject->getSection();
        if ($currentAY) {
            foreach ($fslEntries as $fsl) {
                if ($fsl->getSubject()->getId() === $subject->getId()) {
                    $effectiveSection = $fsl->getSection() ?? $effectiveSection;
                    break;
                }
            }
        }
        if ($this->hasActiveSetEvaluationForLoad($user, $subject, $effectiveSection, $evalRepo)) {
            $this->addFlash('danger', 'Cannot unload "' . $subject->getSubjectCode() . '" because evaluation is currently active.');
            return $this->redirectToRoute('faculty_loaded_subjects');
        }

        // Only clear Subject entity fields if this user is the direct owner
        if ($isDirectOwner) {
            $subject->setFaculty(null);
            $subject->setSchedule(null);
            $subject->setSection(null);
        }

        // Remove from faculty_subject_load table
        if ($currentAY) {
            $fslRepo->removeByFacultySubjectAndAcademicYear($user->getId(), $subject->getId(), $currentAY->getId());
        }

        $em->flush();

        $this->addFlash('success', 'Subject "' . $subject->getSubjectCode() . ' — ' . $subject->getSubjectName() . '" has been unloaded.');
        return $this->redirectToRoute('faculty_loaded_subjects');
    }

    #[Route('/faculty/subjects/publish', name: 'faculty_subjects_publish', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultySubjectsPublish(
        Request $request,
        SubjectRepository $subjectRepo,
        EntityManagerInterface $em,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('publish_subjects', $token)) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $subjectIds = $request->request->all('subject_ids');
        if (!is_array($subjectIds)) {
            $subjectIds = [];
        }

        $subjectIds = array_values(array_unique(array_filter(
            array_map('intval', $subjectIds),
            static fn(int $id): bool => $id > 0
        )));

        $currentAY = $ayRepo->findCurrent();
        $activeSemester = $this->normalizeAcademicSemester($currentAY?->getSemester());
        $allowedSubjectIds = [];
        $blockedSubjectCodes = [];

        foreach ($subjectIds as $sid) {
            $subject = $subjectRepo->find($sid);
            if (!$subject) {
                continue;
            }

            if (!$this->subjectAllowedForActiveAcademicSemester($subject, $activeSemester)) {
                $blockedSubjectCodes[] = $subject->getSubjectCode();
                continue;
            }

            $allowedSubjectIds[] = $sid;
        }

        // Unload subjects that were previously loaded but are now unchecked
        $currentlyLoaded = $subjectRepo->findByFaculty($user->getId());
        // Use requested IDs so invalid selections are not force-unloaded unexpectedly.
        $selectedIdMap = array_flip(array_map('strval', $subjectIds));
        $unloaded = 0;
        foreach ($currentlyLoaded as $loaded) {
            if (!isset($selectedIdMap[$loaded->getId()])) {
                // Only clear faculty if this user is the current owner
                if ($loaded->getFaculty() && $loaded->getFaculty()->getId() === $user->getId()) {
                    $loaded->setFaculty(null);
                }
                $unloaded++;
            }
        }

        // Get schedule and section data from form
        $schedules = $request->request->all('subject_schedules');
        $sections = $request->request->all('subject_sections');
        if (!is_array($schedules)) $schedules = [];
        if (!is_array($sections)) $sections = [];

        // Assign faculty to selected subjects
        // Allow multiple faculty to load the same subject
        $count = 0;
        foreach ($allowedSubjectIds as $sid) {
            $subject = $subjectRepo->find($sid);
            if ($subject) {
                $prevFaculty = $subject->getFaculty();
                $isOwner = !$prevFaculty || $prevFaculty->getId() === $user->getId();
                if ($isOwner) {
                    $subject->setFaculty($user);
                    // Only update subject entity section/schedule when this faculty owns it
                    if (isset($schedules[$sid])) {
                        $subject->setSchedule($schedules[$sid] ?: null);
                    }
                    if (isset($sections[$sid])) {
                        $subject->setSection($sections[$sid] ?: null);
                    }
                }
                $count++;
            }
        }
        $em->flush();

        // Persist load data to faculty_subject_load table
        // Each faculty gets their own section/schedule stored independently
        // Get existing FSL entries to preserve additional sections (picked via "Pick Again")
        $existingFslEntries = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY?->getId());

        // Build map: subjectId => [fsl entries] to identify extra sections
        $existingBySubject = [];
        foreach ($existingFslEntries as $fsl) {
            $sid = $fsl->getSubject()->getId();
            $existingBySubject[$sid][] = $fsl;
        }

        // For each selected subject: update the first (primary) FSL entry, keep additional ones
        // For unselected subjects: remove all FSL entries
        $selectedIdMap2 = array_flip(array_map('strval', $subjectIds));

        // Remove FSL entries for subjects that are no longer selected
        foreach ($existingBySubject as $sid => $fslList) {
            if (!isset($selectedIdMap2[$sid])) {
                // Subject was unchecked — remove all its FSL entries
                foreach ($fslList as $fsl) {
                    $em->remove($fsl);
                }
                unset($existingBySubject[$sid]);
            }
        }

        // Update or create the primary FSL entry for each selected subject
        foreach ($allowedSubjectIds as $sid) {
            $subject = $subjectRepo->find($sid);
            if (!$subject) continue;

            $newSection = isset($sections[$sid]) ? ($sections[$sid] ?: null) : null;
            $newSchedule = isset($schedules[$sid]) ? ($schedules[$sid] ?: null) : null;

            if (isset($existingBySubject[$sid]) && count($existingBySubject[$sid]) > 0) {
                // Update the first (primary) entry
                $primaryFsl = $existingBySubject[$sid][0];
                $primaryFsl->setSection($newSection);
                $primaryFsl->setSchedule($newSchedule);
                // Additional entries (index 1+) are preserved as-is
            } else {
                // No existing entry — create a new one
                $fsl = new FacultySubjectLoad();
                $fsl->setFaculty($user);
                $fsl->setSubject($subject);
                $fsl->setAcademicYear($currentAY);
                $fsl->setSection($newSection);
                $fsl->setSchedule($newSchedule);
                $em->persist($fsl);
            }
        }
        $em->flush();

        $messages = [];
        if ($count > 0) {
            $messages[] = $count . ' subject' . ($count !== 1 ? 's' : '') . ' loaded';
        }
        if ($unloaded > 0) {
            $messages[] = $unloaded . ' subject' . ($unloaded !== 1 ? 's' : '') . ' unloaded';
        }
        if (!empty($blockedSubjectCodes)) {
            $messages[] = count($blockedSubjectCodes) . ' blocked by active semester (' . implode(', ', array_values(array_unique($blockedSubjectCodes))) . ')';
        }
        $this->addFlash('success', $messages ? implode(', ', $messages) . '.' : 'No changes made.');
        return $this->redirectToRoute('faculty_subjects');
    }

    #[Route('/faculty/subjects/pick-again', name: 'faculty_subject_pick_again', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultySubjectPickAgain(
        Request $request,
        SubjectRepository $subjectRepo,
        EntityManagerInterface $em,
        AcademicYearRepository $ayRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('pick_again_subject', $token)) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $subjectId = (int) $request->request->get('subject_id');
        $section = trim($request->request->get('section', ''));
        $schedule = $request->request->get('schedule', '');

        if (!$subjectId || !$section) {
            $this->addFlash('danger', 'Subject and section are required.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $subject = $subjectRepo->find($subjectId);
        if (!$subject) {
            $this->addFlash('danger', 'Subject not found.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $currentAY = $ayRepo->findCurrent();
        $activeSemester = $this->normalizeAcademicSemester($currentAY?->getSemester());
        if (!$this->subjectAllowedForActiveAcademicSemester($subject, $activeSemester)) {
            $this->addFlash('danger', 'Cannot pick this subject because active academic year semester is ' . ($activeSemester ?? 'not set') . '.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $fsl = new FacultySubjectLoad();
        $fsl->setFaculty($user);
        $fsl->setSubject($subject);
        $fsl->setAcademicYear($currentAY);
        $fsl->setSection($section);
        $fsl->setSchedule($schedule ?: null);
        $em->persist($fsl);
        $em->flush();

        $this->addFlash('success', 'Subject "' . $subject->getSubjectCode() . ' — Section ' . strtoupper($section) . '" added to your load.');
        return $this->redirectToRoute('faculty_subjects');
    }

    #[Route('/faculty/subjects/unload-fsl/{id}', name: 'faculty_subject_unload_fsl', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultySubjectUnloadFsl(
        int $id,
        Request $request,
        FacultySubjectLoadRepository $fslRepo,
        EvaluationPeriodRepository $evalRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('unload_fsl_' . $id, $token)) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $fsl = $fslRepo->find($id);
        if (!$fsl || $fsl->getFaculty()->getId() !== $user->getId()) {
            $this->addFlash('danger', 'Load entry not found or not yours.');
            return $this->redirectToRoute('faculty_subjects');
        }

        if ($this->hasActiveSetEvaluationForLoad($user, $fsl->getSubject(), $fsl->getSection(), $evalRepo)) {
            $this->addFlash('danger', 'Cannot unload "' . $fsl->getSubject()->getSubjectCode() . ($fsl->getSection() ? ' — Section ' . $fsl->getSection() : '') . '" because evaluation is currently active.');
            return $this->redirectToRoute('faculty_loaded_subjects');
        }

        $code = $fsl->getSubject()->getSubjectCode();
        $sec = $fsl->getSection();
        $fslRepo->removeById($id);

        $this->addFlash('success', 'Subject "' . $code . ($sec ? ' — Section ' . $sec : '') . '" unloaded.');
        return $this->redirectToRoute('faculty_subjects');
    }

    #[Route('/faculty/subjects/create', name: 'faculty_subject_create', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultySubjectCreate(
        Request $request,
        EntityManagerInterface $em,
        DepartmentRepository $deptRepo,
        AcademicYearRepository $ayRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('faculty_create_subject', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $semesterInput = $request->request->get('semester');
        $activeSemester = $this->normalizeAcademicSemester($ayRepo->findCurrent()?->getSemester());
        $newSubjectSemester = $this->normalizeAcademicSemester(is_string($semesterInput) ? $semesterInput : null);
        if ($activeSemester !== null && $newSubjectSemester !== null && $newSubjectSemester !== $activeSemester) {
            $this->addFlash('danger', 'Cannot create/load subject with semester ' . $newSubjectSemester . ' while active academic year semester is ' . $activeSemester . '.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $subject = new Subject();
        $subject->setSubjectCode($request->request->get('subjectCode', ''));
        $subject->setSubjectName($request->request->get('subjectName', ''));
        $subject->setSemester($semesterInput);
        $subject->setSchoolYear($request->request->get('schoolYear'));
        $subject->setTerm($request->request->get('term'));
        $subject->setSection($request->request->get('section'));
        $subject->setRoom($request->request->get('room'));
        $subject->setSchedule($request->request->get('schedule'));
        $subject->setFaculty($user);

        $unitsVal = $request->request->get('units');
        if ($unitsVal !== null && $unitsVal !== '') {
            $subject->setUnits((int) $unitsVal);
        }

        $ylVal = $request->request->get('yearLevel');
        if ($ylVal) {
            $subject->setYearLevel($ylVal);
        }

        $deptId = $request->request->get('department');
        if ($deptId) {
            $subject->setDepartment($deptRepo->find($deptId));
        } elseif ($user->getDepartment()) {
            $subject->setDepartment($user->getDepartment());
        }

        $em->persist($subject);
        $em->flush();

        $this->addFlash('success', 'Subject "' . $subject->getSubjectCode() . '" created and added to your load.');
        return $this->redirectToRoute('faculty_subjects');
    }

    #[Route('/faculty/subjects/department/{deptId}', name: 'faculty_department_detail')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyDepartmentDetail(
        int $deptId,
        Request $request,
        DepartmentRepository $deptRepo,
        SubjectRepository $subjectRepo,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $department = $deptRepo->find($deptId);

        if (!$department) {
            $this->addFlash('danger', 'Department not found.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $facultyDept = $user->getDepartment();
        $allDepartments = $deptRepo->findAllOrdered();
        $departments = $this->getFacultyScopedDepartments($user, $allDepartments);
        $semesterFilter = $request->query->get('semester');

        if (!in_array($department, $departments, true)) {
            $this->addFlash('danger', 'Department not available for your college scope.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $data = $this->buildAllDeptGroups($departments, $semesterFilter, $subjectRepo);
        $allSemesters = $subjectRepo->findDistinctSemesters();

        // Get all subjects for this department
        $deptSubjects = $subjectRepo->findByDepartment($deptId);
        if ($semesterFilter) {
            $deptSubjects = array_filter($deptSubjects, fn($s) => $s->getSemester() === $semesterFilter);
            $deptSubjects = array_values($deptSubjects);
        }

        $loadedSubjects = $subjectRepo->findByFaculty($user->getId());
        $loadedIds = array_map(fn($s) => $s->getId(), $loadedSubjects);

        $currentAY = $ayRepo->findCurrent();
        $fslEntries = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY?->getId());
        $fslDataMap = [];
        foreach ($fslEntries as $fsl) {
            $sid = $fsl->getSubject()->getId();
            if (!isset($fslDataMap[$sid])) {
                $fslDataMap[$sid] = [];
            }
            $fslDataMap[$sid][] = [
                'id' => $fsl->getId(),
                'section' => $fsl->getSection(),
                'schedule' => $fsl->getSchedule(),
            ];
            if (!in_array($sid, $loadedIds)) {
                $loadedIds[] = $sid;
            }
        }

        $subjectFacultyMap = [];
        foreach ($data['groups'] as $group) {
            foreach ($group['subjects'] as $s) {
                $f = $s->getFaculty();
                if ($f && $f->getId() !== $user->getId()) {
                    $subjectFacultyMap[$s->getId()] = $f->getFirstName() . ' ' . $f->getLastName();
                }
            }
        }

        return $this->render('home/faculty_subjects.html.twig', [
            'deptGroups' => $data['groups'],
            'semesters' => $allSemesters,
            'yearLevels' => $allYearLevels,
            'selectedSemester' => $semesterFilter,
            'selectedSubject' => null,
            'selectedDepartment' => $department,
            'deptSubjects' => $deptSubjects,
            'activeDeptId' => $deptId,
            'facultyDept' => $facultyDept,
            'loadedSubjectIds' => $loadedIds,
            'loadedSubjects' => $loadedSubjects,
            'currentAY' => $currentAY,
            'subjectFacultyMap' => $subjectFacultyMap,
            'fslDataMap' => $fslDataMap,
        ]);
    }

    #[Route('/faculty/subjects/{id}', name: 'faculty_subject_detail')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultySubjectDetail(
        int $id,
        Request $request,
        DepartmentRepository $deptRepo,
        SubjectRepository $subjectRepo,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $subject = $subjectRepo->find($id);

        if (!$subject) {
            $this->addFlash('danger', 'Subject not found.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $facultyDept = $user->getDepartment();
        $allDepartments = $deptRepo->findAllOrdered();
        $departments = $this->getFacultyScopedDepartments($user, $allDepartments);
        $semesterFilter = $request->query->get('semester');

        $subjectDept = $subject->getDepartment();
        if ($subjectDept && !in_array($subjectDept, $departments, true)) {
            $this->addFlash('danger', 'Subject is outside your college scope.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $data = $this->buildAllDeptGroups($departments, $semesterFilter, $subjectRepo);
        $allSemesters = $subjectRepo->findDistinctSemesters();
        $allYearLevels = $subjectRepo->findDistinctYearLevels();
        $activeDeptId = $subject->getDepartment() ? $subject->getDepartment()->getId() : 0;
        $loadedSubjects = $subjectRepo->findByFaculty($user->getId());
        $loadedIds = array_map(fn($s) => $s->getId(), $loadedSubjects);

        $currentAY = $ayRepo->findCurrent();
        $fslEntries = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY?->getId());
        $fslDataMap = [];
        foreach ($fslEntries as $fsl) {
            $sid = $fsl->getSubject()->getId();
            if (!isset($fslDataMap[$sid])) {
                $fslDataMap[$sid] = [];
            }
            $fslDataMap[$sid][] = [
                'id' => $fsl->getId(),
                'section' => $fsl->getSection(),
                'schedule' => $fsl->getSchedule(),
            ];
            if (!in_array($sid, $loadedIds)) {
                $loadedIds[] = $sid;
            }
        }

        $subjectFacultyMap = [];
        foreach ($data['groups'] as $group) {
            foreach ($group['subjects'] as $s) {
                $f = $s->getFaculty();
                if ($f && $f->getId() !== $user->getId()) {
                    $subjectFacultyMap[$s->getId()] = $f->getFirstName() . ' ' . $f->getLastName();
                }
            }
        }

        return $this->render('home/faculty_subjects.html.twig', [
            'deptGroups' => $data['groups'],
            'semesters' => $allSemesters,
            'yearLevels' => $allYearLevels,
            'selectedSemester' => $semesterFilter,
            'selectedSubject' => $subject,
            'selectedDepartment' => null,
            'deptSubjects' => [],
            'activeDeptId' => $activeDeptId,
            'facultyDept' => $facultyDept,
            'loadedSubjectIds' => $loadedIds,
            'loadedSubjects' => $loadedSubjects,
            'currentAY' => $currentAY,
            'subjectFacultyMap' => $subjectFacultyMap,
            'fslDataMap' => $fslDataMap,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    //  Faculty Management Routes
    // ═══════════════════════════════════════════════════════════

    #[Route('/faculty/academic-years', name: 'faculty_academic_years')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyAcademicYears(
        AcademicYearRepository $repo,
        SubjectRepository $subjectRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $currentAY = $repo->findCurrent();
        $loadedSubjects = $subjectRepo->findByFaculty($user->getId());

        // Group loaded subjects by semester
        $bySemester = [];
        foreach ($loadedSubjects as $s) {
            $sem = $s->getSemester() ?: 'Unassigned';
            $bySemester[$sem][] = $s;
        }

        return $this->render('home/faculty/academic_year.html.twig', [
            'academicYears' => $repo->findAllOrdered(),
            'currentAY' => $currentAY,
            'loadedSubjects' => $loadedSubjects,
            'bySemester' => $bySemester,
        ]);
    }

    #[Route('/faculty/department-subjects', name: 'faculty_department_subjects')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyDepartmentSubjects(
        Request $request,
        SubjectRepository $subjectRepo,
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        /** @var User $user */
        $user = $this->getUser();
        $facultyDept = $user->getDepartment();
        $allDepartments = $deptRepo->findAllOrdered();
        $departments = $this->getFacultyScopedDepartments($user, $allDepartments);
        $allowedDeptIds = array_map(fn($d) => $d->getId(), $departments);

        $filterCollege = $request->query->get('college');
        $filterDept = $request->query->get('department');

        $filterSemester = $request->query->get('semester');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 50;

        $qb = $subjectRepo->createQueryBuilder('s')
            ->leftJoin('s.department', 'd')
            ->orderBy('s.subjectCode', 'ASC');

        // Faculty can browse departments within the same college scope.
        if (!empty($allowedDeptIds)) {
            $qb->andWhere('d.id IN (:allowedDeptIds)')->setParameter('allowedDeptIds', $allowedDeptIds);
        }

        if ($filterCollege) {
            $qb->andWhere('d.collegeName = :college')->setParameter('college', $filterCollege);
        }

        if ($filterDept !== null && $filterDept !== '') {
            $filterDeptId = (int) $filterDept;
            if (in_array($filterDeptId, $allowedDeptIds, true)) {
                $qb->andWhere('d.id = :deptId')->setParameter('deptId', $filterDeptId);
            }
        }

        if ($filterSemester) {
            $qb->andWhere('s.semester = :sem')->setParameter('sem', $filterSemester);
        }

        $totalFiltered = (int) (clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($totalFiltered / $limit));
        if ($page > $totalPages) { $page = $totalPages; }

        $subjects = $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $colleges = [];
        foreach ($departments as $d) {
            $cn = $d->getCollegeName();
            if ($cn && !in_array($cn, $colleges, true)) {
                $colleges[] = $cn;
            }
        }
        sort($colleges);

        $facultyList = $userRepo->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_FACULTY%')
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()->getResult();

        return $this->render('admin/subjects.html.twig', [
            'subjects'        => $subjects,
            'faculty'         => $facultyList,
            'departments'     => $departments,
            'colleges'        => $colleges,
            'filterFaculty'   => null,
            'filterDept'      => ($filterDept !== null && $filterDept !== '') ? (int) $filterDept : null,
            'filterSemester'  => $filterSemester,
            'filterTerm'      => null,
            'filterCollege'   => $filterCollege,
            'currentPage'     => $page,
            'totalPages'      => $totalPages,
            'totalFiltered'   => $totalFiltered,
            'limit'           => $limit,
            'readOnly'        => true,
        ]);
    }

    #[Route('/faculty/schedule', name: 'faculty_schedule')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultySchedule(
        SubjectRepository $subjectRepo,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
        EvaluationPeriodRepository $evalRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $currentAY = $ayRepo->findCurrent();
        $savedLoads = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY?->getId());

        // Build schedule items from FSL entries (each section as a separate row)
        $scheduleItems = [];
        $loadedSubjectIds = [];
        foreach ($savedLoads as $fsl) {
            $subject = $fsl->getSubject();
            $loadedSubjectIds[] = $subject->getId();
            $scheduleItems[] = [
                'subject' => $subject,
                'section' => $fsl->getSection(),
                'schedule' => $fsl->getSchedule(),
            ];
        }

        // Include direct Subject.faculty subjects without FSL entries
        $directLoaded = $subjectRepo->findByFaculty($user->getId());
        foreach ($directLoaded as $subject) {
            if (!in_array($subject->getId(), $loadedSubjectIds)) {
                $scheduleItems[] = [
                    'subject' => $subject,
                    'section' => $subject->getSection(),
                    'schedule' => $subject->getSchedule(),
                ];
            }
        }

        // Find active SET evaluations
        $openEvals = $evalRepo->findActive('SET');
        $now = new \DateTime();
        $activeEvals = [];

        foreach ($openEvals as $eval) {
            // Check if evaluation is currently active (within start and end dates)
            $isActive = ($eval->getStartDate() <= $now && $eval->getEndDate() >= $now);
            if ($isActive) {
                $activeEvals[] = $eval;
            }
        }

        // Build active evaluations map for real-time polling
        $activeEvalMap = [];
        foreach ($activeEvals as $eval) {
            $activeEvalMap[$eval->getId()] = [
                'id' => $eval->getId(),
                'name' => $eval->getLabel(),
                'faculty' => $eval->getFaculty(),
                'subject' => $eval->getSubject(),
                    'section' => $eval->getSection(),
                'schoolYear' => $eval->getSchoolYear() ?? $eval->getLabel(),
                'isActive' => true,
                'startDate' => $eval->getStartDate(),
                'endDate' => $eval->getEndDate(),
            ];
        }

        // Attach evaluation to each schedule item
        foreach ($scheduleItems as &$item) {
            $item['evaluation'] = null;

            // For each active evaluation, check if it applies to this subject
            foreach ($activeEvals as $eval) {
                $evalMatch = false;

                // Get evaluation's faculty field
                $evalFaculty = trim($eval->getFaculty() ?? '');
                $userFullName = $user->getFirstName() . ' ' . $user->getLastName();
                $userLastFirst = $user->getLastName() . ', ' . $user->getFirstName();

                // Check if evaluation is for the current faculty member
                if (!empty($evalFaculty)) {
                    // Case-insensitive comparison, trim spaces
                    $evalFacultyLower = strtolower($evalFaculty);
                    $userNameLower = strtolower($userFullName);
                    $userNameLastFirstLower = strtolower($userLastFirst);

                    if ($evalFacultyLower === $userNameLower ||
                        $evalFacultyLower === $userNameLastFirstLower ||
                        stripos($evalFacultyLower, strtolower($user->getLastName())) !== false) {
                        $evalMatch = true;
                    }
                }

                // If evaluation has specific subject, also check subject match
                $evalSubject = trim($eval->getSubject() ?? '');
                if (!empty($evalSubject) && $evalMatch) {
                    // Only show if both faculty and subject match
                    $evalMatch = (stripos($item['subject']->getSubjectCode(), $evalSubject) === 0 ||
                                  stripos($item['subject']->getSubjectName(), $evalSubject) !== false);
                } elseif (!empty($evalSubject) && empty($evalFaculty)) {
                    // If only subject is specified (no faculty), match by subject
                    $evalMatch = (stripos($item['subject']->getSubjectCode(), $evalSubject) === 0 ||
                                  stripos($item['subject']->getSubjectName(), $evalSubject) !== false);
                } elseif (empty($evalSubject) && empty($evalFaculty)) {
                    // If neither subject nor faculty is specified, show for all
                    $evalMatch = true;
                }

                if ($evalMatch) {
                    $item['evaluation'] = [
                        'id' => $eval->getId(),
                        'faculty' => $eval->getFaculty() ?? '',
                        'schoolYear' => $eval->getLabel() ?? '',
                    ];
                    break; // Use first matching evaluation
                }
            }
        }
        unset($item);

        return $this->render('home/faculty/schedule.html.twig', [
            'subjects' => $scheduleItems,
            'activeEvalMap' => $activeEvalMap,
        ]);
    }


    #[Route('/faculty/evaluation/request', name: 'faculty_eval_request', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEvalRequest(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        EvaluationMessageRepository $msgRepo,
        MessageNotificationRepository $notifRepo,
        UserRepository $userRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Handle message submission
        if ($request->isMethod('POST') && $request->request->get('_action') === 'send_message') {
            $body = trim($request->request->get('msg_body', ''));
            $subject = 'Faculty Message';

            if ($body) {
                $msg = new EvaluationMessage();
                $msg->setSender($user);
                $msg->setSubject($subject);
                $msg->setMessage($body);
                $msg->setSenderType('faculty');

                $em->persist($msg);
                $em->flush();

                // Notify all admins and staff
                $adminsAndStaff = $userRepo->findAdminsAndStaff();
                foreach ($adminsAndStaff as $recipient) {
                    $notif = new MessageNotification();
                    $notif->setNotifiedUser($recipient);
                    $notif->setMessage($msg);
                    $em->persist($notif);
                }
                $em->flush();

                $this->addFlash('success', 'Your message has been sent to the administrator.');
            } else {
                $this->addFlash('error', 'Please enter your message.');
            }

            return $this->redirectToRoute('faculty_eval_request');
        }

        // Handle reply to conversation
        if ($request->isMethod('POST') && $request->request->get('_action') === 'reply_message') {
            $parentId = $request->request->get('parent_message_id');
            $body = trim($request->request->get('msg_body', ''));

            $parentMsg = $msgRepo->find($parentId);
            if ($parentMsg && $parentMsg->getSender() === $user && $body) {
                $reply = new EvaluationMessage();
                $reply->setSender($user);
                $reply->setSubject('Re: ' . $parentMsg->getSubject());
                $reply->setMessage($body);
                $reply->setSenderType('faculty');
                $reply->setParentMessage($parentMsg);
                $reply->setCreatedAt(new \DateTime());

                $em->persist($reply);
                $em->flush();

                // Notify all admins and staff
                $adminsAndStaff = $userRepo->findAdminsAndStaff();
                foreach ($adminsAndStaff as $recipient) {
                    $notif = new MessageNotification();
                    $notif->setNotifiedUser($recipient);
                    $notif->setMessage($reply);
                    $em->persist($notif);
                }
                $em->flush();

                $this->addFlash('success', 'Your reply has been sent.');
            } else {
                $this->addFlash('error', 'Unable to send reply.');
            }

            return $this->redirectToRoute('faculty_eval_request');
        }

        // Handle message deletion
        if ($request->isMethod('POST') && $request->request->get('_action') === 'delete_message') {
            $msgId = $request->request->get('msg_id');
            $token = $request->request->get('_token');

            if ($this->isCsrfTokenValid('delete_msg' . $msgId, $token)) {
                $msg = $msgRepo->find($msgId);
                if ($msg && $msg->getSender() === $user) {
                    $em->remove($msg);
                    $em->flush();
                    $this->addFlash('success', 'Message deleted successfully.');
                } else {
                    $this->addFlash('error', 'Message not found or access denied.');
                }
            } else {
                $this->addFlash('error', 'Invalid security token.');
            }

            return $this->redirectToRoute('faculty_eval_request');
        }

        // Handle bulk delete
        if ($request->isMethod('POST') && $request->request->get('_action') === 'bulk_delete') {
            $token = $request->request->get('_token');

            if ($this->isCsrfTokenValid('bulk_delete_msgs', $token)) {
                $msgIds = $request->request->all('msg_ids');
                $deleted = 0;
                foreach ($msgIds as $msgId) {
                    $msg = $msgRepo->find($msgId);
                    if ($msg && $msg->getSender() === $user) {
                        $em->remove($msg);
                        $deleted++;
                    }
                }
                if ($deleted > 0) {
                    $em->flush();
                    $this->addFlash('success', $deleted . ' message' . ($deleted > 1 ? 's' : '') . ' deleted successfully.');
                } else {
                    $this->addFlash('error', 'No messages were deleted.');
                }
            } else {
                $this->addFlash('error', 'Invalid security token.');
            }

            return $this->redirectToRoute('faculty_eval_request');
        }

        // Handle clear all messages
        if ($request->isMethod('POST') && $request->request->get('_action') === 'clear_all') {
            $token = $request->request->get('_token');

            if ($this->isCsrfTokenValid('clear_all_msgs', $token)) {
                $myMessages = $msgRepo->findBySender($user->getId());
                $count = count($myMessages);
                foreach ($myMessages as $msg) {
                    $em->remove($msg);
                }
                if ($count > 0) {
                    $em->flush();
                    $this->addFlash('success', 'All ' . $count . ' message' . ($count > 1 ? 's' : '') . ' cleared.');
                } else {
                    $this->addFlash('error', 'No messages to clear.');
                }
            } else {
                $this->addFlash('error', 'Invalid security token.');
            }

            return $this->redirectToRoute('faculty_eval_request');
        }

        $deptId = $user->getDepartment() ? $user->getDepartment()->getId() : null;
        $evaluations = $evalRepo->findForFaculty($deptId, $user->getFullName());
        $periods = [];
        foreach ($evaluations as $eval) {
            $count = $responseRepo->countEvaluators($user->getId(), $eval->getId());
            if ($count > 0) {
                $periods[] = [
                    'evaluation' => $eval,
                    'evaluators' => $count,
                ];
            }
        }

        // Viewing the chat page means the faculty has seen message notifications.
        try {
            $notifRepo->markAllAsReadForUser($user->getId());
        } catch (\Throwable) {
            // Do not block chat rendering if notification cleanup fails.
        }

        $myMessages = $msgRepo->findBySenderRecentActivity($user->getId());
        $repliesMap = [];
        foreach ($myMessages as $msg) {
            $repliesMap[$msg->getId()] = $msgRepo->findRepliesForMessage($msg->getId());
        }

        return $this->render('home/faculty/eval_request.html.twig', [
            'periods' => $periods,
            'messages' => $myMessages,
            'repliesMap' => $repliesMap,
            'evaluations' => $evaluations,
        ]);
    }

    #[Route('/faculty/evaluation/results', name: 'faculty_eval_results')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEvalResults(
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $evaluatedSubjects = $responseRepo->getEvaluatedSubjects($user->getId());
        $openEvalIdMap = [];
        foreach ($evalRepo->findOpen() as $openEval) {
            $openEvalIdMap[$openEval->getId()] = true;
        }

        // Group by evaluation period and attach period entity
        $periods = [];
        foreach ($evaluatedSubjects as $row) {
            $epId = (int) $row['evaluationPeriodId'];
            if (!isset($openEvalIdMap[$epId])) {
                continue;
            }

            $subjectName = mb_strtolower(trim((string) ($row['subjectName'] ?? '')));
            $isTargetFaculty = mb_strtolower(trim((string) $user->getFullName())) === 'ryan escorial';
            if ($isTargetFaculty && $subjectName === 'capstone project 2') {
                continue;
            }

            if (!isset($periods[$epId])) {
                $evaluation = $evalRepo->find($epId);
                if (!$evaluation) {
                    continue;
                }
                $periods[$epId] = [
                    'evaluation' => $evaluation,
                    'subjects' => [],
                ];
            }
            $periods[$epId]['subjects'][] = $row;
        }

        return $this->render('home/faculty/eval_results.html.twig', [
            'periods' => $periods,
        ]);
    }

    #[Route('/faculty/audit-log', name: 'faculty_audit_log')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyAuditLog(
        AuditLogRepository $auditRepo,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Get logs performed by this faculty only
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        // Get logs where the faculty is the performer
        $qb = $auditRepo->createQueryBuilder('a')
            ->where('a.performedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'DESC');

        $totalLogs = (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $logs = $qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        $totalPages = (int) ceil($totalLogs / $limit);

        return $this->render('home/faculty/audit_log.html.twig', [
            'logs' => $logs,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalLogs' => $totalLogs,
        ]);
    }

    #[Route('/staff/audit-log', name: 'staff_audit_log')]
    #[IsGranted('ROLE_STAFF')]
    public function staffAuditLog(
        AuditLogRepository $auditRepo,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Get logs performed by this staff only
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        // Get logs where the staff is the performer
        $qb = $auditRepo->createQueryBuilder('a')
            ->where('a.performedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'DESC');

        $totalLogs = (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $logs = $qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        $totalPages = (int) ceil($totalLogs / $limit);

        return $this->render('home/staff/audit_log.html.twig', [
            'logs' => $logs,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalLogs' => $totalLogs,
        ]);
    }

    /**
     * Normalize year-level strings so "4th Year" matches "Fourth Year", etc.
     */
    private function normalizeYearLevel(?string $yl): ?string
    {
        if ($yl === null) return null;
        $map = [
            '1st year' => 'First Year',  'first year' => 'First Year',
            '2nd year' => 'Second Year', 'second year' => 'Second Year',
            '3rd year' => 'Third Year',  'third year' => 'Third Year',
            '4th year' => 'Fourth Year', 'fourth year' => 'Fourth Year',
        ];
        return $map[strtolower(trim($yl))] ?? $yl;
    }

    private function studentDashboard(
        $user,
        CurriculumRepository $curriculumRepo,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
    ): Response {
        $openEvals = $evalRepo->findOpen();

        $pending = [];
        $completed = [];
        $studentYearLevel = $this->normalizeYearLevel($user->getYearLevel());

        foreach ($openEvals as $eval) {
            if ($eval->getEvaluationType() !== 'SET') {
                continue; // Students only submit SET
            }

            // ── Targeting filters: department, college, year level ──
            if ($eval->getYearLevel() !== null) {
                $evalYL = $this->normalizeYearLevel($eval->getYearLevel());
                if ($evalYL !== $studentYearLevel) continue;
            }

            if ($eval->getDepartment() !== null && (
                $user->getDepartment() === null || $eval->getDepartment()->getId() !== $user->getDepartment()->getId()
            )) {
                continue;
            }

            if ($eval->getCollege() !== null && (
                $user->getDepartment() === null ||
                $user->getDepartment()->getCollegeName() !== $eval->getCollege()
            )) {
                continue;
            }

            // ── Get faculty and subject from evaluation ──
            $faculty = null;
            $subject = null;
            $subjectLabel = $eval->getSubject() ?? '';

            if ($eval->getFaculty()) {
                $faculty = $userRepo->findOneByFullName($eval->getFaculty());
            }

            if (!$faculty || !$subjectLabel) {
                continue;
            }

            $submitted = $responseRepo->hasSubmitted(
                $user->getId(),
                $eval->getId(),
                $faculty->getId(),
                0, // No subject ID needed now
            );

            $item = [
                'evaluation' => $eval,
                'subject' => $subjectLabel,
                'faculty' => $faculty,
            ];

            if ($submitted) {
                $completed[] = $item;
            } else {
                $pending[] = $item;
            }
        }

        return $this->render('home/student_dashboard.html.twig', [
            'subjects' => $enrolledSubjects ?? [],
            'pending' => $pending,
            'completed' => $completed,
        ]);
    }

    #[Route('/faculty/notification/read/{id}', name: 'faculty_notification_read', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function markNotificationRead(
        int $id,
        EvaluationPeriodRepository $evalRepo,
        FacultyNotificationReadRepository $readRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $eval = $evalRepo->find($id);
        if (!$eval) {
            return $this->json(['ok' => false], 404);
        }

        $existing = $readRepo->findOneBy(['user' => $user, 'evaluationPeriod' => $eval]);
        if (!$existing) {
            $nr = new FacultyNotificationRead();
            $nr->setUser($user);
            $nr->setEvaluationPeriod($eval);
            $em->persist($nr);
            $em->flush();
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/faculty/notification/read-all', name: 'faculty_notification_read_all', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function markAllNotificationsRead(
        EvaluationPeriodRepository $evalRepo,
        FacultyNotificationReadRepository $readRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $facultyName = $user->getFullName();
        $allOpen = $evalRepo->findOpen();
        $readIds = $readRepo->findReadEvaluationIds($user->getId());

        foreach ($allOpen as $eval) {
            if ($eval->getEvaluationType() === 'SUPERIOR') continue;
            if ($eval->getFaculty() !== $facultyName) continue;
            if (in_array($eval->getId(), $readIds)) continue;

            $nr = new FacultyNotificationRead();
            $nr->setUser($user);
            $nr->setEvaluationPeriod($eval);
            $em->persist($nr);
        }
        $em->flush();

        return $this->json(['ok' => true]);
    }
}
