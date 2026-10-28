# Docs

Hyperf 文档平台扩展包，支持多平台：

- **Google**：OAuth、Sheets、Drive（gid 定位、追加、按内容查找、受保护列 / 勾选框）
- **腾讯文档**：OAuth、在线表格、文件夹、分享权限
- **飞书**：OAuth（user_access_token）、电子表格、云空间文件夹、链接分享

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

    // file（默认）| database（读库，缺项回落 file）
    'config_source' => env('DOCS_CONFIG_SOURCE', 'file'),

    'database' => [
        'connection' => env('DOCS_DB_CONNECTION'), // null = 默认库
        'table' => env('DOCS_CONFIG_TABLE', 'docs_platform_configs'),
        'columns' => [
            'platform' => 'platform',
            'tenant_id' => 'tenant_id',
            'payload' => 'config', // 可选 JSON 列；无列/为空则读 attributes
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
        'feishu' => [
            'app_id' => env('FEISHU_APP_ID'),
            'app_secret' => env('FEISHU_APP_SECRET'),
            'redirect_uri' => env('FEISHU_REDIRECT_URI'),
            'drive_root_folder' => env('FEISHU_DRIVE_ROOT_FOLDER_NAME', 'Goletter'),
            'scopes' => env('FEISHU_SCOPES', 'sheets:spreadsheet drive:drive offline_access'),
        ],
    ],
];
```

### 从数据库读取平台凭证（使用说明）

**适用**：OAuth 应用凭证（`client_id` / `secret` / `redirect_uri` 等）按环境或租户存在库里，不想写死在 `.env`。  
**不存**：用户的 `access_token` / `refresh_token`（仍由业务表保存，调用时传入）。

#### 1. 依赖与开关

```bash
composer require hyperf/database hyperf/db-connection
```

```env
DOCS_CONFIG_SOURCE=database
# DOCS_DB_CONNECTION=      # 空则用默认库
# DOCS_CONFIG_TABLE=docs_platform_configs
```

重新发布或手动合并 `config/autoload/docs.php` 中的 `config_source` / `database` 段。

#### 2. 建表并写入配置

**推荐：平铺列**（无 JSON）：

```sql
CREATE TABLE docs_platform_configs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  platform VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'google / tencent / feishu',
  tenant_id VARCHAR(100) NOT NULL DEFAULT '' COMMENT '空 = 全局；有值 = 该租户',
  client_id VARCHAR(100) NOT NULL DEFAULT '',
  client_secret VARCHAR(100) NOT NULL DEFAULT '',
  redirect_uri VARCHAR(100) NOT NULL DEFAULT '',
  remark VARCHAR(200) NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uk_platform_tenant (platform, tenant_id)
);
```

```sql
INSERT INTO docs_platform_configs (platform, tenant_id, client_id, client_secret, redirect_uri) VALUES (
  'google',
  '',
  'xxx.apps.googleusercontent.com',
  'xxx',
  'https://example.com/oauth/google/callback'
);
```

也兼容旧的 JSON 列 `config`：有值时优先用 JSON；没有该列或为空则读 `attributes` 平铺字段。空字符串不会覆盖 `.env` 兜底。

飞书可用 `app_id` / `app_secret`（也兼容 `client_id` / `client_secret`），按需把列名加进 `docs.database.attributes`。

合并规则：**库字段覆盖文件**；库里没有的 key 仍用 `docs.platforms.*`（`.env`）兜底。

#### 3. 业务调用（与原来相同）

```php
use Goletter\Docs\DocsManager;

// 授权 URL / 换 token：凭证从库（或回落文件）读取
$url = $docs->auth('google')->getAuthUrl($state);
$token = $docs->auth('google')->fetchToken($code);

// 表格：用户 token 仍要自己传
$docs->sheets('google')->createSpreadsheet(
    ['access_token' => $userAccessToken],
    '月报'
);
```

#### 4. 多租户（可选）

在进入业务前设置协程上下文，同一请求内后续读写都会带上该 `tenant_id` 查库：

```php
use Goletter\Docs\DocsContext;

DocsContext::setTenantId((string) $tenantId);

// 中间件示例：
// DocsContext::setTenantId((string) $request->getHeaderLine('X-Tenant-Id'));
```

未设置 `tenant_id` 时查 `tenant_id IS NULL`（或空字符串）的全局行。

#### 5. 自定义配置源（可选）

实现 `Goletter\Docs\Contract\PlatformConfigRepositoryInterface`，在项目 `dependencies` 里覆盖绑定即可（不必改本包）。

环境变量：

```env
DOCS_PLATFORM=google
DOCS_CONFIG_SOURCE=file
# DOCS_CONFIG_SOURCE=database
# DOCS_DB_CONNECTION=
# DOCS_CONFIG_TABLE=docs_platform_configs

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

