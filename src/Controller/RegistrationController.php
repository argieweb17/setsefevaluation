<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\DepartmentRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $colleges = $deptRepo->findDistinctCollegeNames();
        $deptCollegeMap = $deptRepo->getDepartmentCollegeMap();

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
        ]);
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
