<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\CourseRepository;
use App\Repository\DepartmentRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class AuthApiController extends AbstractController
{
    #[Route('/login', name: 'login_help', methods: ['GET'])]
    public function loginHelp(): JsonResponse
    {
        return $this->json([
            'message' => 'Use POST /api/login to authenticate.',
            'required' => ['identifier', 'password'],
            'identifier' => 'email or schoolId',
            'acceptedInput' => ['JSON body', 'form data', 'query params'],
        ]);
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(
        Request $request,
        UserRepository $userRepo,
        UserPasswordHasherInterface $hasher
    ): JsonResponse {
        $data = $this->extractLoginData($request);

        if (!is_array($data) || $data === []) {
            return $this->json([
                'error' => 'Missing credentials. Send identifier and password.',
                'example' => ['identifier' => 'student@school.edu', 'password' => 'your-password'],
            ], 400);
        }

        $sources = $this->collectLoginSources($data);

        $identifier = '';
        $password = '';

        foreach ($sources as $source) {
            if ($identifier === '') {
                $identifier = trim((string) (
                    $source['identifier']
                    ?? $source['email']
                    ?? $source['schoolId']
                    ?? $source['school_id']
                    ?? $source['username']
                    ?? ''
                ));
            }

            if ($password === '') {
                $password = (string) ($source['password'] ?? $source['pass'] ?? '');
            }

            if ($identifier !== '' && $password !== '') {
                break;
            }
        }

        if ($identifier === '' || $password === '') {
            return $this->json(['error' => 'Identifier and password are required.'], 400);
        }

        $user = $userRepo->findOneBy(['email' => $identifier]);
        if (!$user) {
            $user = $userRepo->findOneBy(['schoolId' => $identifier]);
        }

        if (!$user || !$hasher->isPasswordValid($user, $password)) {
            return $this->json(['error' => 'Invalid credentials.'], 401);
        }

        if ($user->getAccountStatus() !== 'active') {
            return $this->json(['error' => 'Account is not active.'], 403);
        }

        $token = hash('sha256', $user->getId() . $_ENV['APP_SECRET'] . date('Y-m-d'));

        return $this->json([
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    private function extractLoginData(Request $request): array
    {
        $data = [];

        try {
            $jsonData = $request->toArray();
            if (is_array($jsonData)) {
                $data = $jsonData;
            }
        } catch (\Throwable) {
            $data = [];
        }

        $data = array_merge($data, $request->request->all(), $request->query->all());

        if ($data === []) {
            $rawBody = trim($request->getContent());
            if ($rawBody !== '') {
                parse_str($rawBody, $parsedBody);
                if (is_array($parsedBody) && $parsedBody !== []) {
                    $data = array_merge($data, $parsedBody);
                }
            }
        }

        return $data;
    }

    private function collectLoginSources(array $data): array
    {
        $sources = [$data];
        $pending = [$data];

        while ($pending !== []) {
            $current = array_pop($pending);

            foreach ($current as $value) {
                if (is_array($value) && $value !== []) {
                    $sources[] = $value;
                    $pending[] = $value;
                }
            }
        }

        return $sources;
    }

    #[Route('/register', name: 'register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserRepository $userRepo,
        DepartmentRepository $departmentRepo,
        CourseRepository $courseRepo,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em
    ): JsonResponse {
        if ($request->isMethod('GET')) {
            return $this->json([
                'message' => 'Use POST /api/register to create an account.',
                'required' => ['firstName', 'lastName', 'password'],
                'optional' => ['email', 'schoolId', 'role', 'departmentId', 'courseId', 'campus', 'yearLevel', 'employmentStatus', 'position', 'academicRank'],
            ]);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = $request->request->all();
        }

        if (!is_array($data) || $data === []) {
            return $this->json(['error' => 'Invalid payload. Send JSON or form data.'], 400);
        }

        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $role = strtolower(trim((string) ($data['role'] ?? 'student')));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $schoolId = trim((string) ($data['schoolId'] ?? ''));

        if ($firstName === '' || $lastName === '' || $password === '') {
            return $this->json(['error' => 'firstName, lastName, and password are required.'], 400);
        }

        if (strlen($password) < 8) {
            return $this->json(['error' => 'Password must be at least 8 characters long.'], 400);
        }

        $allowedRoles = ['student', 'faculty', 'staff', 'superior'];
        if (!in_array($role, $allowedRoles, true)) {
            return $this->json(['error' => 'Invalid role. Allowed values: student, faculty, staff, superior.'], 400);
        }

        if ($email === '' && $schoolId === '') {
            return $this->json(['error' => 'Either email or schoolId is required.'], 400);
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email format.'], 400);
        }

        if ($email !== '' && $userRepo->findOneBy(['email' => $email])) {
            return $this->json(['error' => 'Email is already registered.'], 409);
        }

        if ($schoolId !== '' && $userRepo->findOneBy(['schoolId' => $schoolId])) {
            return $this->json(['error' => 'School ID is already registered.'], 409);
        }

        $department = null;
        $departmentId = $data['departmentId'] ?? null;
        if ($departmentId !== null && $departmentId !== '') {
            $department = $departmentRepo->find((int) $departmentId);
            if (!$department) {
                return $this->json(['error' => 'Department not found.'], 404);
            }
        }

        $course = null;
        $courseId = $data['courseId'] ?? null;
        if ($courseId !== null && $courseId !== '') {
            $course = $courseRepo->find((int) $courseId);
            if (!$course) {
                return $this->json(['error' => 'Course not found.'], 404);
            }
        }

        $roleMap = [
            'faculty' => ['ROLE_FACULTY'],
            'staff' => ['ROLE_STAFF'],
            'superior' => ['ROLE_FACULTY', 'ROLE_SUPERIOR'],
            'student' => [],
        ];

        $user = new User();
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setEmail($email !== '' ? $email : null);
        $user->setSchoolId($schoolId !== '' ? $schoolId : null);
        $user->setRoles($roleMap[$role]);
        $user->setAccountStatus($role === 'student' ? 'active' : 'pending');
        $user->setPassword($hasher->hashPassword($user, $password));

        if ($department) {
            $user->setDepartment($department);
        }
        if ($course) {
            $user->setCourse($course);
        }

        $campus = trim((string) ($data['campus'] ?? ''));
        if ($campus !== '') {
            $user->setCampus($campus);
        }

        $yearLevel = trim((string) ($data['yearLevel'] ?? ''));
        if ($yearLevel !== '') {
            $user->setYearLevel($yearLevel);
        }

        $position = trim((string) ($data['position'] ?? ''));
        if ($position !== '') {
            $user->setPosition($position);
        }

        $academicRank = trim((string) ($data['academicRank'] ?? ''));
        if ($academicRank !== '') {
            $user->setAcademicRank($academicRank);
        }

        $employmentStatus = trim((string) ($data['employmentStatus'] ?? ''));
        if ($role === 'superior' && $employmentStatus === '' && $position !== '') {
            $employmentStatus = $this->normalizeSuperiorEmploymentStatus($position);
        }
        if ($employmentStatus !== '') {
            $user->setEmploymentStatus($employmentStatus);
        }

        $em->persist($user);
        $em->flush();

        return $this->json([
            'success' => true,
            'message' => $role === 'student'
                ? 'Registration successful. Account is active.'
                : 'Registration successful. Account is pending admin approval.',
            'requiresApproval' => $role !== 'student',
            'user' => $this->serializeUser($user),
        ], 201);
    }

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

    private function normalizeSuperiorEmploymentStatus(string $position): string
    {
        $normalized = mb_strtolower(trim($position));
        if (str_contains($normalized, 'vice president')) {
            return 'Vice President';
        }
        if (str_contains($normalized, 'president')) {
            return 'President';
        }
        if (str_contains($normalized, 'campus director')) {
            return 'Campus Director';
        }
        if (str_contains($normalized, 'dean')) {
            return 'Dean';
        }
        if (str_contains($normalized, 'head') || str_contains($normalized, 'chair')) {
            return 'Department Head';
        }

        return $position;
    }
}
