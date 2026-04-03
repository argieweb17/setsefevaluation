<?php

namespace App\EventSubscriber;

use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ApiAuthSubscriber implements EventSubscriberInterface
{
    // Routes that don't require authentication
    private const PUBLIC_ROUTES = [
        'api_index',
        'api_login',
        'api_register',
        'api_active_evaluations',
        'active_evaluations',  // Fallback in case class-level prefix doesn't concatenate
    ];

    // Role requirements per route name (null = any authenticated user)
    private const ROUTE_ROLES = [
        'api_login'                => null,
        'api_register'             => null,
        'api_profile'              => null, // any authenticated user
        'api_dashboard'            => null, // any authenticated user
        'api_evaluations'          => ['ROLE_STUDENT', 'ROLE_FACULTY', 'ROLE_STAFF', 'ROLE_ADMIN'],
        'api_evaluation_questions' => ['ROLE_STUDENT', 'ROLE_FACULTY', 'ROLE_STAFF', 'ROLE_ADMIN'],
        'api_evaluation_submit'    => ['ROLE_STUDENT', 'ROLE_FACULTY', 'ROLE_STAFF', 'ROLE_ADMIN'],
        'api_my_results'           => ['ROLE_FACULTY'],
        'api_my_subjects'          => ['ROLE_FACULTY'],
    ];

    public function __construct(
        private UserRepository $userRepo,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Run after CORS (250) but before the controller
            KernelEvents::REQUEST => ['onKernelRequest', 200],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Only handle /api routes
        if (!str_starts_with($path, '/api')) {
            return;
        }

        // Skip OPTIONS (handled by CORS subscriber)
        if ($request->getMethod() === 'OPTIONS') {
            return;
        }

        // Public paths that don't require authentication
        $publicPaths = [
            '/api',
            '/api/',
            '/api/login',
            '/api/register',
            '/api/active-evaluations',
        ];

        if (in_array($path, $publicPaths, true)) {
            return;
        }

        $routeName = $request->attributes->get('_route', '');

        // Public routes don't need auth
        if (in_array($routeName, self::PUBLIC_ROUTES, true)) {
            return;
        }

        // ── Authenticate via Bearer token ──
        $auth = $request->headers->get('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Authentication required.'],
                401
            ));
            return;
        }

        $token = substr($auth, 7);
        $user = $this->resolveUserFromToken($token);

        if ($user === null) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Invalid or expired token.'],
                401
            ));
            return;
        }

        // Store the authenticated user on the request for controllers
        $request->attributes->set('_api_user', $user);

        // ── Authorize: check role requirements ──
        $requiredRoles = self::ROUTE_ROLES[$routeName] ?? null;

        if ($requiredRoles !== null) {
            $userRoles = $user->getRoles();
            $hasRole = false;
            foreach ($requiredRoles as $role) {
                if (in_array($role, $userRoles, true)) {
                    $hasRole = true;
                    break;
                }
            }
            // ROLE_ADMIN always has access (hierarchy)
            if (!$hasRole && in_array('ROLE_ADMIN', $userRoles, true)) {
                $hasRole = true;
            }

            if (!$hasRole) {
                $event->setResponse(new JsonResponse(
                    ['error' => 'Access denied. Insufficient permissions.'],
                    403
                ));
                return;
            }
        }
    }

    private function resolveUserFromToken(string $token): ?\App\Entity\User
    {
        $secret = $_ENV['APP_SECRET'] ?? '';
        $today = date('Y-m-d');

        $users = $this->userRepo->findBy(['accountStatus' => 'active']);
        foreach ($users as $user) {
            $expected = hash('sha256', $user->getId() . $secret . $today);
            if (hash_equals($expected, $token)) {
                return $user;
            }
        }

        return null;
    }
}
