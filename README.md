# Docs

Hyperf 文档平台扩展包，支持多平台：

- **Google**：OAuth、Sheets、Drive（gid 定位、追加、按内容查找、受保护列 / 勾选框）
- **腾讯文档**：OAuth、在线表格、文件夹、分享权限

## 安装

```bash
composer require goletter/hyperf-docs
```

### 本地 path 开发（推荐，可自动加载 ConfigProvider）

Hyperf 通过 `Composer::getMergedExtra('hyperf')['config']` 收集各**已安装包**的 `extra.hyperf.config`。  
若只在根项目 `autoload.psr-4` 映射源码目录，包不会进入 `vendor/composer/installed.json`，**ConfigProvider 不会自动加载**。

请用 path 仓库以 Composer 包方式引入：

```json
{
  "require": {
    "goletter/hyperf-docs": "1.0.0"
  },
  "repositories": [
    {
      "type": "path",
      "url": "packages/goletter/hyperf-docs",
      "options": {
        "symlink": true,
        "versions": {
          "goletter/hyperf-docs": "1.0.0"
        }
      }
    }
  ]
}
```

然后执行：

```bash
composer update goletter/hyperf-docs -W
```

成功后会出现 `vendor/goletter/hyperf-docs`（一般为软链），ConfigProvider 中的 `dependencies` / `annotations.scan.paths` / `publish` 会自动合并进框架，无需再在 `config/autoload/dependencies.php` 手动绑定。

> 不要同时保留根项目 `autoload.psr-4` 里的 `Goletter\\Docs\\` 映射，以免与 path 包重复定义。

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
            // OAuth 文档要求 scope 固定为 all；实际权限以开放平台已开通权限为准
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
GOOGLE_REFRESH_TOKEN=
GOOGLE_SPREADSHEET_ID=

# 腾讯文档（开放平台 Client ID / Secret）
TENCENT_DOCS_CLIENT_ID=
TENCENT_DOCS_CLIENT_SECRET=
TENCENT_DOCS_REDIRECT_URI=
TENCENT_DOCS_DRIVE_ROOT_FOLDER_NAME=Goletter
TENCENT_DOCS_SCOPES=all
TENCENT_DOCS_TOKEN=
TENCENT_DOCS_OPEN_ID=
```

> Google：`access_token` 短期有效，请保存 `refresh_token` 并在过期后刷新。  
> 腾讯文档需先在 [开放合作平台](https://docs.qq.com/open) 创建应用并完成审核；OpenAPI 除 `Access-Token` 外必须带 `Open-Id`（换 token 回包中的 `user_id`）。表格接口通常需要 `scope.sheet` / `scope.sheet.readonly`。

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

$google = $this->docs->platform('google');
$tencent = $this->docs->platform('tencent');
$authUrl = $this->docs->auth('google')->getAuthUrl();

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

统一 `createSpreadsheet` 返回值：

```php
['id' => '...', 'title' => '...', 'url' => '...', 'raw' => /* 原始对象/数组 */]
```

### Google OAuth / Sheets

```php
use Goletter\Docs\DocsManager;
use Goletter\Docs\Platform\GooglePlatform;

/** @var GooglePlatform $platform */
$platform = $this->docs->platform('google');
$sheets = $platform->sheets();

$token = ['access_token' => $accessToken];
// 过期时：
// $token = $platform->refreshToken($refreshToken);

