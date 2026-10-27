<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // 默认文档平台：google / tencent / feishu
    'default' => env('DOCS_PLATFORM', 'google'),

    /*
    |--------------------------------------------------------------------------
    | 配置来源：file（默认，读下方 platforms）| database（读库，缺项回落 file）
    |--------------------------------------------------------------------------
    */
    'config_source' => env('DOCS_CONFIG_SOURCE', 'file'),

    /*
    |--------------------------------------------------------------------------
    | 数据库配置（config_source=database 时生效）
    |--------------------------------------------------------------------------
    |
    | 表示例：
    | CREATE TABLE docs_platform_configs (
    |   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    |   platform VARCHAR(32) NOT NULL,
    |   tenant_id VARCHAR(64) NULL,
    |   config JSON NOT NULL,
    |   updated_at TIMESTAMP NULL,
    |   UNIQUE KEY uk_platform_tenant (platform, tenant_id)
    | );
    |
    | config JSON 字段示例：
    | {"client_id":"...","client_secret":"...","redirect_uri":"...","drive_root_folder":"Goletter"}
    |
    | 多租户：DocsContext::setTenantId($id) 后再调用平台 API。
    |
    */
    'database' => [
        'connection' => env('DOCS_DB_CONNECTION'),
        'table' => env('DOCS_CONFIG_TABLE', 'docs_platform_configs'),
        'columns' => [
            'platform' => 'platform',
            'tenant_id' => 'tenant_id',
            'payload' => 'config',
        ],
    ],

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

        'feishu' => [
            // 开放平台凭证：App ID / App Secret（也兼容 client_id / client_secret）
            'app_id' => env('FEISHU_APP_ID'),
            'app_secret' => env('FEISHU_APP_SECRET'),
            'redirect_uri' => env('FEISHU_REDIRECT_URI'),
            'drive_root_folder' => env('FEISHU_DRIVE_ROOT_FOLDER_NAME', 'Goletter'),
            // 空格或逗号分隔；offline_access 用于拿到 refresh_token
            'scopes' => env(
                'FEISHU_SCOPES',
                'sheets:spreadsheet drive:drive offline_access'
            ),
        ],
    ],
];
