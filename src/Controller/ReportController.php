<?php

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\AuditLog;
use App\Entity\CorrespondenceRecord;
use App\Entity\Curriculum;
use App\Entity\Department;
use App\Entity\EvaluationMessage;
use App\Entity\EvaluationPeriod;
use App\Entity\MessageNotification;
use App\Entity\Question;
use App\Entity\QuestionCategoryDescription;
use App\Entity\Subject;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\CorrespondenceRecordRepository;
use App\Repository\CourseRepository;
use App\Repository\CurriculumRepository;
use App\Repository\DepartmentRepository;
use App\Repository\EvaluationMessageRepository;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\EvaluationResponseRepository;
use App\Repository\FacultySubjectLoadRepository;
use App\Repository\MessageNotificationRepository;
use App\Repository\QuestionCategoryDescriptionRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;
use App\Repository\SuperiorEvaluationRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/reports')]
#[IsGranted('ROLE_STAFF')]
class ReportController extends AbstractController
{
    public function __construct(private AuditLogger $audit) {}
    #[Route('', name: 'app_reports', methods: ['GET'])]
    public function index(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        DepartmentRepository $deptRepo,
        UserRepository $userRepo,
    ): Response {
        $evalId = $request->query->get('evaluation');
        $deptId = $request->query->get('department');

        $evaluations = $evalRepo->findAllOrdered();
        $departments = $deptRepo->findAllOrdered();
        $results = [];

        if ($evalId) {
            $qb = $userRepo->createQueryBuilder('u')
                ->where('u.roles LIKE :role')
                ->setParameter('role', '%ROLE_FACULTY%')
                ->orderBy('u.lastName', 'ASC');

            if ($deptId) {
                $qb->andWhere('u.department = :did')->setParameter('did', $deptId);
            }

            $faculty = $qb->getQuery()->getResult();

            foreach ($faculty as $f) {
                $avg = $responseRepo->getOverallAverage($f->getId(), (int) $evalId);
                $count = $responseRepo->countEvaluators($f->getId(), (int) $evalId);

                if ($count > 0) {
                    $results[] = [
                        'faculty' => $f,
                        'average' => $avg,
                        'evaluators' => $count,
                        'level' => $this->performanceLevel($avg),
                    ];
                }
            }

            usort($results, fn($a, $b) => $b['average'] <=> $a['average']);
        }

        return $this->render('report/index.html.twig', [
            'evaluations' => $evaluations,
            'departments' => $departments,
            'results' => $results,
            'selectedEvaluation' => $evalId,
            'selectedDepartment' => $deptId,
        ]);
    }

    #[Route('/faculty/{id}', name: 'app_report_faculty', methods: ['GET'])]
    public function facultyDetail(
        int $id,
        Request $request,
        UserRepository $userRepo,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
    ): Response {
        $faculty = $userRepo->find($id);
        if (!$faculty) {
            throw $this->createNotFoundException('Faculty not found.');
        }

        $evalId = $request->query->get('evaluation');
        $evaluations = $evalRepo->findAllOrdered();

        $categoryAverages = [];
        $comments = [];
        $overallAvg = 0;

        if ($evalId) {
            $categoryAverages = $responseRepo->getAverageRatingsByFaculty($faculty->getId(), (int) $evalId);
            $comments = $responseRepo->getComments($faculty->getId(), (int) $evalId);
            $overallAvg = $responseRepo->getOverallAverage($faculty->getId(), (int) $evalId);
        }

        return $this->render('report/faculty_detail.html.twig', [
            'faculty' => $faculty,
            'evaluations' => $evaluations,
            'categoryAverages' => $categoryAverages,
            'comments' => $comments,
            'overallAverage' => $overallAvg,
            'selectedEvaluation' => $evalId,
        ]);
    }

    #[Route('/department', name: 'app_report_department', methods: ['GET'])]
    public function departmentReport(
        Request $request,
        DepartmentRepository $deptRepo,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
    ): Response {
        $evalId = $request->query->get('evaluation');
        $evaluations = $evalRepo->findAllOrdered();
        $departments = $deptRepo->findAllOrdered();

        $deptResults = [];
        if ($evalId) {
            foreach ($departments as $dept) {
                $avg = $responseRepo->getDepartmentAverage($dept->getId(), (int) $evalId);
                if ($avg !== null) {
                    $deptResults[] = [
                        'department' => $dept,
                        'average' => $avg,
                        'level' => $this->performanceLevel($avg),
                    ];
                }
            }
            usort($deptResults, fn($a, $b) => $b['average'] <=> $a['average']);
        }

        return $this->render('report/department.html.twig', [
            'evaluations' => $evaluations,
            'departments' => $departments,
            'deptResults' => $deptResults,
            'selectedEvaluation' => $evalId,
        ]);
    }

    // ════════════════════════════════════════════════
    //  STAFF: EVALUATION PERIODS
    // ════════════════════════════════════════════════

    #[Route('/evaluations', name: 'staff_evaluations', methods: ['GET'])]
    #[IsGranted('ROLE_STAFF')]
    public function evaluations(EvaluationPeriodRepository $repo, DepartmentRepository $deptRepo, AcademicYearRepository $ayRepo, UserRepository $userRepo, SubjectRepository $subjectRepo, EvaluationResponseRepository $responseRepo, SuperiorEvaluationRepository $superiorEvalRepo, FacultySubjectLoadRepository $fslRepo): Response
    {
        $currentAY = $ayRepo->syncCurrentToCalendar() ?? $ayRepo->findCurrent();
        $evaluations = $repo->findAllOrdered();

        $departments = $deptRepo->findAllOrdered();
        $colleges = [];
        foreach ($departments as $d) {
            $cn = $d->getCollegeName();
            if ($cn && !in_array($cn, $colleges, true)) {
                $colleges[] = $cn;
            }
        }
        sort($colleges);

        $evaluatorCounts = $responseRepo->countEvaluatorsByPeriod();
        $superiorCounts = $superiorEvalRepo->countEvaluatorsByPeriod();
        foreach ($superiorCounts as $epId => $cnt) {
            $evaluatorCounts[$epId] = ($evaluatorCounts[$epId] ?? 0) + $cnt;
        }

        $facultyUsers = $userRepo->createQueryBuilder('u')
            ->andWhere("u.roles LIKE :role")
            ->andWhere("u.roles NOT LIKE :superior")
            ->setParameter('role', '%ROLE_FACULTY%')
            ->setParameter('superior', '%ROLE_SUPERIOR%')
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        $sefEvaluatorUsers = $userRepo->createQueryBuilder('u')
            ->andWhere('u.accountStatus = :status')
            ->setParameter('status', 'active')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        $rankLabels = [
            'president' => 'President',
            'vice_president' => 'Vice President',
            'campus_director' => 'Campus Director',
            'dean' => 'Dean',
            'department_head' => 'Department Head',
        ];

        $superiorHierarchy = [];
        foreach ($rankLabels as $rankKey => $rankLabel) {
            $superiorHierarchy[$rankKey] = [
                'rankKey' => $rankKey,
                'rankLabel' => $rankLabel,
                'members' => [],
            ];
        }

        foreach ($sefEvaluatorUsers as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $rank = $this->resolveSuperiorHierarchyRank($user);
            if ($rank === null || !isset($superiorHierarchy[$rank])) {
                continue;
            }

            $position = trim((string) ($user->getPosition() ?? ''));
            if ($position === '') {
                $position = trim((string) ($user->getEmploymentStatus() ?? ''));
            }

            $superiorHierarchy[$rank]['members'][] = [
                'id' => $user->getId(),
                'fullName' => $user->getFullName(),
                'position' => $position !== '' ? $position : '—',
                'department' => $user->getDepartment()?->getDepartmentName() ?? '—',
                'departmentId' => $user->getDepartment()?->getId(),
            ];
        }

        foreach ($superiorHierarchy as $rankKey => $rankData) {
            usort($superiorHierarchy[$rankKey]['members'], static function (array $a, array $b): int {
                return strnatcasecmp((string) $a['fullName'], (string) $b['fullName']);
            });
        }

        $facultyPositionMap = [];
        foreach ($facultyUsers as $fu) {
            $facultyPositionMap[$fu->getFullName()] = $fu->getEmploymentStatus() ?? '';
        }

        $scheduleRows = $this->buildScheduleMergedRows($evaluations, $evaluatorCounts, $userRepo, $fslRepo, $ayRepo, $responseRepo);
        $activeScheduleRows = [];
        $expiredScheduleRows = [];
        $nowTs = (new \DateTimeImmutable())->getTimestamp();

        foreach ($scheduleRows as $row) {
            /** @var EvaluationPeriod $eval */
            $eval = $row['eval'];
            $endTs = $eval->getEndDate()->getTimestamp();
            if ($nowTs > $endTs || !$eval->isStatus()) {
                $expiredScheduleRows[] = $row;
            } else {
                $activeScheduleRows[] = $row;
            }
        }

        return $this->render('admin/evaluations.html.twig', [
            'evaluations' => $evaluations,
            'departments' => $departments,
            'colleges' => $colleges,
            'currentAY' => $currentAY,
            'academicYears' => $ayRepo->findAllOrdered(),
            'facultyUsers' => $facultyUsers,
            'sefEvaluatorUsers' => $sefEvaluatorUsers,
            'evaluatorCounts' => $evaluatorCounts,
            'facultyPositionMap' => $facultyPositionMap,
            'superiorHierarchy' => array_values($superiorHierarchy),
            'activeScheduleRows' => $activeScheduleRows,
            'expiredScheduleRows' => $expiredScheduleRows,
            'staffMode' => true,
        ]);
    }

    private function buildScheduleMergedRows(array $evaluations, array $evaluatorCounts, UserRepository $userRepo, FacultySubjectLoadRepository $fslRepo, AcademicYearRepository $ayRepo, EvaluationResponseRepository $responseRepo): array
    {
        $rows = [];
        $indexByKey = [];
        $currentAY = $ayRepo->findCurrent();

        foreach ($evaluations as $eval) {
            if (!$eval instanceof EvaluationPeriod) {
                continue;
            }

            $key = $this->buildScheduleMergeKey($eval);
            $schedule = trim((string) ($eval->getTime() ?? ''));
            $subject = trim((string) ($eval->getSubject() ?? ''));
            $section = strtoupper(trim((string) ($eval->getSection() ?? '')));
            $baseCount = (int) ($evaluatorCounts[$eval->getId()] ?? 0);

            // If subject is empty, try to fetch from faculty's subject load
            $allSubjectLoads = [];
            $subjectEvaluatorCounts = [];
            if (empty($subject) && $eval->getFaculty()) {
                $facultyName = $eval->getFaculty();
                // Try to find faculty by full name (last, first)
                $facultyUsers = $userRepo->createQueryBuilder('u')
                    ->where('CONCAT(u.lastName, \', \', u.firstName) = :fullName')
                    ->orWhere('CONCAT(u.firstName, \' \', u.lastName) = :fullName')
                    ->setParameter('fullName', $facultyName)
                    ->getQuery()->getResult();

                if (!empty($facultyUsers)) {
                    $facultyUser = $facultyUsers[0];
                    $allSubjectLoads = $fslRepo->findByFacultyAndAcademicYear($facultyUser->getId(), $currentAY ? $currentAY->getId() : null);

                    // Get evaluator counts per subject+section for this faculty
                    $evaluatedSubjects = $responseRepo->getEvaluatedSubjects($facultyUser->getId());
                    foreach ($evaluatedSubjects as $subjEval) {
                        if ($subjEval['evaluationPeriodId'] == $eval->getId()) {
                            // Key by subject ID + section (each section has its own count)
                            $sectionKey = $subjEval['subjectId'] . '|' . ($subjEval['section'] ?? 'NOSECTION');
                            $subjectEvaluatorCounts[$sectionKey] = (int) $subjEval['evaluatorCount'];
                        }
                    }

                    if (!empty($allSubjectLoads)) {
                        // Use first subject's info for main display
                        $firstLoad = $allSubjectLoads[0];
                        $subj = $firstLoad->getSubject();
                        if ($subj) {
                            $subject = $subj->getSubjectCode() . ' — ' . $subj->getSubjectName();
                        }
                        if (empty($section) && $firstLoad->getSection()) {
                            $section = strtoupper($firstLoad->getSection());
                        }
                        if (empty($schedule) && $firstLoad->getSchedule()) {
                            $schedule = $firstLoad->getSchedule();
                        }
                    }
                }
            }

            if (!isset($indexByKey[$key])) {
                $items = [];
                // Create items for all subjects (or just one if subject is already set)
                if (!empty($allSubjectLoads)) {
                    foreach ($allSubjectLoads as $load) {
                        $subj = $load->getSubject();
                        if ($subj) {
                            $subjName = $subj->getSubjectCode() . ' — ' . $subj->getSubjectName();
                            $loadSection = strtoupper(trim((string) ($load->getSection() ?? '')));
                            $loadSchedule = trim((string) ($load->getSchedule() ?? ''));
                            // Get evaluator count for this subject+section
                            $sectionKey = $subj->getId() . '|' . ($loadSection ?: 'NOSECTION');
                            $subjCount = $subjectEvaluatorCounts[$sectionKey] ?? 0;
                            $items[] = [
                                'eval' => $eval,
                                'subject' => $subjName,
                                'subjectId' => $subj->getId(),
                                'section' => $loadSection ?: $section,
                                'schedule' => $loadSchedule ?: $schedule,
                                'evaluatorCount' => $subjCount,
                            ];
                        }
                    }
                } else {
                    $items[] = [
                        'eval' => $eval,
                        'subject' => $subject,
                        'subjectId' => null,
                        'section' => $section,
                        'schedule' => $schedule,
                        'evaluatorCount' => $baseCount,
                    ];
                }

                $rows[] = [
                    'eval' => $eval,
                    'items' => $items,
                    'subjects' => $subject !== '' ? [$subject] : [],
                    'sections' => $section !== '' ? [$section] : [],
                    'schedules' => $schedule !== '' ? [$schedule] : [],
                    'evaluatorCount' => $baseCount,
                    'mergedCount' => 1,
                    'searchText' => trim($subject . ' ' . $section . ' ' . $schedule),
                ];
                $indexByKey[$key] = count($rows) - 1;
                continue;
            }

            $idx = $indexByKey[$key];

            // If we have multiple subject loads, add items for each one
            if (!empty($allSubjectLoads)) {
                foreach ($allSubjectLoads as $load) {
                    $subj = $load->getSubject();
                    if ($subj) {
                        $subjName = $subj->getSubjectCode() . ' — ' . $subj->getSubjectName();
                        $loadSection = strtoupper(trim((string) ($load->getSection() ?? '')));
                        $loadSchedule = trim((string) ($load->getSchedule() ?? ''));
                        // Get evaluator count for this subject+section
                        $sectionKey = $subj->getId() . '|' . ($loadSection ?: 'NOSECTION');
                        $subjCount = $subjectEvaluatorCounts[$sectionKey] ?? 0;
                        $rows[$idx]['items'][] = [
                            'eval' => $eval,
                            'subject' => $subjName,
                            'subjectId' => $subj->getId(),
                            'section' => $loadSection ?: $section,
                            'schedule' => $loadSchedule ?: $schedule,
                            'evaluatorCount' => $subjCount,
                        ];
                    }
                }
            } else {
                $rows[$idx]['items'][] = [
                    'eval' => $eval,
                    'subject' => $subject,
                    'subjectId' => null,
                    'section' => $section,
                    'schedule' => $schedule,
                    'evaluatorCount' => $baseCount,
                ];
            }

            // Don't accumulate evaluator count - keep it as the period total
            $rows[$idx]['mergedCount']++;

            if ($subject !== '' && !in_array($subject, $rows[$idx]['subjects'], true)) {
                $rows[$idx]['subjects'][] = $subject;
            }
            if ($section !== '' && !in_array($section, $rows[$idx]['sections'], true)) {
                $rows[$idx]['sections'][] = $section;
            }

            if ($schedule !== '' && !in_array($schedule, $rows[$idx]['schedules'], true)) {
                $rows[$idx]['schedules'][] = $schedule;
            }

            $rows[$idx]['searchText'] = trim($rows[$idx]['searchText'] . ' ' . $subject . ' ' . $section . ' ' . $schedule);
        }

        return $rows;
    }

    private function buildScheduleMergeKey(EvaluationPeriod $eval): string
    {
        if ($eval->getEvaluationType() !== 'SET') {
            return 'single:' . (string) $eval->getId();
        }

        $deptId = (string) ($eval->getDepartment()?->getId() ?? 0);

        return implode('|', [
            'SET',
            (string) ($eval->getSchoolYear() ?? ''),
            (string) ($eval->getSemester() ?? ''),
            (string) ($eval->getFaculty() ?? ''),
            (string) ($eval->getYearLevel() ?? ''),
            (string) ($eval->getCollege() ?? ''),
            $deptId,
            $eval->getStartDate()->format('c'),
            $eval->getEndDate()->format('c'),
            $eval->isStatus() ? '1' : '0',
            $eval->isAnonymousMode() ? '1' : '0',
        ]);
    }

    private function facultyHasScheduledLoad(int $facultyId, ?int $currentAyId, FacultySubjectLoadRepository $fslRepo): bool
    {
        $loads = $fslRepo->findByFacultyAndAcademicYear($facultyId, $currentAyId);
        foreach ($loads as $load) {
            if (!$load->getSubject()) {
                continue;
            }

            $schedule = trim((string) ($load->getSchedule() ?? ''));
            $section = trim((string) ($load->getSection() ?? ''));
            if ($schedule !== '' && $section !== '') {
                return true;
            }
        }

        return false;
    }

