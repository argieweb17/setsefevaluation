<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\SuperiorEvaluationRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/superior')]
#[IsGranted('ROLE_SUPERIOR')]
class SuperiorApiController extends AbstractController
{
    #[Route('/results/detail', name: 'superior_results_detail', methods: ['GET'])]
    public function resultsDetail(
        Request $request,
        SuperiorEvaluationRepository $superiorEvalRepo,
        UserRepository $userRepo,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $this->assertDepartmentHeadSuperior($user);

        $evalId = (int) $request->query->get('evalId');
        $evaluateeId = (int) $request->query->get('evaluateeId');

        $evaluatee = $userRepo->find($evaluateeId);
        if (!$evaluatee) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $catAvgs = $superiorEvalRepo->getCategoryAverages($evaluateeId, $evalId);
        $comments = $superiorEvalRepo->getComments($evaluateeId, $evalId);
        $overall = $superiorEvalRepo->getOverallAverage($evaluateeId, $evalId);

        return $this->json([
            'evaluatee' => $evaluatee->getFullName(),
            'department' => $evaluatee->getDepartment() ? $evaluatee->getDepartment()->getDepartmentName() : '—',
            'overall' => $overall,
            'categories' => $catAvgs,
            'comments' => array_column($comments, 'comment'),
        ]);
    }

    private function assertDepartmentHeadSuperior(?User $user): void
    {
        if (!$user instanceof User || $user->isAdmin() || $user->isStaff()) {
            throw $this->createAccessDeniedException('Superior access is restricted to authorized superior accounts.');
        }

        if ($user->hasAssignedRole('ROLE_SUPERIOR')) {
            return;
        }

        if (!$user->isDepartmentHeadFaculty()) {
            throw $this->createAccessDeniedException('Superior access is restricted to authorized superior accounts.');
        }
    }
}