# 飞书（开放平台 App ID / App Secret）
FEISHU_APP_ID=
FEISHU_APP_SECRET=
FEISHU_REDIRECT_URI=
FEISHU_DRIVE_ROOT_FOLDER_NAME=Goletter
FEISHU_SCOPES=sheets:spreadsheet drive:drive offline_access
FEISHU_TOKEN=
FEISHU_REFRESH_TOKEN=
```

> Google：`access_token` 短期有效，请保存 `refresh_token` 并在过期后刷新。  
> 腾讯文档需先在 [开放合作平台](https://docs.qq.com/open) 创建应用并完成审核；OpenAPI 除 `Access-Token` 外必须带 `Open-Id`（换 token 回包中的 `user_id`）。表格接口通常需要 `scope.sheet` / `scope.sheet.readonly`。  
> 飞书需在 [开放平台](https://open.feishu.cn/) 创建应用，开通电子表格 / 云文档权限，并配置重定向 URL。授权 scope 须含 `offline_access` 才能拿到 `refresh_token`（每次刷新都会换新的 refresh_token，旧的立即失效）。token 只需 `access_token`，无需 `open_id`。

## 使用

三个平台都已定义，通过**工厂模式**创建，可同时配置、按需选用。

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
$feishu = $this->docs->platform('feishu');
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

$file = $this->docs->sheets('feishu')->createSpreadsheet(
    ['access_token' => $feishuToken['access_token']],
    '报表'
);
```

### 传入配置创建实例

业务表里已有 OAuth 应用凭证时，不必依赖全局 `docs.platforms` / 库表，直接传配置拿独立实例（缺的 key 仍回落文件默认）：

```php
$platform = $this->docs->make('google', [
    'client_id' => $row['client_id'],
    'client_secret' => $row['client_secret'],
    'redirect_uri' => $row['redirect_uri'],
    'drive_root_folder' => 'Goletter',
]);

$authUrl = $platform->auth()->getAuthUrl($state);
$token = $platform->auth()->fetchToken($code);

$file = $platform->sheets()->createSpreadsheet(
    ['access_token' => $token['access_token']],
    '报表'
);

// 飞书
$feishu = $this->docs->make('feishu', [
    'app_id' => $row['app_id'],
    'app_secret' => $row['app_secret'],
    'redirect_uri' => $row['redirect_uri'],
]);

// 等价
$this->factory->make('tencent', [
    'client_id' => '...',
    'client_secret' => '...',
    'redirect_uri' => '...',
]);
```

> `make($name, $config)` 仅支持内置 `google` / `tencent` / `feishu`；每次调用新建对象，不污染全局配置与容器单例。用户 `access_token` 仍在调用时传入。

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

// 从分享链接解析 spreadsheetId / gid
// https://docs.google.com/spreadsheets/d/{id}/edit#gid=649506568
$parsed = $sheets->parseSpreadsheetUrl(
    'https://docs.google.com/spreadsheets/d/1f6KLBbJnsOMKMKUxkwH-Bfwm4YWkFA_RBibJbbLIq8M/edit#gid=649506568'
);
// → spreadsheet_id / gid / url
// 或：Goletter\Docs\Support\SheetUrl::parseGoogle($url)
// 反解：SheetUrl::buildGoogle($spreadsheetId, $gid)
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

#### 插在表头下方（旧数据下推）

```php
// 单行 / 多行均可；始终插在第 1 行表头下面
$result = $sheets->insertAfterHeader($token, $spreadsheetId, "gid:{$gid}", [
    [null, null, null, null, '名称1', '111', null, true],
    [null, null, null, null, '名称2', '222', null, true],
]);
/*
[
  'row' => 2,
  'range' => "'Sheet'!A2:Z3",
  'values' => [[...], [...]],
]
*/
```

#### 按条件 upsert（有则改，无则插到表头下）

命中已有行时，**只有数据有变化才会写入**；内容完全一致则跳过，不进入 `updated`。`null` 单元格表示跳过该格（不参与比较、也不覆盖）。

