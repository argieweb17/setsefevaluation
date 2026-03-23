<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\EvaluationResponse;
use App\Repository\AcademicYearRepository;
use App\Repository\CurriculumRepository;
use App\Repository\DepartmentRepository;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\EvaluationResponseRepository;
use App\Repository\FacultySubjectLoadRepository;
use App\Repository\UserRepository;
use App\Repository\QuestionCategoryDescriptionRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/evaluation')]
class EvaluationController extends AbstractController
{
    public function __construct(
        private AuditLogger $audit,
        private MailerInterface $mailer,
    ) {}

    // ════════════════════════════════════════════════
    //  SET — Student Evaluation for Teacher
    // ════════════════════════════════════════════════

    /**
     * Normalize year-level strings so "4th Year" matches "Fourth Year", etc.
     */
    private function normalizeYearLevel(?string $yl): ?string
    {
        if ($yl === null) return null;
        $map = [
            '1st year' => 'First Year',  'first year' => 'First Year',
            '2nd year' => 'Second Year', 'second year' => 'Second Year',
            '3rd year' => 'Third Year',  'third year' => 'Third Year',
            '4th year' => 'Fourth Year', 'fourth year' => 'Fourth Year',
        ];
        return $map[strtolower(trim($yl))] ?? $yl;
    }

