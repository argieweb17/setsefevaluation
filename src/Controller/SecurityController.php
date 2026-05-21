<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Bundle\SecurityBundle\Security;

class SecurityController extends AbstractController
{
    private function isStudentAccount(User $user): bool
    {
        return $user->isStudent();
    }

    private function normalizeStudentSchoolId(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        // Keep alpha-containing IDs untouched; only collapse separators for numeric student IDs.
        if (preg_match('/[A-Za-z]/', $trimmed) === 1) {
            return $trimmed;
        }

        return preg_replace('/\D+/', '', $trimmed) ?? $trimmed;
    }

    private function hasNoPassword(User $user): bool
    {
        return trim((string) $user->getPassword()) === '';
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, RouterInterface $router): Response
    {
        // If already logged in, redirect based on role
        $user = $this->getUser();
        if ($user instanceof User) {
            $roles = $user->getRoles();
            $isStudent = !in_array('ROLE_ADMIN', $roles)
                && !in_array('ROLE_STAFF', $roles)
                && !in_array('ROLE_FACULTY', $roles)
                && !in_array('ROLE_SUPERIOR', $roles);

            return $this->redirectToRoute($isStudent ? 'evaluation_history' : 'app_dashboard');
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();
        $googleClientId = $this->env('GOOGLE_AUTH_CLIENT_ID');
        $googleRoutesReady = $router->getRouteCollection()->get('app_google_login') !== null
            && $router->getRouteCollection()->get('app_google_login_verify') !== null;
        $googleAuthEnabled = $googleClientId !== '' && $googleRoutesReady;

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'googleClientId' => $googleClientId,
            'googleAuthEnabled' => $googleAuthEnabled,
        ]);
    }

    #[Route(path: '/login/google', name: 'app_google_login', methods: ['POST'])]
    public function googleLogin(
        Request $request,
        UserRepository $userRepo,
        AuditLogger $audit,
        MailerInterface $mailer,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];
        $idToken = trim((string) ($data['idToken'] ?? ''));

        if ($idToken === '') {
            return new JsonResponse(['success' => false, 'message' => 'Google sign-in token missing.'], 422);
        }

        $clientId = $this->env('GOOGLE_AUTH_CLIENT_ID');
        if ($clientId === '') {
            return new JsonResponse(['success' => false, 'message' => 'Google login is currently unavailable.'], 503);
        }

        $tokenInfo = $this->fetchGoogleTokenInfo($idToken);
        if ($tokenInfo === null || empty($tokenInfo['email'])) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid Google sign-in. Please try again.'], 401);
        }

        if (($tokenInfo['aud'] ?? '') !== $clientId) {
            return new JsonResponse(['success' => false, 'message' => 'Google client mismatch. Please contact support.'], 401);
        }

        if (($tokenInfo['email_verified'] ?? 'false') !== 'true') {
            return new JsonResponse(['success' => false, 'message' => 'Google account email is not verified.'], 401);
        }

        $email = mb_strtolower(trim((string) $tokenInfo['email']));
        $user = $userRepo->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            return new JsonResponse([
                'success' => false,
                'noAccount' => true,
                'message' => 'No account is linked to this Google email.',
            ], 404);
        }

        $status = trim((string) $user->getAccountStatus());
        if ($status === 'pending') {
            return new JsonResponse([
                'success' => false,
                'pending' => true,
                'message' => 'Your account is pending administrator approval.',
            ], 403);
        }

        if ($status !== 'active') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Your account is inactive. Please contact an administrator.',
            ], 403);
        }

        if ($this->requiresInstitutionalCredentials($user) && !$this->hasValidInstitutionalCredentials($user)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Faculty, Staff, and Superior accounts must have a registered ID. Please contact an administrator.',
            ], 403);
        }

        $code = (string) random_int(100000, 999999);
        $codeHash = password_hash($code, PASSWORD_BCRYPT);
        $expiry = time() + 600;

        $session = $request->getSession();
        $session->set('_google_login_gate', [
            'userId' => $user->getId(),
            'email' => $email,
            'codeHash' => $codeHash,
            'expiry' => $expiry,
            'attempts' => 0,
        ]);

        $fromEmail = $this->env('MAILER_FROM_EMAIL');
        $fromName = $this->env('MAILER_FROM_NAME', 'QUAMC Evaluation');
        if ($fromEmail === '') {
            $dsn = $this->env('MAILER_DSN');
            if (preg_match('#smtp://([^:@]+)@#', $dsn, $m)) {
                $fromEmail = urldecode($m[1]);
            }
        }
        if ($fromEmail === '') {
            $fromEmail = 'noreply@quamc.local';
        }

        $debugCode = null;
        $smtpError = null;
        $mailerDsn = $this->env('MAILER_DSN');
        $isNullTransport = ($mailerDsn === '' || str_starts_with($mailerDsn, 'null://'));
        $appEnv = $this->env('APP_ENV', 'prod');

        if ($isNullTransport && in_array($appEnv, ['dev', 'test'], true)) {
            $debugCode = $code;
            $audit->log('google_login_code_dev_fallback', 'User', $user->getId(), "Null transport fallback for {$email}");
        } else {
            try {
                $htmlBody = sprintf(
                    '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#fff;">'
                    . '<div style="text-align:center;margin-bottom:24px;">'
                    . '<h2 style="color:#1E3A8A;font-size:20px;margin:0;">QUAMC Evaluation System</h2>'
                    . '<p style="color:#64748B;font-size:13px;margin:4px 0 0;">Negros Oriental State University</p>'
                    . '</div>'
                    . '<div style="background:#F0F4FF;border-radius:10px;padding:20px 24px;text-align:center;margin-bottom:20px;">'
                    . '<p style="color:#374151;font-size:14px;margin:0 0 12px;">Your login verification code is:</p>'
                    . '<div style="font-size:36px;font-weight:700;letter-spacing:10px;color:#1E3A8A;font-family:monospace;">%s</div>'
                    . '<p style="color:#6B7280;font-size:12px;margin:12px 0 0;">Expires in <strong>10 minutes</strong></p>'
                    . '</div>'
                    . '<p style="color:#6B7280;font-size:12px;line-height:1.6;margin:0;">'
                    . 'This code was requested to sign in to your account. '
                    . 'Do not share it with anyone. If you did not request this, you can safely ignore this email.'
                    . '</p>'
                    . '</div>',
                    $code,
                );

                $textBody = "QUAMC Evaluation System - Login Verification Code\n\n"
                    . "Your login code is: {$code}\n\n"
                    . "This code expires in 10 minutes. Do not share it with anyone.\n\n"
                    . "If you did not request this, please ignore this email.";

                $message = (new Email())
                    ->from(new Address($fromEmail, $fromName))
                    ->to($email)
                    ->subject('QUAMC Login Code: ' . $code)
                    ->text($textBody)
                    ->html($htmlBody);

                $mailer->send($message);
                $audit->log('google_login_code_sent', 'User', $user->getId(), "Code sent to {$email}");
            } catch (\Throwable $e) {
                $smtpError = $e->getMessage();
                $audit->log('google_login_code_failed', 'User', $user->getId(), "Mail error for {$email}: {$smtpError}");
                if (in_array($appEnv, ['dev', 'test'], true)) {
                    $debugCode = $code;
                } else {
                    return new JsonResponse(['success' => false, 'message' => 'Failed to send login code. Please try again later.'], 500);
                }
            }
        }

        if ($debugCode !== null) {
            return new JsonResponse([
                'success' => true,
                'needsCode' => true,
                'email' => $email,
                'debugCode' => $debugCode,
                'message' => $smtpError
                    ? '[Dev] SMTP error - code: ' . $debugCode . ' | Error: ' . $smtpError
                    : '[Dev] Null transport - code: ' . $debugCode,
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'needsCode' => true,
            'email' => $email,
            'message' => 'Verification code sent to ' . $email . '. Check your Gmail inbox - if not there, check your Spam/Junk folder.',
        ]);
    }

    #[Route(path: '/login/google/verify', name: 'app_google_login_verify', methods: ['POST'])]
    public function verifyGoogleLoginCode(
        Request $request,
        UserRepository $userRepo,
        Security $security,
        AuditLogger $audit,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];
        $code = trim((string) ($data['code'] ?? ''));

        $session = $request->getSession();
        $gate = $session->get('_google_login_gate');

        if (!is_array($gate) || empty($gate['codeHash']) || empty($gate['userId'])) {
            return new JsonResponse(['success' => false, 'message' => 'No login verification in progress. Please sign in with Google again.'], 400);
        }

        if (time() > (int) ($gate['expiry'] ?? 0)) {
            $session->remove('_google_login_gate');
            return new JsonResponse(['success' => false, 'message' => 'Code expired. Please sign in with Google again.'], 400);
        }

        $attempts = ((int) ($gate['attempts'] ?? 0)) + 1;
        $gate['attempts'] = $attempts;
        $session->set('_google_login_gate', $gate);

        if ($attempts > 5) {
            $session->remove('_google_login_gate');
            return new JsonResponse(['success' => false, 'message' => 'Too many failed attempts. Please sign in with Google again.'], 429);
        }

        if ($code === '' || !password_verify($code, (string) $gate['codeHash'])) {
            $remaining = 5 - $attempts;
            return new JsonResponse([
                'success' => false,
                'message' => 'Incorrect code. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.',
            ], 401);
        }

        $user = $userRepo->find($gate['userId']);
        if (!$user instanceof User) {
            $session->remove('_google_login_gate');
            return new JsonResponse(['success' => false, 'message' => 'Account no longer exists.'], 404);
        }

        $status = trim((string) $user->getAccountStatus());
        if ($status === 'pending') {
            $session->remove('_google_login_gate');
            return new JsonResponse([
                'success' => false,
                'pending' => true,
                'message' => 'Your account is pending administrator approval.',
            ], 403);
        }

        if ($status !== 'active') {
            $session->remove('_google_login_gate');
            return new JsonResponse(['success' => false, 'message' => 'Your account is inactive. Please contact an administrator.'], 403);
        }

        if ($this->requiresInstitutionalCredentials($user) && !$this->hasValidInstitutionalCredentials($user)) {
            $session->remove('_google_login_gate');
            return new JsonResponse([
                'success' => false,
                'message' => 'Faculty, Staff, and Superior accounts must have a registered ID. Please contact an administrator.',
            ], 403);
        }

        $session->remove('_google_login_gate');
        $security->login($user, 'form_login', 'main');

        $roles = $user->getRoles();
        $isStudent = !in_array('ROLE_ADMIN', $roles, true)
            && !in_array('ROLE_STAFF', $roles, true)
            && !in_array('ROLE_FACULTY', $roles, true)
            && !in_array('ROLE_SUPERIOR', $roles, true);

        $redirect = $this->generateUrl($isStudent ? 'evaluation_history' : 'app_dashboard');

        $audit->log('google_login_success', 'User', $user->getId(), 'Google login completed with verification code');

        return new JsonResponse([
            'success' => true,
            'redirect' => $redirect,
        ]);
    }

    #[Route(path: '/login/student', name: 'app_student_login', methods: ['GET', 'POST'])]
    public function studentLogin(
        Request $request,
        UserRepository $userRepo,
        Security $security,
        UserPasswordHasherInterface $passwordHasher,
    ): Response
    {
        $this->addFlash('warning', 'Student Quick Login has been removed. Please use the main login page.');

        return $this->redirectToRoute('app_login');
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route(path: '/logout/check', name: 'app_secure_logout', methods: ['GET'])]
    public function secureLogout(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_logout');
        }

        if ($this->isStudentAccount($user) && $this->hasNoPassword($user)) {
            $this->addFlash('warning', 'Set your password first before logging out.');
            return $this->redirectToRoute('app_student_set_password', ['next' => 'logout']);
        }

        return $this->redirectToRoute('app_logout');
    }

    #[Route(path: '/student/set-password', name: 'app_student_set_password', methods: ['GET', 'POST'])]
    public function studentSetPassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if (!$this->isStudentAccount($user)) {
            return $this->redirectToRoute('app_dashboard');
        }

        $next = (string) $request->query->get('next', $request->request->get('next', ''));
        $saved = $request->query->getBoolean('saved', false);
        $error = null;

        if ($request->isMethod('POST')) {
            $newPassword = (string) $request->request->get('new_password', '');
            $confirmPassword = (string) $request->request->get('confirm_password', '');

            if ($newPassword === '' || $confirmPassword === '') {
                $error = 'Please enter and confirm your password.';
            } elseif (strlen($newPassword) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Passwords do not match.';
            }

            if (!$error) {
                $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
                $em->persist($user);
                $em->flush();

                if ($next === 'logout') {
                    return $this->redirectToRoute('app_student_set_password', [
                        'next' => 'logout',
                        'saved' => 1,
                    ]);
                }

                $this->addFlash('success', 'Password added successfully.');
                return $this->redirectToRoute('evaluation_history');
            }
        }

        return $this->render('security/student_set_password.html.twig', [
            'error' => $error,
            'next' => $next,
            'saved' => $saved,
        ]);
    }

    private function fetchGoogleTokenInfo(string $idToken): ?array
    {
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'method' => 'GET'],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
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

    private function requiresInstitutionalCredentials(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array('ROLE_FACULTY', $roles, true)
            || in_array('ROLE_STAFF', $roles, true)
            || in_array('ROLE_SUPERIOR', $roles, true);
    }

    private function hasValidInstitutionalCredentials(User $user): bool
    {
        return trim((string) $user->getSchoolId()) !== '';
    }

    /** Read an env var - prefers $_ENV (Symfony Dotenv) over getenv() (OS env). */
    private function env(string $name, string $default = ''): string
    {
        return $_ENV[$name] ?? $_SERVER[$name] ?? (getenv($name) !== false ? (string) getenv($name) : $default);
    }
}
