<?php

namespace App\Controller\Api;

use App\Repository\AcademicYearRepository;
use App\Repository\FacultySubjectLoadRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminApiController extends AbstractController
{
    #[Route('/api/faculty/{id}/subjects', name: 'admin_api_faculty_subjects', methods: ['GET'])]
    public function apiFacultySubjects(int $id, FacultySubjectLoadRepository $fslRepo, AcademicYearRepository $ayRepo): JsonResponse
    {
        $currentAY = $ayRepo->findCurrent();
        $loads = $fslRepo->findByFacultyAndAcademicYear($id, $currentAY ? $currentAY->getId() : null);

        $data = [];
        foreach ($loads as $load) {
            $subject = $load->getSubject();
            $data[] = [
                'value' => $subject->getSubjectCode() . ' — ' . $subject->getSubjectName(),
                'schedule' => $load->getSchedule() ?? '',
                'section' => $load->getSection() ?? '',
            ];
        }

        return $this->json($data);
    }
}
