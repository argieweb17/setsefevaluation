<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\EvaluationPeriod;
use App\Entity\SuperiorEvaluation;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\FacultySubjectLoadRepository;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;
use App\Repository\SuperiorEvaluationRepository;
use App\Repository\UserRepository;
use App\Repository\DepartmentRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/superior')]
#[IsGranted('ROLE_SUPERIOR')]
class SuperiorController extends AbstractController
{
    public function __construct(private AuditLogger $audit) {}

    #[Route('/evaluate', name: 'superior_evaluate_index', methods: ['GET'])]
    public function evaluateIndex(
        EvaluationPeriodRepository $evalRepo,
        UserRepository $userRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        SubjectRepository $subjectRepo,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
        DepartmentRepository $deptRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $openEvals = [];
        $evaluateesByEval = [];
        $uniqueEvaluatees = [];
        $isDeanEvaluator = $this->isDean($user);

        foreach ($evalRepo->findOpen('SUPERIOR') as $eval) {
            if (!$isDeanEvaluator && $user->getDepartment() && $eval->getDepartment() && $eval->getDepartment()->getId() !== $user->getDepartment()->getId()) {
                continue;
            }

            if (!$this->evaluatorMatchesAssignedFaculty($user, $eval)) {
                continue;
            }

            $evaluatees = $this->buildEvaluateeRowsForEvaluation($eval, $user, $userRepo, $subjectRepo, $fslRepo, $ayRepo);
            $openEvals[] = $eval;
            $evaluateesByEval[$eval->getId()] = $evaluatees;

            foreach ($evaluatees as $item) {
                $uniqueEvaluatees[$item['user']->getId()] = true;
            }
        }

        // Check submission status for each open eval + evaluatee combination
        $submissions = [];
        foreach ($openEvals as $eval) {
            foreach (($evaluateesByEval[$eval->getId()] ?? []) as $e) {
                $key = $eval->getId() . '-' . $e['user']->getId();
                $submissions[$key] = $superiorEvalRepo->hasSubmitted($user->getId(), $eval->getId(), $e['user']->getId());
            }
        }

        return $this->render('superior/evaluate_index.html.twig', [
            'openEvals' => $openEvals,
            'evaluateesByEval' => $evaluateesByEval,
            'totalEvaluatees' => count($uniqueEvaluatees),
            'submissions' => $submissions,
            'departments' => $deptRepo->findAllOrdered(),
        ]);
    }