```php
// 单列：按 F 列匹配
$result = $sheets->upsertRows(
    $token,
    $spreadsheetId,
    "gid:{$gid}",
    [
        [null, null, null, null, '名称1', '111', null, true],
        [null, null, null, null, '名称2', '222', null, true],
    ],
    column: 'F',
);

// 多列 AND：E + F 都相等才算同一行
$result = $sheets->upsertRows(
    $token,
    $spreadsheetId,
    "gid:{$gid}",
    [
        [null, null, null, null, '名称1', '111', null, true],
        [null, null, null, null, '名称2', '222', null, true],
    ],
    column: ['E', 'F'],
);

// 表头占两行（第 1、2 行）：数据从第 3 行起，新行插到第 3 行，第 1~2 行不参与匹配
$result = $sheets->upsertRows(
    $token,
    $spreadsheetId,
    "gid:{$gid}",
    [
        [null, null, null, null, '名称1', '111', null, true],
    ],
    column: 'F',
    dataStartRow: 3,
);
/*
[
  'updated' => [
    ['row' => 5, 'range' => "...", 'values' => [...]],
  ],
  'inserted' => [          // 没有新行时为 null
    'row' => 2,
    'range' => "...",
    'values' => [[...]],
  ],
]
*/
```

#### 左右分块表（只动某一区块）

`$values` 只含该区块列（首元素=区块首列）。默认行为与整表 `insertAfterHeader` / `upsertRows` 一致：**插在第 1 行表头下面、本区块旧数据下推、可批量**；有则改、无则插表头下。

```php
// 1) 表头下批量插入（默认 dataStartRow=2, position=prepend）
$sheets->insertBlock($token, $spreadsheetId, "gid:{$gid}!J:O", [
    ['2026/4/1', 2000, 1990, '水单', 1, '备注'],
    ['2026/4/2', 3000, 2980, '', 1, ''],
]);

// 2) 有则改、无则插到表头下（可多条件）
$sheets->upsertBlock(
    $token,
    $spreadsheetId,
    "gid:{$gid}!A:E",
    [
        ['2026/9/24', 10.5, 0.5, null, null],
        ['2026/9/23', 11, 0.6, null, null],
    ],
    column: 'A',          // 或多列 ['A', 'B'] / 相对下标 0
);

// 接到区块末尾（不插表头下）
$sheets->insertBlock(..., position: 'append');
$sheets->upsertBlock(..., column: 'P', position: 'append');
```

你这种「两行表头 + 合计」的表，数据从第 4 行起，显式传 `dataStartRow: 4`：

```php
$sheets->upsertBlock(
    $token,
    $spreadsheetId,
    "gid:{$gid}!J:O",
    [['2026/4/1', 2000, 1990, '水单', 1, '备注']],
    column: 'J',
    dataStartRow: 4,      // 跳过第 1～3 行表头/合计
    position: 'prepend',  // 插到第 4 行，只挤 J:O
);
```

`prepend` 时格式是否跟着下移：

- Google：用「插入单元格并下移」，格式随数据下移，新行沿用下一行的格式。
- 腾讯文档：开放接口没有插入行/单元格，也不能设置对齐，只能读出后整块写回，格式留在原单元格。需先把区块数据区（如 `A4:E1000`）统一设好对齐等格式，否则下移到空白格的数据会是默认样式。

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

// 从分享链接解析 spreadsheetId / sheetId（?tab=）
// https://docs.qq.com/sheet/DSEhEVGJuQ1dFQXhM?tab=BB08J2
// → spreadsheet_id=300000000$HHDTbnCWEAxL, sheet_id=BB08J2
$parsed = $this->docs->sheets('tencent')->parseSpreadsheetUrl(
    'https://docs.qq.com/sheet/DSEhEVGJuQ1dFQXhM?tab=BB08J2'
);
// 或：Goletter\Docs\Support\SheetUrl::parseTencent($url)

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

### 飞书 OAuth / 电子表格

