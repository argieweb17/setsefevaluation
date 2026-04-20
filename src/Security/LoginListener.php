<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\FacultySubjectLoadRepository;
use App\Repository\LoadslipVerificationRepository;
use App\Repository\SubjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class)]
class LoginListener
{
    public function __construct(
        private SubjectRepository $subjectRepo,
        private FacultySubjectLoadRepository $fslRepo,
        private AcademicYearRepository $ayRepo,
        private LoadslipVerificationRepository $loadslipVerificationRepo,
        private EntityManagerInterface $em,
    ) {}

    private function normalizeStudentNumber(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = str_replace(
            ['O', 'Q', 'D', 'I', 'L', '|', '!', 'S', 'B', 'Z', 'G'],
            ['0', '0', '0', '1', '1', '1', '1', '5', '8', '2', '6'],
            $value
        );

        return (string) preg_replace('/[^0-9]/', '', $value);
    }

    private function normalizeSemesterLabel(?string $value): string
    {
        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/\b(1ST|FIRST)\b/u', $normalized)) {
            return 'FIRST';
        }
        if (preg_match('/\b(2ND|SECOND)\b/u', $normalized)) {
            return 'SECOND';
        }
        if (preg_match('/\b(3RD|THIRD|SUMMER)\b/u', $normalized)) {
            return 'SUMMER';
        }

        return '';
    }

    private function normalizeSchoolYearLabel(?string $value): string
    {
        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/\b(20\d{2})\s*[-\/]\s*(20\d{2})\b/u', $normalized, $m)) {
            return (string) ($m[1] ?? '') . '-' . (string) ($m[2] ?? '');
        }

        return '';
    }

    private function readLoadslipVerificationData(string $schoolId): ?array
    {
        $normalizedSchoolId = $this->normalizeStudentNumber($schoolId);
        $data = $this->loadslipVerificationRepo->findPayloadBySchoolId($normalizedSchoolId);
        if (!is_array($data)) {
            return null;
        }

        $studentNumber = $this->normalizeStudentNumber((string) ($data['studentNumber'] ?? ''));
        if ($studentNumber === '' || $studentNumber !== $normalizedSchoolId) {
            return null;
        }

        $codes = array_values(array_filter(array_map(
            static fn ($v): string => strtoupper(trim((string) $v)),
            (array) ($data['codes'] ?? [])
        )));

        $rows = array_values(array_filter((array) ($data['rows'] ?? []), static fn ($row): bool => is_array($row)));

        if (empty($codes) && empty($rows)) {
            return null;
        }

        $currentAcademicYear = $this->ayRepo->findCurrent() ?? $this->ayRepo->findLatestBySequence();
        $currentSchoolYear = $this->normalizeSchoolYearLabel((string) ($currentAcademicYear?->getYearLabel() ?? ''));
        $currentSemester = $this->normalizeSemesterLabel((string) ($currentAcademicYear?->getSemester() ?? ''));
        $storedSchoolYear = $this->normalizeSchoolYearLabel((string) ($data['schoolYear'] ?? ''));
        $storedSemester = $this->normalizeSemesterLabel((string) ($data['semester'] ?? ''));

        if (($currentSchoolYear !== '' || $currentSemester !== '') && (
            $storedSchoolYear === ''
            || $storedSemester === ''
            || ($currentSchoolYear !== '' && $storedSchoolYear !== $currentSchoolYear)
            || ($currentSemester !== '' && $storedSemester !== $currentSemester)
        )) {
            $this->loadslipVerificationRepo->deleteBySchoolId($normalizedSchoolId);
            return null;
        }

        return [
            'studentNumber' => $studentNumber,
            'codes' => $codes,
            'rows' => $rows,
            'previewPath' => trim((string) ($data['previewPath'] ?? '')),
            'schoolYear' => $storedSchoolYear,
            'semester' => $storedSemester,
            'verified' => (bool) ($data['verified'] ?? true),
        ];
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $user = $event->getUser();

        // Restore student loadslip verification state early so UI badges are accurate immediately after login.
        if ($user instanceof User && $user->isStudent() && $request->hasSession()) {
            $session = $request->getSession();
            $persisted = $this->readLoadslipVerificationData((string) ($user->getSchoolId() ?? ''));

            if ($persisted !== null) {
                $session->set('student_loadslip_codes', (array) ($persisted['codes'] ?? []));
                $session->set('student_loadslip_rows', (array) ($persisted['rows'] ?? []));
                $session->set('student_loadslip_student_number', (string) ($persisted['studentNumber'] ?? ''));
                $session->set('student_loadslip_verified', (bool) ($persisted['verified'] ?? true));

                $persistedPreviewPath = trim((string) ($persisted['previewPath'] ?? ''));
                if ($persistedPreviewPath !== '' && !str_contains($persistedPreviewPath, '..')) {
                    $session->set('student_loadslip_preview_path', $persistedPreviewPath);
                } else {
                    $session->remove('student_loadslip_preview_path');
                }
            } else {
                $session->remove('student_loadslip_codes');
                $session->remove('student_loadslip_rows');
                $session->remove('student_loadslip_student_number');
                $session->remove('student_loadslip_verified');
                $session->remove('student_loadslip_preview_path');
            }
        }

        $currentAY = $this->ayRepo->findCurrent();
        $ayEnded = $currentAY && $currentAY->getEndDate() && new \DateTime() > $currentAY->getEndDate();

        // Only reset all faculty assignments if the academic year has ended
        if ($ayEnded) {
            $subjects = $this->subjectRepo->findAll();
            foreach ($subjects as $subject) {
                if ($subject->getFaculty() !== null) {
                    $subject->setFaculty(null);
                    $subject->setSchedule(null);
                    $subject->setSection(null);
                }
            }
            $this->em->flush();
        }

        // If the logging-in user is faculty, restore their saved loads
        if (!$user instanceof User || !in_array('ROLE_FACULTY', $user->getRoles())) {
            return;
        }

        $savedLoads = $this->fslRepo->findByFacultyAndAcademicYear(
            $user->getId(),
            $currentAY?->getId()
        );

        foreach ($savedLoads as $fsl) {
            $subject = $fsl->getSubject();
            // Only restore if not already assigned to another faculty
            if ($subject->getFaculty() === null) {
                $subject->setFaculty($user);
                $subject->setSection($fsl->getSection());
                $subject->setSchedule($fsl->getSchedule());
            }
        }
        $this->em->flush();
    }
}
