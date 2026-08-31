<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // 默认文档平台：google / tencent
    'default' => env('DOCS_PLATFORM', 'google'),

    /*
    |--------------------------------------------------------------------------
    | 自定义平台映射（工厂可扩展）
    |--------------------------------------------------------------------------
    |
    | 例如：
    | 'my_docs' => \App\Docs\Platform\MyDocsPlatform::class,
    |
    */
    'platforms_map' => [],

    'platforms' => [
        'google' => [
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
            'api_key' => env('GOOGLE_API_KEY'),
            'drive_root_folder' => env('GOOGLE_DRIVE_ROOT_FOLDER_NAME', 'Goletter'),
            'scopes' => [
                'https://www.googleapis.com/auth/documents',
                'https://www.googleapis.com/auth/drive.file',
                'https://www.googleapis.com/auth/spreadsheets',
            ],
        ],

        'tencent' => [
            'client_id' => env('TENCENT_DOCS_CLIENT_ID'),
            'client_secret' => env('TENCENT_DOCS_CLIENT_SECRET'),
            'redirect_uri' => env('TENCENT_DOCS_REDIRECT_URI'),
            'drive_root_folder' => env('TENCENT_DOCS_DRIVE_ROOT_FOLDER_NAME', 'Goletter'),
            // 授权 scope，all 表示申请应用已开通的全部权限；也可按需配置为逗号分隔字符串
            'scopes' => env('TENCENT_DOCS_SCOPES', 'all'),
        ],
    ],
];