    #[Route('/qr/{id}', name: 'evaluation_qr_redirect', methods: ['GET', 'POST'])]
    #[Route('/qr/{id}/{subjectId}', name: 'evaluation_qr_redirect_with_subject', methods: ['GET', 'POST'])]
    #[Route('/qr/{id}/{subjectId}/{section}', name: 'evaluation_qr_redirect_with_section', methods: ['GET', 'POST'])]
    public function qrRedirect(
        int $id,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SubjectRepository $subjectRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
        QuestionCategoryDescriptionRepository $descRepo,
        EvaluationResponseRepository $responseRepo,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
        DepartmentRepository $deptRepo,
        EntityManagerInterface $em,
        ?int $subjectId = null,
        ?string $section = null,
    ): Response {
        $eval = $evalRepo->find($id);
        if (!$eval || !$eval->isOpen() || $eval->getEvaluationType() !== 'SET') {
            $this->addFlash('danger', 'This evaluation is not available.');
            return $this->redirectToRoute('app_login');
        }

        // If subject ID is provided, use it directly
        $subject = null;
        $sectionFromLoad = '';
        $schedule = '';
        $sectionParam = null; // Store the section parameter separately

        if ($subjectId) {
            $subject = $subjectRepo->find($subjectId);

            // Fetch section and schedule from FacultySubjectLoad
            if ($subject && $eval->getFaculty()) {
                $facultyName = $eval->getFaculty();
                $facultyUsers = $userRepo->createQueryBuilder('u')
                    ->where('CONCAT(u.lastName, \', \', u.firstName) = :fullName')
                    ->orWhere('CONCAT(u.firstName, \' \', u.lastName) = :fullName')
                    ->setParameter('fullName', $facultyName)
                    ->getQuery()->getResult();

                if (!empty($facultyUsers)) {
                    $facultyUser = $facultyUsers[0];
                    $currentAY = $ayRepo->findCurrent();

                    // If section parameter is provided, search for that specific section
                    if ($section) {
                        $load = $fslRepo->findOneBy([
                            'faculty' => $facultyUser,
                            'subject' => $subject,
                            'section' => $section,
                            'academicYear' => $currentAY
                        ]);
                        $sectionParam = strtoupper(trim($section));
                    } else {
                        // Otherwise, get the first load
                        $load = $fslRepo->findOneBy([
                            'faculty' => $facultyUser,
                            'subject' => $subject,
                            'academicYear' => $currentAY
                        ]);
                    }

                    if ($load) {
                        $sectionFromLoad = strtoupper(trim((string) ($load->getSection() ?? '')));
                        $schedule = trim((string) ($load->getSchedule() ?? ''));
                    }
                }
            }
        }

        // Determine final section to display
        $finalSection = $sectionParam ?? $sectionFromLoad;

        // Otherwise, resolve subject from evaluation's subject string ("CODE — Name")
        if (!$subject) {
            $subjectStr = $eval->getSubject();
            if ($subjectStr) {
                $parts = explode(' — ', $subjectStr, 2);
                $code = trim($parts[0]);
                $subject = $subjectRepo->findOneBy(['subjectCode' => $code]);
            }
        }

        if (!$subject) {
            $this->addFlash('danger', 'Subject not found for this evaluation.');
            return $this->redirectToRoute('app_login');
        }

        // Resolve faculty
        $faculty = $subject->getFaculty();
        if (!$faculty && $eval->getFaculty()) {
            $faculty = $userRepo->findOneByFullName($eval->getFaculty());
        }
        if (!$faculty) {
            $this->addFlash('danger', 'No faculty assigned to this evaluation.');
            return $this->redirectToRoute('app_login');
        }

        $questions = $questionRepo->findByType('SET');
        $error = null;
        $success = false;
        $yearLevelOptions = ['First Year', 'Second Year', 'Third Year', 'Fourth Year'];
        $colleges = $deptRepo->findDistinctCollegeNames();
        $departments = $deptRepo->findAllOrdered();

        if ($request->isMethod('POST')) {
            $schoolId = trim($request->request->get('school_id', ''));
            $fullName = trim($request->request->get('full_name', ''));
            $email = strtolower(trim($request->request->get('email', '')));
            $collegeName = trim($request->request->get('college_name', ''));
            $yearLevel = trim($request->request->get('year_level', ''));
            $departmentId = (int) $request->request->get('department_id', 0);

            if ($schoolId === '') {
                $error = 'Please enter your Student ID.';
            } elseif ($fullName === '') {
                $error = 'Please enter your Full Name.';
            } elseif ($email === '') {
                $error = 'Please enter your Email Address.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid Email Address.';
            } elseif ($collegeName === '') {
                $error = 'Please select your College.';
            } elseif (!in_array($collegeName, $colleges, true)) {
                $error = 'Please select a valid College.';
            } elseif ($departmentId <= 0) {
                $error = 'Please select your Department.';
            } elseif (!in_array($yearLevel, $yearLevelOptions, true)) {
                $error = 'Please select a valid Year Level.';
            }

            $department = null;
            if (!$error) {
                $department = $deptRepo->find($departmentId);
                if (!$department) {
                    $error = 'Selected department is invalid.';
                } elseif (($department->getCollegeName() ?? '') !== $collegeName) {
                    $error = 'Selected department does not belong to the selected college.';
                }
            }

            // Look up student by school ID
            $student = $schoolId !== '' ? $userRepo->findOneBy(['schoolId' => $schoolId]) : null;

            // Reuse existing student accounts by email to avoid duplicate-email blocks.
            if (!$error) {
                $emailOwner = $userRepo->findOneBy(['email' => $email]);
                if ($emailOwner) {
                    if (!$emailOwner->isStudent()) {
                        if (!$student || $emailOwner->getId() !== $student->getId()) {
                            $error = 'Email address is already in use.';
                        }
                    } elseif (!$student || $emailOwner->getId() !== $student->getId()) {
                        $student = $emailOwner;
                    }
                }
            }

            // Auto-register if not found
            if (!$student && !$error) {
                $nameParts = preg_split('/\s+/', $fullName, 2);
                $firstName = $nameParts[0] ?? '';
                $lastName = $nameParts[1] ?? '';

                if ($firstName === '' || $lastName === '') {
                    $error = 'Please enter your full name as First Name and Last Name.';
                } else {
                    $student = new \App\Entity\User();
                    $student->setSchoolId($schoolId);
                    $student->setFirstName($firstName);
                    $student->setLastName($lastName);
                    $student->setPassword('');
                    $student->setRoles(['ROLE_STUDENT']);
                    $student->setAccountStatus('active');
                }
            }

            if ($student && !$error) {
                if (!$student->getSchoolId()) {
                    $student->setSchoolId($schoolId);
                }
                $student->setEmail($email);
                $student->setDepartment($department);
                $student->setYearLevel($yearLevel);
                $em->persist($student);
                $em->flush();
            }

            if ($student && !$error) {
                $sectionToCheck = $finalSection !== '' ? $finalSection : null;
                if ($responseRepo->hasSubmitted($student->getId(), $eval->getId(), $faculty->getId(), $subject->getId(), $sectionToCheck)) {
                    $error = 'You have already submitted this evaluation.';
                } else {
                    // Save responses
                    $ratings = $request->request->all('ratings');
                    $comments = $request->request->all('comments');
                    $generalComment = trim($comments[0] ?? '');
                    $commentSaved = false;

                    foreach ($questions as $q) {
                        $rating = (int) ($ratings[$q->getId()] ?? 0);
                        if ($rating === 0) continue;

                        $response = new EvaluationResponse();
                        $response->setEvaluationPeriod($eval);
                        $response->setQuestion($q);
                        $response->setFaculty($faculty);
                        $response->setSubject($subject);
                        $response->setSection($finalSection !== '' ? $finalSection : null);
                        $response->setRating($rating);
                        // Attach the general comment to the first response
                        if (!$commentSaved && $generalComment !== '') {
                            $response->setComment($generalComment);
                            $commentSaved = true;
                        }
                        $response->setIsDraft(false);
                        $response->setEvaluator($student);
                        $em->persist($response);
                    }

                    $em->flush();

                    $this->audit->log(AuditLog::ACTION_SUBMIT_SET, 'EvaluationResponse', null,
                        'QR submission by ' . $student->getFullName() . ' for ' . $faculty->getFullName() . ' / ' . $subject->getSubjectCode());

                    $this->sendSubmissionConfirmationEmail(
                        $student->getEmail(),
                        $student->getFullName(),
                        $faculty->getFullName(),
                        (string) $subject->getSubjectCode(),
                        (string) $subject->getSubjectName(),
                        $finalSection !== '' ? $finalSection : null,
                    );

                    $success = true;
                }
            }
        }

        return $this->render('evaluation/qr_form.html.twig', [
            'evaluation' => $eval,
            'subject' => $subject,
            'faculty' => $faculty,
            'section' => $finalSection,
            'schedule' => $schedule,
            'questions' => $questions,
            'categoryDescriptions' => $descRepo->findDescriptionsByType('SET'),
            'colleges' => $colleges,
            'departments' => $departments,
            'yearLevelOptions' => $yearLevelOptions,
            'formValues' => [
                'school_id' => (string) $request->request->get('school_id', ''),
                'full_name' => (string) $request->request->get('full_name', ''),
                'email' => (string) $request->request->get('email', ''),
                'college_name' => (string) $request->request->get('college_name', ''),
                'department_id' => (string) $request->request->get('department_id', ''),
                'year_level' => (string) $request->request->get('year_level', ''),
            ],
            'error' => $error,
            'success' => $success,
        ]);
    }

