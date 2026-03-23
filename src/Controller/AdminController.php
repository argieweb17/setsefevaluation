<?php

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\AuditLog;
use App\Entity\Course;
use App\Entity\Curriculum;
use App\Entity\Department;
use App\Entity\EvaluationMessage;
use App\Entity\EvaluationPeriod;
use App\Entity\MessageNotification;
use App\Entity\Question;
use App\Entity\QuestionCategoryDescription;
use App\Entity\Subject;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\AuditLogRepository;
use App\Repository\CourseRepository;
use App\Repository\CurriculumRepository;
use App\Repository\DepartmentRepository;
use App\Repository\EvaluationMessageRepository;
use App\Repository\EvaluationPeriodRepository;
use App\Repository\EvaluationResponseRepository;
use App\Repository\FacultySubjectLoadRepository;
use App\Repository\MessageNotificationRepository;
use App\Repository\QuestionCategoryDescriptionRepository;
use App\Repository\QuestionRepository;
use App\Repository\SubjectRepository;
use App\Repository\SuperiorEvaluationRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(private AuditLogger $audit) {}

    // ════════════════════════════════════════════════
    //  A. USER MANAGEMENT
    // ════════════════════════════════════════════════

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function users(UserRepository $repo): Response
    {
        $users = $repo->findBy([], ['createdAt' => 'DESC']);
        $pendingUsers = $repo->findBy(['accountStatus' => 'pending'], ['createdAt' => 'DESC']);

        return $this->render('admin/users.html.twig', [
            'users' => $users,
            'pendingUsers' => $pendingUsers,
        ]);
    }

    #[Route('/users/{id}/approve', name: 'admin_user_approve', methods: ['POST'])]
    public function approveUser(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('approve' . $user->getId(), $request->request->get('_token'))) {
            $user->setAccountStatus('active');
            $em->flush();

            $this->audit->log(AuditLog::ACTION_ACTIVATE_USER, 'User', $user->getId(),
                'Approved registration for ' . $user->getFullName());

            $this->addFlash('success', $user->getFullName() . ' has been approved.');
        }
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/reject', name: 'admin_user_reject', methods: ['POST'])]
    public function rejectUser(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('reject' . $user->getId(), $request->request->get('_token'))) {
            $this->audit->log(AuditLog::ACTION_DELETE_USER, 'User', $user->getId(),
                'Rejected registration for ' . $user->getFullName());

            $em->remove($user);
            $em->flush();

            $this->addFlash('success', 'Registration rejected and user removed.');
        }
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/create', name: 'admin_user_create', methods: ['GET', 'POST'])]
    public function createUser(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        DepartmentRepository $deptRepo,
    ): Response {
        if ($request->isMethod('POST')) {
            $user = new User();
            $user->setFirstName($request->request->get('firstName', ''));
            $user->setLastName($request->request->get('lastName', ''));
            $user->setEmail($request->request->get('email', '') ?: null);

            $selectedRoles = $request->request->all('roles');
            $roleMap = [
                'admin' => 'ROLE_ADMIN',
                'superior' => 'ROLE_SUPERIOR',
                'faculty' => 'ROLE_FACULTY',
                'staff' => 'ROLE_STAFF',
            ];
            $roles = [];
            foreach ($selectedRoles as $r) {
                if (isset($roleMap[$r])) {
                    $roles[] = $roleMap[$r];
                }
            }
            $user->setRoles($roles);

            $deptId = $request->request->get('department');
            if ($deptId) {
                $dept = $deptRepo->find($deptId);
                if ($dept) {
                    $user->setDepartment($dept);
                }
            }

            $empStatus = $request->request->get('employmentStatus', '');
            $user->setEmploymentStatus($empStatus ?: null);

            $password = $request->request->get('password', 'password123');
            $user->setPassword($hasher->hashPassword($user, $password));

            $em->persist($user);
            $em->flush();

            $roleLabel = !empty($roles) ? implode(', ', $roles) : 'student';
            $this->audit->log(AuditLog::ACTION_CREATE_USER, 'User', $user->getId(),
                'Created user ' . $user->getFullName() . ' as ' . $roleLabel);

            $this->addFlash('success', 'User "' . $user->getFullName() . '" created successfully.');
            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/user_form.html.twig', [
            'departments' => $deptRepo->findAllOrdered(),
            'title' => 'Add New User',
        ]);
    }

    #[Route('/users/{id}/edit', name: 'admin_user_edit', methods: ['GET', 'POST'])]
    public function editUser(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        DepartmentRepository $deptRepo,
    ): Response {
        if ($request->isMethod('POST')) {
            $user->setFirstName($request->request->get('firstName', ''));
            $user->setLastName($request->request->get('lastName', ''));
            $user->setEmail($request->request->get('email', '') ?: null);

            $selectedRoles = $request->request->all('roles');
            $roleMap = [
                'admin' => 'ROLE_ADMIN',
                'superior' => 'ROLE_SUPERIOR',
                'faculty' => 'ROLE_FACULTY',
                'staff' => 'ROLE_STAFF',
            ];
            $roles = [];
            foreach ($selectedRoles as $r) {
                if (isset($roleMap[$r])) {
                    $roles[] = $roleMap[$r];
                }
            }
            $user->setRoles($roles);

            $deptId = $request->request->get('department');
            $user->setDepartment($deptId ? $deptRepo->find($deptId) : null);

            $empStatus = $request->request->get('employmentStatus', '');
            $user->setEmploymentStatus($empStatus ?: null);

            $em->flush();

            $roleLabel = !empty($roles) ? implode(', ', $roles) : 'student';
            $this->audit->log(AuditLog::ACTION_EDIT_USER, 'User', $user->getId(),
                'Edited user ' . $user->getFullName() . ' — roles: ' . $roleLabel);

            $this->addFlash('success', 'User updated.');
            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/user_form.html.twig', [
            'user' => $user,
            'departments' => $deptRepo->findAllOrdered(),
            'title' => 'Edit User — ' . $user->getFullName(),
        ]);
    }

    #[Route('/users/{id}/toggle-status', name: 'admin_user_toggle_status', methods: ['POST'])]
    public function toggleUserStatus(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('toggle' . $user->getId(), $request->request->get('_token'))) {
            $newStatus = $user->getAccountStatus() === 'active' ? 'inactive' : 'active';
            $user->setAccountStatus($newStatus);
            $em->flush();

            $action = $newStatus === 'active' ? AuditLog::ACTION_ACTIVATE_USER : AuditLog::ACTION_DEACTIVATE_USER;
            $this->audit->log($action, 'User', $user->getId(), $user->getFullName() . ' → ' . $newStatus);

            $this->addFlash('success', $user->getFullName() . ' is now ' . $newStatus . '.');
        }
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/reset-password', name: 'admin_user_reset_password', methods: ['POST'])]
    public function resetPassword(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        if ($this->isCsrfTokenValid('reset' . $user->getId(), $request->request->get('_token'))) {
            $newPass = $request->request->get('newPassword', 'password123');
            $user->setPassword($hasher->hashPassword($user, $newPass));
            $em->flush();

            $this->audit->log(AuditLog::ACTION_RESET_PASSWORD, 'User', $user->getId(),
                'Reset password for ' . $user->getFullName());

            $this->addFlash('success', 'Password reset for ' . $user->getFullName() . '.');
        }
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/{id}/delete', name: 'admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $user->getId(), $request->request->get('_token'))) {
            if ($user === $this->getUser()) {
                $this->addFlash('danger', 'You cannot delete your own account.');
                return $this->redirectToRoute('admin_users');
            }

            $this->audit->log(AuditLog::ACTION_DELETE_USER, 'User', $user->getId(),
                'Deleted user ' . $user->getFullName());

            // Unlink subjects assigned to this faculty
            $subjects = $em->getRepository(Subject::class)->findBy(['faculty' => $user]);
            foreach ($subjects as $subj) {
                $subj->setFaculty(null);
            }

            // Avoid FK constraint errors when deleting users tied to faculty/admin messages.
            $em->createQuery('UPDATE App\\Entity\\EvaluationMessage m SET m.repliedBy = NULL WHERE m.repliedBy = :user')
                ->setParameter('user', $user)
                ->execute();

            $em->createQuery('DELETE FROM App\\Entity\\EvaluationMessage m WHERE m.sender = :user')
                ->setParameter('user', $user)
                ->execute();

            $em->remove($user);
            $em->flush();
            $this->addFlash('success', 'User deleted.');
        }
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/users/bulk-upload', name: 'admin_users_bulk_upload', methods: ['POST'])]
    public function bulkUpload(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        DepartmentRepository $deptRepo,
    ): Response {
        $file = $request->files->get('csv_file');
        if (!$file) {
            $this->addFlash('danger', 'Please select a CSV file.');
            return $this->redirectToRoute('admin_users');
        }

        $handle = fopen($file->getPathname(), 'r');
        $header = fgetcsv($handle); // skip header row
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 4) continue;

            [$firstName, $lastName, $email, $role] = $row;
            $deptName = $row[4] ?? null;

            $user = new User();
            $user->setFirstName(trim($firstName));
            $user->setLastName(trim($lastName));
            $user->setEmail(trim($email) ?: null);
            $user->setPassword($hasher->hashPassword($user, 'password123'));

            $roleMap = [
                'admin' => ['ROLE_ADMIN'],
                'superior' => ['ROLE_SUPERIOR'],
                'faculty' => ['ROLE_FACULTY'],
                'staff' => ['ROLE_STAFF'],
                'student' => [],
            ];
            $user->setRoles($roleMap[strtolower(trim($role))] ?? []);

            if ($deptName) {
                $dept = $deptRepo->findOneBy(['departmentName' => trim($deptName)]);
                if ($dept) {
                    $user->setDepartment($dept);
                }
            }

            $em->persist($user);
            $count++;
        }

        fclose($handle);
        $em->flush();

        $this->audit->log(AuditLog::ACTION_BULK_UPLOAD, 'User', null,
            'Bulk uploaded ' . $count . ' users via CSV');

        $this->addFlash('success', $count . ' user(s) imported successfully.');
        return $this->redirectToRoute('admin_users');
    }

    // ── Role-specific listing pages ──

    #[Route('/students', name: 'admin_students', methods: ['GET'])]
    public function students(UserRepository $repo, DepartmentRepository $deptRepo): Response
    {
        $all = $repo->findBy([], ['createdAt' => 'DESC']);
        $students = array_filter($all, fn(User $u) => !in_array('ROLE_ADMIN', $u->getRoles()) && !in_array('ROLE_SUPERIOR', $u->getRoles()) && !in_array('ROLE_FACULTY', $u->getRoles()) && !in_array('ROLE_STAFF', $u->getRoles()));

        return $this->render('admin/students.html.twig', [
            'users' => array_values($students),
            'departments' => $deptRepo->findAllOrdered(),
        ]);
    }

    #[Route('/faculty-list', name: 'admin_faculty_list', methods: ['GET'])]
    public function facultyList(UserRepository $repo, DepartmentRepository $deptRepo): Response
    {
        $all = $repo->findBy([], ['createdAt' => 'DESC']);
        $faculty = array_filter($all, fn(User $u) => in_array('ROLE_FACULTY', $u->getRoles()));

        return $this->render('admin/faculty/faculty_list.html.twig', [
            'users' => array_values($faculty),
            'departments' => $deptRepo->findAllOrdered(),
        ]);
    }

    #[Route('/staff-list', name: 'admin_staff_list', methods: ['GET'])]
    public function staffList(UserRepository $repo, DepartmentRepository $deptRepo): Response
    {
        $all = $repo->findBy([], ['createdAt' => 'DESC']);
        $staff = array_filter($all, fn(User $u) => in_array('ROLE_STAFF', $u->getRoles()));

        return $this->render('admin/staff_list.html.twig', [
            'users' => array_values($staff),
            'departments' => $deptRepo->findAllOrdered(),
        ]);
    }

    #[Route('/superior-list', name: 'admin_superior_list', methods: ['GET'])]
    public function superiorList(UserRepository $repo, DepartmentRepository $deptRepo): Response
    {
        $all = $repo->findBy([], ['createdAt' => 'DESC']);
        $superiors = array_filter($all, fn(User $u) => in_array('ROLE_SUPERIOR', $u->getRoles()));

        return $this->render('admin/superiors.html.twig', [
            'users' => array_values($superiors),
            'departments' => $deptRepo->findAllOrdered(),
        ]);
    }

    #[Route('/admin-list', name: 'admin_admin_list', methods: ['GET'])]
    public function adminList(UserRepository $repo, DepartmentRepository $deptRepo): Response
    {
        $all = $repo->findBy([], ['createdAt' => 'DESC']);
        $admins = array_filter($all, fn(User $u) => in_array('ROLE_ADMIN', $u->getRoles()));

        return $this->render('admin/admins.html.twig', [
            'users' => array_values($admins),
            'departments' => $deptRepo->findAllOrdered(),
        ]);
    }

    // ── Migration ──

    #[Route('/migration', name: 'admin_migration', methods: ['GET'])]
    public function migration(UserRepository $userRepo, DepartmentRepository $deptRepo, CourseRepository $courseRepo): Response
    {
        $allUsers = $userRepo->findBy([], ['createdAt' => 'DESC']);
        $students = array_filter($allUsers, fn(User $u) => !in_array('ROLE_ADMIN', $u->getRoles()) && !in_array('ROLE_SUPERIOR', $u->getRoles()) && !in_array('ROLE_FACULTY', $u->getRoles()) && !in_array('ROLE_STAFF', $u->getRoles()));
        $faculty = array_filter($allUsers, fn(User $u) => in_array('ROLE_FACULTY', $u->getRoles()));
        $staff = array_filter($allUsers, fn(User $u) => in_array('ROLE_STAFF', $u->getRoles()));
        $superiors = array_filter($allUsers, fn(User $u) => in_array('ROLE_SUPERIOR', $u->getRoles()));
        $admins = array_filter($allUsers, fn(User $u) => in_array('ROLE_ADMIN', $u->getRoles()));

        return $this->render('admin/migration.html.twig', [
            'totalUsers' => count($allUsers),
            'studentCount' => count($students),
            'facultyCount' => count($faculty),
            'staffCount' => count($staff),
            'adminCount' => count($admins),
            'departments' => $deptRepo->findAllOrdered(),
            'courses' => $courseRepo->findAllOrdered(),
        ]);
    }

    #[Route('/migration/import', name: 'admin_migration_import', methods: ['POST'])]
    public function migrationImport(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        DepartmentRepository $deptRepo,
        CourseRepository $courseRepo,
    ): Response {
        $file = $request->files->get('csv_file');
        $targetRole = $request->request->get('target_role', 'student');

        if (!$file) {
            $this->addFlash('danger', 'Please select a CSV file.');
            return $this->redirectToRoute('admin_migration');
        }

        $handle = fopen($file->getPathname(), 'r');
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            $this->addFlash('danger', 'CSV file is empty or invalid.');
            return $this->redirectToRoute('admin_migration');
        }

        // Normalize header names
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $roleMap = [
            'admin' => ['ROLE_ADMIN'],
            'superior' => ['ROLE_SUPERIOR'],
            'faculty' => ['ROLE_FACULTY'],
            'staff' => ['ROLE_STAFF'],
            'student' => [],
        ];

        $count = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3) { $skipped++; continue; }

            $data = array_combine($header, array_pad($row, count($header), ''));

            $firstName = trim($data['firstname'] ?? $data['first_name'] ?? $data['first name'] ?? '');
            $lastName = trim($data['lastname'] ?? $data['last_name'] ?? $data['last name'] ?? '');
            $email = trim($data['email'] ?? '');

            if (!$firstName || !$lastName || !$email) { $skipped++; continue; }

            // Skip if email already exists
            $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($existing) { $skipped++; continue; }

            $user = new User();
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setEmail($email);
            $user->setPassword($hasher->hashPassword($user, $data['password'] ?? 'password123'));
            $user->setRoles($roleMap[$targetRole] ?? []);
            $user->setAccountStatus('active');

            // Optional fields
            if (!empty($data['department'])) {
                $dept = $deptRepo->findOneBy(['departmentName' => trim($data['department'])]);
                if ($dept) $user->setDepartment($dept);
            }
            if (!empty($data['school_id'] ?? $data['schoolid'] ?? '')) {
                $user->setSchoolId(trim($data['school_id'] ?? $data['schoolid'] ?? ''));
            }
            if (!empty($data['campus'])) {
                $user->setCampus(trim($data['campus']));
            }
            if ($targetRole === 'student') {
                if (!empty($data['year_level'] ?? $data['yearlevel'] ?? '')) {
                    $user->setYearLevel(trim($data['year_level'] ?? $data['yearlevel'] ?? ''));
                }
                if (!empty($data['course'])) {
                    $course = $courseRepo->findOneBy(['courseName' => strtoupper(trim($data['course']))]);
                    if ($course) $user->setCourse($course);
                }
            }
            if (in_array($targetRole, ['faculty', 'staff'])) {
                if (!empty($data['employment_status'] ?? $data['employmentstatus'] ?? '')) {
                    $user->setEmploymentStatus(trim($data['employment_status'] ?? $data['employmentstatus'] ?? ''));
                }
            }

            $em->persist($user);
            $count++;
        }

        fclose($handle);
        $em->flush();

        $this->audit->log(AuditLog::ACTION_BULK_UPLOAD, 'User', null,
            'Migration import: ' . $count . ' ' . $targetRole . '(s) imported, ' . $skipped . ' skipped');

        $msg = $count . ' ' . $targetRole . '(s) imported successfully.';
        if ($skipped > 0) $msg .= ' ' . $skipped . ' row(s) skipped (duplicate email or missing data).';
        $this->addFlash('success', $msg);

        return $this->redirectToRoute('admin_migration');
    }

    #[Route('/migration/export/{role}', name: 'admin_migration_export', methods: ['GET'])]
    public function migrationExport(string $role, UserRepository $userRepo): Response
    {
        $allUsers = $userRepo->findBy([], ['lastName' => 'ASC']);

        $roleFilterMap = [
            'student' => fn(User $u) => !in_array('ROLE_ADMIN', $u->getRoles()) && !in_array('ROLE_SUPERIOR', $u->getRoles()) && !in_array('ROLE_FACULTY', $u->getRoles()) && !in_array('ROLE_STAFF', $u->getRoles()),
            'faculty' => fn(User $u) => in_array('ROLE_FACULTY', $u->getRoles()),
            'staff' => fn(User $u) => in_array('ROLE_STAFF', $u->getRoles()),
            'superior' => fn(User $u) => in_array('ROLE_SUPERIOR', $u->getRoles()),
            'admin' => fn(User $u) => in_array('ROLE_ADMIN', $u->getRoles()),
        ];

        if (!isset($roleFilterMap[$role])) {
            $this->addFlash('danger', 'Invalid role type.');
            return $this->redirectToRoute('admin_migration');
        }

        $users = array_filter($allUsers, $roleFilterMap[$role]);

        $response = new StreamedResponse(function() use ($users, $role) {
            $handle = fopen('php://output', 'w');

            // Header row
            $headers = ['FirstName', 'LastName', 'Email', 'Department', 'SchoolID', 'Campus', 'Status'];
            if ($role === 'student') {
                $headers = array_merge($headers, ['YearLevel', 'Course']);
            }
            if (in_array($role, ['faculty', 'staff'])) {
                $headers[] = 'EmploymentStatus';
            }
            fputcsv($handle, $headers);

            foreach ($users as $u) {
                $row = [
                    $u->getFirstName(),
                    $u->getLastName(),
                    $u->getEmail(),
                    $u->getDepartment() ? $u->getDepartment()->getDepartmentName() : '',
                    $u->getSchoolId() ?? '',
                    $u->getCampus() ?? '',
                    $u->getAccountStatus(),
                ];
                if ($role === 'student') {
                    $row[] = $u->getYearLevel() ?? '';
                    $row[] = $u->getCourse() ? $u->getCourse()->getCourseName() : '';
                }
                if (in_array($role, ['faculty', 'staff'])) {
                    $row[] = $u->getEmploymentStatus() ?? '';
                }
                fputcsv($handle, $row);
            }
            fclose($handle);
        });

        $filename = $role . '_export_' . date('Ymd_His') . '.csv';
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        return $response;
    }

    // ════════════════════════════════════════════════
    //  B. ACADEMIC MANAGEMENT
    // ════════════════════════════════════════════════

    #[Route('/departments', name: 'admin_departments', methods: ['GET'])]
    public function departments(DepartmentRepository $repo): Response
    {
        return $this->render('admin/departments.html.twig', [
            'departments' => $repo->findAllOrdered(),
        ]);
    }

    #[Route('/departments/create', name: 'admin_department_create', methods: ['POST'])]
    public function createDepartment(Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('create_dept', $request->request->get('_token'))) {
            $dept = new Department();
            $dept->setDepartmentName($request->request->get('departmentName', ''));
            $dept->setCollegeName($request->request->get('collegeName'));
            $em->persist($dept);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_DEPARTMENT, 'Department', $dept->getId(),
                'Created department ' . $dept->getDepartmentName());

            $this->addFlash('success', 'Department created.');
        }
        return $this->redirectToRoute('admin_curricula');
    }

    #[Route('/departments/{id}/edit', name: 'admin_department_edit', methods: ['POST'])]
    public function editDepartment(Department $dept, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('edit_dept' . $dept->getId(), $request->request->get('_token'))) {
            $dept->setDepartmentName($request->request->get('departmentName', ''));
            $dept->setCollegeName($request->request->get('collegeName'));
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_DEPARTMENT, 'Department', $dept->getId(),
                'Updated department ' . $dept->getDepartmentName());

            if ($request->isXmlHttpRequest() || $request->headers->get('Accept') === 'application/json') {
                return $this->json(['success' => true, 'name' => $dept->getDepartmentName()]);
            }

            $this->addFlash('success', 'Department updated.');
        }
        return $this->redirectToRoute('admin_curricula');
    }

    #[Route('/departments/{id}/delete', name: 'admin_department_delete', methods: ['POST'])]
    public function deleteDepartment(Department $dept, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_dept' . $dept->getId(), $request->request->get('_token'))) {
            try {
                $em->remove($dept);
                $em->flush();
                $this->addFlash('success', 'Department deleted.');
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException $e) {
                $this->addFlash('danger', 'Cannot delete this department because it is still assigned to users or other records.');
            }
        }
        return $this->redirectToRoute('admin_curricula');
    }

    // ── Courses ──

    #[Route('/courses', name: 'admin_courses', methods: ['GET'])]
    public function courses(CourseRepository $repo): Response
    {
        return $this->render('admin/courses.html.twig', [
            'courses' => $repo->findAllOrdered(),
        ]);
    }

    #[Route('/courses/create', name: 'admin_course_create', methods: ['POST'])]
    public function createCourse(Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('create_course', $request->request->get('_token'))) {
            $course = new Course();
            $course->setCourseName($request->request->get('courseName', ''));
            $em->persist($course);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_COURSE, 'Course', $course->getId(),
                'Created course ' . $course->getCourseName());

            $this->addFlash('success', 'Course created.');
        }
        return $this->redirectToRoute('admin_courses');
    }

    #[Route('/courses/{id}/delete', name: 'admin_course_delete', methods: ['POST'])]
    public function deleteCourse(Course $course, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_course' . $course->getId(), $request->request->get('_token'))) {
            try {
                $em->remove($course);
                $em->flush();
                $this->addFlash('success', 'Course deleted.');
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException $e) {
                $this->addFlash('danger', 'Cannot delete this course because it is still assigned to users or other records.');
            }
        }
        return $this->redirectToRoute('admin_courses');
    }

    // ════════════════════════════════════════════════
    //  CURRICULUM MANAGEMENT DASHBOARD
    // ════════════════════════════════════════════════

    #[Route('/curricula', name: 'admin_curricula', methods: ['GET'])]
    public function curricula(
        CurriculumRepository $repo,
        CourseRepository $courseRepo,
        DepartmentRepository $deptRepo,
        SubjectRepository $subjectRepo,
        EvaluationPeriodRepository $evalRepo
    ): Response {
        $departments = $deptRepo->findAllOrdered();

        // Build distinct college list from departments
        $collegeNames = [];
        foreach ($departments as $d) {
            $cn = $d->getCollegeName();
            if ($cn && !in_array($cn, $collegeNames, true)) {
                $collegeNames[] = $cn;
            }
        }
        sort($collegeNames);

        return $this->render('admin/curricula.html.twig', [
            'curricula'         => $repo->findAllOrdered(),
            'courses'           => $courseRepo->findAllOrdered(),
            'departments'       => $departments,
            'subjects'          => $subjectRepo->findAll(),
            'evaluationPeriods' => $evalRepo->findAllOrdered(),
            'colleges'          => $collegeNames,
        ]);
    }

    #[Route('/curricula/create', name: 'admin_curriculum_create', methods: ['POST'])]
    public function createCurriculum(Request $request, EntityManagerInterface $em, CourseRepository $courseRepo, DepartmentRepository $deptRepo, SubjectRepository $subjectRepo): Response
    {
        if ($this->isCsrfTokenValid('create_curriculum', $request->request->get('_token'))) {
            $curriculum = new Curriculum();
            $curriculum->setCurriculumName($request->request->get('curriculumName', ''));
            $curriculum->setCurriculumYear($request->request->get('curriculumYear'));
            $curriculum->setDescription($request->request->get('description'));

            $courseId = $request->request->get('course');
            if ($courseId) { $curriculum->setCourse($courseRepo->find($courseId)); }
            $deptId = $request->request->get('department');
            if ($deptId) { $curriculum->setDepartment($deptRepo->find($deptId)); }

            foreach ($request->request->all('subjects') as $sid) {
                $s = $subjectRepo->find($sid);
                if ($s) { $curriculum->addSubject($s); }
            }

            $em->persist($curriculum);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_CURRICULUM, 'Curriculum', $curriculum->getId(),
                'Created curriculum ' . $curriculum->getCurriculumName());
            $this->addFlash('success', 'Program created successfully.');
        }
        return $this->redirectToRoute('admin_curricula');
    }

    #[Route('/curricula/{id}/edit', name: 'admin_curriculum_edit', methods: ['POST'])]
    public function editCurriculum(Curriculum $curriculum, Request $request, EntityManagerInterface $em, CourseRepository $courseRepo, DepartmentRepository $deptRepo, SubjectRepository $subjectRepo): Response
    {
        if ($this->isCsrfTokenValid('edit_curriculum' . $curriculum->getId(), $request->request->get('_token'))) {
            $curriculum->setCurriculumName($request->request->get('curriculumName', ''));
            $curriculum->setCurriculumYear($request->request->get('curriculumYear'));
            $curriculum->setDescription($request->request->get('description'));

            $courseId = $request->request->get('course');
            $curriculum->setCourse($courseId ? $courseRepo->find($courseId) : null);
            $deptId = $request->request->get('department');
            $curriculum->setDepartment($deptId ? $deptRepo->find($deptId) : null);

            foreach ($curriculum->getSubjects()->toArray() as $s) { $curriculum->removeSubject($s); }
            foreach ($request->request->all('subjects') as $sid) {
                $s = $subjectRepo->find($sid);
                if ($s) { $curriculum->addSubject($s); }
            }

            $em->flush();
            $this->addFlash('success', 'Program updated successfully.');
        }
        return $this->redirectToRoute('admin_curricula');
    }

    #[Route('/curricula/{id}/delete', name: 'admin_curriculum_delete', methods: ['POST'])]
    public function deleteCurriculum(Curriculum $curriculum, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_curriculum' . $curriculum->getId(), $request->request->get('_token'))) {
            $name = $curriculum->getCurriculumName();
            $em->remove($curriculum);
            $em->flush();
            $this->addFlash('success', 'Program "' . $name . '" deleted.');
        }
        return $this->redirectToRoute('admin_curricula');
    }

    #[Route('/curricula/{id}/add-subject', name: 'admin_curriculum_add_subject', methods: ['POST'])]
    public function addSubjectToCurriculum(Curriculum $curriculum, Request $request, EntityManagerInterface $em, SubjectRepository $subjectRepo, DepartmentRepository $deptRepo): Response
    {
        if ($this->isCsrfTokenValid('add_subject_curriculum' . $curriculum->getId(), $request->request->get('_token'))) {
            $mode = $request->request->get('mode', 'existing');

            if ($mode === 'existing') {
                $subjectId = $request->request->get('subject_id');
                $subject = $subjectId ? $subjectRepo->find($subjectId) : null;
                if ($subject && !$curriculum->getSubjects()->contains($subject)) {
                    $curriculum->addSubject($subject);
                    $em->flush();
                    $this->addFlash('success', 'Subject "' . $subject->getSubjectCode() . '" added to curriculum.');
                } else {
                    $this->addFlash('warning', 'Subject already in curriculum or not found.');
                }
            } else {
                $subject = new Subject();
                $subject->setSubjectCode($request->request->get('subjectCode', ''));
                $subject->setSubjectName($request->request->get('subjectName', ''));
                $subject->setSemester($request->request->get('semester'));
                $subject->setSchoolYear($request->request->get('schoolYear'));
                $subject->setYearLevel($request->request->get('yearLevel'));
                $subject->setTerm($request->request->get('term'));
                $unitsVal = $request->request->get('units');
                if ($unitsVal !== null && $unitsVal !== '') { $subject->setUnits((int) $unitsVal); }
                $deptId = $request->request->get('department');
                if ($deptId) { $subject->setDepartment($deptRepo->find($deptId)); }

                $em->persist($subject);
                $curriculum->addSubject($subject);
                $em->flush();
                $this->addFlash('success', 'New subject "' . $subject->getSubjectCode() . '" created and added.');
            }
        }
        return $this->redirectToRoute('admin_curricula');
    }

    #[Route('/curricula/{id}/remove-subject/{subjectId}', name: 'admin_curriculum_remove_subject', methods: ['POST'])]
    public function removeSubjectFromCurriculum(Curriculum $curriculum, int $subjectId, Request $request, EntityManagerInterface $em, SubjectRepository $subjectRepo): Response
    {
        if ($this->isCsrfTokenValid('remove_subject_curriculum' . $curriculum->getId(), $request->request->get('_token'))) {
            $subject = $subjectRepo->find($subjectId);
            if ($subject) {
                $curriculum->removeSubject($subject);
                $em->flush();
                $this->addFlash('success', 'Subject "' . $subject->getSubjectCode() . '" removed from curriculum.');
            }
        }
        return $this->redirectToRoute('admin_curricula');
    }

    #[Route('/subject/{id}/edit', name: 'admin_subject_edit', methods: ['POST'])]
    public function editSubject(Subject $subject, Request $request, EntityManagerInterface $em, DepartmentRepository $deptRepo): Response
    {
        if ($this->isCsrfTokenValid('edit_subject' . $subject->getId(), $request->request->get('_token'))) {
            $subject->setSubjectCode($request->request->get('subjectCode', $subject->getSubjectCode()));
            $subject->setSubjectName($request->request->get('subjectName', $subject->getSubjectName()));
            $subject->setSemester($request->request->get('semester'));
            $subject->setSchoolYear($request->request->get('schoolYear'));
            $subject->setYearLevel($request->request->get('yearLevel'));
            $subject->setTerm($request->request->get('term'));
            $subject->setSection($request->request->get('section'));
            $subject->setRoom($request->request->get('room'));
            $subject->setSchedule($request->request->get('schedule'));
            $unitsVal = $request->request->get('units');
            $subject->setUnits(($unitsVal !== null && $unitsVal !== '') ? (int) $unitsVal : null);
            $deptId = $request->request->get('department');
            $subject->setDepartment($deptId ? $deptRepo->find($deptId) : null);
            $em->flush();
            $this->addFlash('success', 'Subject "' . $subject->getSubjectCode() . '" updated.');
        }
        return $this->redirectToRoute('admin_curricula');
    }

    #[Route('/subjects', name: 'admin_subjects', methods: ['GET'])]
    public function subjects(Request $request, SubjectRepository $repo, UserRepository $userRepo, DepartmentRepository $deptRepo): Response
    {
        $facultyList = $userRepo->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_FACULTY%')
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()->getResult();

        $filterFaculty  = $request->query->get('faculty');
        $filterDept     = $request->query->get('department');
        $filterSemester = $request->query->get('semester');
        $filterTerm     = $request->query->get('term');
        $filterCollege  = $request->query->get('college');
        $page           = max(1, (int) $request->query->get('page', 1));
        $limit          = 50;

        $qb = $repo->createQueryBuilder('s')
            ->leftJoin('s.department', 'd')
            ->orderBy('s.subjectCode', 'ASC');

        if ($filterFaculty) {
            $qb->andWhere('s.faculty = :fid')->setParameter('fid', $filterFaculty);
        }
        if ($filterDept) {
            $qb->andWhere('s.department = :did')->setParameter('did', $filterDept);
        }
        if ($filterSemester) {
            $qb->andWhere('s.semester = :sem')->setParameter('sem', $filterSemester);
        }
        if ($filterTerm) {
            $qb->andWhere('s.term = :term')->setParameter('term', $filterTerm);
        }
        if ($filterCollege) {
            $qb->andWhere('d.collegeName = :college')->setParameter('college', $filterCollege);
        }

        // Count total before pagination
        $totalFiltered = (int) (clone $qb)->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($totalFiltered / $limit));
        if ($page > $totalPages) { $page = $totalPages; }

        $subjects = $qb->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        $departments = $deptRepo->findAllOrdered();
        $colleges = [];
        foreach ($departments as $d) {
            $cn = $d->getCollegeName();
            if ($cn && !in_array($cn, $colleges, true)) {
                $colleges[] = $cn;
            }
        }
        sort($colleges);

        return $this->render('admin/subjects.html.twig', [
            'subjects'        => $subjects,
            'faculty'         => $facultyList,
            'departments'     => $departments,
            'colleges'        => $colleges,
            'filterFaculty'   => $filterFaculty,
            'filterDept'      => $filterDept,
            'filterSemester'  => $filterSemester,
            'filterTerm'      => $filterTerm,
            'filterCollege'   => $filterCollege,
            'currentPage'     => $page,
            'totalPages'      => $totalPages,
            'totalFiltered'   => $totalFiltered,
            'limit'           => $limit,
        ]);
    }

    #[Route('/subjects/create', name: 'admin_subject_create', methods: ['POST'])]
    public function createSubject(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
    ): Response {
        if ($this->isCsrfTokenValid('create_subject', $request->request->get('_token'))) {
            $subject = new Subject();
            $subject->setSubjectCode($request->request->get('subjectCode', ''));
            $subject->setSubjectName($request->request->get('subjectName', ''));
            $subject->setSemester($request->request->get('semester'));
            $subject->setSchoolYear($request->request->get('schoolYear'));
            $subject->setTerm($request->request->get('term'));
            $subject->setSection($request->request->get('section'));
            $subject->setRoom($request->request->get('room'));
            $subject->setSchedule($request->request->get('schedule'));
            $unitsVal = $request->request->get('units');
            if ($unitsVal !== null && $unitsVal !== '') { $subject->setUnits((int) $unitsVal); }

            $facultyId = $request->request->get('faculty');
            if ($facultyId) {
                $subject->setFaculty($userRepo->find($facultyId));
            }

            $deptId = $request->request->get('department');
            if ($deptId) {
                $subject->setDepartment($deptRepo->find($deptId));
            }

            $em->persist($subject);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_SUBJECT, 'Subject', $subject->getId(),
                'Created subject ' . $subject->getSubjectCode());

            $this->addFlash('success', 'Subject created.');
        }
        return $this->redirectToRoute('admin_subjects');
    }

    #[Route('/subjects/{id}/assign-faculty', name: 'admin_subject_assign_faculty', methods: ['POST'])]
    public function assignFaculty(
        Subject $subject,
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepo,
    ): Response {
        if ($this->isCsrfTokenValid('assign' . $subject->getId(), $request->request->get('_token'))) {
            $facultyId = $request->request->get('faculty');
            $faculty = $facultyId ? $userRepo->find($facultyId) : null;
            $subject->setFaculty($faculty);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_ASSIGN_FACULTY, 'Subject', $subject->getId(),
                'Assigned ' . ($faculty ? $faculty->getFullName() : 'none') . ' to ' . $subject->getSubjectCode());

            $this->addFlash('success', 'Faculty assigned.');
        }
        return $this->redirectToRoute('admin_subjects');
    }

    #[Route('/subjects/{id}/delete', name: 'admin_subject_delete', methods: ['POST'])]
    public function deleteSubject(Subject $subject, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_sub' . $subject->getId(), $request->request->get('_token'))) {
            try {
                $em->remove($subject);
                $em->flush();
                $this->addFlash('success', 'Subject deleted.');
            } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException $e) {
                $this->addFlash('danger', 'Cannot delete this subject because it has other records linked to it.');
            }
        }
        return $this->redirectToRoute('admin_subjects');
    }

    // ════════════════════════════════════════════════
    //  ACADEMIC YEAR MANAGEMENT
    // ════════════════════════════════════════════════

    #[Route('/academic-years', name: 'admin_academic_years', methods: ['GET'])]
    public function academicYears(AcademicYearRepository $repo): Response
    {
        return $this->render('admin/academic_years.html.twig', [
            'academicYears' => $repo->findAllOrdered(),
            'currentAY' => $repo->findCurrent(),
        ]);
    }

    #[Route('/academic-years/create', name: 'admin_academic_year_create', methods: ['POST'])]
    public function createAcademicYear(Request $request, EntityManagerInterface $em, AcademicYearRepository $repo): Response
    {
        if ($this->isCsrfTokenValid('create_ay', $request->request->get('_token'))) {
            $createSemesterDates = $request->request->getBoolean('createSemesterDates', false);

            if ($createSemesterDates) {
                $yearLabel = trim((string) $request->request->get('yearLabel', ''));
                if ($yearLabel === '') {
                    $this->addFlash('danger', 'Year label is required.');
                    return $this->redirectToRoute('admin_academic_years');
                }

                $termInputs = [
                    ['semester' => '1st Semester', 'start' => $request->request->get('firstStartDate'), 'end' => $request->request->get('firstEndDate')],
                    ['semester' => '2nd Semester', 'start' => $request->request->get('secondStartDate'), 'end' => $request->request->get('secondEndDate')],
                    ['semester' => 'Summer', 'start' => $request->request->get('summerStartDate'), 'end' => $request->request->get('summerEndDate')],
                ];

                $isCurrent = (bool) $request->request->get('isCurrent', false);
                if ($isCurrent) {
                    $repo->clearCurrent();
                }

                $createdLabels = [];
                $skippedLabels = [];

                foreach ($termInputs as $idx => $term) {
                    $existing = $repo->findOneBy([
                        'yearLabel' => $yearLabel,
                        'semester' => $term['semester'],
                    ]);
                    if ($existing) {
                        $skippedLabels[] = $existing->getLabel();
                        continue;
                    }

                    $ay = new AcademicYear();
                    $ay->setYearLabel($yearLabel);
                    $ay->setSemester($term['semester']);
                    $ay->setIsCurrent($isCurrent && $idx === 0);

                    if (!empty($term['start'])) {
                        $ay->setStartDate(new \DateTime((string) $term['start']));
                    }
                    if (!empty($term['end'])) {
                        $ay->setEndDate(new \DateTime((string) $term['end']));
                    }

                    $em->persist($ay);
                    $createdLabels[] = $ay->getLabel();
                }

                if (!empty($createdLabels)) {
                    $em->flush();
                    $this->audit->log('create_academic_year', 'AcademicYear', null,
                        'Created academic year terms: ' . implode(', ', $createdLabels));
                    $this->addFlash('success', 'Created ' . count($createdLabels) . ' term(s): ' . implode(', ', $createdLabels) . '.');
                }

                if (!empty($skippedLabels)) {
                    $this->addFlash('warning', 'Skipped existing term(s): ' . implode(', ', $skippedLabels) . '.');
                }

                if (empty($createdLabels) && empty($skippedLabels)) {
                    $this->addFlash('warning', 'No terms were created.');
                }

                return $this->redirectToRoute('admin_academic_years');
            }

            $autoGenerate = $request->request->getBoolean('autoGenerateNext', false);
            if ($autoGenerate) {
                $next = $repo->getNextAcademicTerm();
                $yearLabel = $next['yearLabel'];
                $semester = $next['semester'];
            } else {
                $yearLabel = trim((string) $request->request->get('yearLabel', ''));
                $semesterRaw = trim((string) $request->request->get('semester', ''));
                $semester = $semesterRaw !== '' ? $semesterRaw : null;
            }

            if ($yearLabel === '') {
                $this->addFlash('danger', 'Year label is required.');
                return $this->redirectToRoute('admin_academic_years');
            }

            $existing = $repo->findOneBy([
                'yearLabel' => $yearLabel,
                'semester' => $semester,
            ]);
            if ($existing) {
                $this->addFlash('warning', 'Academic year "' . $existing->getLabel() . '" already exists.');
                return $this->redirectToRoute('admin_academic_years');
            }

            $ay = new AcademicYear();
            $ay->setYearLabel($yearLabel);
            $ay->setSemester($semester);

            $startDate = $request->request->get('startDate');
            $endDate = $request->request->get('endDate');
            if ($startDate) $ay->setStartDate(new \DateTime($startDate));
            if ($endDate) $ay->setEndDate(new \DateTime($endDate));

            $isCurrent = (bool) $request->request->get('isCurrent', false);
            if ($isCurrent) {
                $repo->clearCurrent();
            }
            $ay->setIsCurrent($isCurrent);

            $em->persist($ay);
            $em->flush();

            $this->audit->log('create_academic_year', 'AcademicYear', $ay->getId(),
                'Created academic year ' . $ay->getLabel());

            $this->addFlash('success', ($autoGenerate ? 'Auto-generated a new term: ' : 'Academic year "') . $ay->getLabel() . ($autoGenerate ? '.' : '" created.'));
        }
        return $this->redirectToRoute('admin_academic_years');
    }

    #[Route('/academic-years/{id}/edit', name: 'admin_academic_year_edit', methods: ['POST'])]
    public function editAcademicYear(AcademicYear $ay, Request $request, EntityManagerInterface $em, AcademicYearRepository $repo): Response
    {
        if ($this->isCsrfTokenValid('edit_ay' . $ay->getId(), $request->request->get('_token'))) {
            $ay->setYearLabel($request->request->get('yearLabel', ''));
            $ay->setSemester($request->request->get('semester'));

            $startDate = $request->request->get('startDate');
            $endDate = $request->request->get('endDate');
            $ay->setStartDate($startDate ? new \DateTime($startDate) : null);
            $ay->setEndDate($endDate ? new \DateTime($endDate) : null);

            $isCurrent = (bool) $request->request->get('isCurrent', false);
            if ($isCurrent) {
                $repo->clearCurrent();
            }
            $ay->setIsCurrent($isCurrent);

            $em->flush();

            $this->audit->log('edit_academic_year', 'AcademicYear', $ay->getId(),
                'Updated academic year ' . $ay->getLabel());

            $this->addFlash('success', 'Academic year updated.');
        }
        return $this->redirectToRoute('admin_academic_years');
    }

    #[Route('/academic-years/{id}/set-current', name: 'admin_academic_year_set_current', methods: ['POST'])]
    public function setCurrentAcademicYear(AcademicYear $ay, Request $request, EntityManagerInterface $em, AcademicYearRepository $repo, EvaluationPeriodRepository $evalRepo): Response
    {
        if ($this->isCsrfTokenValid('set_current_ay' . $ay->getId(), $request->request->get('_token'))) {
            // Close all open evaluation periods from the previous academic year
            $oldAY = $repo->findCurrent();
            if ($oldAY && $oldAY->getId() !== $ay->getId()) {
                $openEvals = $evalRepo->findOpen();
                foreach ($openEvals as $eval) {
                    $eval->setStatus(false);
                }
            }

            $repo->clearCurrent();
            $ay->setIsCurrent(true);
            $em->flush();

            $this->audit->log('set_current_academic_year', 'AcademicYear', $ay->getId(),
                'Set current academic year to ' . $ay->getLabel() . '. All previous open evaluations were closed.');

            $this->addFlash('success', '"' . $ay->getLabel() . '" set as current academic year. All previous open evaluations have been closed.');
        }
        return $this->redirectToRoute('admin_academic_years');
    }

    #[Route('/academic-years/{id}/delete', name: 'admin_academic_year_delete', methods: ['POST'])]
    public function deleteAcademicYear(AcademicYear $ay, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_ay' . $ay->getId(), $request->request->get('_token'))) {
            $label = $ay->getLabel();
            $em->remove($ay);
            $em->flush();

            $this->audit->log('delete_academic_year', 'AcademicYear', $ay->getId(),
                'Deleted academic year ' . $label);

            $this->addFlash('success', 'Academic year "' . $label . '" deleted.');
        }
        return $this->redirectToRoute('admin_academic_years');
    }

    // ════════════════════════════════════════════════
    //  C. EVALUATION MANAGEMENT (MOVED TO STAFF ONLY)
    // ════════════════════════════════════════════════
    //
    // NOTE: Evaluation management has been moved to ReportController
    // for STAFF access only. Admins should not manage evaluations.

    #[Route('/api/faculty/{id}/subjects', name: 'admin_api_faculty_subjects', methods: ['GET'])]
    public function apiFacultySubjects(int $id, FacultySubjectLoadRepository $fslRepo, AcademicYearRepository $ayRepo): JsonResponse
    {
        $currentAY = $ayRepo->findCurrent();
        $loads = $fslRepo->findByFacultyAndAcademicYear($id, $currentAY ? $currentAY->getId() : null);
        $data = [];
        foreach ($loads as $load) {
            $s = $load->getSubject();
            $data[] = [
                'value' => $s->getSubjectCode() . ' — ' . $s->getSubjectName(),
                'schedule' => $load->getSchedule() ?? '',
                'section' => $load->getSection() ?? '',
            ];
        }
        return $this->json($data);
    }

    // ════════════════════════════════════════════════
    //  D. QUESTIONNAIRE MANAGEMENT
    // ════════════════════════════════════════════════

    #[Route('/questions', name: 'admin_questions', methods: ['GET'])]
    public function questions(Request $request, QuestionRepository $repo, QuestionCategoryDescriptionRepository $descRepo): Response
    {
        $type = $request->query->get('type', 'SET');
        $questions = $repo->findByType($type);
        $categories = $repo->findCategories($type);
        $categoryDescriptions = $descRepo->findDescriptionsByType($type);

        return $this->render('admin/questions.html.twig', [
            'questions' => $questions,
            'categories' => $categories,
            'selectedType' => $type,
            'categoryDescriptions' => $categoryDescriptions,
        ]);
    }

    #[Route('/questions/create', name: 'admin_question_create_form', methods: ['GET'])]
    public function createQuestionForm(Request $request, QuestionRepository $repo): Response
    {
        $type = $request->query->get('type', 'SET');
        $categories = $repo->findCategories($type);

        return $this->render('admin/question_create.html.twig', [
            'selectedType' => $type,
            'categories' => $categories,
        ]);
    }

    #[Route('/questions/create', name: 'admin_question_create', methods: ['POST'])]
    public function createQuestion(Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('create_question', $request->request->get('_token'))) {
            $q = new Question();
            $type = (string) $request->request->get('evaluationType', 'SET');
            $q->setQuestionText($request->request->get('questionText', ''));
            $q->setCategory($request->request->get('category'));
            $q->setEvaluationType($type);
            $q->setWeight((float) ($request->request->get('weight', 1.0)));
            $q->setSortOrder((int) ($request->request->get('sortOrder', 0)));
            $q->setIsRequired($request->request->getBoolean('isRequired', true));
            $q->setEvidenceItems($type === 'SEF'
                ? $this->parseEvidenceItemsText((string) $request->request->get('evidenceItemsText', ''))
                : []);

            $em->persist($q);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_CREATE_QUESTION, 'Question', $q->getId(),
                'Created question: ' . substr($q->getQuestionText(), 0, 50));

            $this->addFlash('success', 'Question created.');
        }
        return $this->redirectToRoute('admin_questions', ['type' => $request->request->get('evaluationType', 'SET')]);
    }

    #[Route('/questions/{id}/edit', name: 'admin_question_edit', methods: ['POST'])]
    public function editQuestion(Question $question, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('edit_q' . $question->getId(), $request->request->get('_token'))) {
            $question->setQuestionText($request->request->get('questionText', ''));
            $question->setCategory($request->request->get('category'));
            $question->setWeight((float) ($request->request->get('weight', 1.0)));
            $question->setSortOrder((int) ($request->request->get('sortOrder', 0)));
            $question->setIsRequired($request->request->getBoolean('isRequired', true));
            $question->setIsActive($request->request->getBoolean('isActive', true));
            $question->setEvidenceItems($question->getEvaluationType() === 'SEF'
                ? $this->parseEvidenceItemsText((string) $request->request->get('evidenceItemsText', ''))
                : []);
            $em->flush();

            $this->audit->log(AuditLog::ACTION_EDIT_QUESTION, 'Question', $question->getId(),
                'Edited question #' . $question->getId());

            $this->addFlash('success', 'Question updated.');
        }
        return $this->redirectToRoute('admin_questions', ['type' => $question->getEvaluationType()]);
    }

    #[Route('/questions/{id}/delete', name: 'admin_question_delete', methods: ['POST'])]
    public function deleteQuestion(Question $question, Request $request, EntityManagerInterface $em): Response
    {
        $type = $question->getEvaluationType();
        if ($this->isCsrfTokenValid('delete_q' . $question->getId(), $request->request->get('_token'))) {
            $this->audit->log(AuditLog::ACTION_DELETE_QUESTION, 'Question', $question->getId(),
                'Deleted question #' . $question->getId());

            $em->remove($question);
            $em->flush();
            $this->addFlash('success', 'Question deleted.');
        }
        return $this->redirectToRoute('admin_questions', ['type' => $type]);
    }

    #[Route('/questions/category-description', name: 'admin_question_category_description', methods: ['POST'])]
    public function saveCategoryDescription(Request $request, EntityManagerInterface $em, QuestionCategoryDescriptionRepository $descRepo): Response
    {
        $type = $request->request->get('evaluationType', 'SET');
        $category = $request->request->get('category', '');
        $description = $request->request->get('description', '');

        if ($this->isCsrfTokenValid('cat_desc', $request->request->get('_token'))) {
            $entity = $descRepo->findOneBy(['category' => $category, 'evaluationType' => $type]);
            if (!$entity) {
                $entity = new QuestionCategoryDescription();
                $entity->setCategory($category);
                $entity->setEvaluationType($type);
                $em->persist($entity);
            }
            $entity->setDescription($description ?: null);
            $em->flush();
            $this->addFlash('success', 'Section description updated.');
        }
        return $this->redirectToRoute('admin_questions', ['type' => $type]);
    }

    /**
     * Parse textarea input into normalized evidence items (one item per line).
     *
     * @return string[]
     */
    private function parseEvidenceItemsText(string $raw): array
    {
        $lines = preg_split('/\R+/', $raw) ?: [];
        $items = [];

        foreach ($lines as $line) {
            $clean = trim((string) preg_replace('/^\s*[-*0-9.()]+\s*/', '', trim($line)));
            if ($clean !== '') {
                $items[] = $clean;
            }
        }

        return array_values(array_unique($items));
    }

    // ════════════════════════════════════════════════
    //  E. REPORTS & ANALYTICS
    // ════════════════════════════════════════════════

    #[Route('/results', name: 'admin_results', methods: ['GET'])]
    public function results(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
    ): Response {
        $evalId = $request->query->get('evaluation');
        $deptId = $request->query->get('department');

        $evaluations = $evalRepo->findAllOrdered();
        $departments = $deptRepo->findAllOrdered();
        $facultyResults = [];

        $qb = $userRepo->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_FACULTY%')
            ->orderBy('u.lastName', 'ASC');

        if ($deptId) {
            $qb->andWhere('u.department = :did')->setParameter('did', $deptId);
        }

        $facultyList = $qb->getQuery()->getResult();

        foreach ($facultyList as $faculty) {
            if ($evalId) {
                $avg = $responseRepo->getOverallAverage($faculty->getId(), (int) $evalId);
                $count = $responseRepo->countEvaluators($faculty->getId(), (int) $evalId);
            } else {
                $avg = $responseRepo->getOverallAverageAll($faculty->getId());
                $count = $responseRepo->countEvaluatorsAll($faculty->getId());
            }

            if ($count > 0) {
                // Get subject+section details
                $subjectDetails = [];
                $isTargetFaculty = mb_strtolower(trim((string) $faculty->getFullName())) === 'ryan escorial';
                $allSubjects = $responseRepo->getEvaluatedSubjectsWithRating($faculty->getId());
                foreach ($allSubjects as $subj) {
                    if ($evalId && (int) $subj['evaluationPeriodId'] !== (int) $evalId) {
                        continue;
                    }

                    $subjectName = mb_strtolower(trim((string) ($subj['subjectName'] ?? '')));
                    if ($isTargetFaculty && $subjectName === 'capstone project 2') {
                        continue;
                    }

                    $subjectDetails[] = [
                        'subjectCode' => $subj['subjectCode'] ?? 'N/A',
                        'subjectName' => $subj['subjectName'] ?? '',
                        'section' => $subj['section'] ?? '—',
                        'average' => round((float) ($subj['avgRating'] ?? 0), 2),
                        'evaluators' => (int) $subj['evaluatorCount'],
                    ];
                }

                if (empty($subjectDetails)) {
                    continue;
                }

                $facultyResults[] = [
                    'faculty' => $faculty,
                    'average' => $avg,
                    'evaluators' => $count,
                    'level' => $this->getPerformanceLevel($avg),
                    'subjectDetails' => $subjectDetails,
                ];
            }
        }

        usort($facultyResults, fn($a, $b) => $b['average'] <=> $a['average']);

        $collegeNames = [];
        foreach ($departments as $d) {
            $cn = $d->getCollegeName();
            if ($cn && !in_array($cn, $collegeNames, true)) {
                $collegeNames[] = $cn;
            }
        }
        sort($collegeNames);

        return $this->render('admin/results.html.twig', [
            'evaluations' => $evaluations,
            'departments' => $departments,
            'colleges' => $collegeNames,
            'facultyResults' => $facultyResults,
            'selectedEvaluation' => $evalId,
            'selectedDepartment' => $deptId,
            'evalEntity' => $evalId ? $evalRepo->find($evalId) : null,
        ]);
    }

    #[Route('/results/superior', name: 'admin_results_superior', methods: ['GET'])]
    public function resultsSuperior(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
        DepartmentRepository $deptRepo,
    ): Response {
        $evalId = $request->query->get('evaluation');
        $deptId = (int) $request->query->get('department', 0);

        $evaluations = $evalRepo->findBy(['evaluationType' => 'SUPERIOR'], ['startDate' => 'DESC']);
        $departments = $deptRepo->findAllOrdered();
        $facultyResults = [];

        // SEF results table should show department heads/chairs (evaluators), not evaluated faculty rows.
        $headQb = $userRepo->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->andWhere('u.accountStatus = :status')
            ->andWhere('(LOWER(COALESCE(u.employmentStatus, :blank)) LIKE :head OR LOWER(COALESCE(u.employmentStatus, :blank)) LIKE :chair)')
            ->setParameter('role', '%ROLE_FACULTY%')
            ->setParameter('status', 'active')
            ->setParameter('blank', '')
            ->setParameter('head', '%head%')
            ->setParameter('chair', '%chair%')
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC');

        if ($deptId > 0) {
            $headQb->andWhere('u.department = :deptId')->setParameter('deptId', $deptId);
        }

        $headUsers = $headQb->getQuery()->getResult();
        foreach ($headUsers as $user) {
            if ($evalId) {
                $avg = $superiorEvalRepo->getEvaluatorAverage($user->getId(), (int) $evalId);
                $count = $superiorEvalRepo->countEvaluateesByEvaluator($user->getId(), (int) $evalId);
            } else {
                $avg = $superiorEvalRepo->getEvaluatorAverage($user->getId());
                $count = $superiorEvalRepo->countEvaluateesByEvaluator($user->getId());
            }

            if ($count > 0) {
                $facultyResults[] = [
                    'faculty' => $user,
                    'average' => $avg,
                    'evaluators' => $count,
                    'level' => $this->getPerformanceLevel($avg),
                ];
            }
        }

        usort($facultyResults, fn($a, $b) => $b['average'] <=> $a['average']);

        $collegeNames = [];
        foreach ($departments as $d) {
            $cn = $d->getCollegeName();
            if ($cn && !in_array($cn, $collegeNames, true)) {
                $collegeNames[] = $cn;
            }
        }
        sort($collegeNames);

        return $this->render('admin/results_superior.html.twig', [
            'evaluations' => $evaluations,
            'departments' => $departments,
            'colleges' => $collegeNames,
            'facultyResults' => $facultyResults,
            'selectedEvaluation' => $evalId,
            'selectedDepartment' => $deptId > 0 ? $deptId : null,
            'evalEntity' => $evalId ? $evalRepo->find($evalId) : null,
        ]);
    }

    #[Route('/results/export', name: 'admin_results_export', methods: ['GET'])]
    public function exportResults(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
    ): StreamedResponse {
        $evalId = (int) $request->query->get('evaluation', 0);
        $eval = $evalRepo->find($evalId);

        $this->audit->log(AuditLog::ACTION_EXPORT_REPORT, 'EvaluationPeriod', $evalId,
            'Exported evaluation results report');

        $faculty = $userRepo->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_FACULTY%')
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()->getResult();

        $filename = 'Evaluation_Results_' . date('Y-m-d') . '.csv';

        $response = new StreamedResponse(function () use ($faculty, $responseRepo, $evalId, $eval) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['SET-SEF Evaluation Results Report']);
            fputcsv($handle, ['Generated: ' . date('F d, Y h:i A')]);
            if ($eval) {
                fputcsv($handle, ['Period: ' . $eval->getLabel()]);
            }
            fputcsv($handle, []);

            fputcsv($handle, ['Rank', 'Faculty Name', 'Department', 'Average Rating', 'Total Evaluators', 'Performance Level']);

            $rank = 1;
            $results = [];
            foreach ($faculty as $f) {
                $avg = $responseRepo->getOverallAverage($f->getId(), $evalId);
                $count = $responseRepo->countEvaluators($f->getId(), $evalId);
                if ($count > 0) {
                    $results[] = ['faculty' => $f, 'avg' => $avg, 'count' => $count];
                }
            }
            usort($results, fn($a, $b) => $b['avg'] <=> $a['avg']);

            foreach ($results as $r) {
                fputcsv($handle, [
                    $rank++,
                    $r['faculty']->getFullName(),
                    $r['faculty']->getDepartment()?->getDepartmentName() ?? 'N/A',
                    $r['avg'],
                    $r['count'],
                    $this->getPerformanceLevel($r['avg']),
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    // ════════════════════════════════════════════════
    //  E2. RESULTS — FACULTY DETAIL (JSON)
    // ════════════════════════════════════════════════

    #[Route('/results/faculty-detail', name: 'admin_results_faculty_detail', methods: ['GET'])]
    public function resultsFacultyDetail(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
    ): JsonResponse {
        $evalId = (int) $request->query->get('evaluation', 0);
        $facultyId = (int) $request->query->get('faculty', 0);

        $evaluation = $evalRepo->find($evalId);
        $faculty = $userRepo->find($facultyId);

        if (!$evaluation || !$faculty) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $questionAverages = $responseRepo->getAverageRatingsByFaculty($facultyId, $evalId);
        $questions = $questionRepo->findByType($evaluation->getEvaluationType());

        $questionData = [];
        foreach ($questions as $q) {
            $qId = $q->getId();
            $avgData = $questionAverages[$qId] ?? null;
            $questionData[] = [
                'category' => $q->getCategory(),
                'text' => $q->getQuestionText(),
                'average' => is_array($avgData) ? $avgData['average'] : null,
                'count' => is_array($avgData) ? $avgData['count'] : 0,
            ];
        }

        $comments = $responseRepo->getComments($facultyId, $evalId);
        $filteredComments = array_values(array_filter($comments, fn($c) => trim($c) !== ''));

        $overallAvg = $responseRepo->getOverallAverage($facultyId, $evalId);
        $evaluatorCount = $responseRepo->countEvaluators($facultyId, $evalId);

        return $this->json([
            'faculty' => [
                'name' => $faculty->getFullName(),
                'email' => $faculty->getEmail(),
                'department' => $faculty->getDepartment() ? $faculty->getDepartment()->getDepartmentName() : null,
            ],
            'evaluation' => [
                'type' => $evaluation->getEvaluationType(),
                'semester' => $evaluation->getSemester(),
                'schoolYear' => $evaluation->getSchoolYear(),
            ],
            'overallAverage' => $overallAvg,
            'evaluatorCount' => $evaluatorCount,
            'performanceLevel' => $this->getPerformanceLevel($overallAvg),
            'questions' => $questionData,
            'comments' => $filteredComments,
        ]);
    }

    // ════════════════════════════════════════════════
    //  E3. RESULTS — PRINT VIEW (Twig template)
    // ════════════════════════════════════════════════

    #[Route('/results/print', name: 'admin_results_print', methods: ['GET'])]
    public function resultsPrint(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
        SubjectRepository $subjectRepo,
    ): Response {
        $evalId = (int) $request->query->get('evaluation', 0);
        $facultyId = (int) $request->query->get('faculty', 0);
        $subjectId = $request->query->get('subject') ? (int) $request->query->get('subject') : null;
        $section = $request->query->get('section');

        $evaluation = $evalRepo->find($evalId);
        $faculty = $userRepo->find($facultyId);

        if (!$evaluation || !$faculty) {
            throw $this->createNotFoundException('Evaluation or faculty not found.');
        }

        $questionAverages = $responseRepo->getAverageRatingsByFaculty($facultyId, $evalId);
        $questions = $questionRepo->findByType($evaluation->getEvaluationType());

        $questionData = [];
        $categoryAverages = [];
        foreach ($questions as $q) {
            $qId = $q->getId();
            $avgData = $questionAverages[$qId] ?? null;
            $avg = is_array($avgData) ? $avgData['average'] : null;
            $cnt = is_array($avgData) ? $avgData['count'] : 0;
            $questionData[] = [
                'category' => $q->getCategory(),
                'text' => $q->getQuestionText(),
                'average' => $avg,
                'count' => $cnt,
            ];
            $cat = $q->getCategory();
            if (!isset($categoryAverages[$cat])) {
                $categoryAverages[$cat] = ['sum' => 0.0, 'n' => 0];
            }
            if ($avg !== null) {
                $categoryAverages[$cat]['sum'] += $avg;
                $categoryAverages[$cat]['n']++;
            }
        }

        // Category summary with equal weighting
        $catCount = count($categoryAverages);
        $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
        $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
        $categorySummary = [];
        $compositeTotal = 0.0;
        $romNum = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
        $idx = 0;
        foreach ($categoryAverages as $cat => $data) {
            $mean = $data['n'] > 0 ? $data['sum'] / $data['n'] : 0;
            $weightedRating = $mean * $weightFrac;
            $compositeTotal += $weightedRating;
            $categorySummary[] = [
                'roman' => $romNum[$idx] ?? (string)($idx + 1),
                'name' => $cat,
                'mean' => round($mean, 2),
                'weightPct' => $weightPct,
                'weightedRating' => round($weightedRating, 2),
            ];
            $idx++;
        }

        // Get comments filtered by subject and section if provided
        if ($subjectId !== null || $section !== null) {
            $comments = $responseRepo->getCommentsBySubjectAndSection($facultyId, $evalId, $subjectId, $section);
        } else {
            $comments = $responseRepo->getComments($facultyId, $evalId);
        }
        $filteredComments = array_values(array_filter($comments, fn($c) => trim($c) !== ''));

        $overallAvg = $responseRepo->getOverallAverage($facultyId, $evalId);

        // Get evaluator count filtered by subject and section if provided
        if ($subjectId !== null || $section !== null) {
            $evaluatorCount = $responseRepo->countEvaluatorsBySubjectAndSection($facultyId, $evalId, $subjectId, $section);
        } else {
            $evaluatorCount = $responseRepo->countEvaluators($facultyId, $evalId);
        }

        // Get subject info if specific subject is being printed
        $subjectCode = null;
        $subjectName = null;
        if ($subjectId !== null) {
            $subject = $subjectRepo->find($subjectId);
            if ($subject) {
                $subjectCode = $subject->getSubjectCode();
                $subjectName = $subject->getSubjectName();
            }
        }

        return $this->render('report/print_results.html.twig', [
            'faculty' => $faculty,
            'evaluation' => $evaluation,
            'questions' => $questionData,
            'comments' => $filteredComments,
            'overallAverage' => $overallAvg,
            'evaluatorCount' => $evaluatorCount,
            'performanceLevel' => $this->getPerformanceLevel($overallAvg),
            'categorySummary' => $categorySummary,
            'compositeTotal' => round($compositeTotal, 2),
            'weightPct' => $weightPct,
            'printSubjectCode' => $subjectCode,
            'printSubjectName' => $subjectName,
            'printSection' => $section,
        ]);
    }

    // ════════════════════════════════════════════════
    //  E4. RESULTS — PRINT COMMENTS (Twig template)
    // ════════════════════════════════════════════════

    #[Route('/results/print-comments', name: 'admin_results_print_comments', methods: ['GET'])]
    public function resultsPrintComments(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        SubjectRepository $subjectRepo,
    ): Response {
        $evalId = (int) $request->query->get('evaluation', 0);
        $facultyId = (int) $request->query->get('faculty', 0);
        $subjectId = $request->query->get('subject') ? (int) $request->query->get('subject') : null;
        $section = $request->query->get('section');

        $evaluation = $evalRepo->find($evalId);
        $faculty = $userRepo->find($facultyId);

        if (!$evaluation || !$faculty) {
            throw $this->createNotFoundException('Evaluation or faculty not found.');
        }

        // Get comments filtered by subject and section if provided
        if ($subjectId !== null || $section !== null) {
            $comments = $responseRepo->getCommentsBySubjectAndSection($facultyId, $evalId, $subjectId, $section);
            $evaluatorCount = $responseRepo->countEvaluatorsBySubjectAndSection($facultyId, $evalId, $subjectId, $section);
        } else {
            $comments = $responseRepo->getComments($facultyId, $evalId);
            $evaluatorCount = $responseRepo->countEvaluators($facultyId, $evalId);
        }
        $filteredComments = array_values(array_filter($comments, fn($c) => trim($c) !== ''));

        // Get subject info if specific subject is being printed
        $subjectCode = null;
        if ($subjectId !== null) {
            $subject = $subjectRepo->find($subjectId);
            if ($subject) {
                $subjectCode = $subject->getSubjectCode();
            }
        }

        return $this->render('report/print_comments.html.twig', [
            'faculty' => $faculty,
            'evaluation' => $evaluation,
            'comments' => $filteredComments,
            'evaluatorCount' => $evaluatorCount,
            'printSubjectCode' => $subjectCode,
            'printSection' => $section,
        ]);
    }

    // ════════════════════════════════════════════════
    //  E5. RESULTS — FACULTY EVALUATIONS PAGE
    // ════════════════════════════════════════════════

    #[Route('/results/faculty-evaluations', name: 'admin_results_faculty_evaluations', methods: ['GET'])]
    public function facultyEvaluations(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        FacultySubjectLoadRepository $fslRepo,
        AcademicYearRepository $ayRepo,
    ): Response {
        $facultyId = (int) $request->query->get('faculty', 0);
        $faculty = $userRepo->find($facultyId);

        if (!$faculty) {
            throw $this->createNotFoundException('Faculty not found.');
        }

        $currentAY = $ayRepo->findCurrent();
        $evalData = $responseRepo->getEvaluationsByFaculty($facultyId);
        $results = [];
        $totalEvaluators = 0;
        $sumAvg = 0;
        $openEvalIdMap = [];
        foreach ($evalRepo->findOpen() as $openEval) {
            $openEvalIdMap[$openEval->getId()] = true;
        }

        foreach ($evalData as $row) {
            $evalId = (int) $row['evaluationPeriodId'];
            if (!isset($openEvalIdMap[$evalId])) continue;

            $eval = $evalRepo->find($evalId);
            if (!$eval) continue;

            $avg = round((float) $row['avgRating'], 2);
            $count = (int) $row['evaluatorCount'];
            $totalEvaluators += $count;
            $sumAvg += $avg;

            // Get subject+section details for this evaluation period
            $subjectDetails = [];
            $allSubjects = $responseRepo->getEvaluatedSubjectsWithRating($facultyId);
            foreach ($allSubjects as $subj) {
                if ((int) $subj['evaluationPeriodId'] === $evalId) {
                    // Fetch schedule from FacultySubjectLoad
                    $schedule = '—';
                    if ($subj['subjectId']) {
                        $load = $fslRepo->findOneBy([
                            'faculty' => $faculty,
                            'subject' => $subj['subjectId'],
                            'section' => $subj['section'],
                            'academicYear' => $currentAY
                        ]);
                        if ($load) {
                            $schedule = $load->getSchedule() ?? '—';
                        }
                    }

                    $subjectDetails[] = [
                        'subjectId' => (int) $subj['subjectId'],
                        'subjectCode' => $subj['subjectCode'] ?? 'N/A',
                        'subjectName' => $subj['subjectName'] ?? '',
                        'section' => $subj['section'] ?? '—',
                        'schedule' => $schedule,
                        'average' => round((float) ($subj['avgRating'] ?? 0), 2),
                        'evaluators' => (int) $subj['evaluatorCount'],
                    ];
                }
            }

            $results[] = [
                'evaluation' => $eval,
                'average' => $avg,
                'evaluators' => $count,
                'level' => $this->getPerformanceLevel($avg),
                'subjectDetails' => $subjectDetails,
            ];
        }

        $overallAvg = count($results) > 0 ? round($sumAvg / count($results), 2) : 0;

        return $this->render('admin/faculty/faculty_evaluations.html.twig', [
            'faculty' => $faculty,
            'evaluations' => $results,
            'totalEvaluators' => $totalEvaluators,
            'overallAvg' => $overallAvg,
        ]);
    }

    // ════════════════════════════════════════════════
    //  E6. RESULTS — PRINT ALL EVALUATIONS
    // ════════════════════════════════════════════════

    #[Route('/results/print-all', name: 'admin_results_print_all', methods: ['GET'])]
    public function resultsPrintAll(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        EvaluationResponseRepository $responseRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
    ): Response {
        $facultyId = (int) $request->query->get('faculty', 0);
        $faculty = $userRepo->find($facultyId);

        if (!$faculty) {
            throw $this->createNotFoundException('Faculty not found.');
        }

        // Check if specific evaluations were selected
        $selectedEvals = $request->query->all('evals');

        $evalData = $responseRepo->getEvaluationsByFaculty($facultyId);
        $allEvaluations = [];

        foreach ($evalData as $row) {
            $eval = $evalRepo->find((int) $row['evaluationPeriodId']);
            if (!$eval) continue;

            // If specific evaluations were selected, skip those not in the list
            if (!empty($selectedEvals) && !in_array((string) $eval->getId(), $selectedEvals, true)) {
                continue;
            }

            $evalId = $eval->getId();
            $avg = round((float) $row['avgRating'], 2);
            $count = (int) $row['evaluatorCount'];

            // Category summary
            $questionAverages = $responseRepo->getAverageRatingsByFaculty($facultyId, $evalId);
            $questions = $questionRepo->findByType($eval->getEvaluationType());
            $categoryAverages = [];
            foreach ($questions as $q) {
                $qId = $q->getId();
                $avgData = $questionAverages[$qId] ?? null;
                $qAvg = is_array($avgData) ? $avgData['average'] : null;
                $cat = $q->getCategory();
                if (!isset($categoryAverages[$cat])) {
                    $categoryAverages[$cat] = ['sum' => 0.0, 'n' => 0];
                }
                if ($qAvg !== null) {
                    $categoryAverages[$cat]['sum'] += $qAvg;
                    $categoryAverages[$cat]['n']++;
                }
            }

            $catCount = count($categoryAverages);
            $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
            $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
            $categorySummary = [];
            $compositeTotal = 0.0;
            foreach ($categoryAverages as $cat => $data) {
                $mean = $data['n'] > 0 ? $data['sum'] / $data['n'] : 0;
                $weightedRating = $mean * $weightFrac;
                $compositeTotal += $weightedRating;
                $categorySummary[] = [
                    'name' => $cat,
                    'mean' => round($mean, 2),
                    'weightPct' => $weightPct,
                    'weightedRating' => round($weightedRating, 2),
                ];
            }

            $comments = $responseRepo->getComments($facultyId, $evalId);
            $filteredComments = array_values(array_filter($comments, fn($c) => trim($c) !== ''));

            // Get subject/section details with their comments
            $allSubjects = $responseRepo->getEvaluatedSubjectsWithRating($facultyId);
            $subjectComments = [];
            foreach ($allSubjects as $subj) {
                if ((int) $subj['evaluationPeriodId'] === (int) $evalId) {
                    $subjComments = $responseRepo->getCommentsBySubjectAndSection(
                        $facultyId,
                        $evalId,
                        $subj['subjectId'],
                        $subj['section']
                    );
                    $filteredSubjComments = array_values(array_filter($subjComments, fn($c) => trim($c) !== ''));
                    if (!empty($filteredSubjComments)) {
                        $subjectComments[] = [
                            'subjectCode' => $subj['subjectCode'] ?? 'N/A',
                            'section' => $subj['section'] ?? '—',
                            'comments' => $filteredSubjComments,
                        ];
                    }
                }
            }

            $allEvaluations[] = [
                'evaluation' => $eval,
                'average' => $avg,
                'evaluators' => $count,
                'level' => $this->getPerformanceLevel($avg),
                'categorySummary' => $categorySummary,
                'compositeTotal' => round($compositeTotal, 2),
                'comments' => $filteredComments,
                'subjectComments' => $subjectComments,
            ];
        }

        // ── Build composite averages across all courses per category ──
        $categoryNames = [];
        $compositeSums = [];
        $compositeCounts = [];

        foreach ($allEvaluations as $item) {
            foreach ($item['categorySummary'] as $cat) {
                $name = $cat['name'];
                if (!in_array($name, $categoryNames, true)) {
                    $categoryNames[] = $name;
                }
                if (!isset($compositeSums[$name])) {
                    $compositeSums[$name] = 0.0;
                    $compositeCounts[$name] = 0;
                }
                $compositeSums[$name] += $cat['mean'];
                $compositeCounts[$name]++;
            }
        }

        $catCount = count($categoryNames);
        $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
        $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
        $compositeCategories = [];
        $compositeGrandTotal = 0.0;

        foreach ($categoryNames as $name) {
            $wMean = $compositeCounts[$name] > 0
                ? round($compositeSums[$name] / $compositeCounts[$name], 2)
                : 0;
            $wRating = round($wMean * $weightFrac, 2);
            $compositeGrandTotal += $wRating;
            $compositeCategories[] = [
                'name' => $name,
                'mean' => $wMean,
                'weightPct' => $weightPct,
                'weightedRating' => $wRating,
            ];
        }

        // ── Split into Baccalaureate vs Graduate, pad to 7 each ──
        $baccEvaluations = [];
        $gradEvaluations = [];

        foreach ($allEvaluations as $item) {
            $college = $item['evaluation']->getCollege() ?? '';
            if (stripos($college, 'Graduate') !== false) {
                $gradEvaluations[] = $item;
            } else {
                $baccEvaluations[] = $item;
            }
        }

        // Build an empty placeholder with the correct category structure
        $emptyCategories = [];
        foreach ($categoryNames as $name) {
            $emptyCategories[] = [
                'name' => $name,
                'mean' => 0.00,
                'weightPct' => $catCount > 0 ? round(100 / $catCount) : 0,
                'weightedRating' => 0.00,
            ];
        }
        $emptySlot = [
            'evaluation' => null,
            'average' => 0.00,
            'evaluators' => 0,
            'level' => 'N/A',
            'categorySummary' => $emptyCategories,
            'compositeTotal' => 0.00,
            'comments' => [],
            'subjectComments' => [],
        ];

        // Pad each group to exactly 7 slots
        while (count($baccEvaluations) < 7) {
            $baccEvaluations[] = $emptySlot;
        }
        while (count($gradEvaluations) < 7) {
            $gradEvaluations[] = $emptySlot;
        }

        return $this->render('report/print_all_results.html.twig', [
            'faculty' => $faculty,
            'allEvaluations' => $allEvaluations,
            'baccEvaluations' => $baccEvaluations,
            'gradEvaluations' => $gradEvaluations,
            'categoryNames' => $categoryNames,
            'compositeCategories' => $compositeCategories,
            'compositeGrandTotal' => round($compositeGrandTotal, 2),
            'compositeLevel' => $this->getPerformanceLevel(round($compositeGrandTotal, 2)),
        ]);
    }

    // ════════════════════════════════════════════════
    //  E7. SEF RESULTS — FACULTY DETAIL (JSON)
    // ════════════════════════════════════════════════

    #[Route('/results/superior/faculty-detail', name: 'admin_results_superior_faculty_detail', methods: ['GET'])]
    public function resultsSuperiorFacultyDetail(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
    ): JsonResponse {
        $evalId = (int) $request->query->get('evaluation', 0);
        $facultyId = (int) $request->query->get('faculty', 0);

        $evaluation = $evalRepo->find($evalId);
        $faculty = $userRepo->find($facultyId);

        if (!$evaluation || !$faculty) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $questionAverages = $superiorEvalRepo->getAverageRatingsByEvaluatee($facultyId, $evalId);
        $questions = $questionRepo->findByType('SEF');

        $questionData = [];
        foreach ($questions as $q) {
            $qId = $q->getId();
            $avgData = $questionAverages[$qId] ?? null;
            $questionData[] = [
                'category' => $q->getCategory(),
                'text' => $q->getQuestionText(),
                'average' => is_array($avgData) ? $avgData['average'] : null,
                'count' => is_array($avgData) ? $avgData['count'] : 0,
            ];
        }

        $comments = $superiorEvalRepo->getComments($facultyId, $evalId);
        $filteredComments = array_values(array_filter(
            array_map(fn($c) => $c['comment'], $comments),
            fn($c) => trim($c) !== ''
        ));

        $overallAvg = $superiorEvalRepo->getOverallAverage($facultyId, $evalId);
        $evaluatorCount = $superiorEvalRepo->countEvaluators($facultyId, $evalId);

        return $this->json([
            'faculty' => [
                'name' => $faculty->getFullName(),
                'email' => $faculty->getEmail(),
                'department' => $faculty->getDepartment() ? $faculty->getDepartment()->getDepartmentName() : null,
            ],
            'evaluation' => [
                'type' => $evaluation->getEvaluationType(),
                'semester' => $evaluation->getSemester(),
                'schoolYear' => $evaluation->getSchoolYear(),
            ],
            'overallAverage' => $overallAvg,
            'evaluatorCount' => $evaluatorCount,
            'performanceLevel' => $this->getPerformanceLevel($overallAvg),
            'questions' => $questionData,
            'comments' => $filteredComments,
        ]);
    }

    // ════════════════════════════════════════════════
    //  E8. SEF RESULTS — PRINT VIEW
    // ════════════════════════════════════════════════

    #[Route('/results/superior/print', name: 'admin_results_superior_print', methods: ['GET'])]
    public function resultsSuperiorPrint(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
    ): Response {
        $evalId = (int) $request->query->get('evaluation', 0);
        $facultyId = (int) $request->query->get('faculty', 0);

        $evaluation = $evalRepo->find($evalId);
        $faculty = $userRepo->find($facultyId);

        if (!$evaluation || !$faculty) {
            throw $this->createNotFoundException('Evaluation or faculty not found.');
        }

        $questionAverages = $superiorEvalRepo->getAverageRatingsByEvaluatee($facultyId, $evalId);
        $questions = $questionRepo->findByType('SEF');

        $questionData = [];
        $categoryAverages = [];
        foreach ($questions as $q) {
            $qId = $q->getId();
            $avgData = $questionAverages[$qId] ?? null;
            $avg = is_array($avgData) ? $avgData['average'] : null;
            $cnt = is_array($avgData) ? $avgData['count'] : 0;
            $questionData[] = [
                'category' => $q->getCategory(),
                'text' => $q->getQuestionText(),
                'average' => $avg,
                'count' => $cnt,
            ];
            $cat = $q->getCategory();
            if (!isset($categoryAverages[$cat])) {
                $categoryAverages[$cat] = ['sum' => 0.0, 'n' => 0];
            }
            if ($avg !== null) {
                $categoryAverages[$cat]['sum'] += $avg;
                $categoryAverages[$cat]['n']++;
            }
        }

        $catCount = count($categoryAverages);
        $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
        $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
        $categorySummary = [];
        $compositeTotal = 0.0;
        $romNum = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
        $idx = 0;
        foreach ($categoryAverages as $cat => $data) {
            $mean = $data['n'] > 0 ? $data['sum'] / $data['n'] : 0;
            $weightedRating = $mean * $weightFrac;
            $compositeTotal += $weightedRating;
            $categorySummary[] = [
                'roman' => $romNum[$idx] ?? (string)($idx + 1),
                'name' => $cat,
                'mean' => round($mean, 2),
                'weightPct' => $weightPct,
                'weightedRating' => round($weightedRating, 2),
            ];
            $idx++;
        }

        $comments = $superiorEvalRepo->getComments($facultyId, $evalId);
        $filteredComments = array_values(array_filter(
            array_map(fn($c) => $c['comment'], $comments),
            fn($c) => trim($c) !== ''
        ));

        $overallAvg = $superiorEvalRepo->getOverallAverage($facultyId, $evalId);
        $evaluatorCount = $superiorEvalRepo->countEvaluators($facultyId, $evalId);

        return $this->render('report/superior/print_results.html.twig', [
            'faculty' => $faculty,
            'evaluation' => $evaluation,
            'questions' => $questionData,
            'comments' => $filteredComments,
            'overallAverage' => $overallAvg,
            'evaluatorCount' => $evaluatorCount,
            'performanceLevel' => $this->getPerformanceLevel($overallAvg),
            'categorySummary' => $categorySummary,
            'compositeTotal' => round($compositeTotal, 2),
            'weightPct' => $weightPct,
        ]);
    }

    // ════════════════════════════════════════════════
    //  E9. SEF RESULTS — PRINT COMMENTS
    // ════════════════════════════════════════════════

    #[Route('/results/superior/print-comments', name: 'admin_results_superior_print_comments', methods: ['GET'])]
    public function resultsSuperiorPrintComments(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
    ): Response {
        $evalId = (int) $request->query->get('evaluation', 0);
        $facultyId = (int) $request->query->get('faculty', 0);

        $evaluation = $evalRepo->find($evalId);
        $faculty = $userRepo->find($facultyId);

        if (!$evaluation || !$faculty) {
            throw $this->createNotFoundException('Evaluation or faculty not found.');
        }

        $comments = $superiorEvalRepo->getComments($facultyId, $evalId);
        $filteredComments = array_values(array_filter(
            array_map(fn($c) => $c['comment'], $comments),
            fn($c) => trim($c) !== ''
        ));
        $evaluatorCount = $superiorEvalRepo->countEvaluators($facultyId, $evalId);

        return $this->render('report/superior/print_comments.html.twig', [
            'faculty' => $faculty,
            'evaluation' => $evaluation,
            'comments' => $filteredComments,
            'evaluatorCount' => $evaluatorCount,
            'printSubjectCode' => null,
            'printSection' => null,
        ]);
    }

    // ════════════════════════════════════════════════
    //  E10. SEF RESULTS — FACULTY EVALUATIONS PAGE
    // ════════════════════════════════════════════════

    #[Route('/results/superior/faculty-evaluations', name: 'admin_results_superior_faculty_evaluations', methods: ['GET'])]
    public function facultyEvaluationsSuperior(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
    ): Response {
        $facultyId = (int) $request->query->get('faculty', 0);
        $faculty = $userRepo->find($facultyId);

        if (!$faculty) {
            throw $this->createNotFoundException('Faculty not found.');
        }

        $evalData = $superiorEvalRepo->getEvaluationsByEvaluator($facultyId);
        $results = [];
        $totalPersonnel = 0;
        $sumAvg = 0;

        foreach ($evalData as $row) {
            $eval = $evalRepo->find((int) $row['evaluationPeriodId']);
            if (!$eval) continue;

            $avg = round((float) $row['avgRating'], 2);
            $count = (int) $row['evaluateeCount'];
            $totalPersonnel += $count;
            $sumAvg += $avg;

            $evaluateeRows = $superiorEvalRepo->getEvaluateesByEvaluator($facultyId, (int) $eval->getId());
            $evaluatedPersonnel = [];
            foreach ($evaluateeRows as $evaluateeRow) {
                $evaluatee = $userRepo->find((int) $evaluateeRow['evaluateeId']);
                if (!$evaluatee) {
                    continue;
                }

                $evaluatedPersonnel[] = [
                    'id' => $evaluatee->getId(),
                    'evaluationId' => $eval->getId(),
                    'name' => $evaluatee->getFullName(),
                    'department' => $evaluatee->getDepartment() ? $evaluatee->getDepartment()->getDepartmentName() : '—',
                    'average' => round((float) $evaluateeRow['avgRating'], 2),
                ];
            }

            $results[] = [
                'evaluation' => $eval,
                'average' => $avg,
                'evaluators' => $count,
                'level' => $this->getPerformanceLevel($avg),
                'evaluatedPersonnel' => $evaluatedPersonnel,
            ];
        }

        $overallAvg = count($results) > 0 ? round($sumAvg / count($results), 2) : 0;

        return $this->render('admin/faculty/faculty_evaluations_superior.html.twig', [
            'faculty' => $faculty,
            'evaluations' => $results,
            'totalPersonnel' => $totalPersonnel,
            'overallAvg' => $overallAvg,
        ]);
    }

    // ════════════════════════════════════════════════
    //  E11. SEF RESULTS — PRINT ALL EVALUATIONS
    // ════════════════════════════════════════════════

    #[Route('/results/superior/print-all', name: 'admin_results_superior_print_all', methods: ['GET'])]
    public function resultsSuperiorPrintAll(
        Request $request,
        EvaluationPeriodRepository $evalRepo,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
        QuestionRepository $questionRepo,
    ): Response {
        $facultyId = (int) $request->query->get('faculty', 0);
        $faculty = $userRepo->find($facultyId);

        if (!$faculty) {
            throw $this->createNotFoundException('Faculty not found.');
        }

        $selectedEvals = $request->query->all('evals');

        $evalData = $superiorEvalRepo->getEvaluationsByEvaluator($facultyId);
        $allEvaluations = [];

        foreach ($evalData as $row) {
            $eval = $evalRepo->find((int) $row['evaluationPeriodId']);
            if (!$eval) continue;

            if (!empty($selectedEvals) && !in_array((string) $eval->getId(), $selectedEvals, true)) {
                continue;
            }

            $evalId = $eval->getId();
            $avg = round((float) $row['avgRating'], 2);
            $count = (int) $row['evaluateeCount'];

            $questionAverages = $superiorEvalRepo->getAverageRatingsByEvaluator($facultyId, $evalId);
            $questions = $questionRepo->findByType('SEF');
            $categoryAverages = [];
            foreach ($questions as $q) {
                $qId = $q->getId();
                $avgData = $questionAverages[$qId] ?? null;
                $qAvg = is_array($avgData) ? $avgData['average'] : null;
                $cat = $q->getCategory();
                if (!isset($categoryAverages[$cat])) {
                    $categoryAverages[$cat] = ['sum' => 0.0, 'n' => 0];
                }
                if ($qAvg !== null) {
                    $categoryAverages[$cat]['sum'] += $qAvg;
                    $categoryAverages[$cat]['n']++;
                }
            }

            $catCount = count($categoryAverages);
            $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
            $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
            $categorySummary = [];
            $compositeTotal = 0.0;
            foreach ($categoryAverages as $cat => $data) {
                $mean = $data['n'] > 0 ? $data['sum'] / $data['n'] : 0;
                $weightedRating = $mean * $weightFrac;
                $compositeTotal += $weightedRating;
                $categorySummary[] = [
                    'name' => $cat,
                    'mean' => round($mean, 2),
                    'weightPct' => $weightPct,
                    'weightedRating' => round($weightedRating, 2),
                ];
            }

            $comments = $superiorEvalRepo->getCommentsByEvaluator($facultyId, $evalId);
            $filteredComments = array_values(array_filter(
                array_map(fn($c) => $c['comment'], $comments),
                fn($c) => trim($c) !== ''
            ));

            $allEvaluations[] = [
                'evaluation' => $eval,
                'average' => $avg,
                'evaluators' => $count,
                'level' => $this->getPerformanceLevel($avg),
                'categorySummary' => $categorySummary,
                'compositeTotal' => round($compositeTotal, 2),
                'comments' => $filteredComments,
            ];
        }

        // Build composite averages across all evaluations per category
        $categoryNames = [];
        $compositeSums = [];
        $compositeCounts = [];

        foreach ($allEvaluations as $item) {
            foreach ($item['categorySummary'] as $cat) {
                $name = $cat['name'];
                if (!in_array($name, $categoryNames, true)) {
                    $categoryNames[] = $name;
                }
                if (!isset($compositeSums[$name])) {
                    $compositeSums[$name] = 0.0;
                    $compositeCounts[$name] = 0;
                }
                $compositeSums[$name] += $cat['mean'];
                $compositeCounts[$name]++;
            }
        }

        $catCount = count($categoryNames);
        $weightFrac = $catCount > 0 ? 1.0 / $catCount : 0;
        $weightPct = $catCount > 0 ? round(100 / $catCount) : 0;
        $compositeCategories = [];
        $compositeGrandTotal = 0.0;

        foreach ($categoryNames as $name) {
            $wMean = $compositeCounts[$name] > 0
                ? round($compositeSums[$name] / $compositeCounts[$name], 2)
                : 0;
            $wRating = round($wMean * $weightFrac, 2);
            $compositeGrandTotal += $wRating;
            $compositeCategories[] = [
                'name' => $name,
                'weightedMean' => $wMean,
                'weightPct' => $weightPct,
                'weightedRating' => $wRating,
            ];
        }

        // Split into Baccalaureate vs Graduate, pad to 7 each
        $baccEvaluations = [];
        $gradEvaluations = [];

        foreach ($allEvaluations as $item) {
            $college = $item['evaluation']->getCollege() ?? '';
            if (stripos($college, 'Graduate') !== false) {
                $gradEvaluations[] = $item;
            } else {
                $baccEvaluations[] = $item;
            }
        }

        $emptyCategories = [];
        foreach ($categoryNames as $name) {
            $emptyCategories[] = [
                'name' => $name,
                'mean' => 0.00,
                'weightPct' => $catCount > 0 ? round(100 / $catCount) : 0,
                'weightedRating' => 0.00,
            ];
        }
        $emptySlot = [
            'evaluation' => null,
            'average' => 0.00,
            'evaluators' => 0,
            'level' => 'N/A',
            'categorySummary' => $emptyCategories,
            'compositeTotal' => 0.00,
            'comments' => [],
            'subjectComments' => [],
        ];

        while (count($baccEvaluations) < 7) {
            $baccEvaluations[] = $emptySlot;
        }
        while (count($gradEvaluations) < 7) {
            $gradEvaluations[] = $emptySlot;
        }

        return $this->render('report/superior/print_all_results.html.twig', [
            'faculty' => $faculty,
            'allEvaluations' => $allEvaluations,
            'baccEvaluations' => $baccEvaluations,
            'gradEvaluations' => $gradEvaluations,
            'categoryNames' => $categoryNames,
            'compositeCategories' => $compositeCategories,
            'compositeGrandTotal' => round($compositeGrandTotal, 2),
            'compositeLevel' => $this->getPerformanceLevel(round($compositeGrandTotal, 2)),
        ]);
    }

    // ════════════════════════════════════════════════
    //  F. SYSTEM SETTINGS / AUDIT LOG
    // ════════════════════════════════════════════════

    #[Route('/audit-log', name: 'admin_audit_log', methods: ['GET'])]
    public function auditLog(Request $request, AuditLogRepository $repo, UserRepository $userRepo): Response
    {
        $filterAction = $request->query->get('action', '');
        $filterUser = $request->query->get('user', '');

        $logs = $repo->findRecent(
            100,
            $filterAction ?: null,
            $filterUser ? (int) $filterUser : null
        );

        return $this->render('admin/audit_log.html.twig', [
            'logs' => $logs,
            'filterAction' => $filterAction,
            'filterUser' => $filterUser,
            'staffUsers' => $userRepo->findBy([], ['lastName' => 'ASC']),
        ]);
    }

    #[Route('/audit-log/delete-all', name: 'admin_audit_log_delete_all', methods: ['POST'])]
    public function deleteAllAuditLogs(Request $request, AuditLogRepository $repo): Response
    {
        if ($this->isCsrfTokenValid('delete_all_audit_logs', $request->request->get('_token'))) {
            $count = $repo->deleteAll();
            $this->addFlash('success', $count . ' audit log entries deleted.');
        }
        return $this->redirectToRoute('admin_audit_log');
    }

    // ── Helper ──

    private function getPerformanceLevel(float $avg): string
    {
        return match (true) {
            $avg >= 4.5 => 'Always manifested',
            $avg >= 3.5 => 'Often manifested',
            $avg >= 2.5 => 'Sometimes manifested',
            $avg >= 1.5 => 'Seldom manifested',
            default => 'Never/Rarely manifested',
        };
    }

    #[Route('/faculty-messages', name: 'admin_faculty_messages', methods: ['GET'])]
    public function facultyMessages(
        EvaluationMessageRepository $msgRepo,
        MessageNotificationRepository $notifRepo,
    ): Response {
        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() !== null) {
            try {
                $notifRepo->markAllAsReadForUser($currentUser->getId());
            } catch (\Throwable) {
                // Do not block message page rendering if notification cleanup fails.
            }
        }

        $messages = $msgRepo->findAllMessages();
        $repliesMap = [];
        foreach ($messages as $msg) {
            $repliesMap[$msg->getId()] = $msgRepo->findRepliesForMessage($msg->getId());
        }
        return $this->render('admin/faculty/faculty_messages.html.twig', [
            'messages' => $messages,
            'repliesMap' => $repliesMap,
            'pendingCount' => $msgRepo->countPending(),
            'deleteRoute' => 'admin_faculty_message_delete',
        ]);
    }

    #[Route('/faculty-messages/{id}/reply', name: 'admin_faculty_message_reply', methods: ['POST'])]
    public function facultyMessageReply(
        int $id,
        Request $request,
        EvaluationMessageRepository $msgRepo,
        MessageNotificationRepository $notifRepo,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
    ): Response {
        $parentMsg = $msgRepo->find($id);
        if (!$parentMsg) {
            throw $this->createNotFoundException('Message not found.');
        }

        $reply = trim($request->request->get('reply', ''));
        $status = $request->request->get('status', EvaluationMessage::STATUS_REVIEWED);

        if ($reply) {
            // Create a new message as a reply in the conversation
            $newMsg = new EvaluationMessage();
            $newMsg->setSender($this->getUser());
            $newMsg->setMessage($reply);
            $newMsg->setSenderType('admin');
            $newMsg->setParentMessage($parentMsg);
            $newMsg->setSubject('Re: ' . $parentMsg->getSubject());
            $newMsg->setStatus(EvaluationMessage::STATUS_REVIEWED);
            $newMsg->setCreatedAt(new \DateTime());

            $file = $request->files->get('attachment');
            if ($file) {
                $originalName = $file->getClientOriginalName();
                $safeName = $slugger->slug(pathinfo($originalName, PATHINFO_FILENAME));
                $newFilename = $safeName . '-' . uniqid() . '.' . $file->guessExtension();
                $file->move(
                    $this->getParameter('kernel.project_dir') . '/public/uploads/attachments',
                    $newFilename
                );
                $newMsg->setAttachment($newFilename);
                $newMsg->setAttachmentOriginalName($originalName);
            }

            $em->persist($newMsg);
        }

        // Update parent message status
        if (in_array($status, [EvaluationMessage::STATUS_REVIEWED, EvaluationMessage::STATUS_RESOLVED])) {
            $parentMsg->setStatus($status);
        }

        $em->flush();

        // Notify the faculty member who sent the original message
        if (isset($newMsg) && $parentMsg->getSender()) {
            $notif = new MessageNotification();
            $notif->setNotifiedUser($parentMsg->getSender());
            $notif->setMessage($newMsg);
            $em->persist($notif);
            $em->flush();
        }

        $this->addFlash('success', 'Reply sent successfully.');

        return $this->redirectToRoute('admin_faculty_messages');
    }

    #[Route('/faculty-messages/{id}/delete', name: 'admin_faculty_message_delete', methods: ['POST'])]
    public function facultyMessageDelete(
        int $id,
        Request $request,
        EvaluationMessageRepository $msgRepo,
        EntityManagerInterface $em,
    ): Response {
        $msg = $msgRepo->find($id);
        if (!$msg) {
            throw $this->createNotFoundException('Message not found.');
        }

        if (!$this->isCsrfTokenValid('admin_delete_msg' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_faculty_messages');
        }

        // Delete attachment file if exists
        if ($msg->getAttachment()) {
            $attachPath = $this->getParameter('kernel.project_dir') . '/public/uploads/attachments/' . $msg->getAttachment();
            if (file_exists($attachPath)) {
                unlink($attachPath);
            }
        }

        $em->remove($msg);
        $em->flush();
        $this->addFlash('success', 'Message deleted successfully.');

        return $this->redirectToRoute('admin_faculty_messages');
    }
}