$spreadsheetId = '1f6KLBbJnsOMKMKUxkwH-Bfwm4YWkFA_RBibJbbLIq8M';
$gid = 649506568; // URL 里 #gid=
```

#### Range / gid

| 写法 | 说明 |
| --- | --- |
| `Sheet1!A1:Z100` | 按工作表标题 |
| `'含空格标题'!A1` | 特殊字符标题自动加引号 |
| `gid:123456` | 整表（读为 `A:Z`） |
| `gid:123456!A1:Z100` | 指定 gid + A1 |
| `gid:123456!F1` | 只写某一列 |

```php
$title = $platform->getSheetTitleByGid($token, $spreadsheetId, $gid);
$range = $platform->rangeForGid($token, $spreadsheetId, $gid, 'A1:Z100');
```

#### 读取

```php
// 只返回有内容的行，并裁掉行尾空单元格
// 第 4 个参数：每行至少几个非空单元格才保留（默认 1）
$rows = $sheets->readCells($token, $spreadsheetId, "gid:{$gid}");
$rows = $sheets->readCells($token, $spreadsheetId, "gid:{$gid}", 3);
```

#### 按内容查找指定行

```php
// 按 F 列（也可用 0-based 下标，A=0）匹配
$matches = $sheets->findRows($token, $spreadsheetId, "gid:{$gid}", 'F', '333333333');
/*
[
  [
    'row' => 12,
    'range' => "'Sheet'!A12:Z12",
    'values' => [..., '333333333', true],
  ],
]
*/
$one = $matches[0] ?? null;
```

#### 写入（受保护列 / 勾选框）

```php
// null = 跳过该格（不会把保护列放进请求 range）
// '' = 清空（仍需编辑权限）
// true/false = 勾选框
$sheets->writeCells($token, $spreadsheetId, "gid:{$gid}!A1", [
    [null, null, null, null, '名称', 'ID', null, true],
]);

// 更稳妥：直接写可编辑列
$sheets->writeCells($token, $spreadsheetId, "gid:{$gid}!F1", [['333333333']]);
$sheets->writeCells($token, $spreadsheetId, "gid:{$gid}!H1", [[true]]);
```

#### 编辑指定行

```php
// 按行号编辑
$result = $sheets->updateRow(
    $token,
    $spreadsheetId,
    "gid:{$gid}",
    [null, null, null, null, '新名称', '333333333', null, true],
    row: 12,
);

// 按列内容定位第一行再编辑（找不到返回 null）
$result = $sheets->updateRow(
    $token,
    $spreadsheetId,
    "gid:{$gid}",
    [null, null, null, null, '新名称', '333333333', null, false],
    column: 'F',
    match: '333333333',
);
/*
[
  'row' => 12,
  'range' => "'Sheet'!A12:Z12",
  'values' => [..., '333333333', false],  // 单行一维
]
*/

// 也可以 findRows + writeCells
$hit = $sheets->findRows($token, $spreadsheetId, "gid:{$gid}", 'F', '333333333')[0] ?? null;
if ($hit) {
    $sheets->writeCells($token, $spreadsheetId, "gid:{$gid}!A{$hit['row']}", [
        [null, null, null, null, '新名称', '333333333', null, true],
    ]);
}
```

#### 删除指定行

```php
// 按行号删除
$result = $sheets->deleteRow($token, $spreadsheetId, "gid:{$gid}", row: 12);
// ['row' => 12]

// 按列内容定位第一行再删除（找不到返回 null）
$result = $sheets->deleteRow(
    $token,
    $spreadsheetId,
    "gid:{$gid}",
    column: 'F',
    match: '333333333',
);
```

#### 追加到表格末尾

```php
// 单行（一维）
$result = $sheets->appendCells($token, $spreadsheetId, "gid:{$gid}", [
    null, null, null, null, '名称', '333333333', null, true,
]);
/*
[
  'row' => 12,
  'range' => "'Sheet'!A12:Z12",
  'values' => [..., '333333333', true],  // 单行返回一维
]
*/

// 多行（二维）；values 仍为二维
$result = $sheets->appendCells($token, $spreadsheetId, "gid:{$gid}", [
    [null, null, null, null, '名称1', '111', null, true],
    [null, null, null, null, '名称2', '222', null, true],
]);
```

含 `null` 时会先算下一空行，再只写有值的列（避开受保护列）。勾选列请传布尔 `true` / `false`，不要用 `''`。

#### 其它

```php
$file = $sheets->createSpreadsheet($token, '报表');
$sheets->batchWrite($token, $spreadsheetId, [
    ['range' => "gid:{$gid}!A1", 'values' => [['a', 'b']]],
]);
$sheets->addSheet($token, $spreadsheetId, 'Sheet2');
$sheets->moveSpreadsheetToDateFolder($token, $spreadsheetId);
$sheets->shareSpreadsheetForAnyoneReader($token, $spreadsheetId);
```

也可直接注入底层 `GoogleAuth` / `GoogleSheets`（不经过工厂），参数为原始 `accessToken` 字符串。

### 腾讯文档 OAuth / 表格

```php
use Goletter\Docs\Tencent\TencentAuth;
use Goletter\Docs\Tencent\TencentSheets;
use Hyperf\Di\Annotation\Inject;

