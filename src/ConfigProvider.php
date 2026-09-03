<?php

declare(strict_types=1);

namespace Goletter\Docs;

use Goletter\Docs\Contract\PlatformFactoryInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
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
                    'description' => 'The config of Docs platforms (Google / Tencent).',
                    'source' => __DIR__ . '/../publish/docs.php',
                    'destination' => BASE_PATH . '/config/autoload/docs.php',
                ],
            ],
        ];
    }
}