    #[Route('/set', name: 'evaluation_set_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function setIndex(
        CurriculumRepository $curriculumRepo,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        SubjectRepository $subjectRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $openEvals = $evalRepo->findOpen();

        $studentYearLevel = $this->normalizeYearLevel($user->getYearLevel());

        // ── Build evaluation list ──
        $subjects = [];
        foreach ($openEvals as $eval) {
            if ($eval->getEvaluationType() !== 'SET') {
                continue;
            }

            // ── Targeting filters: department, college, year level ──
            if ($eval->getYearLevel() !== null) {
                $evalYL = $this->normalizeYearLevel($eval->getYearLevel());
                if ($evalYL !== $studentYearLevel) continue;
            }

            if ($eval->getDepartment() !== null && (
                $user->getDepartment() === null || $eval->getDepartment()->getId() !== $user->getDepartment()->getId()
            )) {
                continue;
            }

            if ($eval->getCollege() !== null && (
                $user->getDepartment() === null ||
                $user->getDepartment()->getCollegeName() !== $eval->getCollege()
            )) {
                continue;
            }

            // ── Resolve faculty from evaluation ──
            $faculty = null;
            $subject = null;

            if ($eval->getFaculty()) {
                $faculty = $userRepo->findOneByFullName($eval->getFaculty());
            }

            if ($eval->getSubject()) {
                $parts = explode(' — ', $eval->getSubject(), 2);
                $code = trim($parts[0]);
                $subject = $subjectRepo->findOneBy(['subjectCode' => $code]);
            }

            if (!$faculty || !$subject) {
                continue;
            }

            $submitted = $responseRepo->hasSubmitted(
                $user->getId(), $eval->getId(), $faculty->getId(), $subject->getId()
            );
            $drafts = $responseRepo->findDrafts($user->getId(), $eval->getId(), $faculty->getId(), $subject->getId());

            $subjects[] = [
                'evaluation' => $eval,
                'subject' => $subject,
                'faculty' => $faculty,
                'submitted' => $submitted,
                'hasDraft' => count($drafts) > 0,
            ];
        }