```php
use Goletter\Docs\Feishu\FeishuAuth;
use Goletter\Docs\Feishu\FeishuSheets;
use Hyperf\Di\Annotation\Inject;

#[Inject]
protected FeishuAuth $auth;

#[Inject]
protected FeishuSheets $sheets;

$authUrl = $this->auth->getAuthUrl(state: 'xyz');
$token = $this->auth->fetchToken($code);
// $token['access_token']、$token['refresh_token']（须含 offline_access）
// 刷新后务必保存新的 refresh_token，旧值立即失效

$file = $this->docs->sheets('feishu')->createSpreadsheet(
    ['access_token' => $token['access_token']],
    '报表'
);
$spreadsheetToken = $file['id'];

// 从分享链接解析 spreadsheetToken / sheetId（?sheet=）
// https://xxx.feishu.cn/sheets/shtcnXXXX?sheet=0b12
$parsed = $this->docs->sheets('feishu')->parseSpreadsheetUrl($url);
// 或：Goletter\Docs\Support\SheetUrl::parseFeishu($url)

$sheets = $this->docs->sheets('feishu');
$tokenPayload = ['access_token' => $token['access_token']];

// range：默认第一张表；也可用 sheetId / 标题
$values = $sheets->readCells($tokenPayload, $spreadsheetToken, 'A1:Z1000');
$sheets->writeCells($tokenPayload, $spreadsheetToken, 'A1', [['姓名', '部门']]);
$sheets->appendCells($tokenPayload, $spreadsheetToken, 'A1', ['张三', '技术部']);
$sheets->insertAfterHeader($tokenPayload, $spreadsheetToken, $parsed['sheet_id'] ?: 'A1', [
    ['李四', '产品部'],
]);
$sheets->upsertRows($tokenPayload, $spreadsheetToken, 'A1:Z1000', [
    ['张三', '技术部'],
], column: 'A');

$sheets->moveSpreadsheetToDateFolder($tokenPayload, $spreadsheetToken);
$sheets->shareSpreadsheetForAnyoneReader($tokenPayload, $spreadsheetToken);
```

飞书 `range` 最终会解析成 `{sheetId}!A1:B2`：

| 写法 | 说明 |
| --- | --- |
| `A1:Z100` | 默认第一个工作表 |
| `{sheetId}!A1:Z100` | 指定工作表 ID |
| `sheet:{sheetId}!A1` | 同上（带前缀） |
| `{标题}!A1:Z100` | 按工作表标题解析 |

> 列区块 `insertBlock` / `upsertBlock`：飞书 `insert_dimension` 是整行插入，区块场景采用「读区块 + 合并写回」，只改指定列，不影响左右其它块（与腾讯类似）。表头下整表插入走原生 `insert_dimension_range`。

## 能力对照

| 能力 | Google | 腾讯文档 | 飞书 |
| --- | --- | --- | --- |
| OAuth 授权 / 刷新 | ✅ | ✅ | ✅ user_access_token |
| 创建表格 | ✅ Spreadsheet | ✅ `type=sheet` | ✅ sheets v3 |
| 读单元格 | ✅ Values（过滤空行） | ✅ spreadsheet v3 | ✅ sheets v2 values |
| 写单元格 | ✅ Values（null 跳过保护列） | ✅ sheetbook v2 | ✅ sheets v2 values |
| 编辑指定行 | ✅ `updateRow` | ✅ `updateRow` | ✅ `updateRow` |
| 删除指定行 | ✅ `deleteRow` | ✅ `deleteRow` | ✅ `deleteRow` |
| 追加行 | ✅ `appendCells` | ✅ `appendCells` | ✅ `values_append` |
| 表头下插入 | ✅ `insertAfterHeader` | ✅ 读后写回 | ✅ `insert_dimension` |
| 按条件 upsert | ✅ `upsertRows` | ✅ `upsertRows` | ✅ `upsertRows` |
| 列区块插入 | ✅ `insertBlock` | ✅ `insertBlock` | ✅ 区块读写回 |
| 列区块 upsert | ✅ `upsertBlock` | ✅ `upsertBlock` | ✅ `upsertBlock` |
| 按列内容查找 | ✅ `findRows` | ✅ `findRows` | ✅ `findRows` |
| 子表定位 | ✅ URL `#gid=` | ✅ 标题 / sheetId | ✅ 标题 / sheetId / `?sheet=` |
| 勾选框 | ✅ `true` / `false` | — | — |
| 添加工作表 | ✅ | ✅ | ✅ sheets_batch_update |
| 移动到日期目录 | ✅ Drive | ✅ folders + move | ✅ Drive files |
| 公开只读分享 | ✅ anyone reader | ✅ `publicRead` | ✅ link_share |

## 测试命令

包内自带：

```bash
# Google
php bin/hyperf.php docs:test --platform=google --auth-url
php bin/hyperf.php docs:test --platform=google --token=xxx

# 腾讯文档
php bin/hyperf.php docs:test --platform=tencent --auth-url
php bin/hyperf.php docs:test --platform=tencent --token=xxx --open-id=yyy

# 飞书
php bin/hyperf.php docs:test --platform=feishu --auth-url
php bin/hyperf.php docs:test --platform=feishu --token=xxx
```

业务侧也可自建命令，用 `DocsManager` + `appendCells` / `findRows` 做联调。
