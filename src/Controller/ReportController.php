<?php

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\AuditLog;
use App\Entity\Curriculum;
use App\Entity\Department;
use App\Entity\EvaluationMessage;
use App\Entity\EvaluationPeriod;
use App\Entity\MessageNotification;
use App\Entity\Question;
use App\Entity\QuestionCategoryDescription;
use App\Entity\Subject;
use App\Repository\AcademicYearRepository;
use App\Repository\CourseRepository;
use App\Repository\CurriculumRepository;
use App\Repository\DepartmentRepository;
use App\Repository\EnrollmentRepository;
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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
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
    public function evaluations(EvaluationPeriodRepository $repo, DepartmentRepository $deptRepo, AcademicYearRepository $ayRepo, UserRepository $userRepo, SubjectRepository $subjectRepo, EvaluationResponseRepository $responseRepo, SuperiorEvaluationRepository $superiorEvalRepo, FacultySubjectLoadRepository $fslRepo): Response
    {
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

        $facultyUsers = $userRepo->createQueryBuilder('u')->andWhere("u.roles LIKE :role")->andWhere("u.roles NOT LIKE :superior")->setParameter('role', '%ROLE_FACULTY%')->setParameter('superior', '%ROLE_SUPERIOR%')->orderBy('u.lastName', 'ASC')->getQuery()->getResult();

        $facultyPositionMap = [];
        foreach ($facultyUsers as $fu) {
            $facultyPositionMap[$fu->getFullName()] = $fu->getEmploymentStatus() ?? '';
        }

        $scheduleRows = $this->buildScheduleMergedRows($evaluations, $evaluatorCounts, $userRepo, $fslRepo, $ayRepo);
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
            'currentAY' => $ayRepo->findCurrent(),
            'academicYears' => $ayRepo->findAllOrdered(),
            'facultyUsers' => $facultyUsers,
            'evaluatorCounts' => $evaluatorCounts,
            'facultyPositionMap' => $facultyPositionMap,
            'activeScheduleRows' => $activeScheduleRows,
            'expiredScheduleRows' => $expiredScheduleRows,
            'staffMode' => true,
        ]);
    }

    private function buildScheduleMergedRows(array $evaluations, array $evaluatorCounts, UserRepository $userRepo, FacultySubjectLoadRepository $fslRepo, AcademicYearRepository $ayRepo): array
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
                            $items[] = [
                                'eval' => $eval,
                                'subject' => $subjName,
                                'section' => $loadSection ?: $section,
                                'schedule' => $loadSchedule ?: $schedule,
                                'evaluatorCount' => $baseCount,
                            ];
                        }
                    }
                } else {
                    $items[] = [
                        'eval' => $eval,
                        'subject' => $subject,
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
                        $rows[$idx]['items'][] = [
                            'eval' => $eval,
                            'subject' => $subjName,
                            'section' => $loadSection ?: $section,
                            'schedule' => $loadSchedule ?: $schedule,
                            'evaluatorCount' => $baseCount,
                        ];
                    }
                }
            } else {
                $rows[$idx]['items'][] = [
                    'eval' => $eval,
                    'subject' => $subject,
                    'section' => $section,
                    'schedule' => $schedule,
                    'evaluatorCount' => $baseCount,
                ];
            }

            $rows[$idx]['evaluatorCount'] += $baseCount;
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

    #[Route('/evaluations/create', name: 'staff_evaluation_create', methods: ['POST'])]
    public function createEvaluation(Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('create_eval', $request->request->get('_token'))) {
            $evaluationType = $request->request->get('evaluationType', 'SET');
            $faculty = $request->request->get('faculty');
            $subject = $request->request->get('subject');
            $schoolYear = $request->request->get('schoolYear');
            $section = $request->request->get('section');

            $evalRepo = $em->getRepository(EvaluationPeriod::class);
            $existing = $evalRepo->findDuplicate($evaluationType, $faculty, $subject, $schoolYear, $section);
            if ($existing) {
                $this->addFlash('danger', 'An evaluation period already exists for this faculty, subject, section, and school year.');
                return $this->redirectToRoute('staff_evaluations');
            }

            $eval = new EvaluationPeriod();
            $eval->setEvaluationType($evaluationType);
            $eval->setSchoolYear($schoolYear);
            $sem = $request->request->get('semester');
            $eval->setSemester($sem !== '' ? $sem : null);
            $eval->setFaculty($faculty);
            $eval->setSubject($subject);
            $eval->setTime($request->request->get('time'));
            $eval->setSection($section);
            $eval->setStartDate(new \DateTime($request->request->get('startDate')));
            $eval->setEndDate(new \DateTime($request->request->get('endDate')));
            $eval->setStatus($request->request->getBoolean('status', true));
            $eval->setAnonymousMode($request->request->getBoolean('anonymousMode', true));

            $yl = $request->request->get('yearLevel');
            $eval->setYearLevel($yl !== '' ? $yl : null);

            $deptId = $request->request->get('department');
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

    #[Route('/evaluations/{id}/toggle', name: 'staff_evaluation_toggle', methods: ['POST'])]
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
    public function editEvaluation(EvaluationPeriod $eval, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('edit_eval' . $eval->getId(), $request->request->get('_token'))) {
            $evaluationType = $request->request->get('evaluationType', 'SET');
            $schoolYear = $request->request->get('schoolYear');
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

    // ════════════════════════════════════════════════
    //  STAFF: QUESTIONNAIRE
    // ════════════════════════════════════════════════

    #[Route('/questions', name: 'staff_questions', methods: ['GET'])]
    public function questions(Request $request, QuestionRepository $repo): Response
    {
        $type = $request->query->get('type', 'SET');
        $questions = $repo->findByType($type);
        $categories = $repo->findCategories($type);

        return $this->render('admin/questions.html.twig', [
            'questions' => $questions,
            'categories' => $categories,
            'selectedType' => $type,
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
            $q->setQuestionText($request->request->get('questionText', ''));
            $q->setCategory($request->request->get('category'));
            $q->setEvaluationType($request->request->get('evaluationType', 'SET'));
            $q->setWeight((float) ($request->request->get('weight', 1.0)));
            $q->setSortOrder((int) ($request->request->get('sortOrder', 0)));
            $q->setIsRequired($request->request->getBoolean('isRequired', true));

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

    #[Route('/results', name: 'staff_results', methods: ['GET'])]
    public function results(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
    ): Response {
        $evalId = $request->query->get('evaluation');
        $deptId = $request->query->get('department');

        $evaluations = $evalRepo->findAllOrdered();
        $departments = $deptRepo->findAllOrdered();
        $facultyResults = [];

        if ($evalId) {
            $qb = $userRepo->createQueryBuilder('u')
                ->where('u.roles LIKE :role')
                ->setParameter('role', '%ROLE_FACULTY%')
                ->orderBy('u.lastName', 'ASC');

            if ($deptId) {
                $qb->andWhere('u.department = :did')->setParameter('did', $deptId);
            }

            $facultyList = $qb->getQuery()->getResult();

            foreach ($facultyList as $faculty) {
                $avg = $responseRepo->getOverallAverage($faculty->getId(), (int) $evalId);
                $count = $responseRepo->countEvaluators($faculty->getId(), (int) $evalId);

                if ($count > 0) {
                    $facultyResults[] = [
                        'faculty' => $faculty,
                        'average' => $avg,
                        'evaluators' => $count,
                        'level' => $this->performanceLevel($avg),
                    ];
                }
            }

            usort($facultyResults, fn($a, $b) => $b['average'] <=> $a['average']);
        }

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
        EnrollmentRepository $enrollRepo,
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
            'enrollments'       => $enrollRepo->findAll(),
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
                $this->addFlash('danger', 'Cannot delete this subject because it has enrollments or other records linked to it.');
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
            $ay = new AcademicYear();
            $ay->setYearLabel($request->request->get('yearLabel', ''));
            $ay->setSemester($request->request->get('semester'));

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

            $this->addFlash('success', 'Academic year "' . $ay->getLabel() . '" created.');
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
            $avg >= 4.5 => 'Excellent',
            $avg >= 3.5 => 'Very Good',
            $avg >= 2.5 => 'Good',
            $avg >= 1.5 => 'Fair',
            default => 'Poor',
        };
    }

    // ════════════════════════════════════════════════
    //  FACULTY MESSAGES (Staff)
    // ════════════════════════════════════════════════

    #[Route('/faculty-messages', name: 'staff_faculty_messages', methods: ['GET'])]
    public function facultyMessages(EvaluationMessageRepository $msgRepo): Response
    {
        $messages = $msgRepo->findAllMessages();
        $repliesMap = [];
        foreach ($messages as $msg) {
            $repliesMap[$msg->getId()] = $msgRepo->findRepliesForMessage($msg->getId());
        }
        return $this->render('admin/faculty_messages.html.twig', [
            'messages'     => $messages,
            'repliesMap'   => $repliesMap,
            'pendingCount' => $msgRepo->countPending(),
            'staffMode'    => true,
            'replyRoute'   => 'staff_faculty_message_reply',
            'deleteRoute'  => 'staff_faculty_message_delete',
        ]);
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

    // ════════════════════════════════════════════════
    //  RESULTS: FACULTY EVALUATIONS (Staff)
    // ════════════════════════════════════════════════

    #[Route('/results/faculty-evaluations', name: 'staff_results_faculty_evaluations', methods: ['GET'])]
    public function facultyEvaluations(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
    ): Response {
        $facultyId = (int) $request->query->get('faculty', 0);
        $faculty = $userRepo->find($facultyId);

        if (!$faculty) {
            throw $this->createNotFoundException('Faculty not found.');
        }

        $evalData = $responseRepo->getEvaluationsByFaculty($facultyId);
        $results = [];
        $totalEvaluators = 0;
        $sumAvg = 0;

        foreach ($evalData as $row) {
            $eval = $evalRepo->find((int) $row['evaluationPeriodId']);
            if (!$eval) continue;

            $avg = round((float) $row['avgRating'], 2);
            $count = (int) $row['evaluatorCount'];
            $totalEvaluators += $count;
            $sumAvg += $avg;

            $results[] = [
                'evaluation' => $eval,
                'average' => $avg,
                'evaluators' => $count,
                'level' => $this->performanceLevel($avg),
            ];
        }

        $overallAvg = count($results) > 0 ? round($sumAvg / count($results), 2) : 0;

        return $this->render('admin/faculty_evaluations.html.twig', [
            'faculty' => $faculty,
            'evaluations' => $results,
            'totalEvaluators' => $totalEvaluators,
            'overallAvg' => $overallAvg,
            'staffMode' => true,
        ]);
    }

    // ════════════════════════════════════════════════
    //  RESULTS: PRINT (Staff)
    // ════════════════════════════════════════════════

    #[Route('/results/print', name: 'staff_results_print', methods: ['GET'])]
    public function resultsPrint(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
    ): Response {
        $evalId = (int) $request->query->get('evaluation', 0);
        $facultyId = (int) $request->query->get('faculty', 0);

        $evaluation = $evalRepo->find($evalId);
        $faculty = $userRepo->find($facultyId);

        if (!$evaluation || !$faculty) {
            throw $this->createNotFoundException('Evaluation or faculty not found.');
        }

        $questionAverages = $responseRepo->getAverageRatingsByFaculty($facultyId, $evalId);
        $questions = $questionRepo->findByType($evaluation->getEvaluationType());

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
                'roman' => $romNum[$idx] ?? (string)($idx + 1),
                'name' => $cat,
                'mean' => round($mean, 2),
                'weightPct' => $weightPct,
                'weightedRating' => round($weightedRating, 2),
            ];
            $idx++;
        }

        $comments = $responseRepo->getComments($facultyId, $evalId);
        $filteredComments = array_values(array_filter($comments, fn($c) => trim($c) !== ''));

        $overallAvg = $responseRepo->getOverallAverage($facultyId, $evalId);
        $evaluatorCount = $responseRepo->countEvaluators($facultyId, $evalId);

        return $this->render('report/print_results.html.twig', [
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
        ]);
    }

    // ════════════════════════════════════════════════
    //  RESULTS: PRINT ALL (Staff)
    // ════════════════════════════════════════════════

    #[Route('/results/print-all', name: 'staff_results_print_all', methods: ['GET'])]
    public function resultsPrintAll(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
    ): Response {
        $facultyId = (int) $request->query->get('faculty', 0);
        $faculty = $userRepo->find($facultyId);

        if (!$faculty) {
            throw $this->createNotFoundException('Faculty not found.');
        }

        $selectedEvals = $request->query->all('evals');
        $evalData = $responseRepo->getEvaluationsByFaculty($facultyId);
        $allEvaluations = [];

        foreach ($evalData as $row) {
            $eval = $evalRepo->find((int) $row['evaluationPeriodId']);
            if (!$eval) continue;

            if (!empty($selectedEvals) && !in_array((string) $eval->getId(), $selectedEvals, true)) {
                continue;
            }

            $evalId = $eval->getId();
            $avg = round((float) $row['avgRating'], 2);
            $count = (int) $row['evaluatorCount'];

            $questionAverages = $responseRepo->getAverageRatingsByFaculty($facultyId, $evalId);
            $questions = $questionRepo->findByType($eval->getEvaluationType());
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
            $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
            $catSummary = [];
            $composite = 0.0;
            $romNum = ['I','II','III','IV','V','VI','VII','VIII','IX','X'];
            $idx = 0;
            foreach ($categoryAverages as $cat => $data) {
                $mean = $data['n'] > 0 ? $data['sum'] / $data['n'] : 0;
                $wr = $mean * $weightFrac;
                $composite += $wr;
                $catSummary[] = [
                    'roman' => $romNum[$idx] ?? (string)($idx+1),
                    'name' => $cat,
                    'mean' => round($mean,2),
                    'weightPct' => $catCount > 0 ? round(100/$catCount) : 0,
                    'weightedRating' => round($wr,2),
                ];
                $idx++;
            }

            $comments = $responseRepo->getComments($facultyId, $evalId);
            $filteredComments = array_values(array_filter($comments, fn($c) => trim($c) !== ''));

            $allEvaluations[] = [
                'evaluation' => $eval,
                'average' => $avg,
                'evaluators' => $count,
                'level' => $this->performanceLevel($avg),
                'categorySummary' => $catSummary,
                'compositeTotal' => round($composite,2),
                'comments' => $filteredComments,
            ];
        }

        return $this->render('report/print_all_results.html.twig', [
            'faculty' => $faculty,
            'allEvaluations' => $allEvaluations,
        ]);
    }
}