    #[Route('/evaluate/{evalId}/{evaluateeId}', name: 'superior_evaluate_form', methods: ['GET', 'POST'])]
    public function evaluateForm(
        int $evalId,
        int $evaluateeId,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        UserRepository $userRepo,
        SubjectRepository $subjectRepo,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
        QuestionRepository $questionRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $eval = $evalRepo->find($evalId);
        $evaluatee = $userRepo->find($evaluateeId);

        if (!$eval || !$evaluatee || !$eval->isOpen()) {
            $this->addFlash('danger', 'Invalid evaluation or it is closed.');
            return $this->redirectToRoute('superior_evaluate_index');
        }

        if (!$this->evaluatorMatchesAssignedFaculty($user, $eval)) {
            $this->addFlash('danger', 'You are not assigned to this SEF evaluation period.');
            return $this->redirectToRoute('superior_evaluate_index');
        }

        $evaluatees = $this->buildEvaluateeRowsForEvaluation($eval, $user, $userRepo, $subjectRepo, $fslRepo, $ayRepo);
        $evaluateeMap = [];
        foreach ($evaluatees as $item) {
            $evaluateeMap[$item['user']->getId()] = $item;
        }

        if (!isset($evaluateeMap[$evaluatee->getId()])) {
            $this->addFlash('danger', 'You are not authorized to evaluate this faculty for the selected period.');
            return $this->redirectToRoute('superior_evaluate_index');
        }

        $evaluateeRoleLabel = (string) ($evaluateeMap[$evaluatee->getId()]['label'] ?? 'Faculty Member');
        $evaluateeSubjects = $evaluateeMap[$evaluatee->getId()]['subjects'] ?? [];

        // Determine evaluatee role
        $empStatus = strtolower($evaluatee->getEmploymentStatus() ?? '');
        $evaluateeRole = SuperiorEvaluation::TYPE_DEPARTMENT_HEAD;
        if (str_contains($empStatus, 'dean')) {
            $evaluateeRole = SuperiorEvaluation::TYPE_DEAN;
        }

        // Already submitted?
        if ($superiorEvalRepo->hasSubmitted($user->getId(), $evalId, $evaluateeId)) {
            $this->addFlash('info', 'You have already submitted this evaluation.');
            return $this->redirectToRoute('superior_evaluate_index');
        }

        // Get questions (use SEF type for superior evaluations)
        $questions = $questionRepo->findBy(
            ['evaluationType' => 'SEF', 'isActive' => true],
            ['category' => 'ASC', 'sortOrder' => 'ASC']
        );

        // Load existing drafts
        $drafts = $superiorEvalRepo->findDrafts($user->getId(), $evalId, $evaluateeId);
        $draftMap = [];
        foreach ($drafts as $d) {
            $draftMap[$d->getQuestion()->getId()] = [
                'rating' => $d->getRating(),
                'comment' => $d->getComment(),
            ];
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('_action', 'submit');
            $ratings = $request->request->all('ratings');
            $comments = $request->request->all('comments');
            $generalComment = trim($comments[0] ?? '');
            $commentSaved = false;
            $isDraft = ($action === 'save_draft');

            // Remove existing drafts
            foreach ($drafts as $d) {
                $em->remove($d);
            }
            $em->flush();

            foreach ($questions as $q) {
                $rating = (int) ($ratings[$q->getId()] ?? 0);
                if ($rating < 1 || $rating > 5) {
                    if (!$isDraft) continue;
                    $rating = 0;
                }

                $response = new SuperiorEvaluation();
                $response->setEvaluationPeriod($eval);
                $response->setEvaluator($user);
                $response->setEvaluatee($evaluatee);
                $response->setEvaluateeRole($evaluateeRole);
                $response->setQuestion($q);
                $response->setRating($rating);
                // Attach the general comment to the first response
                if (!$commentSaved && $generalComment !== '') {
                    $response->setComment($generalComment);
                    $commentSaved = true;
                }
                $response->setIsDraft($isDraft);
                $response->setSubmittedAt(new \DateTime());

                $em->persist($response);
            }

            $em->flush();

            if ($isDraft) {
                $this->audit->log(AuditLog::ACTION_SAVE_DRAFT, 'SuperiorEvaluation', $evalId,
                    'Draft saved for ' . $evaluatee->getFullName());
                $this->addFlash('info', 'Draft saved successfully.');
            } else {
                $this->audit->log(AuditLog::ACTION_SUBMIT_SET, 'SuperiorEvaluation', $evalId,
                    'Superior evaluation submitted for ' . $evaluatee->getFullName());
                $this->addFlash('success', 'Evaluation submitted successfully.');
            }

            return $this->redirectToRoute('superior_evaluate_index');
        }

        // Group questions by category
        $grouped = [];
        foreach ($questions as $q) {
            $cat = $q->getCategory() ?? 'General';
            $grouped[$cat][] = $q;
        }

        return $this->render('superior/evaluate_form.html.twig', [
            'eval' => $eval,
            'evaluatee' => $evaluatee,
            'evaluateeRole' => $evaluateeRole,
            'evaluateeRoleLabel' => $evaluateeRoleLabel,
            'evaluateeSubjects' => $evaluateeSubjects,
            'groupedQuestions' => $grouped,
            'draftMap' => $draftMap,
        ]);
    }

    #[Route('/results', name: 'superior_results', methods: ['GET'])]
    public function results(
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
    ): Response {
        $allEvals = $evalRepo->findAllOrdered();
        $selectedEvalId = null;
        $rankings = [];

        if (!empty($allEvals)) {
            $selectedEvalId = $allEvals[0]->getId();
            $rawRankings = $superiorEvalRepo->getEvaluateeRankings($selectedEvalId);

            foreach ($rawRankings as $r) {
                $evaluatee = $userRepo->find($r['evaluateeId']);
                if ($evaluatee) {
                    $avg = round((float) $r['avgRating'], 2);
                    $level = match (true) {
                        $avg >= 4.5 => 'Excellent',
                        $avg >= 3.5 => 'Very Good',
                        $avg >= 2.5 => 'Good',
                        $avg >= 1.5 => 'Fair',
                        default => 'Poor',
                    };
                    $rankings[] = [
                        'evaluatee' => $evaluatee,
                        'average' => $avg,
                        'evaluators' => (int) $r['evaluatorCount'],
                        'level' => $level,
                    ];
                }
            }
        }

        return $this->render('superior/results.html.twig', [
            'allEvals' => $allEvals,
            'selectedEvalId' => $selectedEvalId,
            'rankings' => $rankings,
            'departments' => $deptRepo->findAllOrdered(),
        ]);
    }

