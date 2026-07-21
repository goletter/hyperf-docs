<?php

declare(strict_types=1);

namespace Goletter\Docs\Command;

use Goletter\Docs\Google\Exceptions\GoogleApiException;
use Goletter\Docs\Google\GoogleAuth;
use Goletter\Docs\Google\GoogleSheets;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Di\Annotation\Inject;
use Symfony\Component\Console\Input\InputOption;

use function Hyperf\Support\env;

#[Command]
class TestCommand extends HyperfCommand
{
    private const COMMAND_NAME = 'docs:test';

    private const DEFAULT_SPREADSHEET_TITLE = 'Goletter Docs 测试表格';

    private const DEFAULT_SHEET = 'Sheet1';

    private const DEFAULT_DRIVE_ROOT_FOLDER = 'Goletter';

    #[Inject]
    protected GoogleAuth $auth;

    #[Inject]
    protected GoogleSheets $sheets;

    public function __construct()
    {
        parent::__construct(self::COMMAND_NAME);
    }

    public function configure(): void
    {
        parent::configure();

        $this
            ->setDescription('测试 Google OAuth、Sheets、Drive 集成')
            ->addOption('auth-url', null, InputOption::VALUE_NONE, '只输出 Google OAuth 授权地址')
            ->addOption('token', null, InputOption::VALUE_OPTIONAL, 'Google OAuth access token')
            ->addOption('title', null, InputOption::VALUE_OPTIONAL, '测试表格标题')
            ->addOption('folder', null, InputOption::VALUE_OPTIONAL, 'Drive 根目录名称');
    }

    public function handle(): int
    {
        try {
            if ((bool) $this->input->getOption('auth-url')) {
                $this->line($this->auth->getAuthUrl());

                return self::SUCCESS;
            }

            $accessToken = $this->accessToken();
            if ($accessToken === '') {
                $this->error('缺少 Google access token，请通过 --token 或 GOOGLE_TOKEN 传入。');
                $this->line('授权地址：' . $this->auth->getAuthUrl());

                return self::FAILURE;
            }

            $result = $this->runSheetsDemo($accessToken);

            $this->info('Google Docs 测试完成');
            $this->line($this->toJson($result));

            return self::SUCCESS;
        } catch (GoogleApiException $e) {
            $this->error('Google API 调用失败');
            $this->line($this->toJson([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'response' => $e->getResponse(),
            ]));

            return self::FAILURE;
        }
    }

    /**
     * @return array{
     *     spreadsheet_id: string,
     *     sheet_titles: list<string>,
     *     sheet_gids: array<string, int>,
     *     folder: array,
     *     spreadsheet_url: string,
     *     share_url: string,
     *     permission: string,
     *     share_permission: object|array
     * }
     */
    private function runSheetsDemo(string $accessToken): array
    {
        $sheetGids = $this->sheetGids();
        $sheets = $this->sampleSheets($sheetGids);
        $spreadsheet = $this->sheets->createSpreadsheet($accessToken, $this->spreadsheetTitle());
        $spreadsheetId = $spreadsheet->getSpreadsheetId();

        if (! is_string($spreadsheetId) || $spreadsheetId === '') {
            throw new GoogleApiException('创建电子表格失败：响应中缺少 spreadsheetId', 500, [
                'spreadsheet' => $spreadsheet->toSimpleObject(),
            ]);
        }

        $this->ensureSheets($accessToken, $spreadsheetId, $sheetGids);
        $this->sheets->batchWrite($accessToken, $spreadsheetId, $this->buildBatchData($sheets));

        $folder = $this->sheets->moveSpreadsheetToDateFolder(
            $accessToken,
            $spreadsheetId,
            $this->driveRootFolder()
        );
        $permission = $this->sheets->shareSpreadsheetForAnyoneReader($accessToken, $spreadsheetId);
        $spreadsheetUrl = $this->spreadsheetUrl($spreadsheetId, $spreadsheet->getSpreadsheetUrl());

        return [
            'spreadsheet_id' => $spreadsheetId,
            'sheet_titles' => array_keys($sheets),
            'sheet_gids' => $sheetGids,
            'folder' => $folder,
            'spreadsheet_url' => $spreadsheetUrl,
            'share_url' => "{$spreadsheetUrl}?usp=sharing",
            'permission' => 'anyone_with_link_reader',
            'share_permission' => $permission->toSimpleObject(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function sheetGids(): array
    {
        return [
            self::DEFAULT_SHEET => 0,
            'Sheet2' => 1001,
            'Sheet3' => 1002,
        ];
    }

    /**
     * @param array<string, int> $sheetGids
     * @return array<string, list<list<string>>>
     */
    private function sampleSheets(array $sheetGids): array
    {
        $header = ['姓名', '部门', '职位', '入职日期'];

        return [
            self::DEFAULT_SHEET => [
                $header,
                [
                    '张三',
                    '技术部',
                    '高级工程师',
                    $this->sheetHyperlink($sheetGids['Sheet2'], '2024-01-15'),
                ],
                [
                    '李四',
                    '产品部',
                    $this->sheetHyperlink($sheetGids['Sheet3'], '产品经理'),
                    '2024-02-20',
                ],
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

    /**
     * @param array<string, int> $sheetGids
     */
    private function ensureSheets(string $accessToken, string $spreadsheetId, array $sheetGids): void
    {
        foreach ($sheetGids as $title => $gid) {
            if ($title === self::DEFAULT_SHEET) {
                continue;
            }

            $this->sheets->addSheet($accessToken, $spreadsheetId, $title, $gid);
        }
    }

    /**
     * @param array<string, list<list<string>>> $sheets
     * @return list<array{range: string, values: list<list<string>>}>
     */
    private function buildBatchData(array $sheets): array
    {
        $batchData = [];
        foreach ($sheets as $title => $values) {
            $columns = max(array_map('count', $values));
            $rows = count($values);
            $batchData[] = [
                'range' => sprintf('%s!A1:%s%d', $title, $this->columnName($columns), $rows),
                'values' => $values,
            ];
        }

        return $batchData;
    }

    private function accessToken(): string
    {
        $token = (string) ($this->input->getOption('token') ?: env('GOOGLE_TOKEN', ''));

        return trim($token);
    }

    private function spreadsheetTitle(): string
    {
        $title = trim((string) ($this->input->getOption('title') ?: self::DEFAULT_SPREADSHEET_TITLE));

        return $title === '' ? self::DEFAULT_SPREADSHEET_TITLE : $title;
    }

    private function driveRootFolder(): string
    {
        $folder = trim((string) (
            $this->input->getOption('folder')
            ?: env('GOOGLE_DRIVE_ROOT_FOLDER_NAME', self::DEFAULT_DRIVE_ROOT_FOLDER)
        ));

        return $folder === '' ? self::DEFAULT_DRIVE_ROOT_FOLDER : $folder;
    }

    private function spreadsheetUrl(string $spreadsheetId, ?string $spreadsheetUrl): string
    {
        return $spreadsheetUrl ?: "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/edit";
    }

    private function sheetHyperlink(int $gid, string $label): string
    {
        return sprintf('=HYPERLINK("#gid=%d&range=A1", "%s")', $gid, $label);
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
