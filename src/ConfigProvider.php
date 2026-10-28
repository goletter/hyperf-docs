<?php

declare(strict_types=1);

namespace Goletter\Docs;

use Goletter\Docs\Config\PlatformConfigRepositoryFactory;
use Goletter\Docs\Contract\PlatformConfigRepositoryInterface;
use Goletter\Docs\Contract\PlatformFactoryInterface;
use Goletter\Docs\Support\RequestLogger;
use Goletter\Docs\Support\RequestLoggerFactory;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                PlatformConfigRepositoryInterface::class => PlatformConfigRepositoryFactory::class,
                RequestLogger::class => RequestLoggerFactory::class,
                PlatformFactoryInterface::class => PlatformFactory::class,
                PlatformFactory::class => PlatformFactory::class,
                DocsManager::class => DocsManager::class,
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config of Docs platforms (Google / Tencent / Feishu).',
                    'source' => __DIR__ . '/../publish/docs.php',
                    'destination' => BASE_PATH . '/config/autoload/docs.php',
                ],
            ],
        ];
    }
}