    #[Route('/results/detail', name: 'superior_results_detail', methods: ['GET'])]
    public function resultsDetail(
        Request $request,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
    ): JsonResponse {
        $evalId = (int) $request->query->get('evalId');
        $evaluateeId = (int) $request->query->get('evaluateeId');

        $evaluatee = $userRepo->find($evaluateeId);
        if (!$evaluatee) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $catAvgs = $superiorEvalRepo->getCategoryAverages($evaluateeId, $evalId);
        $comments = $superiorEvalRepo->getComments($evaluateeId, $evalId);
        $overall = $superiorEvalRepo->getOverallAverage($evaluateeId, $evalId);

        return $this->json([
            'evaluatee' => $evaluatee->getFullName(),
            'department' => $evaluatee->getDepartment() ? $evaluatee->getDepartment()->getDepartmentName() : '—',
            'overall' => $overall,
            'categories' => $catAvgs,
            'comments' => array_column($comments, 'comment'),
        ]);
    }

    /**
     * Build evaluatees for one open SEF period.
     *
     * Staff may create a department-wide SEF period. A superior then evaluates
     * co-faculty in that department (or in their own department for global periods).
     *
     * @return array<int, array{user: User, type: string, label: string, subjects: array<int, array{code: string, name: string, semester: string, section: string, schedule: string}>, subjectCount: int, subjectPreview: string}>
     */
    private function buildEvaluateeRowsForEvaluation(
        EvaluationPeriod $eval,
        User $evaluator,
        UserRepository $userRepo,
        SubjectRepository $subjectRepo,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
    ): array {
        $isDeanEvaluator = $this->isDean($evaluator);

        if ($isDeanEvaluator) {
            // Dean scope: evaluate department heads/chairs across departments.
            $facultyUsers = $userRepo->createQueryBuilder('u')
                ->where('u.roles LIKE :role')
                ->andWhere('u.accountStatus = :status')
                ->andWhere('(LOWER(COALESCE(u.employmentStatus, :blank)) LIKE :head OR LOWER(COALESCE(u.employmentStatus, :blank)) LIKE :chair)')
                ->setParameter('role', '%ROLE_FACULTY%')
                ->setParameter('status', 'active')
                ->setParameter('blank', '')
                ->setParameter('head', '%head%')
                ->setParameter('chair', '%chair%')
                ->orderBy('u.lastName', 'ASC')
                ->addOrderBy('u.firstName', 'ASC')
                ->getQuery()
                ->getResult();

            $rows = [];
            foreach ($facultyUsers as $faculty) {
                if (!$faculty instanceof User) {
                    continue;
                }

                if ($faculty->getId() === $evaluator->getId()) {
                    continue;
                }

                $subjects = $this->resolveFacultySubjects($faculty, $subjectRepo, $fslRepo, $ayRepo);
                $previewItems = array_map(static fn(array $s): string => $s['code'] . ($s['section'] !== '' ? ' (' . $s['section'] . ')' : ''), array_slice($subjects, 0, 3));

                $rows[] = [
                    'user' => $faculty,
                    'type' => SuperiorEvaluation::TYPE_DEPARTMENT_HEAD,
                    'label' => 'Department Head',
                    'subjects' => $subjects,
                    'subjectCount' => count($subjects),
                    'subjectPreview' => !empty($previewItems)
                        ? implode(', ', $previewItems) . (count($subjects) > 3 ? ' +' . (count($subjects) - 3) . ' more' : '')
                        : '',
                ];
            }

            return $rows;
        }

        $targetDept = $eval->getDepartment() ?? $evaluator->getDepartment();
        if (!$targetDept) {
            return [];
        }

        $facultyUsers = $userRepo->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->andWhere('u.accountStatus = :status')
            ->andWhere('u.department = :dept')
            ->setParameter('role', '%ROLE_FACULTY%')
            ->setParameter('status', 'active')
            ->setParameter('dept', $targetDept)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        $rows = [];

        foreach ($facultyUsers as $faculty) {
            if (!$faculty instanceof User) {
                continue;
            }

            if ($faculty->getId() === $evaluator->getId()) {
                continue;
            }

            $subjects = $this->resolveFacultySubjects($faculty, $subjectRepo, $fslRepo, $ayRepo);
            $previewItems = array_map(static fn(array $s): string => $s['code'] . ($s['section'] !== '' ? ' (' . $s['section'] . ')' : ''), array_slice($subjects, 0, 3));

            $rows[] = [
                'user' => $faculty,
                'type' => $this->resolveEvaluateeType($faculty),
                'label' => $this->resolveEvaluateeLabel($faculty),
                'subjects' => $subjects,
                'subjectCount' => count($subjects),
                'subjectPreview' => !empty($previewItems)
                    ? implode(', ', $previewItems) . (count($subjects) > 3 ? ' +' . (count($subjects) - 3) . ' more' : '')
                    : '',
            ];
        }

        return $rows;
    }