    #[Route('/evaluations/create', name: 'staff_evaluation_create', methods: ['POST'])]
    #[IsGranted('ROLE_STAFF')]
    public function createEvaluation(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
    ): Response
    {
        if ($this->isCsrfTokenValid('create_eval', $request->request->get('_token'))) {
            $evaluationType = strtoupper(trim((string) $request->request->get('evaluationType', 'SET')));
            if ($evaluationType === 'SEF') {
                $evaluationType = 'SUPERIOR';
            }
            $faculty = $request->request->get('faculty');
            $facultyId = (int) $request->request->get('facultyId', 0);
            $subject = $request->request->get('subject');
            $schoolYear = trim((string) $request->request->get('schoolYear'));
            $section = $request->request->get('section');
            $sem = $request->request->get('semester');
            $college = trim((string) $request->request->get('college', ''));
            $deptId = $request->request->get('department');
            $position = trim((string) $request->request->get('position', ''));
            $currentAY = $ayRepo->syncCurrentToCalendar() ?? $ayRepo->findCurrent();

            if ($schoolYear === '' && $currentAY) {
                $schoolYear = (string) $currentAY->getYearLabel();
            }

            if ($schoolYear === '') {
                $this->addFlash('danger', 'Please select a valid academic year.');
                return $this->redirectToRoute('staff_evaluations');
            }

            if ($evaluationType === 'SET') {
                $facultyUser = $facultyId > 0 ? $userRepo->find($facultyId) : null;
                if (!$facultyUser && is_string($faculty) && trim($faculty) !== '') {
                    $facultyUser = $userRepo->findOneByFullName(trim($faculty));
                }

                if (!$facultyUser) {
                    $this->addFlash('danger', 'Please select a valid faculty member.');
                    return $this->redirectToRoute('staff_evaluations');
                }

                $hasScheduledLoad = $this->facultyHasScheduledLoad(
                    $facultyUser->getId(),
                    $currentAY ? $currentAY->getId() : null,
                    $fslRepo
                );

                if (!$hasScheduledLoad) {
                    $this->addFlash('danger', 'No subject found or added in schedule for the selected faculty. Please add a subject load with schedule first.');
                    return $this->redirectToRoute('staff_evaluations');
                }
            }

            if ($evaluationType === 'SUPERIOR') {
                $selectedFaculty = null;

                if (is_string($faculty) && trim($faculty) !== '') {
                    $selectedFaculty = $userRepo->findOneByFullName(trim($faculty));
                    if (!$selectedFaculty) {
                        $this->addFlash('danger', 'Please select a valid superior evaluator.');
                        return $this->redirectToRoute('staff_evaluations');
                    }
                } elseif ($position !== '') {
                    $targetRank = $this->mapSuperiorPositionToRank($position);
                    if ($targetRank === null) {
                        $this->addFlash('danger', 'Please select a valid SEF evaluator position.');
                        return $this->redirectToRoute('staff_evaluations');
                    }

                    $qb = $userRepo->createQueryBuilder('u')
                        ->leftJoin('u.department', 'd')
                        ->where('u.accountStatus = :status')
                        ->setParameter('status', 'active')
                        ->orderBy('u.lastName', 'ASC')
                        ->addOrderBy('u.firstName', 'ASC');

                    if ($deptId) {
                        $qb->andWhere('u.department = :deptId')->setParameter('deptId', (int) $deptId);
                    } elseif ($college !== '') {
                        $qb->andWhere('d.collegeName = :college')->setParameter('college', $college);
                    }

                    $candidates = $qb->getQuery()->getResult();
                    foreach ($candidates as $candidate) {
                        if ($candidate instanceof User && $this->resolveSuperiorHierarchyRank($candidate) === $targetRank) {
                            $selectedFaculty = $candidate;
                            break;
                        }
                    }

                    if (!$selectedFaculty) {
                        $this->addFlash('danger', 'No evaluator found for the selected position and filters.');
                        return $this->redirectToRoute('staff_evaluations');
                    }
                }

                if ($selectedFaculty) {
                    $rank = $this->resolveSuperiorHierarchyRank($selectedFaculty);
                    if ($rank === null) {
                        $this->addFlash('danger', 'SEF evaluator must be President, Vice President, Campus Director, Dean, or Department Head/Chair.');
                        return $this->redirectToRoute('staff_evaluations');
                    }

                    if ($deptId && ($rank === 'dean' || $rank === 'department_head')) {
                        $facultyDept = $selectedFaculty->getDepartment();
                        if (!$facultyDept || (int) $facultyDept->getId() !== (int) $deptId) {
                            $this->addFlash('danger', 'Selected evaluator must belong to the selected department.');
                            return $this->redirectToRoute('staff_evaluations');
                        }
                    }

                    if (!$deptId && $selectedFaculty->getDepartment()) {
                        $deptId = (string) $selectedFaculty->getDepartment()->getId();
                    }

                    $faculty = $selectedFaculty->getFullName();
                }
            }

            $evalRepo = $em->getRepository(EvaluationPeriod::class);
            $existing = $evalRepo->findDuplicate($evaluationType, $faculty, $subject, $schoolYear, $section, $sem !== '' ? $sem : null, $deptId ? (int) $deptId : null);
            if ($existing) {
                $this->addFlash('danger', 'A matching evaluation period already exists for the selected criteria.');
                return $this->redirectToRoute('staff_evaluations');
            }

            $eval = new EvaluationPeriod();
            $eval->setEvaluationType($evaluationType);
            $eval->setSchoolYear($schoolYear);
            $eval->setSemester($sem !== '' ? $sem : null);
            $eval->setFaculty($faculty);
            $eval->setCollege($college !== '' ? $college : null);
            $eval->setSubject($subject);
            $eval->setTime($request->request->get('time'));
            $eval->setSection($section);
            $eval->setStartDate(new \DateTime($request->request->get('startDate')));
            $eval->setEndDate(new \DateTime($request->request->get('endDate')));
            $eval->setStatus($request->request->getBoolean('status', true));
            $eval->setAnonymousMode($request->request->getBoolean('anonymousMode', true));

            $yl = $request->request->get('yearLevel');
            $eval->setYearLevel($yl !== '' ? $yl : null);

            if ($deptId) {
                $dept = $em->getRepository(Department::class)->find($deptId);
                $eval->setDepartment($dept);
            }

            $em->persist($eval);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_EVALUATION, 'EvaluationPeriod', $eval->getId(),
                'Created ' . $eval->getEvaluationType() . ' evaluation for ' . $eval->getSchoolYear());

            $this->addFlash('success', 'Evaluation period created.');
        }
        return $this->redirectToRoute('staff_evaluations');
    }

    private function resolveSuperiorHierarchyRank(User $user): ?string
    {
        $raw = mb_strtolower(trim((string) ($user->getPosition() ?: '') . ' ' . (string) ($user->getEmploymentStatus() ?: '')));
        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, 'vice president')) {
            return 'vice_president';
        }
        if (str_contains($raw, 'president')) {
            return 'president';
        }
        if (str_contains($raw, 'campus director')) {
            return 'campus_director';
        }
        if (str_contains($raw, 'dean')) {
            return 'dean';
        }
        if (str_contains($raw, 'head') || str_contains($raw, 'chair')) {
            return 'department_head';
        }

        return null;
    }

    private function mapSuperiorPositionToRank(string $position): ?string
    {
        $value = mb_strtolower(trim($position));
        if ($value === '') {
            return null;
        }

        return match ($value) {
            'president' => 'president',
            'vice president' => 'vice_president',
            'campus director' => 'campus_director',
            'dean' => 'dean',
            'department head' => 'department_head',
            default => null,
        };
    }

    #[Route('/evaluations/{id}/toggle', name: 'staff_evaluation_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_STAFF')]
    public function toggleEvaluation(EvaluationPeriod $eval, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('toggle_eval' . $eval->getId(), $request->request->get('_token'))) {
            $eval->setStatus(!$eval->isStatus());
            $em->flush();

            $action = $eval->isStatus() ? AuditLog::ACTION_OPEN_EVALUATION : AuditLog::ACTION_CLOSE_EVALUATION;
            $this->audit->log($action, 'EvaluationPeriod', $eval->getId(),
                ($eval->isStatus() ? 'Opened' : 'Closed') . ' evaluation ' . $eval->getLabel());

            $this->addFlash('success', 'Evaluation ' . ($eval->isStatus() ? 'opened' : 'closed') . '.');
        }
        return $this->redirectToRoute('staff_evaluations');
    }

    #[Route('/evaluations/{id}/edit', name: 'staff_evaluation_edit', methods: ['POST'])]
    #[IsGranted('ROLE_STAFF')]
    public function editEvaluation(EvaluationPeriod $eval, Request $request, EntityManagerInterface $em, AcademicYearRepository $ayRepo): Response
    {
        if ($this->isCsrfTokenValid('edit_eval' . $eval->getId(), $request->request->get('_token'))) {
            $evaluationType = strtoupper(trim((string) $request->request->get('evaluationType', 'SET')));
            if ($evaluationType === 'SEF') {
                $evaluationType = 'SUPERIOR';
            }
            $schoolYear = trim((string) $request->request->get('schoolYear'));
            if ($schoolYear === '') {
                $currentAY = $ayRepo->syncCurrentToCalendar() ?? $ayRepo->findCurrent();
                $schoolYear = (string) ($currentAY?->getYearLabel() ?? '');
            }
            if ($schoolYear === '') {
                $this->addFlash('danger', 'Please select a valid academic year.');
                return $this->redirectToRoute('staff_evaluations');
            }
            $sem = $request->request->get('semester');
            $faculty = $request->request->get('faculty');
            $subject = $request->request->get('subject');
            $time = $request->request->get('time');
            $section = $request->request->get('section');
            $startDate = new \DateTime($request->request->get('startDate'));
            $endDate = new \DateTime($request->request->get('endDate'));
            $status = $request->request->getBoolean('status', false);
            $anonymous = $request->request->getBoolean('anonymousMode', false);
            $yl = $request->request->get('yearLevel');
            $yearLevel = $yl !== '' ? $yl : null;

            $college = $request->request->get('college');
            $collegeValue = $college !== '' ? $college : null;

            $deptId = $request->request->get('department');
            $dept = null;
            if ($deptId) {
                $dept = $em->getRepository(Department::class)->find($deptId);
            }

            $targets = [$eval];
            $mergedIdsRaw = trim((string) $request->request->get('applyToMergedIds', ''));
            if ($eval->getEvaluationType() === 'SET' && $mergedIdsRaw !== '') {
                $mergedIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $mergedIdsRaw)), fn(int $id): bool => $id > 0)));
                if (!in_array((int) $eval->getId(), $mergedIds, true)) {
                    $mergedIds[] = (int) $eval->getId();
                }
                if (count($mergedIds) > 1) {
                    $targets = $em->getRepository(EvaluationPeriod::class)
                        ->createQueryBuilder('e')
                        ->where('e.id IN (:ids)')
                        ->setParameter('ids', $mergedIds)
                        ->getQuery()
                        ->getResult();
                }
            }

            $isMergedUpdate = count($targets) > 1;
            foreach ($targets as $target) {
                if (!$target instanceof EvaluationPeriod) {
                    continue;
                }

                $target->setEvaluationType($evaluationType);
                $target->setSchoolYear($schoolYear);
                $target->setSemester($sem !== '' ? $sem : null);
                $target->setFaculty($faculty);
                $target->setStartDate(clone $startDate);
                $target->setEndDate(clone $endDate);
                $target->setStatus($status);
                $target->setAnonymousMode($anonymous);
                $target->setYearLevel($yearLevel);
                $target->setCollege($collegeValue);
                $target->setDepartment($dept);

                if (!$isMergedUpdate || $target->getEvaluationType() !== 'SET') {
                    $target->setSubject($subject);
                    $target->setTime($time);
                    $target->setSection($section);
                }
            }

            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_EVALUATION, 'EvaluationPeriod', $eval->getId(),
                'Updated evaluation ' . $eval->getLabel());

            $this->addFlash('success', $isMergedUpdate ? ('Updated ' . count($targets) . ' merged evaluation periods.') : 'Evaluation period updated.');
        }
        return $this->redirectToRoute('staff_evaluations');
    }

    #[Route('/evaluations/{id}/lock-results', name: 'staff_evaluation_lock_results', methods: ['POST'])]
    #[IsGranted('ROLE_STAFF')]
    public function lockResults(EvaluationPeriod $eval, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('lock' . $eval->getId(), $request->request->get('_token'))) {
            $newState = !$eval->isResultsLocked();
            $eval->setResultsLocked($newState);

            // Apply to all merged evaluations
            $mergedIdsRaw = trim((string) $request->request->get('mergedIds', ''));
            if ($eval->getEvaluationType() === 'SET' && $mergedIdsRaw !== '') {
                $mergedIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $mergedIdsRaw)), fn(int $id): bool => $id > 0)));
                if (count($mergedIds) > 1) {
                    $siblings = $em->getRepository(EvaluationPeriod::class)
                        ->createQueryBuilder('e')
                        ->where('e.id IN (:ids)')
                        ->setParameter('ids', $mergedIds)
                        ->getQuery()
                        ->getResult();
                    foreach ($siblings as $sib) {
                        $sib->setResultsLocked($newState);
                    }
                }
            }

            $em->flush();

            $this->audit->log(AuditLog::ACTION_LOCK_RESULTS, 'EvaluationPeriod', $eval->getId(),
                ($eval->isResultsLocked() ? 'Locked' : 'Unlocked') . ' results for ' . $eval->getLabel());

            $this->addFlash('success', 'Results ' . ($eval->isResultsLocked() ? 'locked' : 'unlocked') . '.');
        }
        return $this->redirectToRoute('staff_evaluations');
    }

    #[Route('/evaluations/{id}/delete', name: 'staff_evaluation_delete', methods: ['POST'])]
    #[IsGranted('ROLE_STAFF')]
    public function deleteEvaluation(EvaluationPeriod $eval, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_eval' . $eval->getId(), $request->request->get('_token'))) {
            $targets = [$eval];
            $mergedIdsRaw = trim((string) $request->request->get('mergedIds', ''));
            if ($eval->getEvaluationType() === 'SET' && $mergedIdsRaw !== '') {
                $mergedIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $mergedIdsRaw)), fn(int $id): bool => $id > 0)));
                if (!in_array((int) $eval->getId(), $mergedIds, true)) {
                    $mergedIds[] = (int) $eval->getId();
                }
                if (count($mergedIds) > 1) {
                    $targets = $em->getRepository(EvaluationPeriod::class)
                        ->createQueryBuilder('e')
                        ->where('e.id IN (:ids)')
                        ->setParameter('ids', $mergedIds)
                        ->getQuery()
                        ->getResult();
                }
            }

            foreach ($targets as $target) {
                if (!$target instanceof EvaluationPeriod) {
                    continue;
                }
                $em->createQuery('DELETE FROM App\Entity\SuperiorEvaluation se WHERE se.evaluationPeriod = :ep')
                    ->setParameter('ep', $target)
                    ->execute();
                $em->createQuery('DELETE FROM App\Entity\EvaluationMessage em WHERE em.evaluationPeriod = :ep')
                    ->setParameter('ep', $target)
                    ->execute();
                $em->remove($target);
            }
            $em->flush();
            $this->addFlash('success', count($targets) > 1 ? (count($targets) . ' merged evaluation periods deleted.') : 'Evaluation period deleted.');
        }
        return $this->redirectToRoute('staff_evaluations');
    }

    #[Route('/evaluations/{id}/responses.csv', name: 'staff_evaluation_responses_csv', methods: ['GET'])]
    #[IsGranted('ROLE_STAFF')]
    public function exportEvaluationResponsesCsv(
        EvaluationPeriod $eval,
        Request $request,
        EvaluationResponseRepository $responseRepo,
    ): StreamedResponse {
        $subjectIdRaw = (int) $request->query->get('subjectId', 0);
        $subjectId = $subjectIdRaw > 0 ? $subjectIdRaw : null;
        $sectionRaw = trim((string) $request->query->get('section', ''));
        $section = $sectionRaw !== '' ? $sectionRaw : null;

        $rows = $responseRepo->getStudentResponsesForExport($eval->getId(), $subjectId, $section);
        $studentCount = count($rows);

        $this->audit->log(
            AuditLog::ACTION_EXPORT_REPORT,
            'EvaluationPeriod',
            $eval->getId(),
            'Exported student responses CSV (' . $studentCount . ' students) for ' . $eval->getLabel()
        );

        $filename = sprintf(
            'responses_%s_eval%s_%s.csv',
            strtolower((string) $eval->getEvaluationType()),
            $eval->getId(),
            (new \DateTimeImmutable())->format('Ymd_His')
        );

        $response = new StreamedResponse(function () use ($eval, $section, $rows, $studentCount): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['Evaluation', $eval->getLabel()]);
            fputcsv($handle, ['Type', (string) $eval->getEvaluationType()]);
            fputcsv($handle, ['Section', $section ?? 'All / None']);
            fputcsv($handle, ['Students Responded', (string) $studentCount]);
            fputcsv($handle, []);

            fputcsv($handle, ['Student ID', 'Student Name', 'Email', 'Department', 'Year Level', 'Average Rating', 'Submitted At']);

            foreach ($rows as $row) {
                $submittedAt = $row['submittedAt'] ?? null;
                $submittedAtText = '';

                if ($submittedAt instanceof \DateTimeInterface) {
                    $submittedAtText = $submittedAt->format('Y-m-d H:i:s');
                } elseif (is_string($submittedAt)) {
                    $submittedAtText = $submittedAt;
                }

                fputcsv($handle, [
                    (string) ($row['schoolId'] ?? ''),
                    trim((string) ($row['fullName'] ?? '')),
                    (string) ($row['email'] ?? ''),
                    (string) ($row['departmentName'] ?? ''),
                    (string) ($row['yearLevel'] ?? ''),
                    number_format((float) ($row['avgRating'] ?? 0), 2, '.', ''),
                    $submittedAtText,
                ]);
            }

            fclose($handle);
        });

        $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    // ════════════════════════════════════════════════
    //  STAFF: QUESTIONNAIRE
    // ════════════════════════════════════════════════

    #[Route('/questions', name: 'staff_questions', methods: ['GET'])]
    public function questions(
        Request $request,
        QuestionRepository $repo,
        QuestionCategoryDescriptionRepository $descRepo,
    ): Response
    {
        $type = $request->query->get('type', 'SET');
        $questions = $repo->findByType($type);
        $categories = $repo->findCategories($type);
        $categoryDescriptions = $descRepo->findDescriptionsByType($type);
        $questionnaireDisclaimerText = $descRepo->getDisclaimerText($type);
        $questionnaireDisclaimerHtml = $descRepo->getDisclaimerHtml($type);

        return $this->render('admin/questions.html.twig', [
            'questions' => $questions,
            'categories' => $categories,
            'selectedType' => $type,
            'categoryDescriptions' => $categoryDescriptions,
            'questionnaireDisclaimerText' => $questionnaireDisclaimerText,
            'questionnaireDisclaimerHtml' => $questionnaireDisclaimerHtml,
            'staffMode' => true,
        ]);
    }

    #[Route('/questions/create', name: 'staff_question_create_form', methods: ['GET'])]
    public function createQuestionForm(Request $request, QuestionRepository $repo): Response
    {
        $type = $request->query->get('type', 'SET');
        $categories = $repo->findCategories($type);

        return $this->render('admin/question_create.html.twig', [
            'selectedType' => $type,
            'categories' => $categories,
            'staffMode' => true,
        ]);
    }

    #[Route('/questions/create', name: 'staff_question_create', methods: ['POST'])]
    public function createQuestion(Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('create_question', $request->request->get('_token'))) {
            $q = new Question();
            $type = (string) $request->request->get('evaluationType', 'SET');
            $q->setQuestionText($request->request->get('questionText', ''));
            $q->setCategory($request->request->get('category'));
            $q->setEvaluationType($type);
            $q->setWeight((float) ($request->request->get('weight', 1.0)));
            $q->setSortOrder((int) ($request->request->get('sortOrder', 0)));
            $q->setIsRequired($request->request->getBoolean('isRequired', true));
            $q->setEvidenceItems($type === 'SEF'
                ? $this->parseEvidenceItemsText((string) $request->request->get('evidenceItemsText', ''))
                : []);

            $em->persist($q);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_QUESTION, 'Question', $q->getId(),
                'Created question: ' . substr($q->getQuestionText(), 0, 50));

            $this->addFlash('success', 'Question created.');
        }
        return $this->redirectToRoute('staff_questions', ['type' => $request->request->get('evaluationType', 'SET')]);
    }

    #[Route('/questions/{id}/edit', name: 'staff_question_edit', methods: ['POST'])]
    public function editQuestion(Question $question, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('edit_q' . $question->getId(), $request->request->get('_token'))) {
            $question->setQuestionText($request->request->get('questionText', ''));
            $question->setCategory($request->request->get('category'));
            $question->setWeight((float) ($request->request->get('weight', 1.0)));
            $question->setSortOrder((int) ($request->request->get('sortOrder', 0)));
            $question->setIsRequired($request->request->getBoolean('isRequired', true));
            $question->setIsActive($request->request->getBoolean('isActive', true));
            $question->setEvidenceItems($question->getEvaluationType() === 'SEF'
                ? $this->parseEvidenceItemsText((string) $request->request->get('evidenceItemsText', ''))
                : []);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_EDIT_QUESTION, 'Question', $question->getId(),
                'Edited question #' . $question->getId());

            $this->addFlash('success', 'Question updated.');
        }
        return $this->redirectToRoute('staff_questions', ['type' => $question->getEvaluationType()]);
    }

    #[Route('/questions/{id}/delete', name: 'staff_question_delete', methods: ['POST'])]
    public function deleteQuestion(Question $question, Request $request, EntityManagerInterface $em): Response
    {
        $type = $question->getEvaluationType();
        if ($this->isCsrfTokenValid('delete_q' . $question->getId(), $request->request->get('_token'))) {
            $this->audit->log(AuditLog::ACTION_DELETE_QUESTION, 'Question', $question->getId(),
                'Deleted question #' . $question->getId());

            $em->remove($question);
            $em->flush();
            $this->addFlash('success', 'Question deleted.');
        }
        return $this->redirectToRoute('staff_questions', ['type' => $type]);
    }

    /**
     * Parse textarea input into normalized evidence items (one item per line).
     *
     * @return string[]
     */
    private function parseEvidenceItemsText(string $raw): array
    {
        $lines = preg_split('/\R+/', $raw) ?: [];
        $items = [];

        foreach ($lines as $line) {
            $clean = trim((string) preg_replace('/^\s*[-*0-9.()]+\s*/', '', trim($line)));
            if ($clean !== '') {
                $items[] = $clean;
            }
        }

        return array_values(array_unique($items));
    }

    #[Route('/results', name: 'staff_results', methods: ['GET'])]
    public function results(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
    ): Response {
        $view = strtolower($request->query->get('view', ''));
        $evalId = $request->query->get('evaluation');
        $deptId = $request->query->get('department');

        $evaluations = $evalRepo->findAllOrdered();
        $departments = $deptRepo->findAllOrdered();
        $facultyResults = [];

        // SEF view - Superior Evaluations
        if ($view === 'sef' || $view === 'superior') {
            $evaluations = $evalRepo->findBy(['evaluationType' => 'SUPERIOR'], ['startDate' => 'DESC']);
            $qb = $userRepo->createQueryBuilder('u')
                ->where('(u.roles LIKE :facultyRole OR u.roles LIKE :superiorRole)')
                ->andWhere('u.accountStatus = :status')
                ->andWhere('(
                    LOWER(COALESCE(u.employmentStatus, :blank)) LIKE :vicePresident
                    OR LOWER(COALESCE(u.employmentStatus, :blank)) LIKE :president
                    OR LOWER(COALESCE(u.employmentStatus, :blank)) LIKE :director
                    OR LOWER(COALESCE(u.employmentStatus, :blank)) LIKE :dean
                    OR LOWER(COALESCE(u.employmentStatus, :blank)) LIKE :head
                    OR LOWER(COALESCE(u.employmentStatus, :blank)) LIKE :chair
                )')
                ->setParameter('facultyRole', '%ROLE_FACULTY%')
                ->setParameter('superiorRole', '%ROLE_SUPERIOR%')
                ->setParameter('status', 'active')
                ->setParameter('blank', '')
                ->setParameter('vicePresident', '%vice president%')
                ->setParameter('president', '%president%')
                ->setParameter('director', '%director%')
                ->setParameter('dean', '%dean%')
                ->setParameter('head', '%head%')
                ->setParameter('chair', '%chair%')
                ->orderBy('u.lastName', 'ASC');

            if ($deptId) {
                $qb->andWhere('u.department = :did')->setParameter('did', $deptId);
            }

            $headList = $qb->getQuery()->getResult();
            foreach ($headList as $facultyUser) {
                if ($evalId) {
                    $avg = $superiorEvalRepo->getEvaluatorAverage($facultyUser->getId(), (int) $evalId);
                    $count = $superiorEvalRepo->countEvaluateesByEvaluator($facultyUser->getId(), (int) $evalId);
                } else {
                    $avg = $superiorEvalRepo->getEvaluatorAverage($facultyUser->getId());
                    $count = $superiorEvalRepo->countEvaluateesByEvaluator($facultyUser->getId());
                }

                if ($count > 0) {
                    $facultyResults[] = [
                        'faculty' => $facultyUser,
                        'average' => $avg,
                        'evaluators' => $count,
                        'level' => $this->performanceLevel($avg),
                    ];
                }
            }
        }
        // SET view - Faculty Evaluations (default)
        else {
            $evaluations = $evalRepo->findAllOrdered();
            $qb = $userRepo->createQueryBuilder('u')
                ->where('u.roles LIKE :role')
                ->setParameter('role', '%ROLE_FACULTY%')
                ->orderBy('u.lastName', 'ASC');

            if ($deptId) {
                $qb->andWhere('u.department = :did')->setParameter('did', $deptId);
            }

            $facultyList = $qb->getQuery()->getResult();

            foreach ($facultyList as $faculty) {
                if ($evalId) {
                    $avg = $responseRepo->getOverallAverage($faculty->getId(), (int) $evalId);
                    $count = $responseRepo->countEvaluators($faculty->getId(), (int) $evalId);
                } else {
                    $avg = $responseRepo->getOverallAverageAll($faculty->getId());
                    $count = $responseRepo->countEvaluatorsAll($faculty->getId());
                }

                if ($count > 0) {
                    // Get subject+section details
                    $subjectDetails = [];
                    $allSubjects = $responseRepo->getEvaluatedSubjectsWithRating($faculty->getId());
                    foreach ($allSubjects as $subj) {
                        if ($evalId && (int) $subj['evaluationPeriodId'] !== (int) $evalId) {
                            continue;
                        }

                        $subjectDetails[] = [
                            'subjectCode' => $subj['subjectCode'] ?? 'N/A',
                            'subjectName' => $subj['subjectName'] ?? '',
                            'section' => $subj['section'] ?? '—',
                            'average' => round((float) ($subj['avgRating'] ?? 0), 2),
                            'evaluators' => (int) $subj['evaluatorCount'],
                        ];
                    }

                    $facultyResults[] = [
                        'faculty' => $faculty,
                        'average' => $avg,
                        'evaluators' => $count,
                        'level' => $this->performanceLevel($avg),
                        'subjectDetails' => $subjectDetails,
                    ];
                }
            }
        }

        usort($facultyResults, fn($a, $b) => $b['average'] <=> $a['average']);

        $collegeNames = [];
        foreach ($departments as $d) {
            $cn = $d->getCollegeName();
            if ($cn && !in_array($cn, $collegeNames, true)) {
                $collegeNames[] = $cn;
            }
        }
        sort($collegeNames);

        return $this->render('admin/results.html.twig', [
            'evaluations' => $evaluations,
            'departments' => $departments,
            'colleges' => $collegeNames,
            'facultyResults' => $facultyResults,
            'selectedEvaluation' => $evalId,
            'selectedDepartment' => $deptId,
            'resultView' => ($view === 'sef' || $view === 'superior') ? 'sef' : 'set',
            'staffMode' => true,
        ]);
    }

    #[Route('/results/faculty-detail', name: 'staff_results_faculty_detail', methods: ['GET'])]
    public function staffFacultyPrintDetail(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
    ): JsonResponse {
        $evalId = (int) $request->query->get('evaluation', 0);
        $facultyId = (int) $request->query->get('faculty', 0);

        $evaluation = $evalRepo->find($evalId);
        $faculty = $userRepo->find($facultyId);

        if (!$evaluation || !$faculty) {
            return $this->json(['error' => 'Not found'], 404);
        }

        // Per-question averages
        $questionAverages = $responseRepo->getAverageRatingsByFaculty($facultyId, $evalId);
        $questions = $questionRepo->findByType($evaluation->getEvaluationType());

        $questionData = [];
        foreach ($questions as $q) {
            $qId = $q->getId();
            $avgData = $questionAverages[$qId] ?? null;
            $questionData[] = [
                'category' => $q->getCategory(),
                'text' => $q->getQuestionText(),
                'average' => is_array($avgData) ? $avgData['average'] : null,
                'count' => is_array($avgData) ? $avgData['count'] : 0,
            ];
        }

        // Comments
        $comments = $responseRepo->getComments($facultyId, $evalId);
        $filteredComments = array_values(array_filter($comments, fn($c) => trim($c) !== ''));

        // Overall
        $overallAvg = $responseRepo->getOverallAverage($facultyId, $evalId);
        $evaluatorCount = $responseRepo->countEvaluators($facultyId, $evalId);

        return $this->json([
            'faculty' => [
                'name' => $faculty->getFullName(),
                'email' => $faculty->getEmail(),
                'department' => $faculty->getDepartment() ? $faculty->getDepartment()->getDepartmentName() : null,
            ],
            'evaluation' => [
                'type' => $evaluation->getEvaluationType(),
                'semester' => $evaluation->getSemester(),
                'schoolYear' => $evaluation->getSchoolYear(),
            ],
            'overallAverage' => $overallAvg,
            'evaluatorCount' => $evaluatorCount,
            'performanceLevel' => $this->performanceLevel($overallAvg),
            'questions' => $questionData,
            'comments' => $filteredComments,
        ]);
    }

    #[Route('/results/export', name: 'staff_results_export', methods: ['GET'])]
    public function exportResults(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
    ): StreamedResponse {
        $evalId = (int) $request->query->get('evaluation', 0);
        $eval = $evalRepo->find($evalId);

        $this->audit->log(AuditLog::ACTION_EXPORT_REPORT, 'EvaluationPeriod', $evalId,
            'Staff exported evaluation results report');

        $faculty = $userRepo->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_FACULTY%')
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()->getResult();

        $filename = 'Evaluation_Results_' . date('Y-m-d') . '.csv';

        $response = new StreamedResponse(function () use ($faculty, $responseRepo, $evalId, $eval) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['SET-SEF Evaluation Results Report']);
            fputcsv($handle, ['Generated: ' . date('F d, Y h:i A')]);
            if ($eval) {
                fputcsv($handle, ['Period: ' . $eval->getLabel()]);
            }
            fputcsv($handle, []);

            fputcsv($handle, ['Rank', 'Faculty Name', 'Department', 'Average Rating', 'Total Evaluators', 'Performance Level']);

            $rank = 1;
            $results = [];
            foreach ($faculty as $f) {
                $avg = $responseRepo->getOverallAverage($f->getId(), $evalId);
                $count = $responseRepo->countEvaluators($f->getId(), $evalId);
                if ($count > 0) {
                    $results[] = ['faculty' => $f, 'avg' => $avg, 'count' => $count];
                }
            }
            usort($results, fn($a, $b) => $b['avg'] <=> $a['avg']);

            foreach ($results as $r) {
                fputcsv($handle, [
                    $rank++,
                    $r['faculty']->getFullName(),
                    $r['faculty']->getDepartment()?->getDepartmentName() ?? 'N/A',
                    $r['avg'],
                    $r['count'],
                    $this->performanceLevel($r['avg']),
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    // ════════════════════════════════════════════════
    //  STAFF: DEPARTMENTS
    // ════════════════════════════════════════════════

    #[Route('/departments', name: 'staff_departments', methods: ['GET'])]
    public function departments(DepartmentRepository $repo): Response
    {
        return $this->render('admin/departments.html.twig', [
            'departments' => $repo->findAllOrdered(),
            'staffMode' => true,
        ]);
    }

    #[Route('/departments/create', name: 'staff_department_create', methods: ['POST'])]
    public function createDepartment(Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('create_dept', $request->request->get('_token'))) {
            $dept = new Department();
            $dept->setDepartmentName($request->request->get('departmentName', ''));
            $dept->setCollegeName($request->request->get('collegeName'));
            $em->persist($dept);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_DEPARTMENT, 'Department', $dept->getId(),
                'Created department ' . $dept->getDepartmentName());

            $this->addFlash('success', 'Department created.');
        }
        return $this->redirectToRoute('staff_curricula');
    }

    #[Route('/departments/{id}/edit', name: 'staff_department_edit', methods: ['POST'])]
    public function editDepartment(Department $dept, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('edit_dept' . $dept->getId(), $request->request->get('_token'))) {
            $dept->setDepartmentName($request->request->get('departmentName', ''));
            $dept->setCollegeName($request->request->get('collegeName'));
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_DEPARTMENT, 'Department', $dept->getId(),
                'Updated department ' . $dept->getDepartmentName());

            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return $this->json(['success' => true, 'name' => $dept->getDepartmentName()]);
            }

            $this->addFlash('success', 'Department updated.');
        }
        return $this->redirectToRoute('staff_curricula');
    }

    #[Route('/departments/{id}/delete', name: 'staff_department_delete', methods: ['POST'])]
    public function deleteDepartment(Department $dept, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_dept' . $dept->getId(), $request->request->get('_token'))) {
            try {
                $em->remove($dept);
                $em->flush();
                $this->addFlash('success', 'Department deleted.');
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException $e) {
                $this->addFlash('danger', 'Cannot delete this department because it is still assigned to users or other records.');
            }
        }
        return $this->redirectToRoute('staff_curricula');
    }

    // ════════════════════════════════════════════════
    //  STAFF: CURRICULUM MANAGEMENT DASHBOARD
    // ════════════════════════════════════════════════

    #[Route('/curricula', name: 'staff_curricula', methods: ['GET'])]
    public function curricula(
        CurriculumRepository $repo,
        CourseRepository $courseRepo,
        DepartmentRepository $deptRepo,
        SubjectRepository $subjectRepo,
        EvaluationPeriodRepository $evalRepo
    ): Response {
        $departments = $deptRepo->findAllOrdered();

        $collegeNames = [];
        foreach ($departments as $d) {
            $cn = $d->getCollegeName();
            if ($cn && !in_array($cn, $collegeNames, true)) {
                $collegeNames[] = $cn;
            }
        }
        sort($collegeNames);

        return $this->render('admin/curricula.html.twig', [
            'curricula'         => $repo->findAllOrdered(),
            'courses'           => $courseRepo->findAllOrdered(),
            'departments'       => $departments,
            'subjects'          => $subjectRepo->findAll(),
            'evaluationPeriods' => $evalRepo->findAllOrdered(),
            'colleges'          => $collegeNames,
            'staffMode'         => true,
        ]);
    }

    #[Route('/curricula/create', name: 'staff_curriculum_create', methods: ['POST'])]
    public function createCurriculum(Request $request, EntityManagerInterface $em, CourseRepository $courseRepo, DepartmentRepository $deptRepo, SubjectRepository $subjectRepo): Response
    {
        if ($this->isCsrfTokenValid('create_curriculum', $request->request->get('_token'))) {
            $curriculum = new Curriculum();
            $curriculum->setCurriculumName($request->request->get('curriculumName', ''));
            $curriculum->setCurriculumYear($request->request->get('curriculumYear'));
            $curriculum->setDescription($request->request->get('description'));

            $courseId = $request->request->get('course');
            if ($courseId) { $curriculum->setCourse($courseRepo->find($courseId)); }
            $deptId = $request->request->get('department');
            if ($deptId) { $curriculum->setDepartment($deptRepo->find($deptId)); }

            foreach ($request->request->all('subjects') as $sid) {
                $s = $subjectRepo->find($sid);
                if ($s) { $curriculum->addSubject($s); }
            }

            $em->persist($curriculum);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_CURRICULUM, 'Curriculum', $curriculum->getId(),
                'Created curriculum ' . $curriculum->getCurriculumName());
            $this->addFlash('success', 'Program created successfully.');
        }
        return $this->redirectToRoute('staff_curricula');
    }

    #[Route('/curricula/{id}/edit', name: 'staff_curriculum_edit', methods: ['POST'])]
    public function editCurriculum(Curriculum $curriculum, Request $request, EntityManagerInterface $em, CourseRepository $courseRepo, DepartmentRepository $deptRepo, SubjectRepository $subjectRepo): Response
    {
        if ($this->isCsrfTokenValid('edit_curriculum' . $curriculum->getId(), $request->request->get('_token'))) {
            $curriculum->setCurriculumName($request->request->get('curriculumName', ''));
            $curriculum->setCurriculumYear($request->request->get('curriculumYear'));
            $curriculum->setDescription($request->request->get('description'));

            $courseId = $request->request->get('course');
            $curriculum->setCourse($courseId ? $courseRepo->find($courseId) : null);
            $deptId = $request->request->get('department');
            $curriculum->setDepartment($deptId ? $deptRepo->find($deptId) : null);

            foreach ($curriculum->getSubjects()->toArray() as $s) { $curriculum->removeSubject($s); }
            foreach ($request->request->all('subjects') as $sid) {
                $s = $subjectRepo->find($sid);
                if ($s) { $curriculum->addSubject($s); }
            }

            $em->flush();
            $this->addFlash('success', 'Program updated successfully.');
        }
        return $this->redirectToRoute('staff_curricula');
    }

    #[Route('/curricula/{id}/delete', name: 'staff_curriculum_delete', methods: ['POST'])]
    public function deleteCurriculum(Curriculum $curriculum, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_curriculum' . $curriculum->getId(), $request->request->get('_token'))) {
            $name = $curriculum->getCurriculumName();
            $em->remove($curriculum);
            $em->flush();
            $this->addFlash('success', 'Program "' . $name . '" deleted.');
        }
        return $this->redirectToRoute('staff_curricula');
    }

    #[Route('/curricula/{id}/add-subject', name: 'staff_curriculum_add_subject', methods: ['POST'])]
    public function addSubjectToCurriculum(Curriculum $curriculum, Request $request, EntityManagerInterface $em, SubjectRepository $subjectRepo, DepartmentRepository $deptRepo): Response
    {
        if ($this->isCsrfTokenValid('add_subject_curriculum' . $curriculum->getId(), $request->request->get('_token'))) {
            $mode = $request->request->get('mode', 'existing');

            if ($mode === 'existing') {
                $subjectId = $request->request->get('subject_id');
                $subject = $subjectId ? $subjectRepo->find($subjectId) : null;
                if ($subject && !$curriculum->getSubjects()->contains($subject)) {
                    $curriculum->addSubject($subject);
                    $em->flush();
                    $this->addFlash('success', 'Subject "' . $subject->getSubjectCode() . '" added to curriculum.');
                } else {
                    $this->addFlash('warning', 'Subject already in curriculum or not found.');
                }
            } else {
                $subject = new Subject();
                $subject->setSubjectCode($request->request->get('subjectCode', ''));
                $subject->setSubjectName($request->request->get('subjectName', ''));
                $subject->setSemester($request->request->get('semester'));
                $subject->setSchoolYear($request->request->get('schoolYear'));
                $subject->setYearLevel($request->request->get('yearLevel'));
                $unitsVal = $request->request->get('units');
                if ($unitsVal !== null && $unitsVal !== '') { $subject->setUnits((int) $unitsVal); }
                $deptId = $request->request->get('department');
                if ($deptId) { $subject->setDepartment($deptRepo->find($deptId)); }

                $em->persist($subject);
                $curriculum->addSubject($subject);
                $em->flush();
                $this->addFlash('success', 'New subject "' . $subject->getSubjectCode() . '" created and added.');
            }
        }
        return $this->redirectToRoute('staff_curricula');
    }

    #[Route('/curricula/{id}/remove-subject/{subjectId}', name: 'staff_curriculum_remove_subject', methods: ['POST'])]
    public function removeSubjectFromCurriculum(Curriculum $curriculum, int $subjectId, Request $request, EntityManagerInterface $em, SubjectRepository $subjectRepo): Response
    {
        if ($this->isCsrfTokenValid('remove_subject_curriculum' . $curriculum->getId(), $request->request->get('_token'))) {
            $subject = $subjectRepo->find($subjectId);
            if ($subject) {
                $curriculum->removeSubject($subject);
                $em->flush();
                $this->addFlash('success', 'Subject "' . $subject->getSubjectCode() . '" removed from curriculum.');
            }
        }
        return $this->redirectToRoute('staff_curricula');
    }

    #[Route('/subject/{id}/edit', name: 'staff_subject_edit', methods: ['POST'])]
    public function editSubject(Subject $subject, Request $request, EntityManagerInterface $em, DepartmentRepository $deptRepo): Response
    {
        if ($this->isCsrfTokenValid('edit_subject' . $subject->getId(), $request->request->get('_token'))) {
            $subject->setSubjectCode($request->request->get('subjectCode', $subject->getSubjectCode()));
            $subject->setSubjectName($request->request->get('subjectName', $subject->getSubjectName()));
            $subject->setSemester($request->request->get('semester'));
            $subject->setSchoolYear($request->request->get('schoolYear'));
            $subject->setYearLevel($request->request->get('yearLevel'));
            $subject->setSection($request->request->get('section'));
            $subject->setRoom($request->request->get('room'));
            $subject->setSchedule($request->request->get('schedule'));
            $unitsVal = $request->request->get('units');
            $subject->setUnits(($unitsVal !== null && $unitsVal !== '') ? (int) $unitsVal : null);
            $deptId = $request->request->get('department');
            $subject->setDepartment($deptId ? $deptRepo->find($deptId) : null);
            $em->flush();
            $this->addFlash('success', 'Subject "' . $subject->getSubjectCode() . '" updated.');
        }
        return $this->redirectToRoute('staff_curricula');
    }

    // ════════════════════════════════════════════════
    //  STAFF: SUBJECTS
    // ════════════════════════════════════════════════

    #[Route('/subjects', name: 'staff_subjects', methods: ['GET'])]
    public function subjects(Request $request, SubjectRepository $repo, UserRepository $userRepo, DepartmentRepository $deptRepo): Response
    {
        $facultyList = $userRepo->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_FACULTY%')
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()->getResult();

        $filterFaculty  = $request->query->get('faculty');
        $filterDept     = $request->query->get('department');
        $filterSemester = $request->query->get('semester');
        $filterYearLevelRaw = trim((string) $request->query->get('yearLevel', ''));
        $filterYearLevel = match ($filterYearLevelRaw) {
            '1', '1st Year', 'First Year' => '1',
            '2', '2nd Year', 'Second Year' => '2',
            '3', '3rd Year', 'Third Year' => '3',
            '4', '4th Year', 'Fourth Year' => '4',
            '5', '5th Year', 'Fifth Year' => '5',
            default => '',
        };
        $filterTerm     = $request->query->get('term');
        $filterCollege  = $request->query->get('college');
        $page           = max(1, (int) $request->query->get('page', 1));
        $limit          = 50;

        $qb = $repo->createQueryBuilder('s')
            ->leftJoin('s.department', 'd')
            ->orderBy('s.subjectCode', 'ASC');

        if ($filterFaculty) {
            $qb->andWhere('s.faculty = :fid')->setParameter('fid', $filterFaculty);
        }
        if ($filterDept) {
            $qb->andWhere('s.department = :did')->setParameter('did', $filterDept);
        }
        if ($filterSemester) {
            $qb->andWhere('s.semester = :sem')->setParameter('sem', $filterSemester);
        }
        if ($filterYearLevel) {
            $yearLevelAliases = match ($filterYearLevel) {
                '1' => ['1st Year', 'First Year'],
                '2' => ['2nd Year', 'Second Year'],
                '3' => ['3rd Year', 'Third Year'],
                '4' => ['4th Year', 'Fourth Year'],
                '5' => ['5th Year', 'Fifth Year'],
                default => [],
            };
            if (!empty($yearLevelAliases)) {
                $qb->andWhere('s.yearLevel IN (:yearLevels)')->setParameter('yearLevels', $yearLevelAliases);
            }
        }
        if ($filterTerm) {
            $qb->andWhere('s.term = :term')->setParameter('term', $filterTerm);
        }
        if ($filterCollege) {
            $qb->andWhere('d.collegeName = :college')->setParameter('college', $filterCollege);
        }

        $totalFiltered = (int) (clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($totalFiltered / $limit));
        if ($page > $totalPages) { $page = $totalPages; }

        $subjects = $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $departments = $deptRepo->findAllOrdered();
        $colleges = [];
        foreach ($departments as $d) {
            $cn = $d->getCollegeName();
            if ($cn && !in_array($cn, $colleges, true)) {
                $colleges[] = $cn;
            }
        }
        sort($colleges);

        return $this->render('admin/subjects.html.twig', [
            'subjects'        => $subjects,
            'faculty'         => $facultyList,
            'departments'     => $departments,
            'colleges'        => $colleges,
            'filterFaculty'   => $filterFaculty,
            'filterDept'      => $filterDept,
            'filterSemester'  => $filterSemester,
            'filterYearLevel' => $filterYearLevel,
            'filterTerm'      => $filterTerm,
            'filterCollege'   => $filterCollege,
            'currentPage'     => $page,
            'totalPages'      => $totalPages,
            'totalFiltered'   => $totalFiltered,
            'limit'           => $limit,
            'staffMode'       => true,
        ]);
    }

    #[Route('/subjects/create', name: 'staff_subject_create', methods: ['POST'])]
    public function createSubject(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
    ): Response {
        if ($this->isCsrfTokenValid('create_subject', $request->request->get('_token'))) {
            $subject = new Subject();
            $subject->setSubjectCode($request->request->get('subjectCode', ''));
            $subject->setSubjectName($request->request->get('subjectName', ''));
            $subject->setSemester($request->request->get('semester'));
            $subject->setSchoolYear($request->request->get('schoolYear'));
            $subject->setSection($request->request->get('section'));
            $subject->setRoom($request->request->get('room'));
            $subject->setSchedule($request->request->get('schedule'));
            $unitsVal = $request->request->get('units');
            if ($unitsVal !== null && $unitsVal !== '') { $subject->setUnits((int) $unitsVal); }

            $facultyId = $request->request->get('faculty');
            if ($facultyId) {
                $subject->setFaculty($userRepo->find($facultyId));
            }

            $deptId = $request->request->get('department');
            if ($deptId) {
                $subject->setDepartment($deptRepo->find($deptId));
            }

            $em->persist($subject);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_SUBJECT, 'Subject', $subject->getId(),
                'Created subject ' . $subject->getSubjectCode());

            $this->addFlash('success', 'Subject created.');
        }
        return $this->redirectToRoute('staff_subjects');
    }

    #[Route('/subjects/{id}/assign-faculty', name: 'staff_subject_assign_faculty', methods: ['POST'])]
    public function assignFaculty(
        Subject $subject,
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo,
    ): Response {
        if ($this->isCsrfTokenValid('assign' . $subject->getId(), $request->request->get('_token'))) {
            $facultyId = $request->request->get('faculty');
            $faculty = $facultyId ? $userRepo->find($facultyId) : null;
            $subject->setFaculty($faculty);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_ASSIGN_FACULTY, 'Subject', $subject->getId(),
                'Assigned ' . ($faculty ? $faculty->getFullName() : 'none') . ' to ' . $subject->getSubjectCode());

            $this->addFlash('success', 'Faculty assigned.');
        }
        return $this->redirectToRoute('staff_subjects');
    }

    #[Route('/subjects/{id}/delete', name: 'staff_subject_delete', methods: ['POST'])]
    public function deleteSubject(Subject $subject, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_sub' . $subject->getId(), $request->request->get('_token'))) {
            try {
                $em->remove($subject);
                $em->flush();
                $this->addFlash('success', 'Subject deleted.');
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException $e) {
                $this->addFlash('danger', 'Cannot delete this subject because it has other records linked to it.');
            }
        }
        return $this->redirectToRoute('staff_subjects');
    }

    // ════════════════════════════════════════════════
    //  ACADEMIC YEAR MANAGEMENT (Staff)
    // ════════════════════════════════════════════════

    #[Route('/academic-years', name: 'staff_academic_years', methods: ['GET'])]
    public function academicYears(AcademicYearRepository $repo): Response
    {
        return $this->render('admin/academic_years.html.twig', [
            'academicYears' => $repo->findAllOrdered(),
            'currentAY' => $repo->findCurrent(),
            'staffMode' => true,
        ]);
    }

    #[Route('/academic-years/create', name: 'staff_academic_year_create', methods: ['POST'])]
    public function createAcademicYear(Request $request, EntityManagerInterface $em, AcademicYearRepository $repo): Response
    {
        if ($this->isCsrfTokenValid('create_ay', $request->request->get('_token'))) {
            $createSemesterDates = $request->request->getBoolean('createSemesterDates', false);

            if ($createSemesterDates) {
                $yearLabel = trim((string) $request->request->get('yearLabel', ''));
                if ($yearLabel === '') {
                    $this->addFlash('danger', 'Year label is required.');
                    return $this->redirectToRoute('staff_academic_years');
                }

                $termInputs = [
                    ['semester' => '1st Semester', 'start' => $request->request->get('firstStartDate'), 'end' => $request->request->get('firstEndDate')],
                    ['semester' => '2nd Semester', 'start' => $request->request->get('secondStartDate'), 'end' => $request->request->get('secondEndDate')],
                    ['semester' => 'Summer', 'start' => $request->request->get('summerStartDate'), 'end' => $request->request->get('summerEndDate')],
                ];

                $isCurrent = (bool) $request->request->get('isCurrent', false);
                if ($isCurrent) {
                    $repo->clearCurrent();
                }

                $createdLabels = [];
                $skippedLabels = [];

                foreach ($termInputs as $idx => $term) {
                    $existing = $repo->findOneBy([
                        'yearLabel' => $yearLabel,
                        'semester' => $term['semester'],
                    ]);
                    if ($existing) {
                        $skippedLabels[] = $existing->getLabel();
                        continue;
                    }

                    $ay = new AcademicYear();
                    $ay->setYearLabel($yearLabel);
                    $ay->setSemester($term['semester']);
                    $ay->setIsCurrent($isCurrent && $idx === 0);

                    if (!empty($term['start'])) {
                        $ay->setStartDate(new \DateTime((string) $term['start']));
                    }
                    if (!empty($term['end'])) {
                        $ay->setEndDate(new \DateTime((string) $term['end']));
                    }

                    $em->persist($ay);
                    $createdLabels[] = $ay->getLabel();
                }

                if (!empty($createdLabels)) {
                    $em->flush();
                    $this->audit->log('create_academic_year', 'AcademicYear', null,
                        'Created academic year terms: ' . implode(', ', $createdLabels));
                    $this->addFlash('success', 'Created ' . count($createdLabels) . ' term(s): ' . implode(', ', $createdLabels) . '.');
                }

                if (!empty($skippedLabels)) {
                    $this->addFlash('warning', 'Skipped existing term(s): ' . implode(', ', $skippedLabels) . '.');
                }

                if (empty($createdLabels) && empty($skippedLabels)) {
                    $this->addFlash('warning', 'No terms were created.');
                }

                return $this->redirectToRoute('staff_academic_years');
            }

            $autoGenerate = $request->request->getBoolean('autoGenerateNext', false);
            if ($autoGenerate) {
                $next = $repo->getNextAcademicTerm();
                $yearLabel = $next['yearLabel'];
                $semester = $next['semester'];
            } else {
                $yearLabel = trim((string) $request->request->get('yearLabel', ''));
                $semesterRaw = trim((string) $request->request->get('semester', ''));
                $semester = $semesterRaw !== '' ? $semesterRaw : null;
            }

            if ($yearLabel === '') {
                $this->addFlash('danger', 'Year label is required.');
                return $this->redirectToRoute('staff_academic_years');
            }

            $existing = $repo->findOneBy([
                'yearLabel' => $yearLabel,
                'semester' => $semester,
            ]);
            if ($existing) {
                $this->addFlash('warning', 'Academic year "' . $existing->getLabel() . '" already exists.');
                return $this->redirectToRoute('staff_academic_years');
            }

            $ay = new AcademicYear();
            $ay->setYearLabel($yearLabel);
            $ay->setSemester($semester);

            $startDate = $request->request->get('startDate');
            $endDate = $request->request->get('endDate');
            if ($startDate) $ay->setStartDate(new \DateTime($startDate));
            if ($endDate) $ay->setEndDate(new \DateTime($endDate));

            $isCurrent = (bool) $request->request->get('isCurrent', false);
            if ($isCurrent) {
                $repo->clearCurrent();
            }
            $ay->setIsCurrent($isCurrent);

            $em->persist($ay);
            $em->flush();

            $this->audit->log('create_academic_year', 'AcademicYear', $ay->getId(),
                'Created academic year ' . $ay->getLabel());

            $this->addFlash('success', ($autoGenerate ? 'Auto-generated a new term: ' : 'Academic year "') . $ay->getLabel() . ($autoGenerate ? '.' : '" created.'));
        }
        return $this->redirectToRoute('staff_academic_years');
    }

    #[Route('/academic-years/{id}/edit', name: 'staff_academic_year_edit', methods: ['POST'])]
    public function editAcademicYear(AcademicYear $ay, Request $request, EntityManagerInterface $em, AcademicYearRepository $repo): Response
    {
        if ($this->isCsrfTokenValid('edit_ay' . $ay->getId(), $request->request->get('_token'))) {
            $ay->setYearLabel($request->request->get('yearLabel', ''));
            $ay->setSemester($request->request->get('semester'));

            $startDate = $request->request->get('startDate');
            $endDate = $request->request->get('endDate');
            $ay->setStartDate($startDate ? new \DateTime($startDate) : null);
            $ay->setEndDate($endDate ? new \DateTime($endDate) : null);

            $isCurrent = (bool) $request->request->get('isCurrent', false);
            if ($isCurrent) {
                $repo->clearCurrent();
            }
            $ay->setIsCurrent($isCurrent);

            $em->flush();

            $this->audit->log('edit_academic_year', 'AcademicYear', $ay->getId(),
                'Updated academic year ' . $ay->getLabel());

            $this->addFlash('success', 'Academic year updated.');
        }
        return $this->redirectToRoute('staff_academic_years');
    }

    #[Route('/academic-years/{id}/set-current', name: 'staff_academic_year_set_current', methods: ['POST'])]
    public function setCurrentAcademicYear(AcademicYear $ay, Request $request, EntityManagerInterface $em, AcademicYearRepository $repo): Response
    {
        if ($this->isCsrfTokenValid('set_current_ay' . $ay->getId(), $request->request->get('_token'))) {
            $repo->clearCurrent();
            $ay->setIsCurrent(true);
            $em->flush();

            $this->audit->log('set_current_academic_year', 'AcademicYear', $ay->getId(),
                'Set current academic year to ' . $ay->getLabel());

            $this->addFlash('success', '"' . $ay->getLabel() . '" set as current academic year.');
        }
        return $this->redirectToRoute('staff_academic_years');
    }

    #[Route('/academic-years/{id}/delete', name: 'staff_academic_year_delete', methods: ['POST'])]
    public function deleteAcademicYear(AcademicYear $ay, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_ay' . $ay->getId(), $request->request->get('_token'))) {
            $label = $ay->getLabel();
            $em->remove($ay);
            $em->flush();

            $this->audit->log('delete_academic_year', 'AcademicYear', $ay->getId(),
                'Deleted academic year ' . $label);

            $this->addFlash('success', 'Academic year "' . $label . '" deleted.');
        }
        return $this->redirectToRoute('staff_academic_years');
    }

    private function performanceLevel(float $avg): string
    {
        return match (true) {
            $avg >= 4.5 => 'Always manifested',
            $avg >= 3.5 => 'Often manifested',
            $avg >= 2.5 => 'Sometimes manifested',
            $avg >= 1.5 => 'Seldom manifested',
            default => 'Never/Rarely manifested',
        };
    }

    private function saveCorrespondenceRecord(
        EntityManagerInterface $em,
        ?string $correspondenceId,
        string $evaluationType,
        string $printScope,
        ?string $facultyName = null,
    ): ?CorrespondenceRecord {
        $value = trim((string) $correspondenceId);
        if ($value === '') {
            return null;
        }

        $record = (new CorrespondenceRecord())
            ->setCorrespondenceId($value)
            ->setEvaluationType($evaluationType)
            ->setPrintScope($printScope)
            ->setFacultyName($facultyName !== null ? trim($facultyName) : null);

        $currentUser = $this->getUser();
        if ($currentUser instanceof User) {
            $record->setCreatedBy($currentUser);
        }

        $em->persist($record);
        $em->flush();

        return $record;
    }

    private function getCorrespondenceStorageDir(): string
    {
        return rtrim((string) $this->getParameter('kernel.project_dir'), '/\\') . '/public/uploads/correspondence';
    }

    private function buildCorrespondenceArtifactBaseName(CorrespondenceRecord $record): string
    {
        $safeType = strtoupper((string) $record->getEvaluationType()) === 'SEF' ? 'sef' : 'set';
        $safeId = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $record->getCorrespondenceId());
        $safeId = trim($safeId) !== '' ? $safeId : 'record';
        $createdAt = $record->getCreatedAt() ?? new \DateTimeImmutable();
        $recordId = $record->getId() ?? 0;

        return $safeType . '_' . $safeId . '_' . $createdAt->format('Ymd_His') . '_record_' . $recordId;
    }

    /**
     * @return array{pdfAbs: string, htmlAbs: string}
     */
    private function getCorrespondenceArtifactPaths(CorrespondenceRecord $record): array
    {
        $baseName = $this->buildCorrespondenceArtifactBaseName($record);
        $dir = $this->getCorrespondenceStorageDir();

        return [
            'pdfAbs' => $dir . '/' . $baseName . '.pdf',
            'htmlAbs' => $dir . '/' . $baseName . '.html',
        ];
    }

    private function saveCorrespondencePdf(string $html, CorrespondenceRecord $record): bool
    {
        $normalizedHtml = $this->normalizeCorrespondenceHtmlForPdf($html);
        $paths = $this->getCorrespondenceArtifactPaths($record);
        $baseDir = dirname($paths['pdfAbs']);
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            return false;
        }

        @file_put_contents($paths['htmlAbs'], $normalizedHtml, LOCK_EX);

        try {
            $options = new Options();
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($normalizedHtml, 'UTF-8');
            $dompdf->setPaper([0, 0, 612, 936], 'portrait');
            $dompdf->render();

            $pdfOutput = $dompdf->output();
            if ($pdfOutput === '') {
                return false;
            }

            $writtenBytes = @file_put_contents($paths['pdfAbs'], $pdfOutput, LOCK_EX);
            clearstatcache(true, $paths['pdfAbs']);

            return $writtenBytes !== false && $writtenBytes > 0 && is_file($paths['pdfAbs']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeCorrespondenceHtmlForPdf(string $html): string
    {
        // Keep saved-PDF margins consistent with the live print templates.
        $normalized = $html;

        $normalized = $this->embedCorrespondenceLetterheadImage($normalized);

        // Remove browser-only print scripts from stored snapshots.
        $normalized = preg_replace('/<script\b[^>]*>\s*window\.addEventListener\(\s*["\']load["\'][\s\S]*?<\/script>/i', '', $normalized) ?? $normalized;

        $normalized = preg_replace(
            '/@page\s*\{\s*size:\s*8\.5in\s+13in;\s*margin:\s*[^;]+;\s*\}/i',
            '@page { size: 8.5in 13in; margin: 12mm 16mm 12mm 16mm; }',
            $normalized
        ) ?? $normalized;

        $normalized = preg_replace(
            '/body\s*\{([^{}]*?)margin:\s*[^;]+;([^{}]*?)padding:\s*[^;]+;([^{}]*?)\}/is',
            'body {$1margin: 0;$2padding: 0;$3}',
            $normalized
        ) ?? $normalized;

        // Dompdf handles flow layout more reliably than flex + absolute footer for long-paper snapshots.
        $normalized = preg_replace(
            '/\.page-sheet\s*\{[^{}]*\}/is',
            '.page-sheet { position: static; min-height: 0; overflow: visible; page-break-inside: auto; break-inside: auto; }',
            $normalized,
            1
        ) ?? $normalized;

        $normalized = preg_replace(
            '/\.page-main\s*\{[^{}]*\}/is',
            '.page-main { display: block; min-height: 0; padding-bottom: 0; }',
            $normalized,
            1
        ) ?? $normalized;

        $normalized = preg_replace(
            '/\.page-footer\s*\{[^{}]*\}/is',
            '.page-footer { position: static; left: auto; right: auto; bottom: auto; margin-top: 10mm; page-break-inside: auto; break-inside: auto; }',
            $normalized,
            1
        ) ?? $normalized;

        $normalized = preg_replace(
            '/\.disclaimer\s*\{([^{}]*?)margin-top:\s*auto;([^{}]*?)\}/is',
            '.disclaimer {$1margin-top: 8mm;$2}',
            $normalized,
            1
        ) ?? $normalized;

        // Fix legacy saved templates that always rendered a second comments page,
        // even when no actual evaluator comments were present.
        $hasComments = preg_match('/<li\b/i', $normalized) === 1;
        if ($hasComments) {
            return $normalized;
        }

        if (!str_contains($normalized, 'No comments were submitted for this evaluation.')) {
            return $normalized;
        }

        $normalizedNoBlankPage = preg_replace(
            '/<div class="page-break"><\/div>\s*<div class="page-sheet">.*?<\/div>\s*<\/div>\s*(?=<script|<\/body>)/s',
            '',
            $normalized,
            1
        );

        if (is_string($normalizedNoBlankPage) && $normalizedNoBlankPage !== '') {
            $normalizedNoBlankPage = str_replace(
                'Page <strong>1</strong> of <strong>2</strong>',
                'Page <strong>1</strong> of <strong>1</strong>',
                $normalizedNoBlankPage
            );

            return $normalizedNoBlankPage;
        }

        return $normalized;
    }

    private function embedCorrespondenceLetterheadImage(string $html): string
    {
        $projectDir = rtrim((string) $this->getParameter('kernel.project_dir'), '/\\');
        $imagePath = $projectDir . '/public/images/header.jpg';

        if (!is_file($imagePath) || !is_readable($imagePath)) {
            return $html;
        }

        $raw = @file_get_contents($imagePath);
        if (!is_string($raw) || $raw === '') {
            return $html;
        }

        $dataUri = 'data:image/jpeg;base64,' . base64_encode($raw);

        return preg_replace_callback(
            '/<img\b([^>]*?)\bsrc\s*=\s*(["\'])([^"\']*header\.(?:jpg|jpeg))\2([^>]*)>/i',
            static function (array $match) use ($dataUri): string {
                return '<img' . $match[1] . 'src="' . $dataUri . '"' . $match[4] . '>';
            },
            $html
        ) ?? $html;
    }

    // ════════════════════════════════════════════════
    //  FACULTY MESSAGES (Staff)
    // ════════════════════════════════════════════════

    #[Route('/faculty-messages', name: 'staff_faculty_messages', methods: ['GET'])]
    public function facultyMessages(
        EvaluationMessageRepository $msgRepo,
        MessageNotificationRepository $notifRepo,
    ): Response
    {
        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() !== null) {
            try {
                $notifRepo->markAllAsReadForUser($currentUser->getId());
            } catch (\Throwable) {
                // Do not block message page rendering if notification cleanup fails.
            }
        }

        $messages = $msgRepo->findAllMessages();
        $repliesMap = [];
        foreach ($messages as $msg) {
            $repliesMap[$msg->getId()] = $msgRepo->findRepliesForMessage($msg->getId());
        }
        return $this->render('admin/faculty/faculty_messages.html.twig', [
            'messages'     => $messages,
            'repliesMap'   => $repliesMap,
            'pendingCount' => $msgRepo->countPending(),
            'staffMode'    => true,
            'replyRoute'   => 'staff_faculty_message_reply',
            'deleteRoute'  => 'staff_faculty_message_delete',
        ]);
    }

    #[Route('/announcements', name: 'staff_announcements', methods: ['GET'])]
    public function announcements(QuestionCategoryDescriptionRepository $descRepo): Response
    {
        $meta = $descRepo->getSystemAnnouncementMeta();

        return $this->render('admin/system_announcement.html.twig', [
            'title' => $descRepo->getSystemAnnouncementTitle(),
            'body' => $descRepo->getSystemAnnouncementBody(),
            'imageUrl' => $meta['imageUrl'] ?? '',
            'imageUrls' => $meta['imageUrls'] ?? [],
            'metaUpdatedBy' => $meta['updatedBy'] ?? '',
            'metaUpdatedAt' => $meta['updatedAt'] ?? '',
        ]);
    }

    #[Route('/announcements', name: 'staff_announcements_save', methods: ['POST'])]
    public function saveAnnouncements(
        Request $request,
        EntityManagerInterface $em,
        QuestionCategoryDescriptionRepository $descRepo,
        SluggerInterface $slugger,
    ): Response {
        if (!$this->isCsrfTokenValid('save_system_announcement', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid security token.');
            return $this->redirectToRoute('staff_announcements');
        }

        $title = trim((string) $request->request->get('title', ''));
        $body = trim((string) $request->request->get('body', ''));
        $imageUrl = trim((string) $request->request->get('imageUrl', ''));
        $imageUrlsRaw = trim((string) $request->request->get('imageUrls', ''));

        if ($title === '' || $body === '') {
            $this->addFlash('danger', 'Announcement title and content are required.');
            return $this->redirectToRoute('staff_announcements');
        }

        $imageUrls = [];
        if ($imageUrlsRaw !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $imageUrlsRaw) ?: [];
            foreach ($lines as $line) {
                $value = trim((string) $line);
                if ($value !== '') {
                    $imageUrls[] = $value;
                }
            }
        }

        if ($imageUrl !== '') {
            $imageUrls[] = $imageUrl;
        }

        $files = $request->files->all('images');
        if (!is_array($files)) {
            $files = $files ? [$files] : [];
        }

        if (!empty($files)) {
            $projectDir = (string) $this->getParameter('kernel.project_dir');
            $uploadDir = $projectDir . '/public/uploads/announcements';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }

            $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            foreach ($files as $file) {
                if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()) {
                    continue;
                }

                $ext = strtolower((string) $file->guessExtension());
                if ($ext === '' || !in_array($ext, $allowedExt, true)) {
                    continue;
                }

                $base = (string) $slugger->slug(pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME));
                if ($base === '') {
                    $base = 'announcement';
                }

                $newFilename = $base . '-' . uniqid() . '.' . $ext;
                $file->move($uploadDir, $newFilename);
                $imageUrls[] = '/uploads/announcements/' . $newFilename;
            }
        }

        $imageUrls = array_values(array_unique(array_filter(array_map('trim', $imageUrls), static fn($v) => $v !== '')));
        if (count($imageUrls) > 20) {
            $imageUrls = array_slice($imageUrls, 0, 20);
        }

        $titleEntity = $descRepo->findOneBy([
            'category' => QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_TITLE_CATEGORY,
            'evaluationType' => QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_TYPE,
        ]);
        if (!$titleEntity) {
            $titleEntity = new QuestionCategoryDescription();
            $titleEntity->setCategory(QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_TITLE_CATEGORY);
            $titleEntity->setEvaluationType(QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_TYPE);
            $em->persist($titleEntity);
        }
        $titleEntity->setDescription($title);

        $bodyEntity = $descRepo->findOneBy([
            'category' => QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_BODY_CATEGORY,
            'evaluationType' => QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_TYPE,
        ]);
        if (!$bodyEntity) {
            $bodyEntity = new QuestionCategoryDescription();
            $bodyEntity->setCategory(QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_BODY_CATEGORY);
            $bodyEntity->setEvaluationType(QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_TYPE);
            $em->persist($bodyEntity);
        }
        $bodyEntity->setDescription($body);

        $metaEntity = $descRepo->findOneBy([
            'category' => QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_META_CATEGORY,
            'evaluationType' => QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_TYPE,
        ]);
        if (!$metaEntity) {
            $metaEntity = new QuestionCategoryDescription();
            $metaEntity->setCategory(QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_META_CATEGORY);
            $metaEntity->setEvaluationType(QuestionCategoryDescriptionRepository::SYSTEM_ANNOUNCEMENT_TYPE);
            $em->persist($metaEntity);
        }

        $editorName = 'Staff';
        $currentUser = $this->getUser();
        if ($currentUser instanceof User) {
            $editorName = $currentUser->getFullName();
        }

        $metaEntity->setDescription((string) json_encode([
            'updatedBy' => $editorName,
            'updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'imageUrl' => $imageUrls[0] ?? '',
            'imageUrls' => $imageUrls,
        ]));

        $em->flush();
        $this->addFlash('success', 'System announcement updated.');

        return $this->redirectToRoute('staff_announcements');
    }

    #[Route('/faculty-messages/{id}/reply', name: 'staff_faculty_message_reply', methods: ['POST'])]
    public function facultyMessageReply(
        int $id,
        Request $request,
        EvaluationMessageRepository $msgRepo,
        MessageNotificationRepository $notifRepo,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
    ): Response {
        $parentMsg = $msgRepo->find($id);
        if (!$parentMsg) {
            throw $this->createNotFoundException('Message not found.');
        }

        $reply  = trim($request->request->get('reply', ''));
        $status = $request->request->get('status', EvaluationMessage::STATUS_REVIEWED);

        if ($reply) {
            // Create a new message as a reply in the conversation
            $newMsg = new EvaluationMessage();
            $newMsg->setSender($this->getUser());
            $newMsg->setMessage($reply);
            $newMsg->setSenderType('staff');
            $newMsg->setParentMessage($parentMsg);
            $newMsg->setSubject('Re: ' . $parentMsg->getSubject());
            $newMsg->setStatus(EvaluationMessage::STATUS_REVIEWED);
            $newMsg->setCreatedAt(new \DateTime());

            $file = $request->files->get('attachment');
            if ($file) {
                $originalName = $file->getClientOriginalName();
                $safeName = $slugger->slug(pathinfo($originalName, PATHINFO_FILENAME));
                $newFilename = $safeName . '-' . uniqid() . '.' . $file->guessExtension();
                $file->move(
                    $this->getParameter('kernel.project_dir') . '/public/uploads/attachments',
                    $newFilename
                );
                $newMsg->setAttachment($newFilename);
                $newMsg->setAttachmentOriginalName($originalName);
            }

            $em->persist($newMsg);
        }

        // Update parent message status
        if (in_array($status, [EvaluationMessage::STATUS_REVIEWED, EvaluationMessage::STATUS_RESOLVED])) {
            $parentMsg->setStatus($status);
        }

        $em->flush();

        // Notify the faculty member who sent the original message
        if (isset($newMsg) && $parentMsg->getSender()) {
            $notif = new MessageNotification();
            $notif->setNotifiedUser($parentMsg->getSender());
            $notif->setMessage($newMsg);
            $em->persist($notif);
            $em->flush();
        }

        $this->addFlash('success', 'Reply sent successfully.');

        return $this->redirectToRoute('staff_faculty_messages');
    }

    #[Route('/faculty-messages/{id}/delete', name: 'staff_faculty_message_delete', methods: ['POST'])]
    public function facultyMessageDelete(
        int $id,
        Request $request,
        EvaluationMessageRepository $msgRepo,
        EntityManagerInterface $em,
    ): Response {
        $msg = $msgRepo->find($id);
        if (!$msg) {
            throw $this->createNotFoundException('Message not found.');
        }

        if (!$this->isCsrfTokenValid('admin_delete_msg' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('staff_faculty_messages');
        }

        // Delete attachment file if exists
        if ($msg->getAttachment()) {
            $attachPath = $this->getParameter('kernel.project_dir') . '/public/uploads/attachments/' . $msg->getAttachment();
            if (file_exists($attachPath)) {
                unlink($attachPath);
            }
        }

        $em->remove($msg);
        $em->flush();
        $this->addFlash('success', 'Message deleted successfully.');

        return $this->redirectToRoute('staff_faculty_messages');
    }

    // ════════════════════════════════════════════════
    //  QUESTION CATEGORY DESCRIPTION (Staff)
    // ════════════════════════════════════════════════

    #[Route('/questions/category-description', name: 'staff_question_category_description', methods: ['POST'])]
    public function saveCategoryDescription(Request $request, EntityManagerInterface $em, QuestionCategoryDescriptionRepository $descRepo): Response
    {
        $type = $request->request->get('evaluationType', 'SET');
        $category = $request->request->get('category', '');
        $description = $request->request->get('description', '');

        if ($this->isCsrfTokenValid('cat_desc', $request->request->get('_token'))) {
            $entity = $descRepo->findOneBy(['category' => $category, 'evaluationType' => $type]);
            if (!$entity) {
                $entity = new QuestionCategoryDescription();
                $entity->setCategory($category);
                $entity->setEvaluationType($type);
                $em->persist($entity);
            }
            $entity->setDescription($description ?: null);
            $em->flush();
            $this->addFlash('success', 'Section description updated.');
        }
        return $this->redirectToRoute('staff_questions', ['type' => $type]);
    }

    #[Route('/questions/disclaimer', name: 'staff_question_disclaimer', methods: ['POST'])]
    public function saveQuestionnaireDisclaimer(Request $request, EntityManagerInterface $em, QuestionCategoryDescriptionRepository $descRepo): Response
    {
        $type = $request->request->get('evaluationType', 'SET');
        $description = trim((string) $request->request->get('description', ''));

        if ($this->isCsrfTokenValid('question_disclaimer', $request->request->get('_token'))) {
            $entity = $descRepo->findOneBy([
                'category' => QuestionCategoryDescriptionRepository::DISCLAIMER_CATEGORY,
                'evaluationType' => $type,
            ]);

            if (!$entity) {
                $entity = new QuestionCategoryDescription();
                $entity->setCategory(QuestionCategoryDescriptionRepository::DISCLAIMER_CATEGORY);
                $entity->setEvaluationType($type);
                $em->persist($entity);
            }

            $entity->setDescription($description !== '' ? $description : null);
            $em->flush();
            $this->addFlash('success', 'Questionnaire disclaimer updated.');
        }

        return $this->redirectToRoute('staff_questions', ['type' => $type]);
    }

    // ════════════════════════════════════════════════
    //  RESULTS: FACULTY EVALUATIONS (Staff)
    // ════════════════════════════════════════════════

    #[Route('/results/faculty-evaluations', name: 'staff_results_faculty_evaluations', methods: ['GET'])]
    public function facultyEvaluations(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
    ): Response {
        $facultyId = (int) $request->query->get('faculty', 0);
        $faculty = $userRepo->find($facultyId);

        if (!$faculty) {
            throw $this->createNotFoundException('Faculty not found.');
        }

        $currentAY = $ayRepo->findCurrent();
        $evalData = $responseRepo->getEvaluationsByFaculty($facultyId);
        $results = [];
        $totalEvaluators = 0;
        $sumAvg = 0;
        $openEvalIdMap = [];
        foreach ($evalRepo->findOpen() as $openEval) {
            $openEvalIdMap[$openEval->getId()] = true;
        }

        foreach ($evalData as $row) {
            $evalId = (int) $row['evaluationPeriodId'];
            if (!isset($openEvalIdMap[$evalId])) continue;

            $eval = $evalRepo->find($evalId);
            if (!$eval) continue;

            $avg = round((float) $row['avgRating'], 2);
            $count = (int) $row['evaluatorCount'];
            $totalEvaluators += $count;
            $sumAvg += $avg;

            // Get subject+section details for this evaluation period
            $subjectDetails = [];
            $allSubjects = $responseRepo->getEvaluatedSubjectsWithRating($facultyId);
            foreach ($allSubjects as $subj) {
                if ((int) $subj['evaluationPeriodId'] === $evalId) {
                    // Fetch schedule from FacultySubjectLoad
                    $schedule = '—';
                    if ($subj['subjectId']) {
                        $load = $fslRepo->findOneBy([
                            'faculty' => $faculty,
                            'subject' => $subj['subjectId'],
                            'section' => $subj['section'],
                            'academicYear' => $currentAY
                        ]);
                        if ($load) {
                            $schedule = $load->getSchedule() ?? '—';
                        }
                    }

                    $subjectDetails[] = [
                        'subjectId' => (int) $subj['subjectId'],
                        'subjectCode' => $subj['subjectCode'] ?? 'N/A',
                        'subjectName' => $subj['subjectName'] ?? '',
                        'section' => $subj['section'] ?? '—',
                        'schedule' => $schedule,
                        'average' => round((float) ($subj['avgRating'] ?? 0), 2),
                        'evaluators' => (int) $subj['evaluatorCount'],
                    ];
                }
            }

            $results[] = [
                'evaluation' => $eval,
                'average' => $avg,
                'evaluators' => $count,
                'level' => $this->performanceLevel($avg),
                'subjectDetails' => $subjectDetails,
            ];
        }

        $overallAvg = count($results) > 0 ? round($sumAvg / count($results), 2) : 0;

        return $this->render('admin/faculty/faculty_evaluations.html.twig', [
            'faculty' => $faculty,
            'evaluations' => $results,
            'totalEvaluators' => $totalEvaluators,
            'overallAvg' => $overallAvg,
            'staffMode' => true,
        ]);
    }

    #[Route('/results/superior/faculty-evaluations', name: 'staff_results_superior_faculty_evaluations', methods: ['GET'])]
    public function facultyEvaluationsSuperior(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
    ): Response {
        $facultyId = (int) $request->query->get('faculty', 0);
        $faculty = $userRepo->find($facultyId);

        if (!$faculty) {
            throw $this->createNotFoundException('Faculty not found.');
        }

        $evalData = $superiorEvalRepo->getEvaluationsByEvaluator($facultyId);
        $results = [];
        $totalPersonnel = 0;
        $sumAvg = 0;

        foreach ($evalData as $row) {
            $eval = $evalRepo->find((int) $row['evaluationPeriodId']);
            if (!$eval) continue;

            $avg = round((float) $row['avgRating'], 2);
            $count = (int) $row['evaluateeCount'];
            $totalPersonnel += $count;
            $sumAvg += $avg;

            $evaluateeRows = $superiorEvalRepo->getEvaluateesByEvaluator($facultyId, (int) $eval->getId());
            $evaluatedPersonnel = [];
            foreach ($evaluateeRows as $evaluateeRow) {
                $evaluatee = $userRepo->find((int) $evaluateeRow['evaluateeId']);
                if (!$evaluatee) {
                    continue;
                }

                $evaluatedPersonnel[] = [
                    'id' => $evaluatee->getId(),
                    'evaluationId' => $eval->getId(),
                    'name' => $evaluatee->getFullName(),
                    'department' => $evaluatee->getDepartment() ? $evaluatee->getDepartment()->getDepartmentName() : '—',
                    'average' => round((float) $evaluateeRow['avgRating'], 2),
                ];
            }

            $results[] = [
                'evaluation' => $eval,
                'average' => $avg,
                'evaluators' => $count,
                'level' => $this->performanceLevel($avg),
                'evaluatedPersonnel' => $evaluatedPersonnel,
            ];
        }

        $overallAvg = count($results) > 0 ? round($sumAvg / count($results), 2) : 0;

        return $this->render('admin/faculty/faculty_evaluations_superior.html.twig', [
            'faculty' => $faculty,
            'evaluations' => $results,
            'totalPersonnel' => $totalPersonnel,
            'overallAvg' => $overallAvg,
            'staffMode' => true,
        ]);
    }

    #[Route('/results/superior/view-form', name: 'staff_results_superior_view_form', methods: ['GET'])]
    public function resultsSuperiorViewForm(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
        QuestionCategoryDescriptionRepository $descRepo,
    ): Response {
        $evalId = (int) $request->query->get('evaluation', 0);
        $evaluateeId = (int) $request->query->get('faculty', 0);
        $evaluatorId = (int) $request->query->get('evaluator', 0);

        $evaluation = $evalRepo->find($evalId);
        $evaluatee = $userRepo->find($evaluateeId);
        $evaluator = $userRepo->find($evaluatorId);

        if (!$evaluation || !$evaluatee || !$evaluator) {
            throw $this->createNotFoundException('Evaluation record not found.');
        }

        $responses = $superiorEvalRepo->findSubmittedForEvaluatorAndPair($evaluatorId, $evalId, $evaluateeId);
        if (empty($responses)) {
            $this->addFlash('warning', 'No submitted SEF form found for this record.');
            return $this->redirectToRoute('staff_results_superior_faculty_evaluations', ['faculty' => $evaluatorId]);
        }

        $grouped = [];
        $draftMap = [];
        $generalComment = '';
        $submittedAt = $responses[0]->getSubmittedAt();

        foreach ($responses as $response) {
            $question = $response->getQuestion();
            $questionId = $question?->getId();
            if ($questionId === null || isset($draftMap[$questionId])) {
                continue;
            }

            $category = trim((string) $question->getCategory()) !== '' ? (string) $question->getCategory() : 'General';
            $grouped[$category][] = $question;
            $draftMap[$questionId] = [
                'rating' => $response->getRating(),
                'comment' => $response->getComment(),
                'verification' => $response->getVerificationSelections(),
            ];

            if ($generalComment === '' && trim((string) $response->getComment()) !== '') {
                $generalComment = (string) $response->getComment();
            }

            $responseSubmittedAt = $response->getSubmittedAt();
            if ($submittedAt === null || ($responseSubmittedAt !== null && $responseSubmittedAt > $submittedAt)) {
                $submittedAt = $responseSubmittedAt;
            }
        }

        return $this->render('superior/evaluate_form.html.twig', [
            'eval' => $evaluation,
            'evaluatee' => $evaluatee,
            'evaluateeRole' => 'faculty',
            'evaluateeRoleLabel' => 'Faculty',
            'evaluateeSubjects' => [],
            'privacyDisclaimerHtml' => $descRepo->getDisclaimerHtml('SET'),
            'groupedQuestions' => $grouped,
            'draftMap' => $draftMap,
            'generalComment' => $generalComment,
            'readOnly' => true,
            'submittedAt' => $submittedAt,
            'backRouteName' => 'staff_results_superior_faculty_evaluations',
            'backRouteParams' => ['faculty' => $evaluatorId],
            'backLinkLabel' => 'Back to Faculty Results',
            'backButtonLabel' => 'Back to Faculty Results',
        ]);
    }

    // ════════════════════════════════════════════════
    //  RESULTS: PRINT (Staff)
    // ════════════════════════════════════════════════

    #[Route('/results/print/setup', name: 'staff_results_print_setup', methods: ['GET'])]
    public function resultsPrintSetup(Request $request): Response
    {
        return $this->render('report/print_setup.html.twig', [
            'title' => 'Prepare Print Report',
            'printActionUrl' => $this->generateUrl('staff_results_print'),
            'evaluation' => (int) $request->query->get('evaluation', 0),
            'faculty' => (int) $request->query->get('faculty', 0),
            'subject' => $request->query->get('subject'),
            'section' => $request->query->get('section'),
            'correspondenceIdDefault' => trim((string) $request->query->get('correspondenceId', 'DTAL-OTUP-ODQA-F1249-V002')),
            'preparedByDefault' => 'Argie Pair Pagbunocan',
            'preparedTitleDefault' => 'QUAMC Staff',
            'certifiedByDefault' => 'CESAR P. ESTROPE, Ed.D',
            'certifiedTitleDefault' => 'Director, Quality Assurance Management Center',
        ]);
    }

    #[Route('/results/print', name: 'staff_results_print', methods: ['GET', 'POST'])]
    public function resultsPrint(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
        SubjectRepository $subjectRepo,
        EntityManagerInterface $em,
    ): Response {
        $input = $request->isMethod('POST') ? $request->request : $request->query;
        $evalId = (int) $input->get('evaluation', 0);
        $facultyId = (int) $input->get('faculty', 0);
        $subjectId = $input->get('subject') ? (int) $input->get('subject') : null;
        $section = $input->get('section') ?? null;

        $correspondenceId = trim((string) $input->get('correspondenceId', 'DTAL-OTUP-ODQA-F1249-V002'));
        $preparedBy = trim((string) $input->get('preparedBy', 'Argie Pair Pagbunocan'));
        $preparedTitle = trim((string) $input->get('preparedTitle', 'QUAMC Staff'));
        $certifiedBy = trim((string) $input->get('certifiedBy', 'CESAR P. ESTROPE, Ed.D'));
        $certifiedTitle = trim((string) $input->get('certifiedTitle', 'Director, Quality Assurance Management Center'));
        $preparedSignature = (string) $input->get('preparedSignature', '');
        $certifiedSignature = (string) $input->get('certifiedSignature', '');

        $evaluation = $evalRepo->find($evalId);
        $faculty = $userRepo->find($facultyId);

        if (!$evaluation || !$faculty) {
            throw $this->createNotFoundException('Evaluation or faculty not found.');
        }

        // Get question averages grouped by subject and section
        $subjectSectionResults = $responseRepo->getAverageRatingsByFacultyAndSection($facultyId, $evalId, $subjectId, $section);
        $questions = $questionRepo->findByType($evaluation->getEvaluationType());

        // Helper function to calculate category averages for a question averages array
        $calculateCategoryAverages = function($questionAverages) use ($questions) {
            $categoryAverages = [];
            foreach ($questions as $q) {
                $qId = $q->getId();
                $avgData = $questionAverages[$qId] ?? null;
                $avg = is_array($avgData) ? $avgData['average'] : null;
                $cat = $q->getCategory();
                if (!isset($categoryAverages[$cat])) {
                    $categoryAverages[$cat] = ['sum' => 0.0, 'n' => 0, 'questionCount' => 0];
                }
                $categoryAverages[$cat]['questionCount']++;
                if ($avg !== null) {
                    $categoryAverages[$cat]['sum'] += $avg;
                    $categoryAverages[$cat]['n']++;
                }
            }
            return $categoryAverages;
        };

        // Helper function to build category summary from category averages
        $buildCategorySummary = function($categoryAverages) {
            $romNum = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
            $catCount = count($categoryAverages);
            $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
            $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
            $categorySummary = [];
            $compositeTotal = 0.0;
            $idx = 0;
            foreach ($categoryAverages as $cat => $data) {
                $mean = $data['n'] > 0 ? $data['sum'] / $data['n'] : 0;
                $weightedRating = $mean * $weightFrac;
                $compositeTotal += $weightedRating;
                $questionCount = (int) ($data['questionCount'] ?? 0);
                $averageScore = $mean * $questionCount;
                $maxScore = $questionCount * 5;
                $ratingPct = $maxScore > 0 ? ($averageScore / $maxScore) * 100 : 0;
                $categorySummary[] = [
                    'roman' => $romNum[$idx] ?? (string)($idx + 1),
                    'name' => $cat,
                    'mean' => round($mean, 2),
                    'weightPct' => $weightPct,
                    'weightedRating' => round($weightedRating, 2),
                    'questionCount' => $questionCount,
                    'averageScore' => round($averageScore, 2),
                    'maxScore' => $maxScore,
                    'ratingPct' => round($ratingPct, 2),
                ];
                $idx++;
            }
            return [$categorySummary, round($compositeTotal, 2), $weightPct];
        };

        // Build results for each subject/section combination
        $sectionResults = [];
        foreach ($subjectSectionResults as $subjectSection) {
            $categoryAverages = $calculateCategoryAverages($subjectSection['questionAverages']);
            list($categorySummary, $compositeTotal, $weightPct) = $buildCategorySummary($categoryAverages);

            // Calculate overall average for this section
            $sectionOverallAvg = 0.0;
            if (count($subjectSection['questionAverages']) > 0) {
                $sum = 0.0;
                $count = 0;
                foreach ($subjectSection['questionAverages'] as $avgData) {
                    $sum += $avgData['average'];
                    $count++;
                }
                $sectionOverallAvg = $count > 0 ? round($sum / $count, 2) : 0.0;
            }

            // Get evaluator count for this section
            $evaluatorCount = $responseRepo->countEvaluatorsBySubjectAndSection(
                $facultyId,
                $evalId,
                $subjectSection['subjectId'],
                $subjectSection['section']
            );

            // Get comments for this section
            $comments = $responseRepo->getCommentsBySubjectAndSection(
                $facultyId,
                $evalId,
                $subjectSection['subjectId'],
                $subjectSection['section']
            );
            $filteredComments = array_values(array_filter($comments, fn($c) => trim($c) !== ''));

            $sectionResults[] = [
                'subjectCode' => $subjectSection['subjectCode'],
                'subjectName' => $subjectSection['subjectName'],
                'section' => $subjectSection['section'],
                'categorySummary' => $categorySummary,
                'compositeTotal' => $compositeTotal,
                'weightPct' => $weightPct,
                'overallAvg' => $sectionOverallAvg,
                'performanceLevel' => $this->performanceLevel($sectionOverallAvg),
                'evaluatorCount' => $evaluatorCount,
                'comments' => $filteredComments,
            ];
        }

        // For backward compatibility, also pass single section data if filtered
        $firstResult = $sectionResults[0] ?? null;
        $isSingleSection = count($sectionResults) === 1;

        $viewData = [
            'faculty' => $faculty,
            'evaluation' => $evaluation,
            'sectionResults' => $sectionResults,
            'isSingleSection' => $isSingleSection,
            // Backward compatibility fields (for single section)
            'printSubjectCode' => $firstResult ? $firstResult['subjectCode'] : null,
            'printSubjectName' => $firstResult ? $firstResult['subjectName'] : null,
            'printSection' => $firstResult ? $firstResult['section'] : null,
            'categorySummary' => $firstResult ? $firstResult['categorySummary'] : [],
            'compositeTotal' => $firstResult ? $firstResult['compositeTotal'] : 0,
            'performanceLevel' => $firstResult ? $firstResult['performanceLevel'] : '',
            'evaluatorCount' => $firstResult ? $firstResult['evaluatorCount'] : 0,
            'comments' => $firstResult ? $firstResult['comments'] : [],
            'correspondenceId' => $correspondenceId !== '' ? $correspondenceId : 'DTAL-OTUP-ODQA-F1249-V002',
            'preparedBy' => $preparedBy !== '' ? $preparedBy : 'Argie Pair Pagbunocan',
            'preparedTitle' => $preparedTitle !== '' ? $preparedTitle : 'QUAMC Staff',
            'certifiedBy' => $certifiedBy !== '' ? $certifiedBy : 'CESAR P. ESTROPE, Ed.D',
            'certifiedTitle' => $certifiedTitle !== '' ? $certifiedTitle : 'Director, Quality Assurance Management Center',
            'preparedSignature' => $preparedSignature,
            'certifiedSignature' => $certifiedSignature,
        ];

        $html = $this->renderView('report/print_results.html.twig', $viewData);
        $record = $this->saveCorrespondenceRecord(
            $em,
            $correspondenceId,
            (string) $evaluation->getEvaluationType(),
            'set-print',
            method_exists($faculty, 'getFullName') ? (string) $faculty->getFullName() : null
        );
        if ($record instanceof CorrespondenceRecord) {
            $this->saveCorrespondencePdf($html, $record);
        }

        return new Response($html);
    }

    // ════════════════════════════════════════════════
    //  RESULTS: PRINT COMMENTS (Staff)
    // ════════════════════════════════════════════════

    #[Route('/results/print-comments', name: 'staff_results_print_comments', methods: ['GET'])]
    public function resultsPrintComments(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
        SubjectRepository $subjectRepo,
    ): Response {
        $evalId = (int) $request->query->get('evaluation', 0);
        $facultyId = (int) $request->query->get('faculty', 0);
        $subjectId = $request->query->get('subject') ? (int) $request->query->get('subject') : null;
        $section = $request->query->get('section');

        $evaluation = $evalRepo->find($evalId);
        $faculty = $userRepo->find($facultyId);

        if (!$evaluation || !$faculty) {
            throw $this->createNotFoundException('Evaluation or faculty not found.');
        }

        // Get comments filtered by subject and section if provided
        if ($subjectId !== null || $section !== null) {
            $comments = $responseRepo->getCommentsBySubjectAndSection($facultyId, $evalId, $subjectId, $section);
            $evaluatorCount = $responseRepo->countEvaluatorsBySubjectAndSection($facultyId, $evalId, $subjectId, $section);
        } else {
            $comments = $responseRepo->getComments($facultyId, $evalId);
            $evaluatorCount = $responseRepo->countEvaluators($facultyId, $evalId);
        }
        $filteredComments = array_values(array_filter($comments, fn($c) => trim($c) !== ''));

        $questionAverages = [];
        if ($subjectId !== null || $section !== null) {
            $sectionResults = $responseRepo->getAverageRatingsByFacultyAndSection($facultyId, $evalId, $subjectId, $section);
            if (!empty($sectionResults)) {
                $questionAverages = $sectionResults[0]['questionAverages'] ?? [];
            }
        } else {
            $questionAverages = $responseRepo->getAverageRatingsByFaculty($facultyId, $evalId);
        }

        $questions = $questionRepo->findByType($evaluation->getEvaluationType());
        $categoryAverages = [];
        foreach ($questions as $q) {
            $qId = $q->getId();
            $avgData = $questionAverages[$qId] ?? null;
            $qAvg = is_array($avgData) ? $avgData['average'] : null;
            $cat = $q->getCategory();
            if (!isset($categoryAverages[$cat])) {
                $categoryAverages[$cat] = ['sum' => 0.0, 'n' => 0, 'questionCount' => 0];
            }
            $categoryAverages[$cat]['questionCount']++;
            if ($qAvg !== null) {
                $categoryAverages[$cat]['sum'] += $qAvg;
                $categoryAverages[$cat]['n']++;
            }
        }

        $catCount = count($categoryAverages);
        $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
        $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
        $categorySummary = [];
        $compositeTotal = 0.0;
        foreach ($categoryAverages as $cat => $data) {
            $mean = $data['n'] > 0 ? $data['sum'] / $data['n'] : 0;
            $weightedRating = $mean * $weightFrac;
            $compositeTotal += $weightedRating;
            $questionCount = (int) ($data['questionCount'] ?? 0);
            $averageScore = $mean * $questionCount;
            $maxScore = $questionCount * 5;
            $ratingPct = $maxScore > 0 ? ($averageScore / $maxScore) * 100 : 0;
            $categorySummary[] = [
                'roman' => '',
                'name' => $cat,
                'mean' => round($mean, 2),
                'weightPct' => $weightPct,
                'weightedRating' => round($weightedRating, 2),
                'questionCount' => $questionCount,
                'averageScore' => round($averageScore, 2),
                'maxScore' => $maxScore,
                'ratingPct' => round($ratingPct, 2),
            ];
        }

        $sumAvg = 0.0;
        $countAvg = 0;
        foreach ($questionAverages as $avgData) {
            if (is_array($avgData) && isset($avgData['average'])) {
                $sumAvg += (float) $avgData['average'];
                $countAvg++;
            }
        }
        $overallAvg = $countAvg > 0 ? round($sumAvg / $countAvg, 2) : 0.0;

        // Get subject info if specific subject is being printed
        $subjectCode = null;
        if ($subjectId !== null) {
            $subject = $subjectRepo->find($subjectId);
            if ($subject) {
                $subjectCode = $subject->getSubjectCode();
            }
        }

        return $this->render('report/print_comments.html.twig', [
            'faculty' => $faculty,
            'evaluation' => $evaluation,
            'comments' => $filteredComments,
            'evaluatorCount' => $evaluatorCount,
            'printSubjectCode' => $subjectCode,
            'printSection' => $section,
            'categorySummary' => $categorySummary,
            'compositeTotal' => round($compositeTotal, 2),
            'performanceLevel' => $this->performanceLevel($overallAvg),
        ]);
    }

    // ════════════════════════════════════════════════
    //  RESULTS: PRINT ALL (Staff)
    // ════════════════════════════════════════════════

    #[Route('/results/print-all/setup', name: 'staff_results_print_all_setup', methods: ['GET'])]
    public function resultsPrintAllSetup(Request $request): Response
    {
        return $this->render('report/print_setup.html.twig', [
            'title' => 'Prepare Print All Report',
            'printActionUrl' => $this->generateUrl('staff_results_print_all'),
            'evaluation' => 0,
            'faculty' => (int) $request->query->get('faculty', 0),
            'subject' => null,
            'section' => null,
            'selectedEvals' => $request->query->all('evals'),
            'correspondenceIdDefault' => trim((string) $request->query->get('correspondenceId', 'DTAL-OTUP-ODQA-F1249-V002')),
            'preparedByDefault' => 'Argie Pair Pagbunocan',
            'preparedTitleDefault' => 'QUAMC Staff',
            'certifiedByDefault' => 'CESAR P. ESTROPE, Ed.D',
            'certifiedTitleDefault' => 'Director, Quality Assurance Management Center',
        ]);
    }

    #[Route('/results/print-all', name: 'staff_results_print_all', methods: ['GET', 'POST'])]
    public function resultsPrintAll(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
        EntityManagerInterface $em,
    ): Response {
        $input = $request->isMethod('POST') ? $request->request : $request->query;
        $facultyId = (int) $input->get('faculty', 0);
        $faculty = $userRepo->find($facultyId);

        if (!$faculty) {
            throw $this->createNotFoundException('Faculty not found.');
        }

        $correspondenceId = trim((string) $input->get('correspondenceId', 'DTAL-OTUP-ODQA-F1249-V002'));
        $preparedBy = trim((string) $input->get('preparedBy', 'Argie Pair Pagbunocan'));
        $preparedTitle = trim((string) $input->get('preparedTitle', 'QUAMC Staff'));
        $certifiedBy = trim((string) $input->get('certifiedBy', 'CESAR P. ESTROPE, Ed.D'));
        $certifiedTitle = trim((string) $input->get('certifiedTitle', 'Director, Quality Assurance Management Center'));
        $preparedSignature = (string) $input->get('preparedSignature', '');
        $certifiedSignature = (string) $input->get('certifiedSignature', '');

        // Check if specific evaluations were selected
        $selectedEvals = $input->all('evals');
        $selectedEvalIds = array_map('intval', $selectedEvals);

        // ── Get all subject-section evaluations ──
        $subjectEvalData = $responseRepo->getEvaluatedSubjectsWithRating($facultyId);
        $allEvaluations = [];

        foreach ($subjectEvalData as $row) {
            $evalId = (int) $row['evaluationPeriodId'];
            $eval = $evalRepo->find($evalId);
            if (!$eval) continue;

            // Filter by selected evaluations if any were selected
            if (!empty($selectedEvalIds) && !in_array($evalId, $selectedEvalIds)) {
                continue;
            }

            $subjectId = (int) $row['subjectId'];
            $section = $row['section'] ?? null;
            $avg = round((float) $row['avgRating'], 2);
            $count = (int) $row['evaluatorCount'];

            // Category summary for this subject-section
            $sectionResults = $responseRepo->getAverageRatingsByFacultyAndSection($facultyId, $evalId, $subjectId, $section);
            $questionAveragesData = !empty($sectionResults) ? $sectionResults[0]['questionAverages'] : [];

            $questions = $questionRepo->findByType($eval->getEvaluationType());
            $categoryAverages = [];
            foreach ($questions as $q) {
                $qId = $q->getId();
                $avgData = $questionAveragesData[$qId] ?? null;
                $qAvg = is_array($avgData) ? $avgData['average'] : null;
                $cat = $q->getCategory();
                if (!isset($categoryAverages[$cat])) {
                    $categoryAverages[$cat] = ['sum' => 0.0, 'n' => 0, 'questionCount' => 0];
                }
                $categoryAverages[$cat]['questionCount']++;
                if ($qAvg !== null) {
                    $categoryAverages[$cat]['sum'] += $qAvg;
                    $categoryAverages[$cat]['n']++;
                }
            }

            $catCount = count($categoryAverages);
            $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
            $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
            $categorySummary = [];
            $totalAvgScore = 0.0;
            $totalMaxScore = 0.0;
            foreach ($categoryAverages as $cat => $data) {
                $mean = $data['n'] > 0 ? $data['sum'] / $data['n'] : 0;
                $questionCount = (int) ($data['questionCount'] ?? 0);
                $averageScore = $mean * $questionCount;
                $maxScore = $questionCount * 5;
                $ratingPct = $maxScore > 0 ? ($averageScore / $maxScore) * 100 : 0;
                $totalAvgScore += $averageScore;
                $totalMaxScore += $maxScore;
                $categorySummary[] = [
                    'name' => $cat,
                    'mean' => round($averageScore, 2),
                    'rawMean' => round($mean, 2),
                    'weightPct' => $maxScore,
                    'weightedRating' => round($ratingPct, 2),
                ];
            }
            $totalPct = $totalMaxScore > 0 ? round(($totalAvgScore / $totalMaxScore) * 100, 2) : 0.0;
            $pctLevel = match (true) {
                $totalPct >= 91 => 'ALWAYS MANIFESTED',
                $totalPct >= 61 => 'OFTEN MANIFESTED',
                $totalPct >= 31 => 'SOMETIMES MANIFESTED',
                $totalPct >= 11 => 'SELDOM MANIFESTED',
                default => 'NEVER/RARELY MANIFESTED',
            };

            $comments = $responseRepo->getCommentsBySubjectAndSection($facultyId, $evalId, $subjectId, $section);
            $filteredComments = array_values(array_filter($comments, fn($c) => trim($c) !== ''));

            $allEvaluations[] = [
                'evaluation' => $eval,
                'subjectCode' => $row['subjectCode'] ?? 'N/A',
                'subjectName' => $row['subjectName'] ?? 'General',
                'section' => $section ?? '—',
                'average' => $avg,
                'evaluators' => $count,
                'level' => $pctLevel,
                'categorySummary' => $categorySummary,
                'compositeTotal' => $totalPct,
                'comments' => $filteredComments,
                'college' => $eval->getCollege() ?? '',
            ];
        }

        // ── Build composite averages across all evaluations per category ──
        $categoryNames = [];
        $compositeSums = [];
        $compositeCounts = [];

        foreach ($allEvaluations as $item) {
            foreach ($item['categorySummary'] as $cat) {
                $name = $cat['name'];
                if (!in_array($name, $categoryNames, true)) {
                    $categoryNames[] = $name;
                }
                if (!isset($compositeSums[$name])) {
                    $compositeSums[$name] = 0.0;
                    $compositeCounts[$name] = 0;
                }
                $compositeSums[$name] += $cat['rawMean'] ?? $cat['mean'];
                $compositeCounts[$name]++;
            }
        }

        $catCount = count($categoryNames);
        $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
        $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
        $compositeCategories = [];
        $compositeGrandTotal = 0.0;

        foreach ($categoryNames as $name) {
            $wMean = $compositeCounts[$name] > 0
                ? round($compositeSums[$name] / $compositeCounts[$name], 2)
                : 0;
            $wRating = round($wMean * $weightFrac, 2);
            $compositeGrandTotal += $wRating;
            $compositeCategories[] = [
                'name' => $name,
                'weightedMean' => $wMean,
                'weightPct' => $weightPct,
                'weightedRating' => $wRating,
            ];
        }

        // ── Split into Baccalaureate vs Graduate ──
        $baccEvaluations = [];
        $gradEvaluations = [];

        foreach ($allEvaluations as $item) {
            $college = $item['college'] ?? '';
            if (stripos($college, 'Graduate') !== false) {
                $gradEvaluations[] = $item;
            } else {
                $baccEvaluations[] = $item;
            }
        }

        // Build an empty placeholder with the correct category structure
        $emptyCategories = [];
        foreach ($categoryNames as $name) {
            $emptyCategories[] = [
                'name' => $name,
                'mean' => 0.00,
                'rawMean' => 0.00,
                'weightPct' => 0,
                'weightedRating' => 0.00,
            ];
        }
        $emptySlot = [
            'evaluation' => null,
            'subjectCode' => null,
            'subjectName' => null,
            'section' => null,
            'average' => 0.00,
            'evaluators' => 0,
            'level' => 'N/A',
            'categorySummary' => $emptyCategories,
            'compositeTotal' => 0.00,
            'comments' => [],
            'subjectComments' => [],
        ];

        // Pad each group to exactly 7 slots
        while (count($baccEvaluations) < 7) {
            $baccEvaluations[] = $emptySlot;
        }
        while (count($gradEvaluations) < 7) {
            $gradEvaluations[] = $emptySlot;
        }

        // Extract metadata from first evaluation
        $evaluationType = 'SET';
        $semester = '';
        $schoolYear = '';
        if (!empty($allEvaluations)) {
            $first = reset($allEvaluations);
            if ($first['evaluation']) {
                $evaluationType = $first['evaluation']->getEvaluationType() ?? 'SET';
                $semester = $first['evaluation']->getSemester() ?? '';
                $schoolYear = $first['evaluation']->getSchoolYear() ?? '';
            }
        }

        $viewData = [
            'faculty' => $faculty,
            'allEvaluations' => $allEvaluations,
            'baccEvaluations' => $baccEvaluations,
            'gradEvaluations' => $gradEvaluations,
            'categoryNames' => $categoryNames,
            'compositeCategories' => $compositeCategories,
            'compositeGrandTotal' => round($compositeGrandTotal, 2),
            'compositeLevel' => $this->performanceLevel(round($compositeGrandTotal, 2)),
            'evaluationType' => $evaluationType,
            'semester' => $semester,
            'schoolYear' => $schoolYear,
            'correspondenceId' => $correspondenceId !== '' ? $correspondenceId : 'DTAL-OTUP-ODQA-F1249-V002',
            'preparedBy' => $preparedBy !== '' ? $preparedBy : 'Argie Pair Pagbunocan',
            'preparedTitle' => $preparedTitle !== '' ? $preparedTitle : 'QUAMC Staff',
            'certifiedBy' => $certifiedBy !== '' ? $certifiedBy : 'CESAR P. ESTROPE, Ed.D',
            'certifiedTitle' => $certifiedTitle !== '' ? $certifiedTitle : 'Director, Quality Assurance Management Center',
            'preparedSignature' => $preparedSignature,
            'certifiedSignature' => $certifiedSignature,
        ];

        $html = $this->renderView('report/print_all_results.html.twig', $viewData);
        $record = $this->saveCorrespondenceRecord(
            $em,
            $correspondenceId,
            (string) $evaluationType,
            'set-print-all',
            method_exists($faculty, 'getFullName') ? (string) $faculty->getFullName() : null
        );
        if ($record instanceof CorrespondenceRecord) {
            $this->saveCorrespondencePdf($html, $record);
        }

        return new Response($html);
    }

    #[Route('/results/superior/print/setup', name: 'staff_results_superior_print_setup', methods: ['GET'])]
    public function resultsSuperiorPrintSetup(Request $request): Response
    {
        return $this->render('report/print_setup.html.twig', [
            'title' => 'Prepare Superior Print Report',
            'printActionUrl' => $this->generateUrl('staff_results_superior_print'),
            'evaluation' => (int) $request->query->get('evaluation', 0),
            'faculty' => (int) $request->query->get('faculty', 0),
            'subject' => null,
            'section' => null,
            'correspondenceIdDefault' => trim((string) $request->query->get('correspondenceId', 'DTAL-OTUP-ODQA-F1249-V002')),
            'preparedByDefault' => 'Argie Pair Pagbunocan',
            'preparedTitleDefault' => 'QUAMC Staff',
            'certifiedByDefault' => 'CESAR P. ESTROPE, Ed.D',
            'certifiedTitleDefault' => 'Director, Quality Assurance Management Center',
        ]);
    }

    #[Route('/results/superior/print', name: 'staff_results_superior_print', methods: ['GET', 'POST'])]
    public function resultsSuperiorPrint(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
        EntityManagerInterface $em,
    ): Response {
        $input = $request->isMethod('POST') ? $request->request : $request->query;
        $evalId = (int) $input->get('evaluation', 0);
        $facultyId = (int) $input->get('faculty', 0);

        $correspondenceId = trim((string) $input->get('correspondenceId', 'DTAL-OTUP-ODQA-F1249-V002'));
        $preparedBy = trim((string) $input->get('preparedBy', 'Argie Pair Pagbunocan'));
        $preparedTitle = trim((string) $input->get('preparedTitle', 'QUAMC Staff'));
        $certifiedBy = trim((string) $input->get('certifiedBy', 'CESAR P. ESTROPE, Ed.D'));
        $certifiedTitle = trim((string) $input->get('certifiedTitle', 'Director, Quality Assurance Management Center'));
        $preparedSignature = (string) $input->get('preparedSignature', '');
        $certifiedSignature = (string) $input->get('certifiedSignature', '');

        $evaluation = $evalRepo->find($evalId);
        $faculty = $userRepo->find($facultyId);

        if (!$evaluation || !$faculty) {
            throw $this->createNotFoundException('Evaluation or faculty not found.');
        }

        $questionAverages = $superiorEvalRepo->getAverageRatingsByEvaluatee($facultyId, $evalId);
        $questions = $questionRepo->findByType('SEF');

        $questionData = [];
        $categoryAverages = [];
        foreach ($questions as $q) {
            $qId = $q->getId();
            $avgData = $questionAverages[$qId] ?? null;
            $avg = is_array($avgData) ? $avgData['average'] : null;
            $cnt = is_array($avgData) ? $avgData['count'] : 0;
            $questionData[] = [
                'category' => $q->getCategory(),
                'text' => $q->getQuestionText(),
                'average' => $avg,
                'count' => $cnt,
            ];

            $cat = $q->getCategory();
            if (!isset($categoryAverages[$cat])) {
                $categoryAverages[$cat] = ['sum' => 0.0, 'n' => 0];
            }
            if ($avg !== null) {
                $categoryAverages[$cat]['sum'] += $avg;
                $categoryAverages[$cat]['n']++;
            }
        }

        $catCount = count($categoryAverages);
        $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
        $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
        $categorySummary = [];
        $compositeTotal = 0.0;
        $romNum = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
        $idx = 0;
        foreach ($categoryAverages as $cat => $data) {
            $mean = $data['n'] > 0 ? $data['sum'] / $data['n'] : 0;
            $weightedRating = $mean * $weightFrac;
            $compositeTotal += $weightedRating;
            $categorySummary[] = [
                'roman' => $romNum[$idx] ?? (string) ($idx + 1),
                'name' => $cat,
                'mean' => round($mean, 2),
                'weightPct' => $weightPct,
                'weightedRating' => round($weightedRating, 2),
            ];
            $idx++;
        }

        $comments = $superiorEvalRepo->getComments($facultyId, $evalId);
        $filteredComments = array_values(array_filter(
            array_map(fn($c) => $c['comment'], $comments),
            fn($c) => trim($c) !== ''
        ));

        $overallAvg = $superiorEvalRepo->getOverallAverage($facultyId, $evalId);
        $evaluatorCount = $superiorEvalRepo->countEvaluators($facultyId, $evalId);

        $viewData = [
            'faculty' => $faculty,
            'evaluation' => $evaluation,
            'questions' => $questionData,
            'comments' => $filteredComments,
            'overallAverage' => $overallAvg,
            'evaluatorCount' => $evaluatorCount,
            'performanceLevel' => $this->performanceLevel($overallAvg),
            'categorySummary' => $categorySummary,
            'compositeTotal' => round($compositeTotal, 2),
            'weightPct' => $weightPct,
            'correspondenceId' => $correspondenceId !== '' ? $correspondenceId : 'DTAL-OTUP-ODQA-F1249-V002',
            'preparedBy' => $preparedBy !== '' ? $preparedBy : 'Argie Pair Pagbunocan',
            'preparedTitle' => $preparedTitle !== '' ? $preparedTitle : 'QUAMC Staff',
            'certifiedBy' => $certifiedBy !== '' ? $certifiedBy : 'CESAR P. ESTROPE, Ed.D',
            'certifiedTitle' => $certifiedTitle !== '' ? $certifiedTitle : 'Director, Quality Assurance Management Center',
            'preparedSignature' => $preparedSignature,
            'certifiedSignature' => $certifiedSignature,
        ];

        $html = $this->renderView('report/superior/print_results.html.twig', $viewData);
        $record = $this->saveCorrespondenceRecord(
            $em,
            $correspondenceId,
            'SEF',
            'sef-print',
            method_exists($faculty, 'getFullName') ? (string) $faculty->getFullName() : null
        );
        if ($record instanceof CorrespondenceRecord) {
            $this->saveCorrespondencePdf($html, $record);
        }

        return new Response($html);
    }

    #[Route('/results/superior/print-all', name: 'staff_results_superior_print_all', methods: ['GET'])]
    public function resultsSuperiorPrintAll(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
        EntityManagerInterface $em,
    ): Response {
        $facultyId = (int) $request->query->get('faculty', 0);
        $faculty = $userRepo->find($facultyId);

        if (!$faculty) {
            throw $this->createNotFoundException('Faculty not found.');
        }

        $selectedEvals = $request->query->all('evals');
        $correspondenceId = trim((string) $request->query->get('correspondenceId', ''));

        $evalData = $superiorEvalRepo->getEvaluationsByEvaluator($facultyId);
        $allEvaluations = [];

        foreach ($evalData as $row) {
            $eval = $evalRepo->find((int) $row['evaluationPeriodId']);
            if (!$eval) continue;

            if (!empty($selectedEvals) && !in_array((string) $eval->getId(), $selectedEvals, true)) {
                continue;
            }

            $evalId = $eval->getId();
            $avg = round((float) $row['avgRating'], 2);
            $count = (int) $row['evaluateeCount'];

            $questionAverages = $superiorEvalRepo->getAverageRatingsByEvaluator($facultyId, $evalId);
            $questions = $questionRepo->findByType('SEF');
            $categoryAverages = [];
            foreach ($questions as $q) {
                $qId = $q->getId();
                $avgData = $questionAverages[$qId] ?? null;
                $qAvg = is_array($avgData) ? $avgData['average'] : null;
                $cat = $q->getCategory();
                if (!isset($categoryAverages[$cat])) {
                    $categoryAverages[$cat] = ['sum' => 0.0, 'n' => 0];
                }
                if ($qAvg !== null) {
                    $categoryAverages[$cat]['sum'] += $qAvg;
                    $categoryAverages[$cat]['n']++;
                }
            }

            $catCount = count($categoryAverages);
            $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
            $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
            $categorySummary = [];
            $compositeTotal = 0.0;
            foreach ($categoryAverages as $cat => $data) {
                $mean = $data['n'] > 0 ? $data['sum'] / $data['n'] : 0;
                $weightedRating = $mean * $weightFrac;
                $compositeTotal += $weightedRating;
                $categorySummary[] = [
                    'name' => $cat,
                    'mean' => round($mean, 2),
                    'weightPct' => $weightPct,
                    'weightedRating' => round($weightedRating, 2),
                ];
            }

            $comments = $superiorEvalRepo->getCommentsByEvaluator($facultyId, $evalId);
            $filteredComments = array_values(array_filter(
                array_map(fn($c) => $c['comment'], $comments),
                fn($c) => trim($c) !== ''
            ));

            $allEvaluations[] = [
                'evaluation' => $eval,
                'average' => $avg,
                'evaluators' => $count,
                'level' => $this->performanceLevel($avg),
                'categorySummary' => $categorySummary,
                'compositeTotal' => round($compositeTotal, 2),
                'comments' => $filteredComments,
            ];
        }

        // Build composite averages across all evaluations per category
        $categoryNames = [];
        $compositeSums = [];
        $compositeCounts = [];

        foreach ($allEvaluations as $item) {
            foreach ($item['categorySummary'] as $cat) {
                $name = $cat['name'];
                if (!in_array($name, $categoryNames, true)) {
                    $categoryNames[] = $name;
                }
                if (!isset($compositeSums[$name])) {
                    $compositeSums[$name] = 0.0;
                    $compositeCounts[$name] = 0;
                }
                $compositeSums[$name] += $cat['mean'];
                $compositeCounts[$name]++;
            }
        }

        $catCount = count($categoryNames);
        $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
        $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
        $compositeCategories = [];
        $compositeGrandTotal = 0.0;

        foreach ($categoryNames as $name) {
            $wMean = $compositeCounts[$name] > 0
                ? round($compositeSums[$name] / $compositeCounts[$name], 2)
                : 0;
            $wRating = round($wMean * $weightFrac, 2);
            $compositeGrandTotal += $wRating;
            $compositeCategories[] = [
                'name' => $name,
                'weightedMean' => $wMean,
                'weightPct' => $weightPct,
                'weightedRating' => $wRating,
            ];
        }

        // Split into Baccalaureate vs Graduate, pad to 7 each
        $baccEvaluations = [];
        $gradEvaluations = [];

        foreach ($allEvaluations as $item) {
            $college = $item['evaluation']->getCollege() ?? '';
            if (stripos($college, 'Graduate') !== false) {
                $gradEvaluations[] = $item;
            } else {
                $baccEvaluations[] = $item;
            }
        }

        $emptyCategories = [];
        foreach ($categoryNames as $name) {
            $emptyCategories[] = [
                'name' => $name,
                'mean' => 0.00,
                'weightPct' => $catCount > 0 ? round(100 / $catCount) : 0,
                'weightedRating' => 0.00,
            ];
        }
        $emptySlot = [
            'evaluation' => null,
            'average' => 0.00,
            'evaluators' => 0,
            'level' => 'N/A',
            'categorySummary' => $emptyCategories,
            'compositeTotal' => 0.00,
            'comments' => [],
            'subjectComments' => [],
        ];

        while (count($baccEvaluations) < 7) {
            $baccEvaluations[] = $emptySlot;
        }
        while (count($gradEvaluations) < 7) {
            $gradEvaluations[] = $emptySlot;
        }

        $viewData = [
            'faculty' => $faculty,
            'allEvaluations' => $allEvaluations,
            'baccEvaluations' => $baccEvaluations,
            'gradEvaluations' => $gradEvaluations,
            'categoryNames' => $categoryNames,
            'compositeCategories' => $compositeCategories,
            'compositeGrandTotal' => round($compositeGrandTotal, 2),
            'compositeLevel' => $this->performanceLevel(round($compositeGrandTotal, 2)),
            'correspondenceId' => $correspondenceId !== '' ? $correspondenceId : 'DTAL-OTUP-ODQA-F1249-V002',
        ];

        $html = $this->renderView('report/superior/print_all_results.html.twig', $viewData);
        $record = $this->saveCorrespondenceRecord(
            $em,
            $correspondenceId,
            'SEF',
            'sef-print-all',
            method_exists($faculty, 'getFullName') ? (string) $faculty->getFullName() : null
        );
        if ($record instanceof CorrespondenceRecord) {
            $this->saveCorrespondencePdf($html, $record);
        }

        return new Response($html);
    }

    #[Route('/release', name: 'staff_release_files', methods: ['GET'])]
    public function releaseFiles(CorrespondenceRecordRepository $correspondenceRepo): Response
    {
        return $this->render('report/release_files.html.twig', [
            'title' => 'Release Files',
            'subtitle' => 'Released PDF files and release dates.',
            'records' => $correspondenceRepo->findReleased(),
        ]);
    }

    #[Route('/release/faculty', name: 'staff_release_files_faculty', methods: ['GET'])]
    public function releaseFilesFaculty(CorrespondenceRecordRepository $correspondenceRepo): Response
    {
        return $this->render('report/release_files.html.twig', [
            'title' => 'Release Files - Faculty',
            'subtitle' => 'Released SET faculty files and release dates.',
            'records' => $correspondenceRepo->findReleasedByEvaluationType('SET'),
        ]);
    }

    #[Route('/release/superior', name: 'staff_release_files_superior', methods: ['GET'])]
    public function releaseFilesSuperior(CorrespondenceRecordRepository $correspondenceRepo): Response
    {
        return $this->render('report/release_files.html.twig', [
            'title' => 'Release Files - Superior',
            'subtitle' => 'Released SEF superior files and release dates.',
            'records' => $correspondenceRepo->findReleasedByEvaluationType('SEF'),
        ]);
    }

    #[Route('/correspondence/set-id', name: 'staff_correspondence_set_ids', methods: ['GET'])]
    public function correspondenceSetIds(CorrespondenceRecordRepository $correspondenceRepo): Response
    {
        return $this->render('report/correspondence_ids.html.twig', [
            'title' => 'Correspondence - SET ID',
            'idType' => 'SET ID',
            'records' => $correspondenceRepo->findRecentByEvaluationType('SET'),
        ]);
    }

    #[Route('/correspondence/sef-id', name: 'staff_correspondence_sef_ids', methods: ['GET'])]
    public function correspondenceSefIds(CorrespondenceRecordRepository $correspondenceRepo): Response
    {
        return $this->render('report/correspondence_ids.html.twig', [
            'title' => 'Correspondence - SEF ID',
            'idType' => 'SEF ID',
            'records' => $correspondenceRepo->findRecentByEvaluationType('SEF'),
        ]);
    }

    #[Route('/correspondence/delete/{id}', name: 'staff_correspondence_delete', methods: ['POST'])]
    public function correspondenceDelete(Request $request, CorrespondenceRecord $record, EntityManagerInterface $em): Response
    {
        $id = $record->getId() ?? 0;
        $evaluationType = strtoupper((string) $record->getEvaluationType()) === 'SEF' ? 'SEF' : 'SET';

        if (!$this->isCsrfTokenValid('delete_correspondence' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid delete request token.');

            return $this->redirectToRoute($evaluationType === 'SEF' ? 'staff_correspondence_sef_ids' : 'staff_correspondence_set_ids');
        }

        $paths = $this->getCorrespondenceArtifactPaths($record);
        if (is_file($paths['pdfAbs'])) {
            @unlink($paths['pdfAbs']);
        }
        if (is_file($paths['htmlAbs'])) {
            @unlink($paths['htmlAbs']);
        }

        $em->remove($record);
        $em->flush();

        $this->addFlash('success', 'Correspondence record deleted.');

        return $this->redirectToRoute($evaluationType === 'SEF' ? 'staff_correspondence_sef_ids' : 'staff_correspondence_set_ids');
    }

    #[Route('/correspondence/release/{id}', name: 'staff_correspondence_release', methods: ['POST'])]
    public function correspondenceRelease(Request $request, CorrespondenceRecord $record, EntityManagerInterface $em): Response
    {
        $id = $record->getId() ?? 0;
        $evaluationType = strtoupper((string) $record->getEvaluationType()) === 'SEF' ? 'SEF' : 'SET';

        if (!$this->isCsrfTokenValid('release_correspondence' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid release request token.');

            return $this->redirectToRoute($evaluationType === 'SEF' ? 'staff_correspondence_sef_ids' : 'staff_correspondence_set_ids');
        }

        if ($record->isReleased()) {
            $this->addFlash('info', 'This correspondence record is already released.');

            return $this->redirectToRoute($evaluationType === 'SEF' ? 'staff_correspondence_sef_ids' : 'staff_correspondence_set_ids');
        }

        $receivedByName = trim((string) $request->request->get('received_by_name'));
        $releaseDateInput = trim((string) $request->request->get('release_date'));

        if ($receivedByName === '') {
            $this->addFlash('danger', 'Received by name is required.');

            return $this->redirectToRoute($evaluationType === 'SEF' ? 'staff_correspondence_sef_ids' : 'staff_correspondence_set_ids');
        }

        $now = new \DateTimeImmutable();
        $releasedAt = $now;
        if ($releaseDateInput !== '') {
            $releaseDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $releaseDateInput);
            if (!$releaseDate instanceof \DateTimeImmutable) {
                $releaseDate = \DateTimeImmutable::createFromFormat('!d/m/Y', $releaseDateInput);
            }
            if (!$releaseDate instanceof \DateTimeImmutable) {
                $this->addFlash('danger', 'Invalid release date format.');

                return $this->redirectToRoute($evaluationType === 'SEF' ? 'staff_correspondence_sef_ids' : 'staff_correspondence_set_ids');
            }

            $releasedAt = $releaseDate->setTime(
                (int) $now->format('H'),
                (int) $now->format('i'),
                (int) $now->format('s')
            );
        }

        $record->setIsReleased(true);
        $record->setReceivedByName($receivedByName);
        $record->setReleasedAt($releasedAt);

        $user = $this->getUser();
        if ($user instanceof User) {
            $record->setReleasedBy($user);
        }

        $em->flush();

        $this->addFlash('success', 'Correspondence record released.');

        return $this->redirectToRoute($evaluationType === 'SEF' ? 'staff_release_files_superior' : 'staff_release_files_faculty');
    }

    #[Route('/correspondence/file/{id}', name: 'staff_correspondence_file', methods: ['GET'])]
    public function correspondenceFile(CorrespondenceRecord $record): Response
    {
        $safeId = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $record->getCorrespondenceId());
        $fileName = strtolower($record->getEvaluationType()) . '_' . $safeId . '_record_' . $record->getId() . '.pdf';
        $paths = $this->getCorrespondenceArtifactPaths($record);

        $htmlSnapshot = '';
        if (is_file($paths['htmlAbs'])) {
            $htmlSnapshot = trim((string) @file_get_contents($paths['htmlAbs']));
        }

        if ($htmlSnapshot !== '') {
            $normalizedHtml = $this->normalizeCorrespondenceHtmlForPdf($htmlSnapshot);
            if ($this->saveCorrespondencePdf($normalizedHtml, $record) && is_file($paths['pdfAbs'])) {
                return $this->file($paths['pdfAbs'], $fileName, ResponseHeaderBag::DISPOSITION_INLINE);
            }

            $options = new Options();
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($normalizedHtml, 'UTF-8');
            $dompdf->setPaper([0, 0, 612, 936], 'portrait');
            $dompdf->render();

            $response = new Response($dompdf->output());
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', 'inline; filename="' . $fileName . '"');

            return $response;
        }

        if (is_file($paths['pdfAbs'])) {
            return $this->file($paths['pdfAbs'], $fileName, ResponseHeaderBag::DISPOSITION_INLINE);
        }

        $fallbackHtml = '<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans, sans-serif;font-size:12px;padding:20px;}h1{font-size:16px;margin-bottom:12px;}table{border-collapse:collapse;width:100%;}td{border:1px solid #ddd;padding:8px;}td:first-child{width:35%;font-weight:700;background:#f8fafc;}</style></head><body>'
            . '<h1>Correspondence Record</h1>'
            . '<table>'
            . '<tr><td>Record ID</td><td>#' . $record->getId() . '</td></tr>'
            . '<tr><td>Correspondence ID</td><td>' . htmlspecialchars($record->getCorrespondenceId(), ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td>Type</td><td>' . htmlspecialchars($record->getEvaluationType(), ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td>Print Scope</td><td>' . htmlspecialchars($record->getPrintScope() ?: 'print', ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td>Saved By</td><td>' . htmlspecialchars($record->getCreatedBy()?->getFullName() ?? 'System', ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td>Saved At</td><td>' . htmlspecialchars($record->getCreatedAt()?->format('Y-m-d H:i:s') ?? '', ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '</table>'
            . '</body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($fallbackHtml, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $response = new Response($dompdf->output());
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'inline; filename="' . $fileName . '"');

        return $response;
    }
}
