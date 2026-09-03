<?php

declare(strict_types=1);

namespace Goletter\Docs\Command;

use Goletter\Docs\Contract\PlatformInterface;
use Goletter\Docs\DocsManager;
use Goletter\Docs\Google\Exceptions\GoogleApiException;
use Goletter\Docs\Platform\TencentPlatform;
use Goletter\Docs\Tencent\Exceptions\TencentApiException;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

use function Hyperf\Support\env;

#[Command]
class TestCommand extends HyperfCommand
{
    private const COMMAND_NAME = 'docs:test';

    private const DEFAULT_SPREADSHEET_TITLE = 'Goletter Docs 测试表格';

    private const DEFAULT_SHEET = 'Sheet1';

    private const DEFAULT_DRIVE_ROOT_FOLDER = 'Goletter';

    public function __construct(
        protected DocsManager $docs,
        protected ConfigInterface $config,
    ) {
        parent::__construct(self::COMMAND_NAME);
    }

    public function configure(): void
    {
        parent::configure();

        $this
            ->setDescription('测试文档平台（Google / 腾讯）OAuth、表格、目录、分享')
            ->addOption('platform', 'p', InputOption::VALUE_OPTIONAL, '平台：google / tencent，默认取 docs.default')
            ->addOption('auth-url', null, InputOption::VALUE_NONE, '只输出 OAuth 授权地址')
            ->addOption('token', null, InputOption::VALUE_OPTIONAL, 'OAuth access token')
            ->addOption('open-id', null, InputOption::VALUE_OPTIONAL, '腾讯文档 Open-Id（user_id）')
            ->addOption('title', null, InputOption::VALUE_OPTIONAL, '测试表格标题')
            ->addOption('folder', null, InputOption::VALUE_OPTIONAL, '根目录名称');
    }