        return $this->render('evaluation/set_index.html.twig', [
            'subjects' => $subjects,
        ]);
    }

    #[Route('/set/{evalId}/{subjectId}', name: 'evaluation_set_form', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function setForm(
        int $evalId,
        int $subjectId,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SubjectRepository $subjectRepo,
        QuestionRepository $questionRepo,
        EvaluationResponseRepository $responseRepo,
        QuestionCategoryDescriptionRepository $descRepo,
        EntityManagerInterface $em,
        UserRepository $userRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $eval = $evalRepo->find($evalId);
        $subject = $subjectRepo->find($subjectId);

        if (!$eval || !$subject || !$eval->isOpen() || $eval->getEvaluationType() !== 'SET') {
            $this->addFlash('danger', 'Invalid evaluation or evaluation is closed.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        $faculty = $subject->getFaculty();
        if (!$faculty && $eval->getFaculty()) {
            $faculty = $userRepo->findOneByFullName($eval->getFaculty());
        }
        if (!$faculty) {
            $this->addFlash('danger', 'No faculty assigned to this subject.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        // Check if already submitted (non-draft)
        if ($responseRepo->hasSubmitted($user->getId(), $evalId, $faculty->getId(), $subjectId)) {
            $this->addFlash('warning', 'You have already submitted this evaluation.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        $questions = $questionRepo->findByType('SET');

        // Load existing drafts
        $drafts = $responseRepo->findDrafts($user->getId(), $evalId, $faculty->getId(), $subjectId);
        $draftMap = [];
        foreach ($drafts as $draft) {
            $draftMap[$draft->getQuestion()->getId()] = $draft;
        }

        if ($request->isMethod('POST')) {
            $isDraft = $request->request->get('_action') === 'save_draft';
            $ratings = $request->request->all('ratings');
            $comments = $request->request->all('comments');
            $generalComment = trim($comments[0] ?? '');
            $commentSaved = false;

            // Remove old drafts
            foreach ($drafts as $draft) {
                $em->remove($draft);
            }

            foreach ($questions as $q) {
                $rating = (int) ($ratings[$q->getId()] ?? 0);
                if ($rating === 0 && !$isDraft) {
                    continue; // Skip unrated for final submission
                }

                $response = new EvaluationResponse();
                $response->setEvaluationPeriod($eval);
                $response->setQuestion($q);
                $response->setFaculty($faculty);
                $response->setSubject($subject);
                $response->setRating($rating);
                // Attach the general comment to the first response
                if (!$commentSaved && $generalComment !== '') {
                    $response->setComment($generalComment);
                    $commentSaved = true;
                }
                $response->setIsDraft($isDraft);

                // Always store evaluator for submission tracking;
                // anonymity is enforced at the reporting layer
                $response->setEvaluator($user);

                $em->persist($response);
            }

            $em->flush();

            if ($isDraft) {
                $this->audit->log(AuditLog::ACTION_SAVE_DRAFT, 'EvaluationResponse', null,
                    'Saved draft SET for ' . $faculty->getFullName() . ' / ' . $subject->getSubjectCode());
                $this->addFlash('info', 'Draft saved. You can continue later.');
            } else {
                $this->audit->log(AuditLog::ACTION_SUBMIT_SET, 'EvaluationResponse', null,
                    'Submitted SET for ' . $faculty->getFullName() . ' / ' . $subject->getSubjectCode());
                $this->sendSubmissionConfirmationEmail(
                    $user->getEmail(),
                    $user->getFullName(),
                    $faculty->getFullName(),
                    (string) $subject->getSubjectCode(),
                    (string) $subject->getSubjectName(),
                );
                $this->addFlash('success', 'Evaluation submitted successfully. Thank you!');
            }

            return $this->redirectToRoute('evaluation_set_index');
        }

        return $this->render('evaluation/set_form.html.twig', [
            'evaluation' => $eval,
            'subject' => $subject,
            'faculty' => $faculty,
            'questions' => $questions,
            'draftMap' => $draftMap,
            'categoryDescriptions' => $descRepo->findDescriptionsByType('SET'),
        ]);
    }

    // ════════════════════════════════════════════════
    //  SET — Evaluation History
    // ════════════════════════════════════════════════

    #[Route('/history', name: 'evaluation_history', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function history(
        EvaluationResponseRepository $responseRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $history = $responseRepo->getStudentHistory($user->getId());

        return $this->render('evaluation/history.html.twig', [
            'history' => $history,
        ]);
    }

    private function sendSubmissionConfirmationEmail(
        ?string $toEmail,
        string $studentName,
        string $facultyName,
        string $subjectCode,
        string $subjectName,
        ?string $section = null,
    ): void {
        $toEmail = strtolower(trim((string) $toEmail));
        if ($toEmail === '') {
            return;
        }

        $sectionLine = $section ? "Section: {$section}\n" : '';
        $submittedAt = (new \DateTimeImmutable())->format('F d, Y h:i A');

        $message = (new Email())
            ->from(new Address('no-reply@setsef.local', 'SET-SEF Evaluation'))
            ->to($toEmail)
            ->subject('Evaluation Submission Confirmation')
            ->text(
                "Hello {$studentName},\n\n"
                . "Your evaluation has been submitted successfully.\n\n"
                . "Faculty: {$facultyName}\n"
                . "Subject: {$subjectCode} - {$subjectName}\n"
                . $sectionLine
                . "Submitted: {$submittedAt}\n\n"
                . "Thank you for participating in the SET-SEF evaluation process."
            );

        try {
            $this->mailer->send($message);
        } catch (\Throwable) {
            // Do not block evaluation submission if email transport is unavailable.
        }
    }
}
