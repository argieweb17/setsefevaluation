<?php

namespace App\Controller\Api;

use App\Repository\EvaluationResponseRepository;
use App\Repository\SubjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class FacultyApiController extends AbstractController
{
    #[Route('/my-results', name: 'my_results', methods: ['GET'])]
    public function myResults(
        Request $request,
        EvaluationResponseRepository $responseRepo
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

    #[Route('/my-subjects', name: 'my_subjects', methods: ['GET'])]
    public function mySubjects(
        Request $request,
        SubjectRepository $subjectRepo
    ): JsonResponse {
        $user = $request->attributes->get('_api_user');

        $subjects = $subjectRepo->findBy(['faculty' => $user]);
        $result = [];
        foreach ($subjects as $subject) {
            $result[] = [
                'id' => $subject->getId(),
                'code' => $subject->getSubjectCode(),
                'name' => $subject->getSubjectName(),
                'semester' => $subject->getSemester(),
                'schoolYear' => $subject->getSchoolYear(),
                'yearLevel' => $subject->getYearLevel(),
                'section' => $subject->getSection(),
                'schedule' => $subject->getSchedule(),
                'units' => $subject->getUnits(),
            ];
        }

        return $this->json(['subjects' => $result]);
    }
}
