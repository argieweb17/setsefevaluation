<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\EvaluationPeriod;
use App\Entity\Question;
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

    private const RANK_PRESIDENT = 'president';
    private const RANK_VICE_PRESIDENT = 'vice_president';
    private const RANK_CAMPUS_DIRECTOR = 'campus_director';
    private const RANK_DEAN = 'dean';
    private const RANK_DEPARTMENT_HEAD = 'department_head';

    #[Route('/evaluate', name: 'superior_evaluate_index', methods: ['GET'])]
    public function evaluateIndex(
        Request $request,
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
        $this->assertDepartmentHeadSuperior($user);
        $openEvals = [];
        $evaluateesByEval = [];
        $uniqueEvaluatees = [];

        foreach ($evalRepo->findOpen('SUPERIOR') as $eval) {
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

        // Submitted evaluations history (filterable by academic year)
        $selectedHistoryYear = trim((string) $request->query->get('schoolYear', ''));
        $historyResponses = $superiorEvalRepo->findSubmittedByEvaluator(
            $user->getId(),
            $selectedHistoryYear !== '' ? $selectedHistoryYear : null
        );

        $historyMap = [];
        foreach ($historyResponses as $response) {
            $period = $response->getEvaluationPeriod();
            $evaluatee = $response->getEvaluatee();
            $key = $period->getId() . '-' . $evaluatee->getId();

            if (!isset($historyMap[$key])) {
                $historyMap[$key] = [
                    'evaluation' => $period,
                    'evaluatee' => $evaluatee,
                    'evaluateeLabel' => $this->resolveEvaluateeLabel($evaluatee),
                    'submittedAt' => $response->getSubmittedAt(),
                    'ratingSum' => 0,
                    'ratingCount' => 0,
                ];
            }

            $historyMap[$key]['ratingSum'] += $response->getRating();
            $historyMap[$key]['ratingCount']++;

            if ($response->getSubmittedAt() > $historyMap[$key]['submittedAt']) {
                $historyMap[$key]['submittedAt'] = $response->getSubmittedAt();
            }
        }

        $historyItems = [];
        foreach ($historyMap as $row) {
            $historyItems[] = [
                'evaluation' => $row['evaluation'],
                'evaluatee' => $row['evaluatee'],
                'evaluateeLabel' => $row['evaluateeLabel'],
                'submittedAt' => $row['submittedAt'],
                'averageRating' => $row['ratingCount'] > 0 ? round($row['ratingSum'] / $row['ratingCount'], 2) : 0.0,
            ];
        }

        usort($historyItems, static function (array $a, array $b): int {
            return $b['submittedAt'] <=> $a['submittedAt'];
        });

        $historyYears = $superiorEvalRepo->findSubmittedSchoolYearsByEvaluator($user->getId());

        return $this->render('superior/evaluate_index.html.twig', [
            'openEvals' => $openEvals,
            'evaluateesByEval' => $evaluateesByEval,
            'totalEvaluatees' => count($uniqueEvaluatees),
            'submissions' => $submissions,
            'historyItems' => $historyItems,
            'historyYears' => $historyYears,
            'selectedHistoryYear' => $selectedHistoryYear,
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
        $this->assertDepartmentHeadSuperior($user);
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

        $evaluateeRole = (string) ($evaluateeMap[$evaluatee->getId()]['type'] ?? SuperiorEvaluation::TYPE_DEPARTMENT_HEAD);

        // Already submitted?
        if ($superiorEvalRepo->hasSubmitted($user->getId(), $evalId, $evaluateeId)) {
            $this->addFlash('info', 'You have already submitted this evaluation.');
            return $this->redirectToRoute('superior_evaluate_view', [
                'evalId' => $evalId,
                'evaluateeId' => $evaluateeId,
            ]);
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
                'verification' => $d->getVerificationSelections(),
            ];
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('_action', 'submit');
            $ratings = $request->request->all('ratings');
            $verifications = $request->request->all('verification');
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
                $response->setVerificationSelections(
                    $this->sanitizeVerificationSelections($q, $verifications[$q->getId()] ?? [])
                );
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
                return $this->redirectToRoute('superior_evaluate_index');
            } else {
                $this->audit->log(AuditLog::ACTION_SUBMIT_SET, 'SuperiorEvaluation', $evalId,
                    'Superior evaluation submitted for ' . $evaluatee->getFullName());
                $this->addFlash('success', 'Evaluation submitted successfully.');
                return $this->redirectToRoute('superior_evaluate_view', [
                    'evalId' => $evalId,
                    'evaluateeId' => $evaluateeId,
                ]);
            }
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
            'generalComment' => '',
            'readOnly' => false,
        ]);
    }

    #[Route('/evaluate/{evalId}/{evaluateeId}/view', name: 'superior_evaluate_view', methods: ['GET'])]
    public function evaluateView(
        int $evalId,
        int $evaluateeId,
        EvaluationPeriodRepository $evalRepo,
        UserRepository $userRepo,
        SubjectRepository $subjectRepo,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertDepartmentHeadSuperior($user);
        $eval = $evalRepo->find($evalId);
        $evaluatee = $userRepo->find($evaluateeId);

        if (!$eval || !$evaluatee) {
            $this->addFlash('danger', 'Invalid evaluation request.');
            return $this->redirectToRoute('superior_evaluate_index');
        }

        $responses = $superiorEvalRepo->findSubmittedForEvaluatorAndPair($user->getId(), $evalId, $evaluateeId);
        if (empty($responses)) {
            $this->addFlash('warning', 'No submitted evaluation found for this record.');
            return $this->redirectToRoute('superior_evaluate_index');
        }

        $evaluateeSubjects = $this->resolveFacultySubjects($evaluatee, $subjectRepo, $fslRepo, $ayRepo);

        $grouped = [];
        $draftMap = [];
        $generalComment = '';
        $submittedAt = $responses[0]->getSubmittedAt();

        foreach ($responses as $response) {
            $question = $response->getQuestion();
            $questionId = $question->getId();
            if ($questionId === null || isset($draftMap[$questionId])) {
                continue;
            }
            $category = $question->getCategory() ?? 'General';
            $grouped[$category][] = $question;
            $draftMap[$questionId] = [
                'rating' => $response->getRating(),
                'comment' => $response->getComment(),
                'verification' => $response->getVerificationSelections(),
            ];

            if ($generalComment === '' && trim((string) $response->getComment()) !== '') {
                $generalComment = (string) $response->getComment();
            }

            if ($response->getSubmittedAt() > $submittedAt) {
                $submittedAt = $response->getSubmittedAt();
            }
        }

        return $this->render('superior/evaluate_form.html.twig', [
            'eval' => $eval,
            'evaluatee' => $evaluatee,
            'evaluateeRole' => $this->resolveEvaluateeType($evaluatee),
            'evaluateeRoleLabel' => $this->resolveEvaluateeLabel($evaluatee),
            'evaluateeSubjects' => $evaluateeSubjects,
            'groupedQuestions' => $grouped,
            'draftMap' => $draftMap,
            'generalComment' => $generalComment,
            'readOnly' => true,
            'submittedAt' => $submittedAt,
        ]);
    }

    #[Route('/results', name: 'superior_results', methods: ['GET'])]
    public function results(
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertDepartmentHeadSuperior($user);

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
        /** @var User $user */
        $user = $this->getUser();
        $this->assertDepartmentHeadSuperior($user);

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
        $targetRank = $this->resolveTargetSuperiorRank($evaluator);
        if ($targetRank === null) {
            return [];
        }

        $targetDept = $eval->getDepartment();

        $users = $userRepo->createQueryBuilder('u')
            ->andWhere('u.accountStatus = :status')
            ->setParameter('status', 'active')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        $rows = [];

        foreach ($users as $candidate) {
            if (!$candidate instanceof User) {
                continue;
            }

            if ($candidate->getId() === $evaluator->getId()) {
                continue;
            }

            if ($this->resolveSuperiorRank($candidate) !== $targetRank) {
                continue;
            }

            if ($targetDept && $this->requiresDepartmentMatchForRank($targetRank)) {
                $candidateDept = $candidate->getDepartment();
                if (!$candidateDept || $candidateDept->getId() !== $targetDept->getId()) {
                    continue;
                }
            }

            $subjects = $this->resolveFacultySubjects($candidate, $subjectRepo, $fslRepo, $ayRepo);
            $previewItems = array_map(static fn(array $s): string => $s['code'] . ($s['section'] !== '' ? ' (' . $s['section'] . ')' : ''), array_slice($subjects, 0, 3));

            $rows[] = [
                'user' => $candidate,
                'type' => $this->resolveEvaluateeTypeByRank($targetRank),
                'label' => $this->resolveEvaluateeLabelByRank($targetRank),
                'subjects' => $subjects,
                'subjectCount' => count($subjects),
                'subjectPreview' => !empty($previewItems)
                    ? implode(', ', $previewItems) . (count($subjects) > 3 ? ' +' . (count($subjects) - 3) . ' more' : '')
                    : '',
            ];
        }

        return $rows;
    }

    private function assertDepartmentHeadSuperior(?User $user): void
    {
        if (!$user instanceof User || $user->isAdmin() || $user->isStaff()) {
            throw $this->createAccessDeniedException('Superior access is restricted to authorized superior accounts.');
        }

        if ($user->hasAssignedRole('ROLE_SUPERIOR')) {
            return;
        }

        if (!$user->isDepartmentHeadFaculty()) {
            throw $this->createAccessDeniedException('Superior access is restricted to authorized superior accounts.');
        }
    }

    private function evaluatorMatchesAssignedFaculty(User $evaluator, EvaluationPeriod $eval): bool
    {
        $assignedEvaluator = trim((string) ($eval->getFaculty() ?? ''));
        if ($assignedEvaluator === '') {
            // Blank assignment means any eligible superior in scope may evaluate.
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
        return $this->resolveEvaluateeTypeByRank($this->resolveSuperiorRank($evaluatee));
    }

    private function resolveEvaluateeTypeByRank(?string $rank): string
    {
        return match ($rank) {
            self::RANK_VICE_PRESIDENT => SuperiorEvaluation::TYPE_VICE_PRESIDENT,
            self::RANK_CAMPUS_DIRECTOR => SuperiorEvaluation::TYPE_CAMPUS_DIRECTOR,
            self::RANK_DEAN => SuperiorEvaluation::TYPE_DEAN,
            default => SuperiorEvaluation::TYPE_DEPARTMENT_HEAD,
        };
    }

    /**
     * Keep only valid verification selections that belong to the question's evidence items.
     *
     * @param mixed $rawSelections
     * @return string[]
     */
    private function sanitizeVerificationSelections(Question $question, mixed $rawSelections): array
    {
        $available = $question->getEvidenceItems();
        if (empty($available) || !is_array($rawSelections)) {
            return [];
        }

        $selected = [];
        foreach ($rawSelections as $item) {
            if (!is_string($item)) {
                continue;
            }

            $clean = trim($item);
            if ($clean !== '' && in_array($clean, $available, true)) {
                $selected[] = $clean;
            }
        }

        return array_values(array_unique($selected));
    }

    private function resolveEvaluateeLabel(User $evaluatee): string
    {
        $label = $this->resolveEvaluateeLabelByRank($this->resolveSuperiorRank($evaluatee));
        if ($label !== '') {
            return $label;
        }

        $status = trim((string) ($evaluatee->getPosition() ?: $evaluatee->getEmploymentStatus()));
        if ($status === '') {
            return 'Faculty Member';
        }

        return $status;
    }

    private function resolveEvaluateeLabelByRank(?string $rank): string
    {
        return match ($rank) {
            self::RANK_VICE_PRESIDENT => 'Vice President',
            self::RANK_CAMPUS_DIRECTOR => 'Campus Director',
            self::RANK_DEAN => 'Dean',
            self::RANK_DEPARTMENT_HEAD => 'Department Head',
            default => '',
        };
    }

    private function resolveTargetSuperiorRank(User $evaluator): ?string
    {
        return match ($this->resolveSuperiorRank($evaluator)) {
            self::RANK_PRESIDENT => self::RANK_VICE_PRESIDENT,
            self::RANK_VICE_PRESIDENT => self::RANK_CAMPUS_DIRECTOR,
            self::RANK_CAMPUS_DIRECTOR => self::RANK_DEAN,
            self::RANK_DEAN => self::RANK_DEPARTMENT_HEAD,
            default => null,
        };
    }

    private function resolveSuperiorRank(User $user): ?string
    {
        $raw = mb_strtolower(trim((string) ($user->getPosition() ?: '') . ' ' . (string) ($user->getEmploymentStatus() ?: '')));
        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, 'vice president')) {
            return self::RANK_VICE_PRESIDENT;
        }
        if (str_contains($raw, 'president')) {
            return self::RANK_PRESIDENT;
        }
        if (str_contains($raw, 'campus director')) {
            return self::RANK_CAMPUS_DIRECTOR;
        }
        if (str_contains($raw, 'dean')) {
            return self::RANK_DEAN;
        }
        if (str_contains($raw, 'head') || str_contains($raw, 'chair')) {
            return self::RANK_DEPARTMENT_HEAD;
        }

        return null;
    }

    private function requiresDepartmentMatchForRank(string $rank): bool
    {
        return $rank === self::RANK_DEAN || $rank === self::RANK_DEPARTMENT_HEAD;
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
