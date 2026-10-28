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

    // config_source=database：优先 JSON 列（columns.payload），否则读 attributes 平铺列
    'database' => [
        'connection' => env('DOCS_DB_CONNECTION'),
        'table' => env('DOCS_CONFIG_TABLE', 'docs_platform_configs'),
        'columns' => [
            'platform' => 'platform',
            'tenant_id' => 'tenant_id',
            'payload' => 'config',
        ],
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
    | 请求耗时日志（默认关闭）
    |--------------------------------------------------------------------------
    |
    | enabled=true 时，在 Google / 腾讯 / 飞书 Client 请求出口记录 duration_ms。
    | 需项目安装 hyperf/logger，并在 config/autoload/logger.php 配置对应 group。
    |
    | 示例（独立文件 runtime/logs/docs.log）：
    | 'docs' => [
    |     'handlers' => [[
    |         'class' => Monolog\Handler\RotatingFileHandler::class,
    |         'constructor' => [
    |             'filename' => BASE_PATH . '/runtime/logs/docs.log',
    |             'level' => Monolog\Level::Info,
    |         ],
    |     ]],
    | ],
    | 然后设 DOCS_LOG_GROUP=docs
    |
    | slow_ms > 0 时仅记录耗时达到阈值的成功请求（失败仍会记）.
    |
    */
    'logging' => [
        'enabled' => (bool) env('DOCS_LOG_ENABLED', false),
        'name' => env('DOCS_LOG_NAME', 'docs'),
        'group' => env('DOCS_LOG_GROUP', 'default'),
        'slow_ms' => (int) env('DOCS_LOG_SLOW_MS', 0),
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
