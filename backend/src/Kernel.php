<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Honors APP_CACHE_DIR when set (e.g. /tmp/yummy-cache in Docker on Windows), isolating ParaTest workers via TEST_TOKEN.
     */
    public function getCacheDir(): string
    {
        $base = (string) ($_SERVER['APP_CACHE_DIR'] ?? $_ENV['APP_CACHE_DIR'] ?? '');
        if ($base !== '') {
            $token = (string) ($_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? '');

            return rtrim($base, '/\\').'/'.$this->environment.$token;
        }

        return parent::getCacheDir();
    }

    /**
     * Honors APP_LOG_DIR when set (e.g. /tmp/yummy-log in Docker on Windows).
     */
    public function getLogDir(): string
    {
        $base = (string) ($_SERVER['APP_LOG_DIR'] ?? $_ENV['APP_LOG_DIR'] ?? '');
        if ($base !== '') {
            return rtrim($base, '/\\');
        }

        return parent::getLogDir();
    }
}
