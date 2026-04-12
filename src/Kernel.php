<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);
    }

    public function getCacheDir(): string
    {
        if ($this->isVercelRuntime()) {
            return '/tmp/symfony/cache/'.$this->environment;
        }

        return parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        if ($this->isVercelRuntime()) {
            return '/tmp/symfony/log';
        }

        return parent::getLogDir();
    }

    private function isVercelRuntime(): bool
    {
        return isset($_SERVER['VERCEL']) || false !== getenv('VERCEL');
    }
}
