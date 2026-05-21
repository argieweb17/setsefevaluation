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
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
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

        $googleClientId = $this->env('GOOGLE_AUTH_CLIENT_ID');
        $googleAuthEnabled = $googleClientId !== '';

        // Read current Google gate state from session (for JS hydration)
        $session = $request->getSession();
        $googleGate = $session->get('_google_gate', []);
        $googleAuthState = is_array($googleGate) && !empty($googleGate['verified']) ? [
            'verified' => true,
            'email'    => $googleGate['email'] ?? '',
            'role'     => $googleGate['role'] ?? '',
        ] : null;

        $user = new User();
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
                    'googleClientId' => $googleClientId,
                    'googleAuthEnabled' => $googleAuthEnabled,
                    'googleAuthState' => $googleAuthState,
                ]);
            }

            // ── Google gate enforcement for all roles (when Google auth is enabled) ──
            $submittedRole = $form->get('role')->getData();
            if ($googleAuthEnabled && in_array($submittedRole, ['faculty', 'staff', 'superior', 'student'], true)) {
                $gate = $session->get('_google_gate', []);
                if (!is_array($gate) || empty($gate['verified']) || ($gate['role'] ?? '') !== $submittedRole) {
                    $securityError = 'Google sign-in verification is required for ' . ucfirst((string)$submittedRole) . ' registration.';
                    return $this->render('registration/register.html.twig', [
                        'registrationForm' => $form,
                        'registrationSuccess' => false,
                        'registeredRole' => null,
                        'colleges' => $colleges,
                        'deptCollegeMap' => $deptCollegeMap,
                        'securityError' => $securityError,
                        'googleClientId' => $googleClientId,
                        'googleAuthEnabled' => $googleAuthEnabled,
                        'googleAuthState' => null,
                    ]);
                }
            }

            // ── Timing check: reject if submitted too fast (< 3 seconds) ──
            $formTimestamp = (int) $form->get('_ts')->getData();
            if ($formTimestamp > 0 && (time() - $formTimestamp) < 3) {
                $audit->log('registration_bot_blocked', 'User', null, 'Timing check failed from IP: ' . $request->getClientIp());
                $securityError = 'Registration submitted too quickly. Please try again.';
            }

            // ── Rate limiting: max 5 registrations per IP per hour ──
            if (!$securityError) {
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

                $gateEmail = '';
                if ($googleAuthEnabled) {
                    $gateState = $session->get('_google_gate', []);
                    if (is_array($gateState)) {
                        $gateEmail = mb_strtolower(trim((string) ($gateState['email'] ?? '')));
                    }
                }

                if ($gateEmail === '') {
                    $securityError = 'Google account email could not be resolved. Please verify with Google again.';

                    return $this->render('registration/register.html.twig', [
                        'registrationForm' => $form,
                        'registrationSuccess' => false,
                        'registeredRole' => null,
                        'colleges' => $colleges,
                        'deptCollegeMap' => $deptCollegeMap,
                        'securityError' => $securityError,
                        'googleClientId' => $googleClientId,
                        'googleAuthEnabled' => $googleAuthEnabled,
                        'googleAuthState' => $googleAuthState,
                    ]);
                }

                $user->setEmail($gateEmail);

                $schoolId = $this->resolveRoleIdentifier($request, $user, $role);
                $hasCredentialError = false;

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
                        'googleClientId' => $googleClientId,
                        'googleAuthEnabled' => $googleAuthEnabled,
                        'googleAuthState' => $googleAuthState,
                    ]);
                }

                $em->persist($user);
                $em->flush();

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
            'googleClientId' => $googleClientId,
            'googleAuthEnabled' => $googleAuthEnabled,
            'googleAuthState' => $googleAuthState,
        ]);
    }

    #[Route('/register/google/start', name: 'app_register_google_start', methods: ['POST'])]
    public function startGoogleVerification(Request $request, AuditLogger $audit, MailerInterface $mailer, UserRepository $userRepo): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $idToken = trim((string) ($data['idToken'] ?? ''));
        $role    = trim((string) ($data['role'] ?? ''));

        $privilegedRoles = ['faculty', 'staff', 'superior', 'student'];
        if (!in_array($role, $privilegedRoles, true)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid role.'], 400);
        }

        if ($idToken === '') {
            return new JsonResponse(['success' => false, 'message' => 'Google sign-in token missing.'], 422);
        }

        $clientId  = $this->env('GOOGLE_AUTH_CLIENT_ID');
        $tokenInfo = $this->fetchGoogleTokenInfo($idToken);

        if ($tokenInfo === null || empty($tokenInfo['email'])) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid Google sign-in. Please try again.'], 401);
        }

        if ($clientId !== '' && ($tokenInfo['aud'] ?? '') !== $clientId) {
            return new JsonResponse(['success' => false, 'message' => 'Google client mismatch. Please contact support.'], 401);
        }

        if (($tokenInfo['email_verified'] ?? 'false') !== 'true') {
            return new JsonResponse(['success' => false, 'message' => 'Google account email is not verified.'], 401);
        }

        $email = mb_strtolower(trim((string) $tokenInfo['email']));

        // ── Check if this email is already registered ──
        $existing = $userRepo->findOneBy(['email' => $email]);
        if ($existing !== null) {
            $audit->log('google_gate_duplicate_email', 'User', $existing->getId(), "Duplicate registration attempt via Google: {$email}");
            return new JsonResponse([
                'success'     => false,
                'duplicate'   => true,
                'message'     => 'An account with this Google email already exists. Please log in instead.',
            ], 409);
        }

        $code     = (string) random_int(100000, 999999);
        $codeHash = password_hash($code, PASSWORD_BCRYPT);
        $expiry   = time() + 600;

        $session = $request->getSession();
        $session->set('_google_gate', [
            'codeHash' => $codeHash,
            'email'    => $email,
            'role'     => $role,
            'expiry'   => $expiry,
            'attempts' => 0,
            'verified' => false,
        ]);

        $fromEmail = $this->env('MAILER_FROM_EMAIL');
        $fromName  = $this->env('MAILER_FROM_NAME', 'QUAMC Evaluation');
        if ($fromEmail === '') {
            $dsn = $this->env('MAILER_DSN');
            if (preg_match('#smtp://([^:@]+)@#', $dsn, $m)) {
                $fromEmail = urldecode($m[1]);
            }
        }
        if ($fromEmail === '') {
            $fromEmail = 'noreply@quamc.local';
        }

        $debugCode  = null;
        $smtpError  = null;
        $mailerDsn  = $this->env('MAILER_DSN');
        $isNullTransport = ($mailerDsn === '' || str_starts_with($mailerDsn, 'null://'));
        $appEnv = $this->env('APP_ENV', 'prod');

        if ($isNullTransport && in_array($appEnv, ['dev', 'test'], true)) {
            /* No real SMTP configured — use dev fallback immediately */
            $debugCode = $code;
            $audit->log('google_code_dev_fallback', 'User', null, "Null transport fallback: code for {$email}");
        } else {
            try {
                $htmlBody = sprintf(
                    '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#fff;">'
                    . '<div style="text-align:center;margin-bottom:24px;">'
                    . '<h2 style="color:#1E3A8A;font-size:20px;margin:0;">QUAMC Evaluation System</h2>'
                    . '<p style="color:#64748B;font-size:13px;margin:4px 0 0;">Negros Oriental State University</p>'
                    . '</div>'
                    . '<div style="background:#F0F4FF;border-radius:10px;padding:20px 24px;text-align:center;margin-bottom:20px;">'
                    . '<p style="color:#374151;font-size:14px;margin:0 0 12px;">Your verification code is:</p>'
                    . '<div style="font-size:36px;font-weight:700;letter-spacing:10px;color:#1E3A8A;font-family:monospace;">%s</div>'
                    . '<p style="color:#6B7280;font-size:12px;margin:12px 0 0;">Expires in <strong>10 minutes</strong></p>'
                    . '</div>'
                    . '<p style="color:#6B7280;font-size:12px;line-height:1.6;margin:0;">'
                    . 'This code was requested for a <strong>%s</strong> account registration. '
                    . 'Do not share it with anyone. If you did not request this, please ignore this email.'
                    . '</p>'
                    . '</div>',
                    $code,
                    ucfirst($role)
                );

                $textBody = "QUAMC Evaluation System — Verification Code\n\n"
                    . "Your verification code is: {$code}\n\n"
                    . "This code expires in 10 minutes. Do not share it with anyone.\n\n"
                    . "If you did not request this, please ignore this email.";

                $message = (new Email())
                    ->from(new Address($fromEmail, $fromName))
                    ->to($email)
                    ->subject('QUAMC Verification Code: ' . $code)
                    ->text($textBody)
                    ->html($htmlBody);

                $mailer->send($message);
                $audit->log('google_code_sent', 'User', null, "Code sent to {$email} for {$role}");
            } catch (\Throwable $e) {
                $smtpError = $e->getMessage();
                $audit->log('google_code_email_failed', 'User', null, "Mail error for {$email}: {$smtpError}");
                if (in_array($appEnv, ['dev', 'test'], true)) {
                    $debugCode = $code;
                } else {
                    return new JsonResponse(['success' => false, 'message' => 'Failed to send verification email. Please try again later.'], 500);
                }
            }
        }

        if ($debugCode !== null) {
            $responseData = [
                'success'   => true,
                'debugCode' => $debugCode,
                'message'   => $smtpError
                    ? '[Dev] SMTP error — code: ' . $debugCode . ' | Error: ' . $smtpError
                    : '[Dev] Null transport — code: ' . $debugCode,
            ];
        } else {
            $responseData = [
                'success' => true,
                'email'   => $email,
                'message' => 'Verification code sent to ' . $email . '. Check your Gmail inbox — if not there, check your Spam/Junk folder.',
            ];
        }

        return new JsonResponse($responseData);
    }

    #[Route('/register/google/verify', name: 'app_register_google_verify', methods: ['POST'])]
    public function verifyGoogleCode(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $code = trim((string) ($data['code'] ?? ''));

        $session = $request->getSession();
        $gate    = $session->get('_google_gate');

        if (!is_array($gate) || empty($gate['codeHash'])) {
            return new JsonResponse(['success' => false, 'message' => 'No verification in progress. Please sign in with Google first.'], 400);
        }

        if (time() > ($gate['expiry'] ?? 0)) {
            $session->remove('_google_gate');
            return new JsonResponse(['success' => false, 'message' => 'Code expired. Please sign in with Google again.'], 400);
        }

        $attempts         = ($gate['attempts'] ?? 0) + 1;
        $gate['attempts'] = $attempts;

        if ($attempts > 5) {
            $session->remove('_google_gate');
            return new JsonResponse(['success' => false, 'message' => 'Too many failed attempts. Please sign in with Google again.'], 429);
        }

        $session->set('_google_gate', $gate);

        if ($code === '' || !password_verify($code, $gate['codeHash'])) {
            $remaining = 5 - $attempts;
            return new JsonResponse([
                'success' => false,
                'message' => 'Incorrect code. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.',
            ], 401);
        }

        $gate['verified'] = true;
        $session->set('_google_gate', $gate);

        $audit->log('google_verification_passed', 'User', null, "Email {$gate['email']} verified for role {$gate['role']}");

        return new JsonResponse(['success' => true, 'email' => $gate['email'], 'role' => $gate['role']]);
    }

    private function fetchGoogleTokenInfo(string $idToken): ?array
    {
        $url     = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'method' => 'GET'],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            return null;
        }

        $data = json_decode($result, true);
        if (!is_array($data) || isset($data['error'])) {
            return null;
        }

        return $data;
    }

    /** Read an env var — prefers $_ENV (Symfony Dotenv) over getenv() (OS env). */
    private function env(string $name, string $default = ''): string
    {
        return $_ENV[$name] ?? $_SERVER[$name] ?? (getenv($name) !== false ? (string) getenv($name) : $default);
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
