<?php

namespace App\Controller\Api;

use App\Entity\AuditLog;
use App\Entity\EvaluationPeriod;
use App\Entity\EvaluationResponse;
use App\Entity\SuperiorEvaluation;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\DepartmentRepository;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\EvaluationResponseRepository;
use App\Repository\QuestionCategoryDescriptionRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;
use App\Repository\SuperiorEvaluationRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/evaluation', name: 'api_evaluation_')]
class EvaluationApiController extends AbstractController
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    // ═══════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════

    private function getApiUser(Request $request): ?User
    {
        $user = $request->attributes->get('_api_user');
        return $user instanceof User ? $user : null;
    }

    private function requireAuth(Request $request): User|JsonResponse
    {
        $user = $this->getApiUser($request);
        if (!$user) {
            return $this->json(['error' => 'Authentication required.'], 401);
        }
        return $user;
    }

    private function requireRole(Request $request, array $roles): User|JsonResponse
    {
        $user = $this->requireAuth($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $userRoles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $userRoles, true)) {
            return $user;
        }

        foreach ($roles as $role) {
            if (in_array($role, $userRoles, true)) {
                return $user;
            }
        }

        return $this->json(['error' => 'Access denied. Insufficient permissions.'], 403);
    }

    private function serializePeriod(EvaluationPeriod $eval, ?int $respondentCount = null): array
    {
        $data = [
            'id' => $eval->getId(),
            'evaluationType' => $eval->getEvaluationType(),
            'schoolYear' => $eval->getSchoolYear(),
            'semester' => $eval->getSemester(),
            'faculty' => $eval->getFaculty(),
            'subject' => $eval->getSubject(),
            'section' => $eval->getSection(),
            'schedule' => $eval->getTime(),
            'yearLevel' => $eval->getYearLevel(),
            'college' => $eval->getCollege(),
            'department' => $eval->getDepartment()?->getDepartmentName(),
            'departmentId' => $eval->getDepartment()?->getId(),
            'startDate' => $eval->getStartDate()->format('Y-m-d H:i:s'),
            'endDate' => $eval->getEndDate()->format('Y-m-d H:i:s'),
            'status' => $eval->isStatus(),
            'isOpen' => $eval->isOpen(),
            'anonymousMode' => $eval->isAnonymousMode(),
            'resultsLocked' => $eval->isResultsLocked(),
            'label' => $eval->getLabel(),
        ];

        if ($respondentCount !== null) {
            $data['respondentCount'] = $respondentCount;
        }

        return $data;
    }

    // ═══════════════════════════════════════════════════════
    //  LIST & DETAIL — Evaluation Periods
    // ═══════════════════════════════════════════════════════

    /** List evaluation periods with optional filters. */
    #[Route('/periods', name: 'periods', methods: ['GET'])]
    public function listPeriods(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        SuperiorEvaluationRepository $superiorRepo,
    ): JsonResponse {
        $user = $this->requireAuth($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $type = $request->query->get('type');           // SET or SEF
        $status = $request->query->get('status');       // open, active, all
        $schoolYear = $request->query->get('schoolYear');
        $semester = $request->query->get('semester');

        if ($status === 'open') {
            $periods = $evalRepo->findOpen($type);
        } elseif ($status === 'active') {
            $periods = $evalRepo->findActive($type);
        } else {
            $periods = $evalRepo->findAllOrdered();
        }

        // Apply additional filters
        if ($type && $status !== 'open' && $status !== 'active') {
            $periods = array_filter($periods, fn(EvaluationPeriod $p) => $p->getEvaluationType() === strtoupper($type));
        }
        if ($schoolYear) {
            $periods = array_filter($periods, fn(EvaluationPeriod $p) => $p->getSchoolYear() === $schoolYear);
        }
        if ($semester) {
            $periods = array_filter($periods, fn(EvaluationPeriod $p) => $p->getSemester() === $semester);
        }

        // Build respondent counts per period
        $setCounts = $responseRepo->countEvaluatorsByPeriod();
        $sefCounts = $superiorRepo->countEvaluatorsByPeriod();

        $items = [];
        foreach (array_values($periods) as $eval) {
            $count = $setCounts[$eval->getId()] ?? $sefCounts[$eval->getId()] ?? 0;
            $items[] = $this->serializePeriod($eval, $count);
        }

        return $this->json([
            'count' => count($items),
            'periods' => $items,
        ]);
    }

    /** Get a single evaluation period with full details. */
    #[Route('/periods/{id}', name: 'period_detail', methods: ['GET'])]
    public function periodDetail(
        int $id,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        SuperiorEvaluationRepository $superiorRepo,
        QuestionRepository $questionRepo,
        QuestionCategoryDescriptionRepository $descRepo,
    ): JsonResponse {
        $user = $this->requireAuth($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eval = $evalRepo->find($id);
        if (!$eval) {
            return $this->json(['error' => 'Evaluation period not found.'], 404);
        }

        $evalType = $eval->getEvaluationType();
        $respondentCount = $evalType === 'SEF'
            ? count(array_unique(array_column(
                $superiorRepo->getEvaluateeRankings($id) ?: [],
                'evaluateeId'
            )))
            : $responseRepo->countEvaluators(0, $id); // 0 = all faculty

        $questions = $questionRepo->findBy(
            ['evaluationType' => $evalType, 'isActive' => true],
            ['category' => 'ASC', 'sortOrder' => 'ASC']
        );

        $groupedQuestions = [];
        foreach ($questions as $q) {
            $cat = $q->getCategory() ?? 'General';
            if (!isset($groupedQuestions[$cat])) {
                $descEntity = $descRepo->findOneBy(['category' => $cat, 'evaluationType' => $evalType]);
                $groupedQuestions[$cat] = [
                    'category' => $cat,
                    'description' => $descEntity?->getDescription(),
                    'questions' => [],
                ];
            }
            $groupedQuestions[$cat]['questions'][] = [
                'id' => $q->getId(),
                'text' => $q->getQuestionText(),
                'weight' => $q->getWeight(),
                'isRequired' => $q->isRequired(),
                'evidenceItems' => $evalType === 'SEF' ? ($q->getEvidenceItems() ?? []) : [],
            ];
        }

        return $this->json([
            'period' => $this->serializePeriod($eval, $respondentCount),
            'questionnaire' => array_values($groupedQuestions),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  CRUD — Evaluation Periods (Admin / Staff)
    // ═══════════════════════════════════════════════════════

    /** Create a new evaluation period. */
    #[Route('/periods', name: 'period_create', methods: ['POST'])]
    public function createPeriod(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        DepartmentRepository $deptRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->requireRole($request, ['ROLE_STAFF']);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $type = strtoupper(trim($data['evaluationType'] ?? 'SET'));
        if (!in_array($type, [EvaluationPeriod::TYPE_SET, EvaluationPeriod::TYPE_SEF], true)) {
            return $this->json(['error' => 'evaluationType must be SET or SEF.'], 400);
        }

        $schoolYear = trim($data['schoolYear'] ?? '');
        $semester = trim($data['semester'] ?? '');
        $startDate = $data['startDate'] ?? null;
        $endDate = $data['endDate'] ?? null;

        if ($schoolYear === '' || $semester === '' || !$startDate || !$endDate) {
            return $this->json(['error' => 'schoolYear, semester, startDate, and endDate are required.'], 400);
        }

        try {
            $start = new \DateTime($startDate);
            $end = new \DateTime($endDate);
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid date format. Use Y-m-d or Y-m-d H:i:s.'], 400);
        }

        if ($end <= $start) {
            return $this->json(['error' => 'endDate must be after startDate.'], 400);
        }

        // Check duplicates
        $duplicate = $evalRepo->findDuplicate(
            $type,
            $data['faculty'] ?? null,
            $data['subject'] ?? null,
            $schoolYear,
            $data['section'] ?? null,
            $semester,
            isset($data['departmentId']) ? (int) $data['departmentId'] : null,
        );
        if ($duplicate) {
            return $this->json(['error' => 'A similar evaluation period already exists.', 'existingId' => $duplicate->getId()], 409);
        }

        $eval = new EvaluationPeriod();
        $eval->setEvaluationType($type);
        $eval->setSchoolYear($schoolYear);
        $eval->setSemester($semester);
        $eval->setStartDate($start);
        $eval->setEndDate($end);
        $eval->setStatus((bool) ($data['status'] ?? true));
        $eval->setAnonymousMode((bool) ($data['anonymousMode'] ?? true));
        $eval->setFaculty($data['faculty'] ?? null);
        $eval->setSubject($data['subject'] ?? null);
        $eval->setSection($data['section'] ?? null);
        $eval->setTime($data['schedule'] ?? null);
        $eval->setYearLevel($data['yearLevel'] ?? null);
        $eval->setCollege($data['college'] ?? null);

        if (isset($data['departmentId'])) {
            $dept = $deptRepo->find((int) $data['departmentId']);
            if ($dept) {
                $eval->setDepartment($dept);
            }
        }

        $em->persist($eval);
        $em->flush();

        $this->audit->log(AuditLog::ACTION_CREATE_EVALUATION, 'EvaluationPeriod', $eval->getId(),
            "Created {$type} evaluation: {$schoolYear} {$semester}");

        return $this->json([
            'success' => true,
            'message' => 'Evaluation period created.',
            'period' => $this->serializePeriod($eval),
        ], 201);
    }

    /** Update an existing evaluation period. */
    #[Route('/periods/{id}', name: 'period_update', methods: ['PUT', 'PATCH'])]
    public function updatePeriod(
        int $id,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        DepartmentRepository $deptRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->requireRole($request, ['ROLE_STAFF']);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eval = $evalRepo->find($id);
        if (!$eval) {
            return $this->json(['error' => 'Evaluation period not found.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        if (isset($data['evaluationType'])) {
            $type = strtoupper(trim($data['evaluationType']));
            if (!in_array($type, [EvaluationPeriod::TYPE_SET, EvaluationPeriod::TYPE_SEF], true)) {
                return $this->json(['error' => 'evaluationType must be SET or SEF.'], 400);
            }
            $eval->setEvaluationType($type);
        }

        if (isset($data['schoolYear'])) $eval->setSchoolYear(trim($data['schoolYear']));
        if (isset($data['semester'])) $eval->setSemester(trim($data['semester']));

        if (isset($data['startDate'])) {
            try { $eval->setStartDate(new \DateTime($data['startDate'])); }
            catch (\Throwable) { return $this->json(['error' => 'Invalid startDate format.'], 400); }
        }
        if (isset($data['endDate'])) {
            try { $eval->setEndDate(new \DateTime($data['endDate'])); }
            catch (\Throwable) { return $this->json(['error' => 'Invalid endDate format.'], 400); }
        }

        if (array_key_exists('status', $data)) $eval->setStatus((bool) $data['status']);
        if (array_key_exists('anonymousMode', $data)) $eval->setAnonymousMode((bool) $data['anonymousMode']);
        if (array_key_exists('resultsLocked', $data)) $eval->setResultsLocked((bool) $data['resultsLocked']);
        if (array_key_exists('faculty', $data)) $eval->setFaculty($data['faculty']);
        if (array_key_exists('subject', $data)) $eval->setSubject($data['subject']);
        if (array_key_exists('section', $data)) $eval->setSection($data['section']);
        if (array_key_exists('schedule', $data)) $eval->setTime($data['schedule']);
        if (array_key_exists('yearLevel', $data)) $eval->setYearLevel($data['yearLevel']);
        if (array_key_exists('college', $data)) $eval->setCollege($data['college']);

        if (isset($data['departmentId'])) {
            $dept = $deptRepo->find((int) $data['departmentId']);
            $eval->setDepartment($dept);
        }

        $em->flush();

        $this->audit->log(AuditLog::ACTION_EDIT_EVALUATION, 'EvaluationPeriod', $eval->getId(),
            'Updated evaluation period #' . $eval->getId());

        return $this->json([
            'success' => true,
            'message' => 'Evaluation period updated.',
            'period' => $this->serializePeriod($eval),
        ]);
    }

    /** Delete an evaluation period and all associated responses. */
    #[Route('/periods/{id}', name: 'period_delete', methods: ['DELETE'])]
    public function deletePeriod(
        int $id,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->requireRole($request, ['ROLE_ADMIN']);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eval = $evalRepo->find($id);
        if (!$eval) {
            return $this->json(['error' => 'Evaluation period not found.'], 404);
        }

        $label = $eval->getLabel();
        $em->remove($eval);
        $em->flush();

        $this->audit->log(AuditLog::ACTION_DELETE_EVALUATION, 'EvaluationPeriod', $id,
            "Deleted evaluation period: {$label}");

        return $this->json(['success' => true, 'message' => 'Evaluation period deleted.']);
    }

    // ═══════════════════════════════════════════════════════
    //  STATUS ACTIONS — Open / Close / Lock Results
    // ═══════════════════════════════════════════════════════

    /** Open or close an evaluation period. */
    #[Route('/periods/{id}/status', name: 'period_status', methods: ['PATCH'])]
    public function toggleStatus(
        int $id,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->requireRole($request, ['ROLE_STAFF']);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eval = $evalRepo->find($id);
        if (!$eval) {
            return $this->json(['error' => 'Evaluation period not found.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $newStatus = (bool) ($data['status'] ?? !$eval->isStatus());
        $eval->setStatus($newStatus);
        $em->flush();

        $action = $newStatus ? AuditLog::ACTION_OPEN_EVALUATION : AuditLog::ACTION_CLOSE_EVALUATION;
        $this->audit->log($action, 'EvaluationPeriod', $id,
            ($newStatus ? 'Opened' : 'Closed') . ' evaluation period #' . $id);

        return $this->json([
            'success' => true,
            'status' => $newStatus,
            'isOpen' => $eval->isOpen(),
        ]);
    }

    /** Lock or unlock results for an evaluation period. */
    #[Route('/periods/{id}/lock-results', name: 'period_lock_results', methods: ['PATCH'])]
    public function lockResults(
        int $id,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->requireRole($request, ['ROLE_STAFF']);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eval = $evalRepo->find($id);
        if (!$eval) {
            return $this->json(['error' => 'Evaluation period not found.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $locked = (bool) ($data['locked'] ?? !$eval->isResultsLocked());
        $eval->setResultsLocked($locked);
        $em->flush();

        $this->audit->log(AuditLog::ACTION_LOCK_RESULTS, 'EvaluationPeriod', $id,
            ($locked ? 'Locked' : 'Unlocked') . ' results for period #' . $id);

        return $this->json([
            'success' => true,
            'resultsLocked' => $locked,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  SUBMIT — SET Evaluation
    // ═══════════════════════════════════════════════════════

    /** Submit or save draft of a SET evaluation. */
    #[Route('/periods/{evalId}/submit', name: 'submit_set', methods: ['POST'])]
    public function submitSet(
        int $evalId,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        QuestionRepository $questionRepo,
        SubjectRepository $subjectRepo,
        UserRepository $userRepo,
        EvaluationResponseRepository $responseRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->requireAuth($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eval = $evalRepo->find($evalId);
        if (!$eval || !$eval->isOpen() || $eval->getEvaluationType() !== 'SET') {
            return $this->json(['error' => 'Evaluation not found or not open for SET.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $ratings = $data['ratings'] ?? [];
        $comment = trim($data['comment'] ?? '');
        $facultyId = $data['facultyId'] ?? null;
        $subjectId = $data['subjectId'] ?? null;
        $isDraft = (bool) ($data['isDraft'] ?? false);

        if (!$facultyId || !$subjectId) {
            return $this->json(['error' => 'facultyId and subjectId are required.'], 400);
        }
        if (empty($ratings) && !$isDraft) {
            return $this->json(['error' => 'ratings are required for submission.'], 400);
        }

        $faculty = $userRepo->find((int) $facultyId);
        $subject = $subjectRepo->find((int) $subjectId);

        if (!$faculty) {
            return $this->json(['error' => 'Faculty not found.'], 404);
        }
        if (!$subject) {
            return $this->json(['error' => 'Subject not found.'], 404);
        }

        // Check duplicate final submission
        if (!$isDraft && $responseRepo->hasSubmitted($user->getId(), $evalId, $faculty->getId(), $subjectId)) {
            return $this->json(['error' => 'You have already submitted this evaluation.'], 409);
        }

        // Remove existing drafts
        $drafts = $responseRepo->findDrafts($user->getId(), $evalId, $faculty->getId(), $subjectId);
        foreach ($drafts as $d) {
            $em->remove($d);
        }

        $questions = $questionRepo->findByType('SET');
        $commentSaved = false;

        foreach ($questions as $q) {
            $rating = (int) ($ratings[$q->getId()] ?? 0);
            if ($rating === 0 && !$isDraft) {
                continue;
            }

            $response = new EvaluationResponse();
            $response->setEvaluationPeriod($eval);
            $response->setQuestion($q);
            $response->setFaculty($faculty);
            $response->setSubject($subject);
            $response->setRating($rating);
            if (!$commentSaved && $comment !== '') {
                $response->setComment($comment);
                $commentSaved = true;
            }
            $response->setIsDraft($isDraft);
            $response->setEvaluator($user);

            $em->persist($response);
        }

        $em->flush();

        $auditAction = $isDraft ? AuditLog::ACTION_SAVE_DRAFT : AuditLog::ACTION_SUBMIT_SET;
        $this->audit->log($auditAction, 'EvaluationResponse', null,
            ($isDraft ? 'Draft saved' : 'Submitted SET') . ' for ' . $faculty->getFullName() . ' / ' . $subject->getSubjectCode());

        return $this->json([
            'success' => true,
            'isDraft' => $isDraft,
            'message' => $isDraft ? 'Draft saved.' : 'Evaluation submitted successfully.',
        ]);
    }

    /** Load draft responses for a SET evaluation. */
    #[Route('/periods/{evalId}/draft', name: 'draft_set', methods: ['GET'])]
    public function loadDraft(
        int $evalId,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
    ): JsonResponse {
        $user = $this->requireAuth($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eval = $evalRepo->find($evalId);
        if (!$eval) {
            return $this->json(['error' => 'Evaluation period not found.'], 404);
        }

        $facultyId = (int) $request->query->get('facultyId', 0);
        $subjectId = (int) $request->query->get('subjectId', 0);

        if (!$facultyId) {
            return $this->json(['error' => 'facultyId query parameter is required.'], 400);
        }

        $drafts = $responseRepo->findDrafts($user->getId(), $evalId, $facultyId, $subjectId ?: null);

        $ratings = [];
        $comment = '';
        foreach ($drafts as $d) {
            $ratings[$d->getQuestion()->getId()] = $d->getRating();
            if ($d->getComment()) {
                $comment = $d->getComment();
            }
        }

        return $this->json([
            'hasDraft' => count($drafts) > 0,
            'ratings' => $ratings,
            'comment' => $comment,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  SUBMIT — Superior (SEF) Evaluation
    // ═══════════════════════════════════════════════════════

    /** Submit or save draft of a Superior (SEF) evaluation. */
    #[Route('/periods/{evalId}/superior/{evaluateeId}/submit', name: 'submit_superior', methods: ['POST'])]
    public function submitSuperior(
        int $evalId,
        int $evaluateeId,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        QuestionRepository $questionRepo,
        UserRepository $userRepo,
        SuperiorEvaluationRepository $superiorRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->requireRole($request, ['ROLE_SUPERIOR']);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eval = $evalRepo->find($evalId);
        if (!$eval || !$eval->isOpen()) {
            return $this->json(['error' => 'Evaluation not found or not open.'], 404);
        }

        $evaluatee = $userRepo->find($evaluateeId);
        if (!$evaluatee) {
            return $this->json(['error' => 'Evaluatee not found.'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $ratings = $data['ratings'] ?? [];
        $verifications = $data['verifications'] ?? [];
        $comment = trim($data['comment'] ?? '');
        $isDraft = (bool) ($data['isDraft'] ?? false);
        $evaluateeRole = $data['evaluateeRole'] ?? SuperiorEvaluation::TYPE_DEPARTMENT_HEAD;

        if (empty($ratings) && !$isDraft) {
            return $this->json(['error' => 'ratings are required for submission.'], 400);
        }

        // Already submitted?
        if (!$isDraft && $superiorRepo->hasSubmitted($user->getId(), $evalId, $evaluateeId)) {
            return $this->json(['error' => 'You have already submitted this evaluation.'], 409);
        }

        // Remove existing drafts
        $drafts = $superiorRepo->findDrafts($user->getId(), $evalId, $evaluateeId);
        foreach ($drafts as $d) {
            $em->remove($d);
        }
        $em->flush();

        $questions = $questionRepo->findBy(
            ['evaluationType' => 'SEF', 'isActive' => true],
            ['category' => 'ASC', 'sortOrder' => 'ASC']
        );

        $commentSaved = false;
        foreach ($questions as $q) {
            $rating = (int) ($ratings[$q->getId()] ?? 0);
            if ($rating < 1 && !$isDraft) {
                continue;
            }

            $response = new SuperiorEvaluation();
            $response->setEvaluationPeriod($eval);
            $response->setEvaluator($user);
            $response->setEvaluatee($evaluatee);
            $response->setEvaluateeRole($evaluateeRole);
            $response->setQuestion($q);
            $response->setRating($rating);
            $response->setVerificationSelections($verifications[$q->getId()] ?? []);
            if (!$commentSaved && $comment !== '') {
                $response->setComment($comment);
                $commentSaved = true;
            }
            $response->setIsDraft($isDraft);
            $response->setSubmittedAt(new \DateTime());

            $em->persist($response);
        }

        $em->flush();

        $auditAction = $isDraft ? AuditLog::ACTION_SAVE_DRAFT : AuditLog::ACTION_SUBMIT_SET;
        $this->audit->log($auditAction, 'SuperiorEvaluation', $evalId,
            ($isDraft ? 'Draft saved' : 'Superior evaluation submitted') . ' for ' . $evaluatee->getFullName());

        return $this->json([
            'success' => true,
            'isDraft' => $isDraft,
            'message' => $isDraft ? 'Draft saved.' : 'Superior evaluation submitted successfully.',
        ]);
    }

    /** Load drafts for a superior evaluation. */
    #[Route('/periods/{evalId}/superior/{evaluateeId}/draft', name: 'draft_superior', methods: ['GET'])]
    public function loadSuperiorDraft(
        int $evalId,
        int $evaluateeId,
        Request $request,
        SuperiorEvaluationRepository $superiorRepo,
    ): JsonResponse {
        $user = $this->requireRole($request, ['ROLE_SUPERIOR']);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $drafts = $superiorRepo->findDrafts($user->getId(), $evalId, $evaluateeId);

        $ratings = [];
        $verifications = [];
        $comment = '';
        foreach ($drafts as $d) {
            $qId = $d->getQuestion()->getId();
            $ratings[$qId] = $d->getRating();
            $verifications[$qId] = $d->getVerificationSelections();
            if ($d->getComment()) {
                $comment = $d->getComment();
            }
        }

        return $this->json([
            'hasDraft' => count($drafts) > 0,
            'ratings' => $ratings,
            'verifications' => $verifications,
            'comment' => $comment,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  RESULTS — Evaluation Results & Rankings
    // ═══════════════════════════════════════════════════════

    /** Get SET evaluation results for a specific evaluation period. */
    #[Route('/periods/{id}/results', name: 'period_results', methods: ['GET'])]
    public function periodResults(
        int $id,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
    ): JsonResponse {
        $user = $this->requireRole($request, ['ROLE_FACULTY', 'ROLE_STAFF', 'ROLE_SUPERIOR']);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eval = $evalRepo->find($id);
        if (!$eval) {
            return $this->json(['error' => 'Evaluation period not found.'], 404);
        }

        if ($eval->isResultsLocked() && !in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->json(['error' => 'Results for this period are locked.'], 403);
        }

        $rankings = $responseRepo->getFacultyRankings($id);

        $results = [];
        foreach ($rankings as $r) {
            $faculty = $userRepo->find($r['facultyId']);
            $results[] = [
                'facultyId' => $r['facultyId'],
                'facultyName' => $faculty?->getFullName() ?? 'Unknown',
                'department' => $faculty?->getDepartment()?->getDepartmentName(),
                'averageRating' => round((float) $r['avgRating'], 2),
                'evaluatorCount' => (int) $r['evaluatorCount'],
            ];
        }

        return $this->json([
            'period' => $this->serializePeriod($eval),
            'rankings' => $results,
        ]);
    }

    /** Get detailed results for a specific faculty within an evaluation period. */
    #[Route('/periods/{id}/results/faculty/{facultyId}', name: 'faculty_results', methods: ['GET'])]
    public function facultyResults(
        int $id,
        int $facultyId,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        QuestionRepository $questionRepo,
    ): JsonResponse {
        $user = $this->requireRole($request, ['ROLE_FACULTY', 'ROLE_STAFF', 'ROLE_SUPERIOR']);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Faculty can only view their own results
        $userRoles = $user->getRoles();
        if (in_array('ROLE_FACULTY', $userRoles, true)
            && !in_array('ROLE_STAFF', $userRoles, true)
            && !in_array('ROLE_SUPERIOR', $userRoles, true)
            && !in_array('ROLE_ADMIN', $userRoles, true)
            && $user->getId() !== $facultyId) {
            return $this->json(['error' => 'You can only view your own results.'], 403);
        }

        $eval = $evalRepo->find($id);
        if (!$eval) {
            return $this->json(['error' => 'Evaluation period not found.'], 404);
        }

        if ($eval->isResultsLocked() && !in_array('ROLE_ADMIN', $userRoles, true)) {
            return $this->json(['error' => 'Results for this period are locked.'], 403);
        }

        $overallAvg = $responseRepo->getOverallAverage($facultyId, $id);
        $evaluatorCount = $responseRepo->countEvaluators($facultyId, $id);
        $comments = $responseRepo->getComments($facultyId, $id);
        $avgByQuestion = $responseRepo->getAverageRatingsByFaculty($facultyId, $id);

        $questions = $questionRepo->findBy(
            ['evaluationType' => $eval->getEvaluationType(), 'isActive' => true],
            ['category' => 'ASC', 'sortOrder' => 'ASC']
        );

        $questionResults = [];
        foreach ($questions as $q) {
            $qAvg = $avgByQuestion[$q->getId()] ?? null;
            $questionResults[] = [
                'questionId' => $q->getId(),
                'text' => $q->getQuestionText(),
                'category' => $q->getCategory(),
                'averageRating' => $qAvg ? round((float) $qAvg['average'], 2) : null,
                'responseCount' => $qAvg ? (int) $qAvg['count'] : 0,
            ];
        }

        return $this->json([
            'period' => $this->serializePeriod($eval),
            'facultyId' => $facultyId,
            'overallAverage' => round($overallAvg, 2),
            'evaluatorCount' => $evaluatorCount,
            'comments' => $eval->isAnonymousMode() ? $comments : $comments,
            'questionResults' => $questionResults,
        ]);
    }

    /** Get SEF (superior) evaluation results for an evaluatee. */
    #[Route('/periods/{id}/results/superior/{evaluateeId}', name: 'superior_results', methods: ['GET'])]
    public function superiorResults(
        int $id,
        int $evaluateeId,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorRepo,
        QuestionRepository $questionRepo,
        UserRepository $userRepo,
    ): JsonResponse {
        $user = $this->requireRole($request, ['ROLE_SUPERIOR', 'ROLE_STAFF']);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eval = $evalRepo->find($id);
        if (!$eval) {
            return $this->json(['error' => 'Evaluation period not found.'], 404);
        }

        $evaluatee = $userRepo->find($evaluateeId);
        if (!$evaluatee) {
            return $this->json(['error' => 'Evaluatee not found.'], 404);
        }

        $overallAvg = $superiorRepo->getOverallAverage($evaluateeId, $id);
        $evaluatorCount = $superiorRepo->countEvaluators($evaluateeId, $id);
        $comments = $superiorRepo->getComments($evaluateeId, $id);
        $categoryAvgs = $superiorRepo->getCategoryAverages($evaluateeId, $id);
        $avgByQuestion = $superiorRepo->getAverageRatingsByEvaluatee($evaluateeId, $id);

        $questions = $questionRepo->findBy(
            ['evaluationType' => 'SEF', 'isActive' => true],
            ['category' => 'ASC', 'sortOrder' => 'ASC']
        );

        $questionResults = [];
        foreach ($questions as $q) {
            $qAvg = $avgByQuestion[$q->getId()] ?? null;
            $questionResults[] = [
                'questionId' => $q->getId(),
                'text' => $q->getQuestionText(),
                'category' => $q->getCategory(),
                'averageRating' => $qAvg ? round((float) $qAvg['average'], 2) : null,
                'responseCount' => $qAvg ? (int) $qAvg['count'] : 0,
            ];
        }

        return $this->json([
            'period' => $this->serializePeriod($eval),
            'evaluatee' => [
                'id' => $evaluatee->getId(),
                'fullName' => $evaluatee->getFullName(),
                'department' => $evaluatee->getDepartment()?->getDepartmentName(),
            ],
            'overallAverage' => round($overallAvg, 2),
            'evaluatorCount' => $evaluatorCount,
            'categoryAverages' => $categoryAvgs,
            'comments' => $comments,
            'questionResults' => $questionResults,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    //  HISTORY & PARTICIPATION
    // ═══════════════════════════════════════════════════════

    /** Get the current student's evaluation history. */
    #[Route('/history', name: 'history', methods: ['GET'])]
    public function history(
        Request $request,
        EvaluationResponseRepository $responseRepo,
    ): JsonResponse {
        $user = $this->requireAuth($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $history = $responseRepo->getStudentHistory($user->getId());

        return $this->json([
            'count' => count($history),
            'history' => $history,
        ]);
    }

    /** Check if the current user has already submitted for a specific evaluation+faculty+subject. */
    #[Route('/periods/{evalId}/check-submission', name: 'check_submission', methods: ['GET'])]
    public function checkSubmission(
        int $evalId,
        Request $request,
        EvaluationResponseRepository $responseRepo,
    ): JsonResponse {
        $user = $this->requireAuth($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $facultyId = (int) $request->query->get('facultyId', 0);
        $subjectId = (int) $request->query->get('subjectId', 0);

        if (!$facultyId) {
            return $this->json(['error' => 'facultyId query parameter is required.'], 400);
        }

        $hasSubmitted = $responseRepo->hasSubmitted(
            $user->getId(), $evalId, $facultyId, $subjectId ?: null
        );

        return $this->json([
            'hasSubmitted' => $hasSubmitted,
        ]);
    }

    /** Get participation statistics for an evaluation period. */
    #[Route('/periods/{id}/participation', name: 'participation', methods: ['GET'])]
    public function participation(
        int $id,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
    ): JsonResponse {
        $user = $this->requireRole($request, ['ROLE_STAFF', 'ROLE_SUPERIOR']);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $eval = $evalRepo->find($id);
        if (!$eval) {
            return $this->json(['error' => 'Evaluation period not found.'], 404);
        }

        // Count total students as expected respondents
        $totalStudents = count($userRepo->findBy(['accountStatus' => 'active']));

        $stats = $responseRepo->getParticipationRate($id, $totalStudents);

        return $this->json([
            'period' => $this->serializePeriod($eval),
            'participation' => [
                'submitted' => $stats['submitted'] ?? 0,
                'expected' => $stats['expected'] ?? $totalStudents,
                'rate' => $stats['rate'] ?? 0,
            ],
        ]);
    }
}
