<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\EvaluationPeriod;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\EvaluationResponseRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;
use App\Repository\AcademicYearRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class ApiController extends AbstractController
{
    // ════════════════════════════════════════════════
    //  AUTH
    // ════════════════════════════════════════════════

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(
        Request $request,
        UserRepository $userRepo,
        UserPasswordHasherInterface $hasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (!$email || !$password) {
            return $this->json(['error' => 'Email and password are required.'], 400);
        }

        $user = $userRepo->findOneBy(['email' => $email]);

        if (!$user || !$hasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'Invalid credentials.'], 401);
        }

        if ($user->getAccountStatus() !== 'active') {
            return $this->json(['error' => 'Account is not active.'], 403);
        }

        // Generate a simple token (hash of user id + secret + time)
        $token = hash('sha256', $user->getId() . $_ENV['APP_SECRET'] . date('Y-m-d'));

        return $this->json([
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    // ════════════════════════════════════════════════
    //  PROFILE
    // ════════════════════════════════════════════════

    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function profile(Request $request): JsonResponse
    {
        $user = $request->attributes->get('_api_user');

        return $this->json(['user' => $this->serializeUser($user)]);
    }

    // ════════════════════════════════════════════════
    //  DASHBOARD
    // ════════════════════════════════════════════════

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
            if ($eval->getEndDate() < $now || $eval->getStartDate() > $now) continue;

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

    // ════════════════════════════════════════════════
    //  EVALUATIONS LIST
    // ════════════════════════════════════════════════

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

    // ════════════════════════════════════════════════
    //  EVALUATION FORM (GET questions, POST submit)
    // ════════════════════════════════════════════════

    #[Route('/evaluations/{id}/questions', name: 'evaluation_questions', methods: ['GET'])]
    public function evaluationQuestions(
        EvaluationPeriod $eval,
        Request $request,
        QuestionRepository $questionRepo
    ): JsonResponse {
        $user = $request->attributes->get('_api_user');

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
            if (!$question) continue;

            $response = new \App\Entity\EvaluationResponse();
            $response->setEvaluationPeriod($eval);
            $response->setQuestion($question);
            $response->setRating((int)$rating);
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

    // ════════════════════════════════════════════════
    //  RESULTS (for faculty viewing their own)
    // ════════════════════════════════════════════════

    #[Route('/my-results', name: 'my_results', methods: ['GET'])]
    public function myResults(
        Request $request,
        EvaluationResponseRepository $responseRepo,
        EvaluationPeriodRepository $evalRepo
    ): JsonResponse {
        $user = $request->attributes->get('_api_user');

        $responses = $responseRepo->createQueryBuilder('r')
            ->select('ep.id as evalId, ep.evaluationType, ep.schoolYear, ep.semester,
                       AVG(r.rating) as avgRating, COUNT(DISTINCT r.evaluator) as evaluatorCount')
            ->join('r.evaluationPeriod', 'ep')
            ->where('r.faculty = :user')
            ->setParameter('user', $user)
            ->groupBy('ep.id, ep.evaluationType, ep.schoolYear, ep.semester')
            ->getQuery()
            ->getResult();

        return $this->json(['results' => $responses]);
    }

    // ════════════════════════════════════════════════
    //  SUBJECTS (faculty's assigned subjects)
    // ════════════════════════════════════════════════

    #[Route('/my-subjects', name: 'my_subjects', methods: ['GET'])]
    public function mySubjects(
        Request $request,
        SubjectRepository $subjectRepo
    ): JsonResponse {
        $user = $request->attributes->get('_api_user');

        $subjects = $subjectRepo->findBy(['faculty' => $user]);
        $result = [];
        foreach ($subjects as $s) {
            $result[] = [
                'id' => $s->getId(),
                'code' => $s->getSubjectCode(),
                'name' => $s->getSubjectName(),
                'semester' => $s->getSemester(),
                'schoolYear' => $s->getSchoolYear(),
                'yearLevel' => $s->getYearLevel(),
                'section' => $s->getSection(),
                'schedule' => $s->getSchedule(),
                'units' => $s->getUnits(),
            ];
        }

        return $this->json(['subjects' => $result]);
    }

    // ════════════════════════════════════════════════
    //  ACTIVE EVALUATIONS (for real-time QR updates)
    // ════════════════════════════════════════════════

    #[Route('/active-evaluations', name: 'active_evaluations', methods: ['GET'])]
    public function activeEvaluations(
        EvaluationPeriodRepository $evalRepo
    ): JsonResponse {
        $now = new \DateTime();
        $activeEvals = $evalRepo->findBy(['evaluationType' => 'SET', 'status' => true]);
        $result = [];

        foreach ($activeEvals as $eval) {
            $isActive = ($eval->getStartDate() <= $now && $eval->getEndDate() >= $now);
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

    // ════════════════════════════════════════════════
    //  HELPERS
    // ════════════════════════════════════════════════

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
