# Docs

Hyperf 文档平台扩展包，支持多平台：

- **Google**：Docs / Sheets / Drive
- **腾讯文档**：OAuth、在线表格、文件夹、分享权限

## 安装

```bash
composer require goletter/hyperf-docs
```

## 配置

发布配置：

```bash
php bin/hyperf.php vendor:publish goletter/hyperf-docs
```

```php
return [
    'default' => env('DOCS_PLATFORM', 'google'),

    // 自定义平台 class 映射（工厂可扩展 / 覆盖）
    'platforms_map' => [
        // 'my_docs' => \App\Docs\Platform\MyDocsPlatform::class,
    ],

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
            'scopes' => env('TENCENT_DOCS_SCOPES', 'all'),
        ],
    ],
];
```

环境变量：

```env
DOCS_PLATFORM=google

# Google
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
GOOGLE_API_KEY=
GOOGLE_DRIVE_ROOT_FOLDER_NAME=Goletter
GOOGLE_TOKEN=

# 腾讯文档（开放平台 Client ID / Secret）
TENCENT_DOCS_CLIENT_ID=
TENCENT_DOCS_CLIENT_SECRET=
TENCENT_DOCS_REDIRECT_URI=
TENCENT_DOCS_DRIVE_ROOT_FOLDER_NAME=Goletter
TENCENT_DOCS_SCOPES=all
TENCENT_DOCS_TOKEN=
TENCENT_DOCS_OPEN_ID=
```

> 腾讯文档需先在 [开放合作平台](https://docs.qq.com/open) 创建应用并完成审核，拿到 Client ID / Secret。  
> 调用 OpenAPI 时除 `Access-Token` 外，还必须带上 `Open-Id`（换 token 回包中的 `user_id`）。

## 使用

两个平台都已定义，通过**工厂模式**创建，可同时配置、按需选用。

```php
use Goletter\Docs\DocsManager;
use Goletter\Docs\Contract\PlatformFactoryInterface;
use Hyperf\Di\Annotation\Inject;

#[Inject]
protected DocsManager $docs;

#[Inject]
protected PlatformFactoryInterface $factory;

// 工厂直接创建
$google = $this->factory->make('google');
$tencent = $this->factory->make('tencent');
$default = $this->factory->make(); // docs.default

// 或通过门面（内部仍走工厂）
$google = $this->docs->platform('google');
$tencent = $this->docs->platform('tencent');
$authUrl = $this->docs->auth()->getAuthUrl();

$file = $this->docs->sheets('google')->createSpreadsheet(
    ['access_token' => $googleToken['access_token']],
    '报表'
);

$file = $this->docs->sheets('tencent')->createSpreadsheet(
    [
        'access_token' => $tencentToken['access_token'],
        'open_id' => $tencentToken['open_id'],
    ],
    '报表'
);
```

扩展自定义平台：

```php
// config/autoload/docs.php
'platforms_map' => [
    'my_docs' => \App\Docs\Platform\MyDocsPlatform::class,
],

// 或运行时注册
$this->factory->register('my_docs', MyDocsPlatform::class);
$this->factory->make('my_docs');
```

统一 `Sheets` 返回值：

```php
['id' => '...', 'title' => '...', 'url' => '...', 'raw' => /* 原始对象/数组 */]
```

也可继续直接注入底层类（不经过工厂）：

### Google OAuth / Sheets

```php
use Goletter\Docs\Google\GoogleAuth;
use Goletter\Docs\Google\GoogleSheets;
use Hyperf\Di\Annotation\Inject;

#[Inject]
protected GoogleAuth $auth;

#[Inject]
protected GoogleSheets $sheets;

$authUrl = $this->auth->getAuthUrl();
$token = $this->auth->fetchToken($code);
$token = $this->auth->refreshToken($refreshToken);

$spreadsheet = $this->sheets->createSpreadsheet($accessToken, '报表');
$this->sheets->writeCells($accessToken, $spreadsheetId, 'Sheet1!A1', [['姓名', '部门']]);
$this->sheets->batchWrite($accessToken, $spreadsheetId, [
    ['range' => 'Sheet1!A1', 'values' => [['a', 'b']]],
]);
$this->sheets->addSheet($accessToken, $spreadsheetId, 'Sheet2');
$this->sheets->moveSpreadsheetToDateFolder($accessToken, $spreadsheetId);
$this->sheets->shareSpreadsheetForAnyoneReader($accessToken, $spreadsheetId);
```

### 腾讯文档 OAuth / 表格

```php
use Goletter\Docs\Tencent\TencentAuth;
use Goletter\Docs\Tencent\TencentSheets;
use Hyperf\Di\Annotation\Inject;

#[Inject]
protected TencentAuth $auth;

#[Inject]
protected TencentSheets $sheets;

$authUrl = $this->auth->getAuthUrl(state: 'xyz');
$token = $this->auth->fetchToken($code);
// $token['access_token']、$token['refresh_token']、$token['open_id']

$file = $this->sheets->createSpreadsheet($accessToken, $openId, '报表');
$this->sheets->writeCells($accessToken, $openId, $file['ID'], 'A1', [['姓名', '部门']]);
$this->sheets->moveSpreadsheetToDateFolder($accessToken, $openId, $file['ID']);
$this->sheets->shareSpreadsheetForAnyoneReader($accessToken, $openId, $file['ID']);
```

## 说明

| 能力 | Google | 腾讯文档 |
| --- | --- | --- |
| OAuth 授权 / 刷新 | ✅ | ✅ |
| 创建表格 | ✅ Spreadsheet | ✅ `type=sheet` |
| 读写单元格 | ✅ Sheets Values | ✅ sheetbook values |
| 添加工作表 | ✅ | ✅ spreadsheet v3 batchUpdate |
| 移动到日期目录 | ✅ Drive | ✅ folders + move |
| 公开只读分享 | ✅ anyone reader | ✅ `publicRead` |

## 测试命令

```bash
# Google
php bin/hyperf.php docs:test --platform=google --auth-url
php bin/hyperf.php docs:test --platform=google --token=xxx

# 腾讯文档
php bin/hyperf.php docs:test --platform=tencent --auth-url
php bin/hyperf.php docs:test --platform=tencent --token=xxx --open-id=yyy
```
