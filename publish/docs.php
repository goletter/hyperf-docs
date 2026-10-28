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
    | 支持两种表结构（优先读 JSON payload；没有或为空则读平铺列）：
    |
    | 1) 平铺列（推荐）：
    | CREATE TABLE docs_platform_configs (
    |   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    |   platform VARCHAR(32) NOT NULL DEFAULT '',
    |   tenant_id VARCHAR(100) NOT NULL DEFAULT '' COMMENT '空 = 全局',
    |   client_id VARCHAR(100) NOT NULL DEFAULT '',
    |   client_secret VARCHAR(100) NOT NULL DEFAULT '',
    |   redirect_uri VARCHAR(100) NOT NULL DEFAULT '',
    |   remark VARCHAR(200) NULL,
    |   created_at DATETIME NULL,
    |   updated_at DATETIME NULL,
    |   UNIQUE KEY uk_platform_tenant (platform, tenant_id)
    | );
    |
    | 2) JSON 列（兼容旧表）：
    |   ... + config JSON  —— columns.payload 指向该列
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
            // JSON 列名；表上没有该列或为空时自动改读 attributes 平铺字段
            'payload' => 'config',
        ],
        // 平铺列 → 配置 key（list 或 map：配置key => 列名）
        'attributes' => [
            'client_id',
            'client_secret',
            'redirect_uri',
            'app_id',
            'app_secret',
            'api_key',
            'drive_root_folder',
            'scopes',
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
