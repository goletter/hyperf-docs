# Docs

Hyperf 文档平台扩展包，支持多平台（当前已实现 Google Docs / Sheets / Drive）。

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
        // 其他平台后续在 platforms 下扩展
    ],
];
```

环境变量：

```env
DOCS_PLATFORM=google
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
GOOGLE_API_KEY=
GOOGLE_DRIVE_ROOT_FOLDER_NAME=Goletter
```

## 使用

### Google OAuth

```php
use Goletter\Docs\Google\GoogleAuth;
use Hyperf\Di\Annotation\Inject;

#[Inject]
protected GoogleAuth $auth;

$authUrl = $this->auth->getAuthUrl();
$token = $this->auth->fetchToken($code);
$token = $this->auth->refreshToken($refreshToken);
```

### Google Sheets

```php
use Goletter\Docs\Google\GoogleSheets;
use Hyperf\Di\Annotation\Inject;

#[Inject]
protected GoogleSheets $sheets;

$spreadsheet = $this->sheets->createSpreadsheet($accessToken, '报表');
$this->sheets->writeCells($accessToken, $spreadsheetId, 'Sheet1!A1', [['姓名', '部门']]);
$this->sheets->batchWrite($accessToken, $spreadsheetId, [
    ['range' => 'Sheet1!A1', 'values' => [['a', 'b']]],
]);
$this->sheets->addSheet($accessToken, $spreadsheetId, 'Sheet2');
$this->sheets->moveSpreadsheetToDateFolder($accessToken, $spreadsheetId);
$this->sheets->shareSpreadsheetForAnyoneReader($accessToken, $spreadsheetId);
```
