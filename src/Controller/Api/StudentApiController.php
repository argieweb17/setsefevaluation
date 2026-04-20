<?php

namespace App\Controller\Api;

use App\Entity\EvaluationPeriod;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\EvaluationResponseRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class StudentApiController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'name' => 'SET-SEF Evaluation API',
            'publicEndpoints' => [
                'GET /api',
                'GET /api/login',
                'POST /api/login',
                'GET /api/register',
                'POST /api/register',
                'GET /api/active-evaluations',
            ],
            'protectedEndpoints' => [
                'GET /api/qr/evaluations',
                'GET /api/qr/evaluations/{id}',
                'GET /api/questionnaire/evaluations/{id}',
                'GET /api/questionnaire/type/{evaluationType}',
            ],
            'evaluationApi' => [
                'periods' => [
                    'GET /api/evaluation/periods' => 'List periods (filters: type, status, schoolYear, semester)',
                    'GET /api/evaluation/periods/{id}' => 'Period detail with questionnaire',
                    'POST /api/evaluation/periods' => 'Create period (staff/admin)',
                    'PUT /api/evaluation/periods/{id}' => 'Update period (staff/admin)',
                    'DELETE /api/evaluation/periods/{id}' => 'Delete period (admin)',
                    'PATCH /api/evaluation/periods/{id}/status' => 'Open/close period',
                    'PATCH /api/evaluation/periods/{id}/lock-results' => 'Lock/unlock results',
                ],
                'submission' => [
                    'POST /api/evaluation/periods/{id}/submit' => 'Submit SET evaluation (isDraft for drafts)',
                    'GET /api/evaluation/periods/{id}/draft' => 'Load SET draft (facultyId, subjectId)',
                    'POST /api/evaluation/periods/{id}/superior/{evaluateeId}/submit' => 'Submit SEF evaluation',
                    'GET /api/evaluation/periods/{id}/superior/{evaluateeId}/draft' => 'Load SEF draft',
                ],
                'results' => [
                    'GET /api/evaluation/periods/{id}/results' => 'Faculty rankings for a period',
                    'GET /api/evaluation/periods/{id}/results/faculty/{facultyId}' => 'Detailed faculty results',
                    'GET /api/evaluation/periods/{id}/results/superior/{evaluateeId}' => 'SEF evaluatee results',
                ],
                'other' => [
                    'GET /api/evaluation/history' => 'Student evaluation history',
                    'GET /api/evaluation/periods/{id}/check-submission' => 'Check if already submitted',
                    'GET /api/evaluation/periods/{id}/participation' => 'Participation statistics',
                ],
            ],
            'auth' => 'Use Authorization: Bearer <token> for protected /api endpoints.',
        ]);
    }

    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function profile(Request $request): JsonResponse
    {
        $user = $request->attributes->get('_api_user');

        return $this->json(['user' => $this->serializeUser($user)]);
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        AcademicYearRepository $ayRepo
    ): JsonResponse {
        $user = $request->attributes->get('_api_user');

        $currentAY = $ayRepo->findCurrent();
        $activeEvals = $evalRepo->findBy(['status' => true]);
        $now = new \DateTime();

        $pendingCount = 0;
        $completedCount = 0;
        $openEvals = [];

        foreach ($activeEvals as $eval) {
            if ($eval->getEndDate() < $now || $eval->getStartDate() > $now) {
                continue;
            }

            $hasResponded = $responseRepo->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.evaluationPeriod = :eval')
                ->andWhere('r.evaluator = :user')
                ->andWhere('r.isDraft = false')
                ->setParameter('eval', $eval)
                ->setParameter('user', $user)
                ->getQuery()
                ->getSingleScalarResult();

            if ($hasResponded > 0) {
                $completedCount++;
            } else {
                $pendingCount++;
                $openEvals[] = $this->serializeEvaluation($eval);
            }
        }

        return $this->json([
            'user' => $this->serializeUser($user),
            'currentAcademicYear' => $currentAY ? $currentAY->getYearLabel() . ' - ' . $currentAY->getSemester() : null,
            'stats' => [
                'pendingEvaluations' => $pendingCount,
                'completedEvaluations' => $completedCount,
                'totalActive' => count($activeEvals),
            ],
            'openEvaluations' => $openEvals,
        ]);
    }

    #[Route('/evaluations', name: 'evaluations', methods: ['GET'])]
    public function evaluations(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo
    ): JsonResponse {
        $user = $request->attributes->get('_api_user');

        $evals = $evalRepo->findAllOrdered();
        $now = new \DateTime();
        $result = [];

        foreach ($evals as $eval) {
            $isActive = $eval->isStatus() && $eval->getStartDate() <= $now && $eval->getEndDate() >= $now;

            $hasResponded = $responseRepo->createQueryBuilder('r')
                ->select('COUNT(r.id)')
                ->where('r.evaluationPeriod = :eval')
                ->andWhere('r.evaluator = :user')
                ->andWhere('r.isDraft = false')
                ->setParameter('eval', $eval)
                ->setParameter('user', $user)
                ->getQuery()
                ->getSingleScalarResult();

            $item = $this->serializeEvaluation($eval);
            $item['isActive'] = $isActive;
            $item['hasResponded'] = $hasResponded > 0;
            $result[] = $item;
        }

        return $this->json(['evaluations' => $result]);
    }

    #[Route('/evaluations/{id}/questions', name: 'evaluation_questions', methods: ['GET'])]
    public function evaluationQuestions(
        EvaluationPeriod $eval,
        QuestionRepository $questionRepo
    ): JsonResponse {
        $questions = $questionRepo->findBy(
            ['evaluationType' => $eval->getEvaluationType(), 'isActive' => true],
            ['category' => 'ASC', 'sortOrder' => 'ASC']
        );

        $grouped = [];
        foreach ($questions as $q) {
            $cat = $q->getCategory();
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = ['category' => $cat, 'questions' => []];
            }
            $grouped[$cat]['questions'][] = [
                'id' => $q->getId(),
                'text' => $q->getQuestionText(),
                'evidenceItems' => $eval->getEvaluationType() === 'SEF' ? $q->getEvidenceItems() : [],
                'weight' => $q->getWeight(),
                'isRequired' => $q->isRequired(),
            ];
        }

        return $this->json([
            'evaluation' => $this->serializeEvaluation($eval),
            'categories' => array_values($grouped),
        ]);
    }

    #[Route('/evaluations/{id}/submit', name: 'evaluation_submit', methods: ['POST'])]
    public function submitEvaluation(
        EvaluationPeriod $eval,
        Request $request,
        EntityManagerInterface $em,
        QuestionRepository $questionRepo,
        SubjectRepository $subjectRepo,
        UserRepository $userRepo
    ): JsonResponse {
        $user = $request->attributes->get('_api_user');

        $data = json_decode($request->getContent(), true);
        $ratings = $data['ratings'] ?? [];
        $comment = $data['comment'] ?? '';
        $facultyId = $data['facultyId'] ?? null;
        $subjectId = $data['subjectId'] ?? null;

        if (empty($ratings)) {
            return $this->json(['error' => 'Ratings are required.'], 400);
        }

        $faculty = $facultyId ? $userRepo->find($facultyId) : null;
        $subject = $subjectId ? $subjectRepo->find($subjectId) : null;

        foreach ($ratings as $qId => $rating) {
            $question = $questionRepo->find($qId);
            if (!$question) {
                continue;
            }

            $response = new \App\Entity\EvaluationResponse();
            $response->setEvaluationPeriod($eval);
            $response->setQuestion($question);
            $response->setRating((int) $rating);
            $response->setComment($comment);
            $response->setSubmittedAt(new \DateTime());
            $response->setIsDraft(false);

            if (!$eval->isAnonymousMode()) {
                $response->setEvaluator($user);
            }
            if ($faculty) {
                $response->setFaculty($faculty);
            }
            if ($subject) {
                $response->setSubject($subject);
            }

            $em->persist($response);
        }

        $em->flush();

        return $this->json(['success' => true, 'message' => 'Evaluation submitted successfully.']);
    }

    #[Route('/active-evaluations', name: 'active_evaluations', methods: ['GET'])]
    public function activeEvaluations(
        EvaluationPeriodRepository $evalRepo
    ): JsonResponse {
        $now = new \DateTime();
        $activeEvals = $evalRepo->findBy(['evaluationType' => 'SET', 'status' => true]);
        $result = [];

        foreach ($activeEvals as $eval) {
            $isActive = $eval->getStartDate() <= $now && $eval->getEndDate() >= $now;
            if ($isActive) {
                $result[] = [
                    'id' => $eval->getId(),
                    'name' => $eval->getLabel(),
                    'faculty' => $eval->getFaculty() ?? '',
                    'subject' => $eval->getSubject() ?? '',
                    'section' => $eval->getSection() ?? '',
                    'schoolYear' => $eval->getSchoolYear() ?? $eval->getLabel(),
                    'isActive' => true,
                    'startDate' => $eval->getStartDate()->format('Y-m-d'),
                    'endDate' => $eval->getEndDate()->format('Y-m-d'),
                ];
            }
        }

        return $this->json(['evaluations' => $result]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'fullName' => $user->getLastName() . ', ' . $user->getFirstName(),
            'roles' => $user->getRoles(),
            'department' => $user->getDepartment()?->getDepartmentName(),
            'campus' => $user->getCampus(),
            'schoolId' => $user->getSchoolId(),
            'yearLevel' => $user->getYearLevel(),
            'profilePicture' => $user->getProfilePicture(),
            'accountStatus' => $user->getAccountStatus(),
        ];
    }

    private function serializeEvaluation(EvaluationPeriod $eval): array
    {
        return [
            'id' => $eval->getId(),
            'type' => $eval->getEvaluationType(),
            'schoolYear' => $eval->getSchoolYear(),
            'semester' => $eval->getSemester(),
            'faculty' => $eval->getFaculty(),
            'subject' => $eval->getSubject(),
            'startDate' => $eval->getStartDate()->format('Y-m-d'),
            'endDate' => $eval->getEndDate()->format('Y-m-d'),
            'status' => $eval->isStatus(),
            'anonymous' => $eval->isAnonymousMode(),
            'department' => $eval->getDepartment()?->getDepartmentName(),
            'yearLevel' => $eval->getYearLevel(),
        ];
    }

}
