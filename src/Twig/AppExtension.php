<?php

namespace App\Twig;

use App\Repository\AcademicYearRepository;
use App\Repository\AuditLogRepository;
use App\Repository\CurriculumRepository;
use App\Repository\EvaluationMessageRepository;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\FacultyNotificationReadRepository;
use App\Repository\FacultySubjectLoadRepository;
use App\Repository\MessageNotificationRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    private ?array $cachedCounts = null;

    public function __construct(
        private Security $security,
        private SubjectRepository $subjectRepo,
        private UserRepository $userRepo,
        private CurriculumRepository $curriculumRepo,
        private AcademicYearRepository $academicYearRepo,
        private EvaluationPeriodRepository $evalPeriodRepo,
        private QuestionRepository $questionRepo,
        private AuditLogRepository $auditLogRepo,
        private EvaluationMessageRepository $evalMessageRepo,
        private FacultyNotificationReadRepository $notifReadRepo,
        private MessageNotificationRepository $messageNotifRepo,
        private FacultySubjectLoadRepository $fslRepo,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('faculty_subjects', [$this, 'getFacultySubjects']),
            new TwigFunction('sidebar_counts', [$this, 'getSidebarCounts']),
            new TwigFunction('current_academic_year', [$this, 'getCurrentAcademicYear']),
            new TwigFunction('faculty_pending_evaluations', [$this, 'getFacultyPendingEvaluations']),
            new TwigFunction('unread_message_notifications', [$this, 'getUnreadMessageNotifications']),
            new TwigFunction('faculty_has_conversation', [$this, 'facultyHasConversation']),
        ];
    }

    public function getCurrentAcademicYear(): ?array
    {
        $ay = $this->academicYearRepo->findCurrent();
        if (!$ay) {
            return null;
        }
        $label = $ay->getYearLabel();
        if ($ay->getSemester()) {
            $label .= ' · ' . $ay->getSemester();
        }
        return [
            'id' => $ay->getId(),
            'label' => $label,
            'yearLabel' => $ay->getYearLabel(),
            'semester' => $ay->getSemester(),
        ];
    }

    public function getSidebarCounts(): array
    {
        if ($this->cachedCounts !== null) {
            return $this->cachedCounts;
        }

        $allUsers = $this->userRepo->findAll();

        $students = 0;
        $faculty = 0;
        $staff = 0;
        $superiors = 0;
        $admins = 0;
        $pendingApprovals = 0;

        foreach ($allUsers as $u) {
            $roles = $u->getRoles();
            $isSuperiorBucket = $u->hasAssignedRole('ROLE_SUPERIOR') || $u->isDepartmentHeadFaculty();
            if ($u->getAccountStatus() === 'pending') {
                $pendingApprovals++;
            }
            if (in_array('ROLE_ADMIN', $roles)) {
                $admins++;
            } elseif ($isSuperiorBucket) {
                $superiors++;
            } elseif (in_array('ROLE_FACULTY', $roles, true)) {
                $faculty++;
            } elseif (in_array('ROLE_STAFF', $roles)) {
                $staff++;
            } else {
                $students++;
            }
        }

        $this->cachedCounts = [
            'users' => count($allUsers),
            'pending_approvals' => $pendingApprovals,
            'students' => $students,
            'faculty' => $faculty,
            'staff' => $staff,
            'superiors' => $superiors,
            'admins' => $admins,
            'curricula' => count($this->curriculumRepo->findAll()),
            'subjects' => count($this->subjectRepo->findAll()),
            'academic_years' => count($this->academicYearRepo->findAll()),
            'eval_periods' => count($this->evalPeriodRepo->findAll()),
            'questions' => count($this->questionRepo->findAll()),
            'audit_logs' => count($this->auditLogRepo->findAll()),
            'faculty_messages' => $this->evalMessageRepo->countPending(),
        ];

        return $this->cachedCounts;
    }

    /**
     * Returns subjects assigned to the currently logged-in faculty user.
     */
    public function getFacultySubjects(): array
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->security->getUser();
        if (!$user || !in_array('ROLE_FACULTY', $user->getRoles())) {
            return [];
        }

        $currentAY = $this->academicYearRepo->findCurrent();
        $subjectsById = [];

        $savedLoads = $this->fslRepo->findByFacultyAndAcademicYear($user->getId(), $currentAY?->getId());
        foreach ($savedLoads as $load) {
            $subject = $load->getSubject();
            $subjectsById[$subject->getId()] = $subject;
        }

        $directSubjects = $this->subjectRepo->findByFaculty($user->getId());
        foreach ($directSubjects as $subject) {
            $subjectsById[$subject->getId()] = $subject;
        }

        $subjects = array_values($subjectsById);
        usort($subjects, static fn($a, $b) => strcmp($a->getSubjectCode(), $b->getSubjectCode()));

        return $subjects;
    }

    /**
     * Returns open evaluation periods assigned to the current faculty user's loaded subjects.
     * Each item is ['eval' => EvaluationPeriod, 'read' => bool].
     */
    public function getFacultyPendingEvaluations(): array
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->security->getUser();
        if (!$user || !in_array('ROLE_FACULTY', $user->getRoles())) {
            return [];
        }

        $facultyName = $user->getFullName();
        $allOpen = $this->evalPeriodRepo->findOpen();
        $readIds = $this->notifReadRepo->findReadEvaluationIds($user->getId());
        $pending = [];

        foreach ($allOpen as $eval) {
            if ($eval->getEvaluationType() === 'SUPERIOR') {
                continue;
            }
            if ($eval->getFaculty() === $facultyName) {
                $pending[] = [
                    'eval' => $eval,
                    'read' => in_array($eval->getId(), $readIds),
                ];
            }
        }

        return $pending;
    }

    /**
     * Returns the count of unread message notifications for the current user.
     */
    public function getUnreadMessageNotifications(): int
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return 0;
        }

        return $this->messageNotifRepo->countUnreadForUser($user->getId());
    }

    public function facultyHasConversation(): bool
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->security->getUser();
        if (!$user || !in_array('ROLE_FACULTY', $user->getRoles(), true)) {
            return false;
        }

        return $this->evalMessageRepo->hasConversationBySender($user->getId());
    }
}
