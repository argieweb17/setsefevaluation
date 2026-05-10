<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\DepartmentRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RegistrationController extends AbstractController
{
    private const GOOGLE_GATE_SESSION_KEY = '_register_google_gate';
    private const GOOGLE_AUTH_CODE_TTL = 600;
    private const GOOGLE_AUTH_CODE_MAX_ATTEMPTS = 5;

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        AuditLogger $audit,
        DepartmentRepository $deptRepo,
        UserRepository $userRepo,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $colleges = $deptRepo->findDistinctCollegeNames();
        $deptCollegeMap = $deptRepo->getDepartmentCollegeMap();
        $googleAuthClientId = $this->resolveGoogleAuthClientId();
        $googleAuthEnabled = $googleAuthClientId !== '';
        $googleApiKey = $this->resolveGoogleApiKey();
        $googleGate = $this->getGoogleAuthGate($request);

        $user = new User();
        if (is_array($googleGate) && (bool) ($googleGate['verified'] ?? false)) {
            $verifiedEmail = mb_strtolower(trim((string) ($googleGate['email'] ?? '')));
            if ($verifiedEmail !== '') {
                $user->setEmail($verifiedEmail);
            }

            $verifiedFirstName = trim((string) ($googleGate['firstName'] ?? ''));
            if ($verifiedFirstName !== '') {
                $user->setFirstName($verifiedFirstName);
            }

            $verifiedLastName = trim((string) ($googleGate['lastName'] ?? ''));
            if ($verifiedLastName !== '') {
                $user->setLastName($verifiedLastName);
            }
        }

        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        $securityError = null;

        if ($form->isSubmitted() && $form->isValid()) {
            // ── Honeypot check: bots fill hidden fields ──
            $honeypot = $form->get('website')->getData();
            if (!empty($honeypot)) {
                $audit->log('registration_bot_blocked', 'User', null, 'Honeypot triggered from IP: ' . $request->getClientIp());
                // Silently pretend success to confuse bots
                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $this->createForm(RegistrationFormType::class, new User()),
                    'registrationSuccess' => true,
                    'registeredRole' => 'student',
                    'colleges' => $colleges,
                    'deptCollegeMap' => $deptCollegeMap,
                    'googleAuthEnabled' => $googleAuthEnabled,
                    'googleAuthClientId' => $googleAuthClientId,
                    'googleApiKey' => $googleApiKey,
                    'googleAuthState' => $this->buildGoogleAuthState($request),
                ]);
            }

            // ── Timing check: reject if submitted too fast (< 3 seconds) ──
            $formTimestamp = (int) $form->get('_ts')->getData();
            if ($formTimestamp > 0 && (time() - $formTimestamp) < 3) {
                $audit->log('registration_bot_blocked', 'User', null, 'Timing check failed from IP: ' . $request->getClientIp());
                $securityError = 'Registration submitted too quickly. Please try again.';
            }

            // ── Rate limiting: max 5 registrations per IP per hour ──
            if (!$securityError) {
                $session = $request->getSession();
                $regAttempts = $session->get('_reg_attempts', []);
                $now = time();
                // Clean up attempts older than 1 hour
                $regAttempts = array_filter($regAttempts, fn($t) => ($now - $t) < 3600);
                if (count($regAttempts) >= 5) {
                    $audit->log('registration_rate_limited', 'User', null, 'Rate limit exceeded from IP: ' . $request->getClientIp());
                    $securityError = 'Too many registration attempts. Please try again later.';
                } else {
                    $regAttempts[] = $now;
                    $session->set('_reg_attempts', $regAttempts);
                }
            }

            if (!$securityError) {
                $user->setPassword(
                    $hasher->hashPassword($user, $form->get('plainPassword')->getData())
                );

                $role = $form->get('role')->getData();
                $roleMap = [
                    'faculty' => ['ROLE_FACULTY'],
                    'staff'   => ['ROLE_STAFF'],
                    'superior' => ['ROLE_FACULTY', 'ROLE_SUPERIOR'],
                    'student' => ['ROLE_STUDENT'],
                ];
                $user->setRoles($roleMap[$role] ?? []);

                $email = mb_strtolower(trim((string) $user->getEmail()));
                $user->setEmail($email !== '' ? $email : null);

                $schoolId = $this->resolveRoleIdentifier($request, $user, $role);
                $hasCredentialError = false;

                if ($this->requiresInstitutionalCredentials($role) && !$googleAuthEnabled) {
                    $form->addError(new FormError('Google Sign-In registration is not configured. Please contact the administrator.'));
                    $securityError = 'Google Sign-In registration is not configured. Please contact the administrator.';
                    $hasCredentialError = true;
                }

                if ($this->requiresInstitutionalCredentials($role) && !$this->hasVerifiedGoogleGate($request, $role, $email)) {
                    $form->get('email')->addError(new FormError('Please complete Google sign-in and verify the authentication code sent to your email.'));
                    if (!$securityError) {
                        $securityError = 'Google verification is required for Faculty, Staff, and Superior registration.';
                    }
                    $hasCredentialError = true;
                }

                if ($this->requiresInstitutionalCredentials($role)) {
                    if ($schoolId === '') {
                        $form->get('schoolId')->addError(new FormError('Employee/Staff ID is required for Faculty, Staff, and Superior accounts.'));
                        if (!$securityError) {
                            $securityError = 'Employee/Staff ID is required for Faculty, Staff, and Superior accounts.';
                        }
                        $hasCredentialError = true;
                    }
                }

                if ($schoolId !== '') {
                    $existingWithId = $userRepo->findOneBy(['schoolId' => $schoolId]);
                    if ($existingWithId !== null) {
                        $form->get('schoolId')->addError(new FormError('This ID is already registered.'));
                        if (!$securityError) {
                            $securityError = 'The provided ID is already registered.';
                        }
                        $hasCredentialError = true;
                    } else {
                        $user->setSchoolId($schoolId);
                    }
                }

                if ($role === 'superior') {
                    $position = trim((string) $request->request->get('_position', ''));
                    $user->setPosition($position !== '' ? $position : null);
                    $user->setAcademicRank($request->request->get('_academic_rank') ?: null);

                    // Keep superior rank discoverable even when employmentStatus is not explicitly shown in the form.
                    if ($position !== '' && !$user->getEmploymentStatus()) {
                        $user->setEmploymentStatus($this->normalizeSuperiorEmploymentStatus($position));
                    }
                }

                // Students are active immediately; faculty/staff/superior need admin approval
                if ($role === 'student') {
                    $user->setAccountStatus('active');
                } else {
                    $user->setAccountStatus('pending');
                }

                if ($hasCredentialError) {
                    return $this->render('registration/register.html.twig', [
                        'registrationForm' => $form,
                        'registrationSuccess' => false,
                        'registeredRole' => null,
                        'colleges' => $colleges,
                        'deptCollegeMap' => $deptCollegeMap,
                        'securityError' => $securityError,
                        'googleAuthEnabled' => $googleAuthEnabled,
                        'googleAuthClientId' => $googleAuthClientId,
                        'googleApiKey' => $googleApiKey,
                        'googleAuthState' => $this->buildGoogleAuthState($request),
                    ]);
                }

                $em->persist($user);
                $em->flush();

                if ($this->requiresInstitutionalCredentials($role)) {
                    $request->getSession()->remove(self::GOOGLE_GATE_SESSION_KEY);
                }

                $audit->log('user_registered', 'User', $user->getId(), sprintf(
                    'New %s registration: %s (IP: %s)',
                    $role,
                    $user->getEmail(),
                    $request->getClientIp()
                ));

                if ($role === 'student') {
                    $this->addFlash('registration_success', 'Your student account has been successfully created. You can now log in with your credentials.');
                } else {
                    $this->addFlash('registration_pending', 'Your account has been successfully created. Please wait for administrator approval before you can log in.');
                }

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
            'registrationSuccess' => false,
            'registeredRole' => null,
            'colleges' => $colleges,
            'deptCollegeMap' => $deptCollegeMap,
            'securityError' => $securityError,
            'googleAuthEnabled' => $googleAuthEnabled,
            'googleAuthClientId' => $googleAuthClientId,
            'googleApiKey' => $googleApiKey,
            'googleAuthState' => $this->buildGoogleAuthState($request),
        ]);
    }

    #[Route('/register/google/start', name: 'app_register_google_start', methods: ['POST'])]
    public function startGoogleVerification(
        Request $request,
        HttpClientInterface $httpClient,
        TransportInterface $mailerTransport,
        AuditLogger $audit,
    ): JsonResponse {
        $googleAuthClientId = $this->resolveGoogleAuthClientId();
        if ($googleAuthClientId === '') {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Google Sign-In is not configured on this server.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $payload = $this->extractRequestPayload($request);
        $idToken = trim((string) ($payload['id_token'] ?? ''));
        $role = mb_strtolower(trim((string) ($payload['role'] ?? '')));

        if (!$this->requiresInstitutionalCredentials($role)) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Google Sign-In is only required for Faculty, Staff, and Superior registration.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($idToken === '') {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Missing Google identity token.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $identity = $this->verifyGoogleIdToken($idToken, $googleAuthClientId, $httpClient);
        if ($identity === null) {
            $audit->log('registration_google_token_invalid', 'User', null, 'Invalid Google token from IP: ' . $request->getClientIp());

            return new JsonResponse([
                'ok' => false,
                'message' => 'Google sign-in validation failed. Please try again.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $email = mb_strtolower(trim((string) ($identity['email'] ?? '')));

        [$firstName, $lastName] = $this->normalizeGoogleNames(
            trim((string) ($identity['firstName'] ?? '')),
            trim((string) ($identity['lastName'] ?? '')),
            trim((string) ($identity['fullName'] ?? '')),
        );

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $mailSent = $this->sendGoogleVerificationCodeEmail($mailerTransport, $email, $code, $firstName);
        $isDevEnv = $this->isDevelopmentEnvironment();

        if (!$mailSent && !$isDevEnv) {
            return new JsonResponse([
                'ok' => false,
                'message' => $this->resolveMailerFailureMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $request->getSession()->set(self::GOOGLE_GATE_SESSION_KEY, [
            'role' => $role,
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'codeHash' => $this->hashGoogleCode($code, $request),
            'expiresAt' => time() + self::GOOGLE_AUTH_CODE_TTL,
            'attempts' => 0,
            'verified' => false,
        ]);

        if ($mailSent) {
            $audit->log('registration_google_code_sent', 'User', null, sprintf(
                'Google auth code sent for %s registration to %s (IP: %s)',
                $role,
                $email,
                $request->getClientIp()
            ));
        } else {
            $audit->log('registration_google_code_dev_fallback', 'User', null, sprintf(
                'Google auth code email failed; using dev fallback for %s registration to %s (IP: %s)',
                $role,
                $email,
                $request->getClientIp()
            ));
        }

        $response = [
            'ok' => true,
            'message' => $mailSent
                ? 'Authentication code sent to ' . $this->maskEmail($email) . '.'
                : 'Email delivery is unavailable in local development. Use the temporary verification code shown below.',
            'state' => $this->buildGoogleAuthState($request),
        ];

        if (!$mailSent && $isDevEnv) {
            $response['debugCode'] = $code;
        }

        return new JsonResponse($response);
    }

    #[Route('/register/google/verify-code', name: 'app_register_google_verify_code', methods: ['POST'])]
    public function verifyGoogleCode(
        Request $request,
        AuditLogger $audit,
    ): JsonResponse {
        $payload = $this->extractRequestPayload($request);
        $role = mb_strtolower(trim((string) ($payload['role'] ?? '')));
        $code = trim((string) ($payload['code'] ?? ''));

        if (!$this->requiresInstitutionalCredentials($role)) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Invalid role for Google verification.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Enter a valid 6-digit authentication code.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $gate = $this->getGoogleAuthGate($request);
        if (!is_array($gate)) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'No active Google sign-in session found. Please sign in with Google again.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ((string) ($gate['role'] ?? '') !== $role) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Selected role does not match your Google sign-in session.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ((bool) ($gate['verified'] ?? false)) {
            return new JsonResponse([
                'ok' => true,
                'message' => 'Google verification already completed.',
                'state' => $this->buildGoogleAuthState($request),
            ]);
        }

        $expiresAt = (int) ($gate['expiresAt'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            $request->getSession()->remove(self::GOOGLE_GATE_SESSION_KEY);

            return new JsonResponse([
                'ok' => false,
                'message' => 'Authentication code expired. Please sign in with Google again.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attempts = (int) ($gate['attempts'] ?? 0);
        if ($attempts >= self::GOOGLE_AUTH_CODE_MAX_ATTEMPTS) {
            $request->getSession()->remove(self::GOOGLE_GATE_SESSION_KEY);

            return new JsonResponse([
                'ok' => false,
                'message' => 'Too many invalid attempts. Please sign in with Google again.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $expectedHash = (string) ($gate['codeHash'] ?? '');
        $providedHash = $this->hashGoogleCode($code, $request);
        if ($expectedHash === '' || !hash_equals($expectedHash, $providedHash)) {
            $attempts++;
            $gate['attempts'] = $attempts;
            $request->getSession()->set(self::GOOGLE_GATE_SESSION_KEY, $gate);

            return new JsonResponse([
                'ok' => false,
                'message' => 'Invalid authentication code.',
                'attemptsLeft' => max(0, self::GOOGLE_AUTH_CODE_MAX_ATTEMPTS - $attempts),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $gate['verified'] = true;
        $gate['verifiedAt'] = time();
        unset($gate['codeHash'], $gate['expiresAt'], $gate['attempts']);
        $request->getSession()->set(self::GOOGLE_GATE_SESSION_KEY, $gate);

        $audit->log('registration_google_code_verified', 'User', null, sprintf(
            'Google auth code verified for %s (%s) from IP: %s',
            (string) ($gate['role'] ?? 'unknown'),
            (string) ($gate['email'] ?? 'unknown'),
            $request->getClientIp()
        ));

        return new JsonResponse([
            'ok' => true,
            'message' => 'Google verification complete. You can continue registration.',
            'state' => $this->buildGoogleAuthState($request),
        ]);
    }

    private function resolveRoleIdentifier(Request $request, User $user, ?string $role): string
    {
        if ($role === 'staff') {
            return trim((string) $request->request->get('_staff_id', ''));
        }

        if ($role === 'faculty' || $role === 'superior') {
            return trim((string) $request->request->get('_employee_id', ''));
        }

        return trim((string) $user->getSchoolId());
    }

    private function requiresInstitutionalCredentials(?string $role): bool
    {
        return in_array($role, ['faculty', 'staff', 'superior'], true);
    }

    private function resolveGoogleAuthClientId(): string
    {
        return trim((string) (
            $_ENV['GOOGLE_AUTH_CLIENT_ID']
            ?? $_SERVER['GOOGLE_AUTH_CLIENT_ID']
            ?? $_ENV['GOOGLE_CLIENT_ID']
            ?? $_SERVER['GOOGLE_CLIENT_ID']
            ?? ''
        ));
    }

    private function resolveGoogleApiKey(): string
    {
        return trim((string) (
            $_ENV['GOOGLE_API_KEY']
            ?? $_SERVER['GOOGLE_API_KEY']
            ?? ''
        ));
    }

    private function extractRequestPayload(Request $request): array
    {
        $contentType = mb_strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) $request->getContent(), true);
            return is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }

    private function getGoogleAuthGate(Request $request): ?array
    {
        $gate = $request->getSession()->get(self::GOOGLE_GATE_SESSION_KEY);
        if (!is_array($gate)) {
            return null;
        }

        if (!(bool) ($gate['verified'] ?? false)) {
            $expiresAt = (int) ($gate['expiresAt'] ?? 0);
            if ($expiresAt > 0 && $expiresAt < time()) {
                $request->getSession()->remove(self::GOOGLE_GATE_SESSION_KEY);
                return null;
            }
        }

        return $gate;
    }

    private function buildGoogleAuthState(Request $request): array
    {
        $gate = $this->getGoogleAuthGate($request);
        if (!is_array($gate)) {
            return ['verified' => false];
        }

        $expiresAt = isset($gate['expiresAt']) ? (int) $gate['expiresAt'] : null;
        $expiresIn = 0;
        if (is_int($expiresAt) && $expiresAt > 0) {
            $expiresIn = max(0, $expiresAt - time());
        }

        return [
            'verified' => (bool) ($gate['verified'] ?? false),
            'role' => (string) ($gate['role'] ?? ''),
            'email' => (string) ($gate['email'] ?? ''),
            'firstName' => (string) ($gate['firstName'] ?? ''),
            'lastName' => (string) ($gate['lastName'] ?? ''),
            'expiresIn' => $expiresIn,
            'attemptsLeft' => max(0, self::GOOGLE_AUTH_CODE_MAX_ATTEMPTS - (int) ($gate['attempts'] ?? 0)),
        ];
    }

    private function hasVerifiedGoogleGate(Request $request, string $role, string $email): bool
    {
        $gate = $this->getGoogleAuthGate($request);
        if (!is_array($gate) || !(bool) ($gate['verified'] ?? false)) {
            return false;
        }

        if ((string) ($gate['role'] ?? '') !== $role) {
            return false;
        }

        $normalizedGateEmail = mb_strtolower(trim((string) ($gate['email'] ?? '')));
        $normalizedEmail = mb_strtolower(trim($email));

        if ($normalizedGateEmail === '' || $normalizedEmail === '') {
            return false;
        }

        return hash_equals($normalizedGateEmail, $normalizedEmail);
    }

    private function verifyGoogleIdToken(
        string $idToken,
        string $googleClientId,
        HttpClientInterface $httpClient,
    ): ?array {
        try {
            $response = $httpClient->request('GET', 'https://oauth2.googleapis.com/tokeninfo', [
                'query' => ['id_token' => $idToken],
            ]);

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $payload = $response->toArray(false);
            if (!is_array($payload)) {
                return null;
            }

            $audience = trim((string) ($payload['aud'] ?? ''));
            if ($audience === '' || !hash_equals($googleClientId, $audience)) {
                return null;
            }

            $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
            $emailVerified = mb_strtolower(trim((string) ($payload['email_verified'] ?? '')));

            if ($email === '' || !in_array($emailVerified, ['true', '1', 'yes'], true)) {
                return null;
            }

            return [
                'email' => $email,
                'firstName' => trim((string) ($payload['given_name'] ?? '')),
                'lastName' => trim((string) ($payload['family_name'] ?? '')),
                'fullName' => trim((string) ($payload['name'] ?? '')),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeGoogleNames(string $firstName, string $lastName, string $fullName): array
    {
        if ($firstName !== '' && $lastName !== '') {
            return [$firstName, $lastName];
        }

        $fullName = trim($fullName);
        if ($fullName === '') {
            return [$firstName, $lastName];
        }

        $parts = preg_split('/\s+/u', $fullName);
        if (!is_array($parts) || $parts === []) {
            return [$firstName, $lastName];
        }

        if ($firstName === '') {
            $firstName = (string) ($parts[0] ?? '');
        }

        if ($lastName === '' && count($parts) > 1) {
            $lastName = (string) end($parts);
        }

        return [trim($firstName), trim($lastName)];
    }

    private function hashGoogleCode(string $code, Request $request): string
    {
        $appSecret = (string) ($_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? '');
        return hash('sha256', $code . '|' . $request->getSession()->getId() . '|' . $appSecret);
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';

        if ($local === '' || $domain === '') {
            return $email;
        }

        if (mb_strlen($local) <= 2) {
            return mb_substr($local, 0, 1) . '*@' . $domain;
        }

        return mb_substr($local, 0, 2) . str_repeat('*', max(1, mb_strlen($local) - 2)) . '@' . $domain;
    }

    private function sendGoogleVerificationCodeEmail(
        TransportInterface $mailerTransport,
        string $toEmail,
        string $code,
        string $firstName,
    ): bool {
        $displayName = trim($firstName) !== '' ? trim($firstName) : 'there';
        [$fromEmail, $fromName] = $this->resolveMailerFromIdentity();

        $message = (new Email())
            ->from(new Address($fromEmail, $fromName))
            ->to($toEmail)
            ->subject('Your SET-SEF Registration Authentication Code')
            ->text(
                "Hello {$displayName},\n\n"
                . "Use this authentication code to continue your registration:\n\n"
                . "Code: {$code}\n\n"
                . "This code will expire in 10 minutes.\n"
                . "If you did not initiate this request, you may ignore this email."
            );

        try {
            // Send synchronously so verification UX does not depend on a background messenger worker.
            $mailerTransport->send($message);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveMailerFromIdentity(): array
    {
        $fromEmail = trim((string) ($_ENV['MAILER_FROM_EMAIL'] ?? $_SERVER['MAILER_FROM_EMAIL'] ?? ''));
        $fromName = trim((string) ($_ENV['MAILER_FROM_NAME'] ?? $_SERVER['MAILER_FROM_NAME'] ?? 'SET-SEF Evaluation'));

        if ($fromEmail === '') {
            $mailerDsn = trim((string) ($_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? ''));
            $parsedDsn = parse_url($mailerDsn);
            if (is_array($parsedDsn) && isset($parsedDsn['user'])) {
                $candidate = rawurldecode((string) $parsedDsn['user']);
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                    $fromEmail = $candidate;
                }
            }
        }

        if ($fromEmail === '') {
            $fromEmail = 'no-reply@setsef.local';
        }

        if ($fromName === '') {
            $fromName = 'SET-SEF Evaluation';
        }

        return [$fromEmail, $fromName];
    }

    private function resolveMailerFailureMessage(): string
    {
        $mailerDsn = trim((string) ($_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? ''));
        $normalized = mb_strtolower($mailerDsn);

        if ($normalized === '' || $normalized === 'null://null') {
            return 'Email delivery is not configured. Set MAILER_DSN to a working SMTP server (for example Gmail SMTP) and try again.';
        }

        if (str_contains($normalized, '127.0.0.1:1025') || str_contains($normalized, 'localhost:1025') || str_contains($normalized, 'mailpit')) {
            return 'Local mail server is not reachable. Start Mailpit on port 1025 or switch MAILER_DSN to Gmail SMTP, then try again.';
        }

        return 'Unable to send authentication code right now. Please check MAILER_DSN and try again.';
    }

    private function isDevelopmentEnvironment(): bool
    {
        $appEnv = mb_strtolower(trim((string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'dev')));

        return in_array($appEnv, ['dev', 'test', 'local'], true);
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
