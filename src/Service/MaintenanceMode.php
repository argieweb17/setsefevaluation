<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpKernel\KernelInterface;

class MaintenanceMode
{
    private string $stateFile;

    public function __construct(KernelInterface $kernel)
    {
        $this->stateFile = $kernel->getProjectDir() . '/var/maintenance_mode.json';
    }

    /**
     * @return array{
     *     enabled: bool,
     *     message: string,
     *     enabledAt: ?string,
     *     enabledBy: ?string,
     *     enabledById: ?int
     * }
     */
    public function getState(): array
    {
        $state = $this->readState();

        return [
            'enabled' => (bool) ($state['enabled'] ?? false),
            'message' => (string) ($state['message'] ?? ''),
            'enabledAt' => isset($state['enabledAt']) && is_string($state['enabledAt']) ? $state['enabledAt'] : null,
            'enabledBy' => isset($state['enabledBy']) && is_string($state['enabledBy']) ? $state['enabledBy'] : null,
            'enabledById' => isset($state['enabledById']) && is_int($state['enabledById']) ? $state['enabledById'] : null,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->getState()['enabled'];
    }

    public function enable(string $message, ?User $user = null): void
    {
        $cleanMessage = trim($message);
        if ($cleanMessage === '') {
            $cleanMessage = 'System update in progress. Please try again later.';
        }

        $this->writeState([
            'enabled' => true,
            'message' => $cleanMessage,
            'enabledAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'enabledBy' => $user?->getFullName(),
            'enabledById' => $user?->getId(),
        ]);
    }

    public function disable(): void
    {
        $this->writeState([
            'enabled' => false,
            'message' => '',
            'enabledAt' => null,
            'enabledBy' => null,
            'enabledById' => null,
        ]);
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }

        $raw = file_get_contents($this->stateFile);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            $this->stateFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
