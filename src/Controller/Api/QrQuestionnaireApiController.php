<?php

namespace App\Controller\Api;

use App\Entity\EvaluationPeriod;
use App\Entity\Question;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\QuestionCategoryDescriptionRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/api', name: 'api_')]
class QrQuestionnaireApiController extends AbstractController
{
    #[Route('/qr/evaluations', name: 'qr_evaluations', methods: ['GET'])]
    public function qrEvaluations(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SubjectRepository $subjectRepo,
    ): JsonResponse {
        $evaluations = $evalRepo->findOpen('SET');
        $items = [];

        foreach ($evaluations as $evaluation) {
            $items[] = $this->serializeQrEvaluation($request, $evaluation, $subjectRepo);
        }

        return $this->json([
            'count' => count($items),
            'evaluations' => $items,
        ]);
    }

    #[Route('/qr/evaluations/{id}', name: 'qr_evaluation_detail', methods: ['GET'])]
    public function qrEvaluationDetail(
        int $id,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SubjectRepository $subjectRepo,
    ): JsonResponse {
        $evaluation = $evalRepo->find($id);
        if (!$evaluation) {
            return $this->json(['error' => 'Evaluation not found.'], 404);
        }

        return $this->json([
            'evaluation' => $this->serializeQrEvaluation($request, $evaluation, $subjectRepo),
        ]);
    }

    #[Route('/questionnaire/evaluations/{id}', name: 'questionnaire_evaluation', methods: ['GET'])]
    public function questionnaireByEvaluation(
        int $id,
        EvaluationPeriodRepository $evalRepo,
        QuestionRepository $questionRepo,
        QuestionCategoryDescriptionRepository $descRepo,
    ): JsonResponse {
        $evaluation = $evalRepo->find($id);
        if (!$evaluation) {
            return $this->json(['error' => 'Evaluation not found.'], 404);
        }

        $evaluationType = strtoupper((string) ($evaluation->getEvaluationType() ?? 'SET'));

        return $this->json([
            'evaluation' => [
                'id' => $evaluation->getId(),
                'type' => $evaluationType,
                'schoolYear' => $evaluation->getSchoolYear(),
                'semester' => $evaluation->getSemester(),
                'faculty' => $evaluation->getFaculty(),
                'subject' => $evaluation->getSubject(),
                'section' => $evaluation->getSection(),
                'schedule' => $evaluation->getTime(),
                'isOpen' => $evaluation->isOpen(),
                'startDate' => $evaluation->getStartDate()->format('Y-m-d H:i:s'),
                'endDate' => $evaluation->getEndDate()->format('Y-m-d H:i:s'),
            ],
            'questionnaire' => $this->buildQuestionnairePayload($evaluationType, $questionRepo, $descRepo),
        ]);
    }

    #[Route('/questionnaire/type/{evaluationType}', name: 'questionnaire_type', methods: ['GET'])]
    public function questionnaireByType(
        string $evaluationType,
        QuestionRepository $questionRepo,
        QuestionCategoryDescriptionRepository $descRepo,
    ): JsonResponse {
        $normalizedType = strtoupper(trim($evaluationType));
        if (!in_array($normalizedType, [EvaluationPeriod::TYPE_SET, EvaluationPeriod::TYPE_SEF], true)) {
            return $this->json([
                'error' => 'Invalid evaluation type. Allowed values: SET, SEF.',
            ], 400);
        }

        return $this->json([
            'type' => $normalizedType,
            'questionnaire' => $this->buildQuestionnairePayload($normalizedType, $questionRepo, $descRepo),
        ]);
    }

