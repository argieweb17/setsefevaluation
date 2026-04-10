<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route('', name: 'app_profile', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $studentLoadslipCodes = (array) $request->getSession()->get('student_loadslip_codes', []);
        $studentLoadslipVerified = (bool) $request->getSession()->get('student_loadslip_verified', false);
        $isStudentVerified = $user->isStudent() && ($studentLoadslipVerified || !empty($studentLoadslipCodes));

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'isStudentVerified' => $isStudentVerified,
        ]);
    }

    #[Route('/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        EntityManagerInterface $em,
        AuditLogger $audit,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $user->setFirstName($request->request->get('firstName', ''));
            $user->setLastName($request->request->get('lastName', ''));

            // Student-specific fields
            if ($user->isStudent()) {
                $user->setSchoolId($request->request->get('schoolId'));
                $user->setYearLevel($request->request->get('yearLevel'));
                $user->setCampus($request->request->get('campus') ?: null);
            }

            $em->flush();

            $audit->log(AuditLog::ACTION_EDIT_USER, 'User', $user->getId(), 'Updated own profile');
            $this->addFlash('success', 'Profile updated.');
            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/edit.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/change-password', name: 'app_profile_change_password', methods: ['GET', 'POST'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        AuditLogger $audit,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $current = $request->request->get('currentPassword', '');
            $new = $request->request->get('newPassword', '');
            $confirm = $request->request->get('confirmPassword', '');

            if (!$hasher->isPasswordValid($user, $current)) {
                $this->addFlash('danger', 'Current password is incorrect.');
                return $this->render('profile/change_password.html.twig');
            }

            if ($new !== $confirm) {
                $this->addFlash('danger', 'New passwords do not match.');
                return $this->render('profile/change_password.html.twig');
            }

            if (strlen($new) < 6) {
                $this->addFlash('danger', 'Password must be at least 6 characters.');
                return $this->render('profile/change_password.html.twig');
            }

            $user->setPassword($hasher->hashPassword($user, $new));
            $em->flush();

            $audit->log(AuditLog::ACTION_RESET_PASSWORD, 'User', $user->getId(), 'Changed own password');
            $this->addFlash('success', 'Password changed successfully.');
            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/change_password.html.twig');
    }

    #[Route('/upload-picture', name: 'app_profile_upload_picture', methods: ['POST'])]
    public function uploadPicture(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $file = $request->files->get('profilePicture');

        if (!$file) {
            $this->addFlash('danger', 'Please select an image.');
            return $this->redirectToRoute('app_profile');
        }

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $slugger->slug($originalFilename);
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();

        try {
            $file->move(
                $this->getParameter('kernel.project_dir') . '/public/images/profiles',
                $newFilename
            );
        } catch (FileException $e) {
            $this->addFlash('danger', 'Failed to upload image.');
            return $this->redirectToRoute('app_profile');
        }

        $user->setProfilePicture($newFilename);
        $em->flush();

        $this->addFlash('success', 'Profile picture updated.');
        return $this->redirectToRoute('app_profile');
    }
}
