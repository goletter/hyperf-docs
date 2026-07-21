<?php

declare(strict_types=1);

namespace Goletter\Docs;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
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
                    'description' => 'The config of Google Docs / Sheets / Drive client.',
                    'source' => __DIR__ . '/../publish/docs.php',
                    'destination' => BASE_PATH . '/config/autoload/docs.php',
                ],
            ],
        ];
    }
}