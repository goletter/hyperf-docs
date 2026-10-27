<?php

declare(strict_types=1);

namespace Goletter\Docs\Config;

use Goletter\Docs\Contract\PlatformConfigRepositoryInterface;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

class PlatformConfigRepositoryFactory
{
    public function __invoke(ContainerInterface $container): PlatformConfigRepositoryInterface
    {
        $config = $container->get(ConfigInterface::class);
        $file = new FilePlatformConfigRepository($config);
        $source = strtolower((string) $config->get('docs.config_source', 'file'));

        if ($source === 'database') {
            return new DatabasePlatformConfigRepository($config, $file);
        }

        return $file;
    }
}
