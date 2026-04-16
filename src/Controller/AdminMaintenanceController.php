<?php

namespace App\Controller;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\MaintenanceMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/maintenance')]
#[IsGranted('ROLE_ADMIN')]
class AdminMaintenanceController extends AbstractController
{
    public function __construct(
        private readonly MaintenanceMode $maintenanceMode,
        private readonly AuditLogger $audit,
    ) {}

    #[Route('', name: 'admin_maintenance', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/maintenance.html.twig', [
            'state' => $this->maintenanceMode->getState(),
        ]);
    }

    #[Route('/enable', name: 'admin_maintenance_enable', methods: ['POST'])]
    public function enable(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('maintenance_enable', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid maintenance request token.');
            return $this->redirectToRoute('admin_maintenance');
        }

        $message = trim((string) $request->request->get('message', ''));
        /** @var User|null $user */
        $user = $this->getUser();

        $this->maintenanceMode->enable($message, $user);

        $this->audit->log(
            AuditLog::ACTION_ENABLE_MAINTENANCE,
            'System',
            null,
            'Maintenance mode enabled by admin.'
        );

        $this->addFlash('success', 'Maintenance mode is now enabled. Non-admin users will see the maintenance page.');

        return $this->redirectToRoute('admin_maintenance');
    }

    #[Route('/disable', name: 'admin_maintenance_disable', methods: ['POST'])]
    public function disable(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('maintenance_disable', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid maintenance request token.');
            return $this->redirectToRoute('admin_maintenance');
        }

        $this->maintenanceMode->disable();

        $this->audit->log(
            AuditLog::ACTION_DISABLE_MAINTENANCE,
            'System',
            null,
            'Maintenance mode disabled by admin.'
        );

        $this->addFlash('success', 'Maintenance mode is now disabled. The system is accessible again.');

        return $this->redirectToRoute('admin_maintenance');
    }
}