#[Inject]
protected TencentAuth $auth;

#[Inject]
protected TencentSheets $sheets;

$authUrl = $this->auth->getAuthUrl(state: 'xyz'); // scope 固定为 all
$token = $this->auth->fetchToken($code);
// $token['access_token']、$token['refresh_token']、$token['open_id']

$file = $this->sheets->createSpreadsheet($accessToken, $openId, '报表');
$spreadsheetId = $file['ID'] ?? $file['id'];

// 读：spreadsheet v3（需 scope.sheet / scope.sheet.readonly）
$values = $this->sheets->readCells($accessToken, $openId, $spreadsheetId, 'A1:Z1000');

// 写：sheetbook v2
$this->sheets->writeCells($accessToken, $openId, $spreadsheetId, 'A1', [['姓名', '部门']]);

// 追加 / 按列查找（门面 sheets('tencent') 同样支持）
$sheets = $this->docs->sheets('tencent');
$tokenPayload = [
    'access_token' => $accessToken,
    'open_id' => $openId,
];
$sheets->appendCells($tokenPayload, $spreadsheetId, 'A1', [
    '张三', '技术部',
]);
$sheets->findRows($tokenPayload, $spreadsheetId, 'A1:Z1000', 'A', '张三');
$sheets->updateRow($tokenPayload, $spreadsheetId, 'A1:Z1000', ['李四', '产品部'], column: 'A', match: '张三');
$sheets->deleteRow($tokenPayload, $spreadsheetId, 'A1:Z1000', column: 'A', match: '李四');

$this->sheets->moveSpreadsheetToDateFolder($accessToken, $openId, $spreadsheetId);
$this->sheets->shareSpreadsheetForAnyoneReader($accessToken, $openId, $spreadsheetId);
```

权限变更或接口报 `Request Scope Invalid` 时：在开放平台开通表格相关权限 → 用户重新授权换新 token。

## 能力对照

| 能力 | Google | 腾讯文档 |
| --- | --- | --- |
| OAuth 授权 / 刷新 | ✅ | ✅ |
| 创建表格 | ✅ Spreadsheet | ✅ `type=sheet` |
| 读单元格 | ✅ Values（过滤空行） | ✅ spreadsheet v3 |
| 写单元格 | ✅ Values（null 跳过保护列） | ✅ sheetbook v2 |
| 编辑指定行 | ✅ `updateRow`（行号 / 按列查找） | ✅ `updateRow` |
| 删除指定行 | ✅ `deleteRow`（行号 / 按列查找） | ✅ `deleteRow` |
| 追加行 | ✅ `appendCells` | ✅ `appendCells` |
| 按列内容查找 | ✅ `findRows` | ✅ `findRows` |
| gid / sheetId 定位 | ✅ URL `#gid=` | ✅ 标题或 sheetId |
| 勾选框 | ✅ `true` / `false` | — |
| 添加工作表 | ✅ | ✅ spreadsheet v3 batchUpdate |
| 移动到日期目录 | ✅ Drive | ✅ folders + move |
| 公开只读分享 | ✅ anyone reader | ✅ `publicRead` |

## 测试命令

包内自带：

```bash
# Google
php bin/hyperf.php docs:test --platform=google --auth-url
php bin/hyperf.php docs:test --platform=google --token=xxx

# 腾讯文档
php bin/hyperf.php docs:test --platform=tencent --auth-url
php bin/hyperf.php docs:test --platform=tencent --token=xxx --open-id=yyy
```

业务侧也可自建命令，用 `DocsManager` + `gid` / `appendCells` / `findRows` 做联调。
