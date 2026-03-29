<?php

namespace App\Controller\Api;

use App\Repository\AcademicYearRepository;
use App\Repository\FacultySubjectLoadRepository;
use App\Repository\SubjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/reports')]
#[IsGranted('ROLE_STAFF')]
class StaffApiController extends AbstractController
{
    #[Route('/api/faculty/{id}/subjects', name: 'staff_api_faculty_subjects', methods: ['GET'])]
    public function apiFacultySubjects(
        int $id,
        Request $request,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
        SubjectRepository $subjectRepo
    ): JsonResponse {
        $currentAY = $ayRepo->findCurrent();
        $loads = $fslRepo->findByFacultyAndAcademicYear($id, $currentAY ? $currentAY->getId() : null);
        $strictLoad = $request->query->getBoolean('strictLoad', false);

        $data = [];
        $seen = [];

        foreach ($loads as $load) {
            $subject = $load->getSubject();
            if (!$subject) {
                continue;
            }

            $value = $subject->getSubjectCode() . ' — ' . $subject->getSubjectName();
            $schedule = trim((string) ($load->getSchedule() ?? ''));
            $section = trim((string) ($load->getSection() ?? ''));
            $key = mb_strtolower($value . '|' . $schedule . '|' . $section);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $data[] = [
                'value' => $value,
                'schedule' => $schedule,
                'section' => $section,
            ];
        }

        if ($strictLoad) {
            return $this->json($data);
        }

        // Fallback for legacy direct assignments in Subject.faculty.
        foreach ($subjectRepo->findByFaculty($id) as $subject) {
            $value = $subject->getSubjectCode() . ' — ' . $subject->getSubjectName();
            $schedule = trim((string) ($subject->getSchedule() ?? ''));
            $section = trim((string) ($subject->getSection() ?? ''));
            $key = mb_strtolower($value . '|' . $schedule . '|' . $section);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $data[] = [
                'value' => $value,
                'schedule' => $schedule,
                'section' => $section,
            ];
        }

        return $this->json($data);
    }
}
