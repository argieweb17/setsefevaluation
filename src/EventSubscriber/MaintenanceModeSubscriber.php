<?php

namespace App\EventSubscriber;

use App\Service\MaintenanceMode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

class MaintenanceModeSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = [
        'app_login',
        'app_student_login',
        'app_logout',
        'app_secure_logout',
        'admin_maintenance',
        'admin_maintenance_enable',
        'admin_maintenance_disable',
    ];

    public function __construct(
        private readonly MaintenanceMode $maintenanceMode,
        private readonly Security $security,
        private readonly Environment $twig,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->maintenanceMode->isEnabled()) {
            return;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        $path = $request->getPathInfo();

        if ($this->isAllowedPath($route, $path)) {
            return;
        }

        if (str_starts_with($path, '/api') || str_starts_with($path, '/reports/api') || str_starts_with($path, '/superior/api')) {
            $event->setResponse(new JsonResponse([
                'error' => 'The system is currently under maintenance. Please try again later.',
            ], 503));
            return;
        }

        $state = $this->maintenanceMode->getState();

        $content = $this->twig->render('maintenance/index.html.twig', [
            'message' => $state['message'],
            'enabledAt' => $state['enabledAt'],
        ]);

        $event->setResponse(new Response($content, 503));
    }

    private function isAllowedPath(string $route, string $path): bool
    {
        if ($route !== '' && in_array($route, self::ALLOWED_ROUTES, true)) {
            return true;
        }

        if (str_starts_with($route, '_profiler') || str_starts_with($route, '_wdt')) {
            return true;
        }

        $staticPrefixes = ['/assets', '/build', '/images', '/uploads', '/sounds'];
        foreach ($staticPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        if ($path === '/favicon.ico') {
            return true;
        }

        return false;
    }
}