    public function handle(): int
    {
        try {
            $platformName = $this->platformName();
            $platform = $this->docs->platform($platformName);

            if ((bool) $this->input->getOption('auth-url')) {
                $this->line($platform->auth()->getAuthUrl('docs-test'));

                return self::SUCCESS;
            }

            $token = $this->tokenPayload($platformName);
            if ($token['access_token'] === '') {
                $this->error(sprintf(
                    '缺少 access token，请通过 --token 或环境变量传入（Google: GOOGLE_TOKEN，腾讯: TENCENT_DOCS_TOKEN）。'
                ));
                if ($platformName === TencentPlatform::NAME) {
                    $this->line('腾讯文档还需 --open-id 或 TENCENT_DOCS_OPEN_ID。');
                }
                $this->line('授权地址：' . $platform->auth()->getAuthUrl('docs-test'));

                return self::FAILURE;
            }

            if ($platformName === TencentPlatform::NAME && ($token['open_id'] ?? '') === '') {
                $this->error('腾讯文档缺少 open_id，请通过 --open-id 或 TENCENT_DOCS_OPEN_ID 传入。');

                return self::FAILURE;
            }

            $result = $this->runSheetsDemo($platform, $token);

            $this->info(sprintf('[%s] Docs 测试完成', $platformName));
            $this->line($this->toJson($result));

            return self::SUCCESS;
        } catch (GoogleApiException|TencentApiException $e) {
            $this->error('Docs API 调用失败');
            $this->line($this->toJson([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'response' => $e->getResponse(),
            ]));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param array{access_token: string, open_id?: string} $token
     * @return array<string, mixed>
     */
    private function runSheetsDemo(PlatformInterface $platform, array $token): array
    {
        $sheets = $platform->sheets();
        $titles = [self::DEFAULT_SHEET, 'Sheet2', 'Sheet3'];
        $sample = $this->sampleSheets();

        $file = $sheets->createSpreadsheet($token, $this->spreadsheetTitle());
        $spreadsheetId = (string) ($file['id'] ?? '');
        if ($spreadsheetId === '') {
            throw new InvalidArgumentException('创建表格失败：响应中缺少 id');
        }

        foreach ($titles as $title) {
            if ($title === self::DEFAULT_SHEET) {
                continue;
            }
            $sheets->addSheet($token, $spreadsheetId, $title);
        }

        $batch = [];
        foreach ($sample as $title => $values) {
            $columns = max(1, ...array_map('count', $values));
            $rows = count($values);
            $batch[] = [
                'range' => sprintf('%s!A1:%s%d', $title, $this->columnName($columns), $rows),
                'values' => $values,
            ];
        }
        $sheets->batchWrite($token, $spreadsheetId, $batch);

        $folder = $sheets->moveSpreadsheetToDateFolder(
            $token,
            $spreadsheetId,
            $this->driveRootFolder($platform->name())
        );
        $permission = $sheets->shareSpreadsheetForAnyoneReader($token, $spreadsheetId);
        $spreadsheetUrl = (string) ($file['url'] ?? '');

        return [
            'platform' => $platform->name(),
            'spreadsheet_id' => $spreadsheetId,
            'sheet_titles' => array_keys($sample),
            'folder' => $folder,
            'spreadsheet_url' => $spreadsheetUrl,
            'share_permission' => $permission,
            'raw' => $file['raw'] ?? null,
        ];
    }

    /**
     * @return array<string, list<list<string>>>
     */
    private function sampleSheets(): array
    {
        $header = ['姓名', '部门', '职位', '入职日期'];

        return [
            self::DEFAULT_SHEET => [
                $header,
                ['张三', '技术部', '高级工程师', '2024-01-15'],
                ['李四', '产品部', '产品经理', '2024-02-20'],
                ['王五', '设计部', 'UI设计师', '2024-03-10'],
            ],
            'Sheet2' => [
                $header,
                ['赵六', '运营部', '运营专员', '2024-04-01'],
                ['钱七', '市场部', '市场经理', '2024-04-18'],
            ],
            'Sheet3' => [
                $header,
                ['孙八', '客服部', '客服主管', '2024-05-06'],
                ['周九', '财务部', '会计', '2024-05-22'],
            ],
        ];
    }

    private function platformName(): string
    {
        $platform = trim((string) ($this->input->getOption('platform') ?: ''));

        return $platform !== '' ? $platform : $this->docs->getDefaultPlatform();
    }

    /**
     * @return array{access_token: string, open_id?: string}
     */
    private function tokenPayload(string $platformName): array
    {
        $token = trim((string) ($this->input->getOption('token') ?: ''));
        if ($token === '') {
            $token = $platformName === TencentPlatform::NAME
                ? trim((string) env('TENCENT_DOCS_TOKEN', ''))
                : trim((string) env('GOOGLE_TOKEN', ''));
        }

        $payload = ['access_token' => $token];

        if ($platformName === TencentPlatform::NAME) {
            $openId = trim((string) ($this->input->getOption('open-id') ?: env('TENCENT_DOCS_OPEN_ID', '')));
            $payload['open_id'] = $openId;
        }

        return $payload;
    }

    private function spreadsheetTitle(): string
    {
        $title = trim((string) ($this->input->getOption('title') ?: self::DEFAULT_SPREADSHEET_TITLE));

        return $title === '' ? self::DEFAULT_SPREADSHEET_TITLE : $title;
    }

    private function driveRootFolder(string $platformName): string
    {
        $folder = trim((string) ($this->input->getOption('folder') ?: ''));
        if ($folder !== '') {
            return $folder;
        }

        $configKey = $platformName === TencentPlatform::NAME
            ? 'docs.platforms.tencent.drive_root_folder'
            : 'docs.platforms.google.drive_root_folder';

        $folder = trim((string) $this->config->get($configKey, self::DEFAULT_DRIVE_ROOT_FOLDER));

        return $folder === '' ? self::DEFAULT_DRIVE_ROOT_FOLDER : $folder;
    }

    private function columnName(int $columnNumber): string
    {
        $columnName = '';
        while ($columnNumber > 0) {
            $remainder = ($columnNumber - 1) % 26;
            $columnName = chr(65 + $remainder) . $columnName;
            $columnNumber = intdiv($columnNumber - 1, 26);
        }

        return $columnName;
    }

    private function toJson(mixed $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';
    }
}
