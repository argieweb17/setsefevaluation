<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\EvaluationResponse;
use App\Repository\AcademicYearRepository;
use App\Repository\CurriculumRepository;
use App\Repository\EnrollmentRepository;
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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/evaluation')]
class EvaluationController extends AbstractController
{
    public function __construct(private AuditLogger $audit) {}

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
    public function qrRedirect(
        int $id,
        ?int $subjectId = null,
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SubjectRepository $subjectRepo,
        EnrollmentRepository $enrollRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
        QuestionCategoryDescriptionRepository $descRepo,
        EvaluationResponseRepository $responseRepo,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
        EntityManagerInterface $em,
    ): Response {
        $eval = $evalRepo->find($id);
        if (!$eval || !$eval->isOpen() || $eval->getEvaluationType() !== 'SET') {
            $this->addFlash('danger', 'This evaluation is not available.');
            return $this->redirectToRoute('app_login');
        }

        // If subject ID is provided, use it directly
        $subject = null;
        $section = '';
        $schedule = '';
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
                    $load = $fslRepo->findOneBy([
                        'faculty' => $facultyUser,
                        'subject' => $subject,
                        'academicYear' => $currentAY
                    ]);

                    if ($load) {
                        $section = strtoupper(trim((string) ($load->getSection() ?? '')));
                        $schedule = trim((string) ($load->getSchedule() ?? ''));
                    }
                }
            }
        }

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

        if ($request->isMethod('POST')) {
            $schoolId = trim($request->request->get('school_id', ''));
            $fullName = trim($request->request->get('full_name', ''));

            // Look up student by school ID
            $student = $userRepo->findOneBy(['schoolId' => $schoolId]);

            // Auto-register if not found
            if (!$student) {
                if ($fullName === '') {
                    $error = 'Please enter your Full Name.';
                } else {
                    $nameParts = explode(' ', $fullName, 2);
                    $firstName = $nameParts[0];
                    $lastName = $nameParts[1] ?? '';

                    $student = new \App\Entity\User();
                    $student->setSchoolId($schoolId);
                    $student->setFirstName($firstName);
                    $student->setLastName($lastName);
                    $student->setEmail(null);
                    $student->setPassword('');
                    $student->setRoles(['ROLE_STUDENT']);
                    $student->setAccountStatus('active');
                    $em->persist($student);
                    $em->flush();
                }
            }

            if ($student && !$error) {
                if ($responseRepo->hasSubmitted($student->getId(), $eval->getId(), $faculty->getId(), $subject->getId())) {
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

                    $success = true;
                }
            }
        }

        return $this->render('evaluation/qr_form.html.twig', [
            'evaluation' => $eval,
            'subject' => $subject,
            'faculty' => $faculty,
            'section' => $section,
            'schedule' => $schedule,
            'questions' => $questions,
            'categoryDescriptions' => $descRepo->findDescriptionsByType('SET'),
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
        EnrollmentRepository $enrollRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $openEvals = $evalRepo->findOpen();

        // ── Resolve the student's approved enrollments ──
        $studentYearLevel = $this->normalizeYearLevel($user->getYearLevel());

        $enrollments = $enrollRepo->findByStudent($user->getId());
        $enrolledSubjects = [];
        $enrollmentSections = [];
        foreach ($enrollments as $enrollment) {
            if ($enrollment->isApproved()) {
                $subj = $enrollment->getSubject();
                $enrolledSubjects[$subj->getId()] = $subj;
                $enrollmentSections[$subj->getId()] = $enrollment->getSection();
            }
        }

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

            // ── Only show subjects the student is enrolled in (approved) ──
            foreach ($enrolledSubjects as $subject) {
                $faculty = $subject->getFaculty();

                // If eval targets a specific faculty+subject, match against eval
                if ($eval->getFaculty() && $eval->getSubject()) {
                    $subjectLabel = $subject->getSubjectCode() . ' — ' . $subject->getSubjectName();
                    if ($subjectLabel !== $eval->getSubject()) {
                        continue;
                    }
                    if (!$faculty) {
                        $faculty = $userRepo->findOneByFullName($eval->getFaculty());
                    }
                    if (!$faculty || $faculty->getFullName() !== $eval->getFaculty()) {
                        continue;
                    }
                } else {
                    if (!$faculty) continue;
                }

                // If eval targets a specific section, match against enrollment section
                if ($eval->getSection() !== null && $eval->getSection() !== '') {
                    $studentSection = $enrollmentSections[$subject->getId()] ?? null;
                    if ($studentSection === null || strtolower(trim($studentSection)) !== strtolower(trim($eval->getSection()))) {
                        continue;
                    }
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
        EnrollmentRepository $enrollRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $eval = $evalRepo->find($evalId);
        $subject = $subjectRepo->find($subjectId);

        if (!$eval || !$subject || !$eval->isOpen() || $eval->getEvaluationType() !== 'SET') {
            $this->addFlash('danger', 'Invalid evaluation or evaluation is closed.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        // Check if student is enrolled (approved) in this subject
        $enrolled = false;
        $studentSection = null;
        $enrollments = $enrollRepo->findByStudent($user->getId());
        foreach ($enrollments as $enrollment) {
            if ($enrollment->isApproved() && $enrollment->getSubject()->getId() === $subjectId) {
                $enrolled = true;
                $studentSection = $enrollment->getSection();
                break;
            }
        }
        if (!$enrolled) {
            $this->addFlash('danger', 'You are not enrolled in this subject.');
            return $this->redirectToRoute('evaluation_set_index');
        }

        // If eval targets a specific section, verify student's enrollment section matches
        if ($eval->getSection() !== null && $eval->getSection() !== '') {
            if ($studentSection === null || strtoupper(trim($studentSection)) !== strtoupper(trim($eval->getSection()))) {
                $this->addFlash('danger', 'This evaluation is not for your section.');
                return $this->redirectToRoute('evaluation_set_index');
            }
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
}