    private function buildQuestionnairePayload(
        string $evaluationType,
        QuestionRepository $questionRepo,
        QuestionCategoryDescriptionRepository $descRepo,
    ): array {
        $questions = $questionRepo->findBy(
            ['evaluationType' => $evaluationType, 'isActive' => true],
            ['category' => 'ASC', 'sortOrder' => 'ASC']
        );

        $categoryDescriptions = $descRepo->findDescriptionsByType($evaluationType);

        $grouped = [];
        foreach ($questions as $question) {
            $category = (string) ($question->getCategory() ?? 'General');
            if (!isset($grouped[$category])) {
                $grouped[$category] = [
                    'category' => $category,
                    'description' => (string) ($categoryDescriptions[$category] ?? ''),
                    'questions' => [],
                ];
            }

            $grouped[$category]['questions'][] = $this->serializeQuestion($question, $evaluationType);
        }

        return [
            'type' => $evaluationType,
            'disclaimerText' => $descRepo->getDisclaimerText($evaluationType),
            'disclaimerHtml' => $descRepo->getDisclaimerHtml($evaluationType),
            'categoryDescriptions' => $categoryDescriptions,
            'categories' => array_values($grouped),
            'questionCount' => count($questions),
        ];
    }

    private function serializeQuestion(Question $question, string $evaluationType): array
    {
        return [
            'id' => $question->getId(),
            'text' => $question->getQuestionText(),
            'category' => $question->getCategory(),
            'weight' => $question->getWeight(),
            'isRequired' => $question->isRequired(),
            'sortOrder' => $question->getSortOrder(),
            'evidenceItems' => $evaluationType === EvaluationPeriod::TYPE_SEF ? $question->getEvidenceItems() : [],
        ];
    }

    private function serializeQrEvaluation(Request $request, EvaluationPeriod $evaluation, SubjectRepository $subjectRepo): array
    {
        $subjectId = $this->resolveSubjectIdFromEvaluation($evaluation, $subjectRepo);
        $section = trim((string) ($evaluation->getSection() ?? ''));

        if ($subjectId !== null && $section !== '') {
            $qrPath = $this->generateUrl('evaluation_qr_redirect_with_section', [
                'id' => $evaluation->getId(),
                'subjectId' => $subjectId,
                'section' => $section,
            ], UrlGeneratorInterface::ABSOLUTE_PATH);
        } elseif ($subjectId !== null) {
            $qrPath = $this->generateUrl('evaluation_qr_redirect_with_subject', [
                'id' => $evaluation->getId(),
                'subjectId' => $subjectId,
            ], UrlGeneratorInterface::ABSOLUTE_PATH);
        } else {
            $qrPath = $this->generateUrl('evaluation_qr_redirect', [
                'id' => $evaluation->getId(),
            ], UrlGeneratorInterface::ABSOLUTE_PATH);
        }

        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $qrUrl = $baseUrl . $qrPath;

        return [
            'id' => $evaluation->getId(),
            'type' => $evaluation->getEvaluationType(),
            'schoolYear' => $evaluation->getSchoolYear(),
            'semester' => $evaluation->getSemester(),
            'faculty' => $evaluation->getFaculty(),
            'subject' => $evaluation->getSubject(),
            'section' => $evaluation->getSection(),
            'schedule' => $evaluation->getTime(),
            'department' => $evaluation->getDepartment()?->getDepartmentName(),
            'yearLevel' => $evaluation->getYearLevel(),
            'isOpen' => $evaluation->isOpen(),
            'status' => $evaluation->isStatus(),
            'startDate' => $evaluation->getStartDate()->format('Y-m-d H:i:s'),
            'endDate' => $evaluation->getEndDate()->format('Y-m-d H:i:s'),
            'subjectId' => $subjectId,
            'qrPath' => $qrPath,
            'qrUrl' => $qrUrl,
            'qrImageUrl' => 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($qrUrl),
        ];
    }

    private function resolveSubjectIdFromEvaluation(EvaluationPeriod $evaluation, SubjectRepository $subjectRepo): ?int
    {
        $subjectText = trim((string) ($evaluation->getSubject() ?? ''));
        if ($subjectText === '') {
            return null;
        }

        $parts = preg_split('/\s*[\-—]\s*/u', $subjectText, 2);
        $subjectCode = trim((string) ($parts[0] ?? ''));
        if ($subjectCode === '') {
            return null;
        }

        $subject = $subjectRepo->findOneBy(['subjectCode' => $subjectCode]);
        return $subject?->getId();
    }
}
