<?php

namespace App\Controller;

use App\Entity\Enrollment;
use App\Entity\EvaluationMessage;
use App\Entity\FacultyNotificationRead;
use App\Entity\FacultySubjectLoad;
use App\Entity\Subject;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\AuditLogRepository;
use App\Repository\EvaluationMessageRepository;
use App\Repository\CurriculumRepository;
use App\Repository\FacultyNotificationReadRepository;
use App\Repository\FacultySubjectLoadRepository;
use App\Repository\DepartmentRepository;
use App\Repository\EnrollmentRepository;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\EvaluationResponseRepository;
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
        QuestionRepository $questionRepo,
        DepartmentRepository $deptRepo,
        AuditLogRepository $auditRepo,
        CurriculumRepository $curriculumRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        EnrollmentRepository $enrollRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->adminDashboard($userRepo, $evalRepo, $responseRepo, $deptRepo, $subjectRepo, $questionRepo, $auditRepo, $curriculumRepo);
        }

        if ($this->isGranted('ROLE_SUPERIOR')) {
            return $this->superiorDashboard($user, $evalRepo, $responseRepo, $userRepo, $deptRepo, $superiorEvalRepo);
        }

        if ($this->isGranted('ROLE_STAFF')) {
            return $this->staffDashboard($evalRepo, $responseRepo, $userRepo, $deptRepo, $subjectRepo);
        }

        if ($this->isGranted('ROLE_FACULTY')) {
            return $this->facultyDashboard($user, $subjectRepo, $evalRepo, $responseRepo);
        }

        // Student (ROLE_USER)
        return $this->studentDashboard($user, $curriculumRepo, $evalRepo, $responseRepo, $userRepo, $enrollRepo);
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

        // ── Total responses count ──
        $totalResponses = (int) $responseRepo->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.isDraft = false')
            ->getQuery()->getSingleScalarResult();

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

        $completedEvals = [];
        foreach ($submissions as $sub) {
            $ep   = $epMap[$sub['epId']] ?? null;
            $fac  = $uMap[$sub['facultyId']] ?? null;
            $subj = isset($sub['subjectId']) ? ($sMap[$sub['subjectId']] ?? null) : null;

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
        $user,
        SubjectRepository $subjectRepo,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
    ): Response {
        $subjects = $subjectRepo->findByFaculty($user->getId());
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
        ]);
    }

    #[Route('/faculty/loaded-subjects', name: 'faculty_loaded_subjects', methods: ['GET'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyLoadedSubjects(
        SubjectRepository $subjectRepo,
        AcademicYearRepository $ayRepo,
        FacultySubjectLoadRepository $fslRepo,
        EvaluationPeriodRepository $evalRepo,
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
        $facultyName = mb_strtolower(trim($user->getFullName()));
        $subjectEvalMap = [];
        foreach ($openEvals as $eval) {
            $evalFaculty = $eval->getFaculty();
            if ($evalFaculty && mb_strtolower(trim($evalFaculty)) === $facultyName) {
                $evalSubjectStr = $eval->getSubject();
                if ($evalSubjectStr) {
                    $parts = explode(' — ', $evalSubjectStr, 2);
                    $code = strtoupper(trim($parts[0]));
                    $section = strtoupper(trim($eval->getSection() ?? ''));
                    // Key by code+section for exact match, and code-only as fallback
                    $subjectEvalMap[$code . '|' . $section] = $eval;
                    if (!isset($subjectEvalMap[$code . '|'])) {
                        $subjectEvalMap[$code . '|'] = $eval;
                    }
                }
            }
        }

        // Attach evaluation to each loaded item (exact section match first, then fallback)
        foreach ($loadedItems as &$item) {
            $code = strtoupper(trim($item['subject']->getSubjectCode()));
            $section = strtoupper(trim($item['section'] ?? ''));
            $item['evaluation'] = $subjectEvalMap[$code . '|' . $section]
                ?? $subjectEvalMap[$code . '|']
                ?? null;
        }
        unset($item);

        return $this->render('admin/loaded_subjects.html.twig', [
            'subjects' => $loadedItems,
            'totalUnits' => $totalUnits,
            'previousLoads' => array_values($previousLoads),
            'pastLoadsByAY' => array_values($pastLoadsByAY),
            'currentAY' => $currentAY,
            'semesterEnded' => $semesterEnded,
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
        foreach ($loadedSubjects as $subject) {
            $subject->setFaculty(null);
            $subject->setSchedule(null);
            $subject->setSection(null);
        }

        // Also remove all from faculty_subject_load for current AY (includes shared subjects)
        $currentAY = $ayRepo->findCurrent();
        $count = count($loadedSubjects);
        if ($currentAY) {
            $loads = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY->getId());
            $count = max($count, count($loads));
            foreach ($loads as $fsl) {
                $em->remove($fsl);
            }
        }

        $em->flush();

        $this->addFlash('success', $count . ' subject' . ($count !== 1 ? 's' : '') . ' unloaded.');
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

        // Unload subjects that were previously loaded but are now unchecked
        $currentlyLoaded = $subjectRepo->findByFaculty($user->getId());
        $selectedIdMap = array_flip($subjectIds);
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
        foreach ($subjectIds as $sid) {
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
        $currentAY = $ayRepo->findCurrent();

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
        $selectedIdMap2 = array_flip($subjectIds);

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
        foreach ($subjectIds as $sid) {
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
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('faculty_create_subject', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('faculty_subjects');
        }

        $subject = new Subject();
        $subject->setSubjectCode($request->request->get('subjectCode', ''));
        $subject->setSubjectName($request->request->get('subjectName', ''));
        $subject->setSemester($request->request->get('semester'));
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
            $fslDataMap[$sid] = [
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
            'selectedSemester' => $semesterFilter,
            'selectedSubject' => null,
            'selectedDepartment' => $department,
            'deptSubjects' => $deptSubjects,
            'activeDeptId' => $deptId,
            'facultyDept' => $facultyDept,
            'loadedSubjectIds' => $loadedIds,
            'loadedSubjects' => $loadedSubjects,
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
        $activeDeptId = $subject->getDepartment() ? $subject->getDepartment()->getId() : 0;
        $loadedSubjects = $subjectRepo->findByFaculty($user->getId());
        $loadedIds = array_map(fn($s) => $s->getId(), $loadedSubjects);

        $currentAY = $ayRepo->findCurrent();
        $fslEntries = $fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY?->getId());
        $fslDataMap = [];
        foreach ($fslEntries as $fsl) {
            $sid = $fsl->getSubject()->getId();
            $fslDataMap[$sid] = [
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
            'selectedSemester' => $semesterFilter,
            'selectedSubject' => $subject,
            'selectedDepartment' => null,
            'deptSubjects' => [],
            'activeDeptId' => $activeDeptId,
            'facultyDept' => $facultyDept,
            'loadedSubjectIds' => $loadedIds,
            'loadedSubjects' => $loadedSubjects,
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

        // Find active SET evaluations matching the faculty's subjects
        $openEvals = $evalRepo->findActive('SET');
        $facultyName = mb_strtolower(trim($user->getFullName()));
        $subjectEvalMap = [];
        foreach ($openEvals as $eval) {
            $evalFaculty = $eval->getFaculty();
            if ($evalFaculty && mb_strtolower(trim($evalFaculty)) === $facultyName) {
                $evalSubjectStr = $eval->getSubject();
                if ($evalSubjectStr) {
                    $parts = explode(' — ', $evalSubjectStr, 2);
                    $code = strtoupper(trim($parts[0]));
                    $subjectEvalMap[$code] = $eval;
                }
            }
        }

        // Attach evaluation to each schedule item
        foreach ($scheduleItems as &$item) {
            $code = strtoupper(trim($item['subject']->getSubjectCode()));
            $item['evaluation'] = $subjectEvalMap[$code] ?? null;
        }
        unset($item);

        return $this->render('home/faculty/schedule.html.twig', [
            'subjects' => $scheduleItems,
        ]);
    }

    #[Route('/faculty/enrollment-management', name: 'faculty_enrollment_management')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEnrollmentManagement(EnrollmentRepository $enrollRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $enrollments = $enrollRepo->createQueryBuilder('e')
            ->join('e.subject', 's')
            ->join('e.student', 'st')
            ->leftJoin('st.department', 'd')
            ->addSelect('s', 'st', 'd')
            ->where('s.faculty = :fid')
            ->setParameter('fid', $user->getId())
            ->orderBy('s.subjectCode', 'ASC')
            ->addOrderBy('st.lastName', 'ASC')
            ->addOrderBy('st.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        $subjectCounts = [];
        foreach ($enrollments as $enrollment) {
            $subject = $enrollment->getSubject();
            $sid = $subject->getId();
            if (!isset($subjectCounts[$sid])) {
                $subjectCounts[$sid] = [
                    'subject' => $subject,
                    'count' => 0,
                ];
            }
            $subjectCounts[$sid]['count']++;
        }

        $pendingCount = 0;
        foreach ($enrollments as $enrollment) {
            if ($enrollment->isPending()) {
                $pendingCount++;
            }
        }

        // Build section grouping per subject for sectioning management
        $sectionData = [];
        foreach ($enrollments as $enrollment) {
            $subject = $enrollment->getSubject();
            $sid = $subject->getId();
            $section = $enrollment->getSection() ?: $subject->getSection() ?: 'Unassigned';
            if (!isset($sectionData[$sid])) {
                $sectionData[$sid] = [
                    'subject' => $subject,
                    'sections' => [],
                ];
            }
            if (!isset($sectionData[$sid]['sections'][$section])) {
                $sectionData[$sid]['sections'][$section] = [];
            }
            $sectionData[$sid]['sections'][$section][] = $enrollment;
        }
        // Sort sections within each subject
        foreach ($sectionData as &$sd) {
            ksort($sd['sections']);
        }
        unset($sd);

        return $this->render('home/faculty/enrollment_management.html.twig', [
            'enrollments' => $enrollments,
            'subjectCounts' => array_values($subjectCounts),
            'pendingCount' => $pendingCount,
            'sectionData' => array_values($sectionData),
        ]);
    }

    #[Route('/student/enrolled-subjects', name: 'student_enrolled_subjects')]
    #[IsGranted('ROLE_USER')]
    public function studentEnrolledSubjects(EnrollmentRepository $enrollRepo, SubjectRepository $subjectRepo, AcademicYearRepository $ayRepo, DepartmentRepository $deptRepo): Response
    {
        if ($this->isGranted('ROLE_FACULTY') || $this->isGranted('ROLE_STAFF') || $this->isGranted('ROLE_SUPERIOR') || $this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_dashboard');
        }

        /** @var User $user */
        $user = $this->getUser();
        $enrollments = $enrollRepo->findByStudent($user->getId());
        $availableSubjects = $this->getStudentAvailableLoadedSubjects($user, $subjectRepo, $enrollRepo, $ayRepo);

        // Collect distinct year levels and departments from available subjects for filters
        $yearLevels = [];
        $faculties = [];
        foreach ($availableSubjects as $s) {
            if ($s->getYearLevel()) $yearLevels[$s->getYearLevel()] = $s->getYearLevel();
            if ($s->getFaculty()) $faculties[$s->getFaculty()->getId()] = $s->getFaculty()->getFullName();
        }
        sort($yearLevels);
        asort($faculties);

        return $this->render('home/student_enrolled_subjects.html.twig', [
            'enrollments' => $enrollments,
            'availableSubjects' => $availableSubjects,
            'currentAY' => $ayRepo->findCurrent(),
            'yearLevels' => array_values($yearLevels),
            'faculties' => $faculties,
        ]);
    }

    #[Route('/student/enrolled-subjects/add', name: 'student_enrolled_subject_add', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function studentEnrolledSubjectAdd(
        Request $request,
        SubjectRepository $subjectRepo,
        EnrollmentRepository $enrollRepo,
        AcademicYearRepository $ayRepo,
        EntityManagerInterface $em,
    ): Response {
        if ($this->isGranted('ROLE_FACULTY') || $this->isGranted('ROLE_STAFF') || $this->isGranted('ROLE_SUPERIOR') || $this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_dashboard');
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('student_add_subject', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token.');
            return $this->redirectToRoute('student_enrolled_subjects');
        }

        $subjectId = (int) $request->request->get('subject_id');
        $availableMap = [];
        foreach ($this->getStudentAvailableLoadedSubjects($user, $subjectRepo, $enrollRepo, $ayRepo) as $subject) {
            $availableMap[$subject->getId()] = $subject;
        }

        if (!isset($availableMap[$subjectId])) {
            $this->addFlash('danger', 'Selected subject is not available for your account.');
            return $this->redirectToRoute('student_enrolled_subjects');
        }

        if ($enrollRepo->isEnrolled($user->getId(), $subjectId)) {
            $this->addFlash('info', 'You are already enrolled in that subject.');
            return $this->redirectToRoute('student_enrolled_subjects');
        }

        $enrollment = new Enrollment();
        $enrollment->setStudent($user);
        $enrollment->setSubject($availableMap[$subjectId]);
        if ($availableMap[$subjectId]->getSection()) $enrollment->setSection($availableMap[$subjectId]->getSection());
        if ($availableMap[$subjectId]->getSchedule()) $enrollment->setSchedule($availableMap[$subjectId]->getSchedule());
        $em->persist($enrollment);
        $em->flush();

        $this->addFlash('success', 'Subject added to your enrolled subjects.');
        return $this->redirectToRoute('student_enrolled_subjects');
    }

    #[Route('/student/enrolled-subjects/import', name: 'student_enrolled_subject_import', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function studentEnrolledSubjectImport(
        Request $request,
        SubjectRepository $subjectRepo,
        EnrollmentRepository $enrollRepo,
        AcademicYearRepository $ayRepo,
        EntityManagerInterface $em,
    ): Response {
        if ($this->isGranted('ROLE_FACULTY') || $this->isGranted('ROLE_STAFF') || $this->isGranted('ROLE_SUPERIOR') || $this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_dashboard');
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('student_import_subjects', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token.');
            return $this->redirectToRoute('student_enrolled_subjects');
        }

        $availableSubjects = $this->getStudentAvailableLoadedSubjects($user, $subjectRepo, $enrollRepo, $ayRepo);
        $count = 0;
        foreach ($availableSubjects as $subject) {
            if ($enrollRepo->isEnrolled($user->getId(), $subject->getId())) {
                continue;
            }

            $enrollment = new Enrollment();
            $enrollment->setStudent($user);
            $enrollment->setSubject($subject);
            if ($subject->getSection()) $enrollment->setSection($subject->getSection());
            if ($subject->getSchedule()) $enrollment->setSchedule($subject->getSchedule());
            $em->persist($enrollment);
            $count++;
        }
        $em->flush();

        if ($count > 0) {
            $this->addFlash('success', $count . ' subject' . ($count !== 1 ? 's' : '') . ' imported from your load slip.');
        } else {
            $this->addFlash('info', 'No new subjects were available from your load slip.');
        }

        return $this->redirectToRoute('student_enrolled_subjects');
    }

    #[Route('/student/enrolled-subjects/{id}/remove', name: 'student_enrolled_subject_remove', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function studentEnrolledSubjectRemove(
        int $id,
        Request $request,
        EnrollmentRepository $enrollRepo,
        EntityManagerInterface $em,
    ): Response {
        if ($this->isGranted('ROLE_FACULTY') || $this->isGranted('ROLE_STAFF') || $this->isGranted('ROLE_SUPERIOR') || $this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_dashboard');
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('student_remove_subject_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token.');
            return $this->redirectToRoute('student_enrolled_subjects');
        }

        $enrollment = $enrollRepo->find($id);
        if (!$enrollment || $enrollment->getStudent()->getId() !== $user->getId()) {
            $this->addFlash('danger', 'Enrollment record not found.');
            return $this->redirectToRoute('student_enrolled_subjects');
        }

        $subjectLabel = $enrollment->getSubject()->getSubjectCode() . ' - ' . $enrollment->getSubject()->getSubjectName();
        $em->remove($enrollment);
        $em->flush();

        $this->addFlash('success', 'Removed subject from your load slip: ' . $subjectLabel . '.');
        return $this->redirectToRoute('student_enrolled_subjects');
    }

    #[Route('/faculty/enrollment/{id}/approve', name: 'faculty_enrollment_approve', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEnrollmentApprove(
        int $id,
        Request $request,
        EnrollmentRepository $enrollRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('faculty_enrollment_action_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $enrollment = $enrollRepo->find($id);
        if (!$enrollment || $enrollment->getSubject()->getFaculty()?->getId() !== $user->getId()) {
            $this->addFlash('danger', 'Enrollment record not found.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $enrollment->setStatus(Enrollment::STATUS_APPROVED);
        $em->flush();

        $this->addFlash('success', 'Enrollment approved for ' . $enrollment->getStudent()->getFullName() . '.');
        return $this->redirectToRoute('faculty_enrollment_management');
    }

    #[Route('/faculty/enrollment/{id}/reject', name: 'faculty_enrollment_reject', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEnrollmentReject(
        int $id,
        Request $request,
        EnrollmentRepository $enrollRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('faculty_enrollment_action_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $enrollment = $enrollRepo->find($id);
        if (!$enrollment || $enrollment->getSubject()->getFaculty()?->getId() !== $user->getId()) {
            $this->addFlash('danger', 'Enrollment record not found.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $enrollment->setStatus(Enrollment::STATUS_REJECTED);
        $em->flush();

        $this->addFlash('warning', 'Enrollment rejected for ' . $enrollment->getStudent()->getFullName() . '.');
        return $this->redirectToRoute('faculty_enrollment_management');
    }

    #[Route('/faculty/enrollment/approve-all', name: 'faculty_enrollment_approve_all', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEnrollmentApproveAll(
        Request $request,
        EnrollmentRepository $enrollRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('faculty_approve_all', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $enrollments = $enrollRepo->createQueryBuilder('e')
            ->join('e.subject', 's')
            ->where('s.faculty = :fid')
            ->andWhere('e.status = :status')
            ->setParameter('fid', $user->getId())
            ->setParameter('status', Enrollment::STATUS_PENDING)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($enrollments as $enrollment) {
            $enrollment->setStatus(Enrollment::STATUS_APPROVED);
            $count++;
        }
        $em->flush();

        if ($count > 0) {
            $this->addFlash('success', $count . ' pending enrollment' . ($count !== 1 ? 's' : '') . ' approved.');
        } else {
            $this->addFlash('info', 'No pending enrollments to approve.');
        }

        return $this->redirectToRoute('faculty_enrollment_management');
    }

    #[Route('/faculty/enrollment/{id}/update-section', name: 'faculty_enrollment_update_section', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEnrollmentUpdateSection(
        int $id,
        Request $request,
        EnrollmentRepository $enrollRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('faculty_section_update_' . $id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $enrollment = $enrollRepo->find($id);
        if (!$enrollment || $enrollment->getSubject()->getFaculty()?->getId() !== $user->getId()) {
            $this->addFlash('danger', 'Enrollment record not found.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $section = trim($request->request->get('section', ''));
        $enrollment->setSection($section ?: null);
        $em->flush();

        $this->addFlash('success', 'Section updated for ' . $enrollment->getStudent()->getFullName() . '.');
        return $this->redirectToRoute('faculty_enrollment_management');
    }

    #[Route('/faculty/enrollment/bulk-section', name: 'faculty_enrollment_bulk_section', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEnrollmentBulkSection(
        Request $request,
        EnrollmentRepository $enrollRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('faculty_bulk_section', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $enrollmentIds = $request->request->all('enrollment_ids');
        $section = trim($request->request->get('section', ''));

        if (empty($enrollmentIds)) {
            $this->addFlash('warning', 'No students selected.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $enrollments = $enrollRepo->createQueryBuilder('e')
            ->join('e.subject', 's')
            ->where('e.id IN (:ids)')
            ->andWhere('s.faculty = :fid')
            ->setParameter('ids', $enrollmentIds)
            ->setParameter('fid', $user->getId())
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($enrollments as $enrollment) {
            $enrollment->setSection($section ?: null);
            $count++;
        }
        $em->flush();

        if ($count > 0) {
            $this->addFlash('success', $count . ' enrollment' . ($count !== 1 ? 's' : '') . ' assigned to section ' . ($section ?: '(cleared)') . '.');
        }

        return $this->redirectToRoute('faculty_enrollment_management');
    }

    #[Route('/faculty/enrollment/auto-section/{subjectId}', name: 'faculty_enrollment_auto_section', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEnrollmentAutoSection(
        int $subjectId,
        Request $request,
        EnrollmentRepository $enrollRepo,
        SubjectRepository $subjectRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('faculty_auto_section_' . $subjectId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $subject = $subjectRepo->find($subjectId);
        if (!$subject || $subject->getFaculty()?->getId() !== $user->getId()) {
            $this->addFlash('danger', 'Subject not found.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $sections = trim($request->request->get('sections', 'A,B'));
        $sectionList = array_filter(array_map('trim', explode(',', $sections)));
        if (empty($sectionList)) {
            $this->addFlash('warning', 'No sections specified.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $enrollments = $enrollRepo->createQueryBuilder('e')
            ->join('e.subject', 's')
            ->join('e.student', 'st')
            ->where('e.subject = :sid')
            ->andWhere('s.faculty = :fid')
            ->setParameter('sid', $subjectId)
            ->setParameter('fid', $user->getId())
            ->orderBy('st.lastName', 'ASC')
            ->addOrderBy('st.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        $numSections = count($sectionList);
        foreach ($enrollments as $i => $enrollment) {
            $enrollment->setSection($sectionList[$i % $numSections]);
        }
        $em->flush();

        $this->addFlash('success', count($enrollments) . ' student(s) distributed across ' . implode(', ', $sectionList) . '.');
        return $this->redirectToRoute('faculty_enrollment_management');
    }

    #[Route('/faculty/enrollment/clear-sections/{subjectId}', name: 'faculty_enrollment_clear_sections', methods: ['POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEnrollmentClearSections(
        int $subjectId,
        Request $request,
        EnrollmentRepository $enrollRepo,
        SubjectRepository $subjectRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('faculty_clear_sections_' . $subjectId, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid request token.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $subject = $subjectRepo->find($subjectId);
        if (!$subject || $subject->getFaculty()?->getId() !== $user->getId()) {
            $this->addFlash('danger', 'Subject not found.');
            return $this->redirectToRoute('faculty_enrollment_management');
        }

        $enrollments = $enrollRepo->createQueryBuilder('e')
            ->join('e.subject', 's')
            ->where('e.subject = :sid')
            ->andWhere('s.faculty = :fid')
            ->setParameter('sid', $subjectId)
            ->setParameter('fid', $user->getId())
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($enrollments as $enrollment) {
            if ($enrollment->getSection()) {
                $enrollment->setSection(null);
                $count++;
            }
        }
        $em->flush();

        $this->addFlash('success', $count . ' section assignment(s) cleared for ' . $subject->getSubjectCode() . '.');
        return $this->redirectToRoute('faculty_enrollment_management');
    }

    #[Route('/faculty/evaluation/request', name: 'faculty_eval_request', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEvalRequest(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        EvaluationMessageRepository $msgRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // Handle message submission
        if ($request->isMethod('POST') && $request->request->get('_action') === 'send_message') {
            $subject = trim($request->request->get('msg_subject', ''));
            $body = trim($request->request->get('msg_body', ''));
            $evalId = $request->request->get('msg_evaluation');

            if ($subject && $body) {
                $msg = new EvaluationMessage();
                $msg->setSender($user);
                $msg->setSubject($subject);
                $msg->setMessage($body);

                if ($evalId) {
                    $eval = $evalRepo->find($evalId);
                    if ($eval) {
                        $msg->setEvaluationPeriod($eval);
                    }
                }

                $em->persist($msg);
                $em->flush();

                $this->addFlash('success', 'Your message has been sent to the administrator.');
            } else {
                $this->addFlash('error', 'Please fill in all required fields.');
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

        $myMessages = $msgRepo->findBySender($user->getId());

        return $this->render('home/faculty/eval_request.html.twig', [
            'periods' => $periods,
            'messages' => $myMessages,
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

        // Group by evaluation period and attach period entity
        $periods = [];
        foreach ($evaluatedSubjects as $row) {
            $epId = (int) $row['evaluationPeriodId'];
            if (!isset($periods[$epId])) {
                $periods[$epId] = [
                    'evaluation' => $evalRepo->find($epId),
                    'subjects' => [],
                ];
            }
            $periods[$epId]['subjects'][] = $row;
        }

        return $this->render('home/faculty/eval_results.html.twig', [
            'periods' => $periods,
        ]);
    }

    #[Route('/faculty/evaluation/summary', name: 'faculty_eval_summary')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEvalSummary(
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $deptId = $user->getDepartment() ? $user->getDepartment()->getId() : null;
        $evaluations = $evalRepo->findForFaculty($deptId, $user->getFullName());
        $summaries = [];
        foreach ($evaluations as $eval) {
            $avg = $responseRepo->getOverallAverage($user->getId(), $eval->getId());
            $count = $responseRepo->countEvaluators($user->getId(), $eval->getId());
            if ($count > 0) {
                $summaries[] = [
                    'evaluation' => $eval,
                    'average' => $avg,
                    'evaluators' => $count,
                ];
            }
        }
        return $this->render('home/faculty/eval_summary.html.twig', [
            'summaries' => $summaries,
        ]);
    }

    #[Route('/faculty/evaluation/analytics', name: 'faculty_eval_analytics')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEvalAnalytics(
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $deptId = $user->getDepartment() ? $user->getDepartment()->getId() : null;
        $evaluations = $evalRepo->findForFaculty($deptId, $user->getFullName());
        $chartData = [];
        foreach ($evaluations as $eval) {
            $avg = $responseRepo->getOverallAverage($user->getId(), $eval->getId());
            $count = $responseRepo->countEvaluators($user->getId(), $eval->getId());
            if ($count > 0) {
                $chartData[] = [
                    'label' => $eval->getLabel(),
                    'average' => round($avg, 2),
                    'evaluators' => $count,
                ];
            }
        }
        return $this->render('home/faculty/eval_analytics.html.twig', [
            'chartData' => $chartData,
        ]);
    }

    #[Route('/faculty/evaluation/download', name: 'faculty_eval_download')]
    #[IsGranted('ROLE_FACULTY')]
    public function facultyEvalDownload(
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        EvaluationMessageRepository $msgRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $deptId = $user->getDepartment() ? $user->getDepartment()->getId() : null;
        $allEvals = $evalRepo->findForFaculty($deptId, $user->getFullName());
        $evaluations = [];
        foreach ($allEvals as $eval) {
            $count = $responseRepo->countEvaluators($user->getId(), $eval->getId());
            if ($count > 0) {
                $evaluations[] = $eval;
            }
        }
        $attachments = $msgRepo->findAttachmentsForUser($user->getId());
        return $this->render('home/faculty/eval_download.html.twig', [
            'evaluations' => $evaluations,
            'attachments' => $attachments,
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

    /**
     * @return array<int, \App\Entity\Subject>
     */
    private function getStudentAvailableLoadedSubjects(
        User $user,
        SubjectRepository $subjectRepo,
        EnrollmentRepository $enrollRepo,
        AcademicYearRepository $ayRepo,
    ): array {
        $deptId = $user->getDepartment()?->getId();
        $studentYearLevel = $this->normalizeYearLevel($user->getYearLevel());
        $currentAY = $ayRepo->findCurrent();
        $currentSemester = $currentAY?->getSemester();

        $subjects = $subjectRepo->createQueryBuilder('s')
            ->leftJoin('s.department', 'd')
            ->leftJoin('s.faculty', 'f')
            ->addSelect('d', 'f')
            ->where('s.faculty IS NOT NULL')
            ->orderBy('s.subjectCode', 'ASC')
            ->getQuery()
            ->getResult();

        $enrolledIds = array_map(
            fn($enrollment) => $enrollment->getSubject()->getId(),
            $enrollRepo->findByStudent($user->getId())
        );
        $enrolledIdMap = array_flip($enrolledIds);

        $available = [];
        foreach ($subjects as $subject) {
            if (isset($enrolledIdMap[$subject->getId()])) {
                continue;
            }

            // Only show subjects that belong to the student's department
            if ($deptId && $subject->getDepartment() && $subject->getDepartment()->getId() !== $deptId) {
                continue;
            }

            // Only show subjects that match the student's year level
            if ($studentYearLevel && $subject->getYearLevel()) {
                $subjectYL = $this->normalizeYearLevel($subject->getYearLevel());
                if ($subjectYL !== $studentYearLevel) {
                    continue;
                }
            }

            $available[] = $subject;
        }

        return $available;
    }

    private function studentDashboard(
        $user,
        CurriculumRepository $curriculumRepo,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        EnrollmentRepository $enrollRepo,
    ): Response {
        $openEvals = $evalRepo->findOpen();

        // ── Resolve the student's approved enrollments ──
        $studentYearLevel = $this->normalizeYearLevel($user->getYearLevel());

        $enrollments = $enrollRepo->findByStudent($user->getId());
        $enrolledSubjects = [];
        $enrollmentSections = [];
        foreach ($enrollments as $enrollment) {
            if ($enrollment->isApproved()) {
                $subj = $enrollment->getSubject();
                $enrolledSubjects[$subj->getId()] = $subj;
                $enrollmentSections[$subj->getId()] = $enrollment->getSection();
            }
        }

        $pending = [];
        $completed = [];

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

            // ── Only show subjects the student is enrolled in (approved) ──
            foreach ($enrolledSubjects as $subject) {
                $faculty = $subject->getFaculty();

                // If eval targets a specific faculty+subject, resolve faculty from eval
                if ($eval->getFaculty() && $eval->getSubject()) {
                    $subjectLabel = $subject->getSubjectCode() . ' — ' . $subject->getSubjectName();
                    if ($subjectLabel !== $eval->getSubject()) {
                        continue;
                    }
                    if (!$faculty) {
                        $faculty = $userRepo->findOneByFullName($eval->getFaculty());
                    }
                    if (!$faculty || $faculty->getFullName() !== $eval->getFaculty()) {
                        continue;
                    }
                } else {
                    if (!$faculty) continue;
                }

                // If eval targets a specific section, match against enrollment section
                if ($eval->getSection() !== null && $eval->getSection() !== '') {
                    $studentSection = $enrollmentSections[$subject->getId()] ?? null;
                    if ($studentSection === null || strtoupper(trim($studentSection)) !== strtoupper(trim($eval->getSection()))) {
                        continue;
                    }
                }

                $submitted = $responseRepo->hasSubmitted(
                    $user->getId(),
                    $eval->getId(),
                    $faculty->getId(),
                    $subject->getId(),
                );

                $item = [
                    'evaluation' => $eval,
                    'subject' => $subject,
                    'faculty' => $faculty,
                ];

                if ($submitted) {
                    $completed[] = $item;
                } else {
                    $pending[] = $item;
                }
            }
        }

        return $this->render('home/student_dashboard.html.twig', [
            'subjects' => $enrolledSubjects,
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