    private function evaluatorMatchesAssignedFaculty(User $evaluator, EvaluationPeriod $eval): bool
    {
        if ($this->isDean($evaluator)) {
            // Dean scope should not be blocked by per-faculty assignment.
            return true;
        }

        $assignedEvaluator = trim((string) ($eval->getFaculty() ?? ''));
        if ($assignedEvaluator === '') {
            // Blank assignment means any department head/chair in scope may evaluate.
            return true;
        }

        return $this->facultyMatchesName($evaluator, $assignedEvaluator);
    }

    private function facultyMatchesName(User $faculty, string $needle): bool
    {
        $normalizedNeedle = mb_strtolower(trim($needle));
        $full = mb_strtolower(trim($faculty->getFullName()));
        $lastFirst = mb_strtolower(trim($faculty->getLastName() . ', ' . $faculty->getFirstName()));

        return $normalizedNeedle === $full || $normalizedNeedle === $lastFirst;
    }

    private function resolveEvaluateeType(User $evaluatee): string
    {
        $status = mb_strtolower(trim((string) $evaluatee->getEmploymentStatus()));
        return str_contains($status, 'dean') ? SuperiorEvaluation::TYPE_DEAN : SuperiorEvaluation::TYPE_DEPARTMENT_HEAD;
    }

    private function isDean(User $user): bool
    {
        $status = mb_strtolower(trim((string) $user->getEmploymentStatus()));
        return str_contains($status, 'dean');
    }

    private function resolveEvaluateeLabel(User $evaluatee): string
    {
        $status = trim((string) $evaluatee->getEmploymentStatus());
        if ($status === '') {
            return 'Faculty Member';
        }

        $normalized = mb_strtolower($status);
        if (str_contains($normalized, 'dean')) {
            return 'Dean';
        }
        if (str_contains($normalized, 'head') || str_contains($normalized, 'chair')) {
            return 'Department Head';
        }

        return $status;
    }

    /**
     * @return array<int, array{code: string, name: string, semester: string, section: string, schedule: string}>
     */
    private function resolveFacultySubjects(
        User $faculty,
        SubjectRepository $subjectRepo,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
    ): array {
        $currentAY = $ayRepo->findCurrent();
        $loads = $fslRepo->findByFacultyAndAcademicYear($faculty->getId(), $currentAY ? $currentAY->getId() : null);

        $rows = [];
        $seen = [];

        foreach ($loads as $load) {
            $subject = $load->getSubject();
            if (!$subject) {
                continue;
            }

            $section = strtoupper(trim((string) ($load->getSection() ?? '')));
            $schedule = trim((string) ($load->getSchedule() ?? ''));
            $key = mb_strtolower((string) $subject->getSubjectCode() . '|' . $section . '|' . $schedule);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = [
                'code' => (string) $subject->getSubjectCode(),
                'name' => (string) $subject->getSubjectName(),
                'semester' => (string) ($subject->getSemester() ?? ''),
                'section' => $section,
                'schedule' => $schedule,
            ];
        }

        if (!empty($rows)) {
            return $rows;
        }

        foreach ($subjectRepo->findByFaculty($faculty->getId()) as $subject) {
            $section = strtoupper(trim((string) ($subject->getSection() ?? '')));
            $schedule = trim((string) ($subject->getSchedule() ?? ''));
            $key = mb_strtolower((string) $subject->getSubjectCode() . '|' . $section . '|' . $schedule);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = [
                'code' => (string) $subject->getSubjectCode(),
                'name' => (string) $subject->getSubjectName(),
                'semester' => (string) ($subject->getSemester() ?? ''),
                'section' => $section,
                'schedule' => $schedule,
            ];
        }

        return $rows;
    }
}
