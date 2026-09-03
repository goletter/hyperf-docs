<?php

declare(strict_types=1);

namespace Goletter\Docs\Google;

use Google\Model as GoogleModel;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Google\Service\Sheets;
use Google\Service\Sheets\AddSheetRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\DeleteDimensionRequest;
use Google\Service\Sheets\DimensionRange;
use Google\Service\Sheets\Request;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\ValueRange;
use Hyperf\Contract\ConfigInterface;

class GoogleSheets
{
    private const DEFAULT_SHEET_TITLE = 'Sheet1';

    private const DEFAULT_ROOT_FOLDER = 'Goletter';

    private const DRIVE_FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

    private const VALUE_INPUT_OPTION = 'USER_ENTERED';

    public function __construct(
        protected GoogleClient $googleClient,
        protected ConfigInterface $config,
    ) {
    }

    protected function getSheetsService(string $accessToken): Sheets
    {
        return new Sheets($this->googleClient->createWithAccessToken($accessToken));
    }

    protected function getDriveService(string $accessToken): Drive
    {
        return new Drive($this->googleClient->createWithAccessToken($accessToken));
    }

    /**
     * 创建新的电子表格.
     */
    public function createSpreadsheet(string $accessToken, string $title): Spreadsheet
    {
        return $this->googleClient->request(function () use ($accessToken, $title) {
            return $this->getSheetsService($accessToken)
                ->spreadsheets
                ->create($this->buildSpreadsheet($title));
        });
    }

    /**
     * 读取单元格数据（只返回有内容的行；行尾空单元格会裁掉）.
     *
     * $range 支持：
     * - Sheet1!A1:Z1000
     * - '含空格标题'!A1:Z1000
     * - gid:123456 / gid:123456!A1:Z1000（仅 gid 时读整表 A:Z）
     *
     * @param int $minNonEmpty 每行至少几个非空单元格才保留（默认 1，可调）
     * @return list<list<mixed>>
     */
    public function readCells(
        string $accessToken,
        string $spreadsheetId,
        string $range = 'Sheet1!A1:Z1000',
        int $minNonEmpty = 1,
    ): array {
        return $this->googleClient->request(function () use ($accessToken, $spreadsheetId, $range, $minNonEmpty) {
            $resolved = $this->resolveRange($accessToken, $spreadsheetId, $range, true);
            $response = $this->getSheetsService($accessToken)
                ->spreadsheets_values
                ->get($spreadsheetId, $resolved);

            return $this->filterNonEmptyRows($response->getValues() ?? [], $minNonEmpty);
        });
    }

    /**
     * 按列内容查找行.
     *
     * @param string|int $column 列字母 F 或 0-based 下标（A=0）
     * @return list<array{row: int, range: string, values: list<mixed>}>
     */
    public function findRows(
        string $accessToken,
        string $spreadsheetId,
        string $range,
        string|int $column,
        mixed $value,
    ): array {
        return $this->googleClient->request(function () use ($accessToken, $spreadsheetId, $range, $column, $value) {
            $resolved = $this->resolveRange($accessToken, $spreadsheetId, $range, true);
            $response = $this->getSheetsService($accessToken)
                ->spreadsheets_values
                ->get($spreadsheetId, $resolved);
            $rows = $response->getValues() ?? [];
            $colIndex = $this->toZeroBasedColumn($column);
            $startRow = $this->resolveReadStartRow($resolved);
            $sheetPrefix = $this->sheetPrefixFromRange($resolved);
            $matches = [];

            foreach ($rows as $offset => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $cell = $row[$colIndex] ?? null;
                if (! $this->cellEquals($cell, $value)) {
                    continue;
                }
                if (! $this->rowHasData($row, 1)) {
                    continue;
                }

                $rowNumber = $startRow + (int) $offset;
                $values = $this->trimTrailingEmptyCells(array_values($row));
                $matches[] = [
                    'row' => $rowNumber,
                    'range' => $sheetPrefix . 'A' . $rowNumber . ':Z' . $rowNumber,
                    'values' => $values,
                ];
            }

            return $matches;
        });
    }

    /**
     * 写入数据到单元格.
     *
     * $range 同样支持 gid:123456!A1 形式.
     *
     * 注意受保护区域 / 勾选框：
     * - null：跳过该格（不进请求 range）
     * - ''：清空文本格；勾选框列请用 true/false，不要用 ''
     * - true/false：写入勾选框（已勾选 / 未勾选）
     *
     * @param list<list<null|bool|scalar>> $values
     */
    public function writeCells(
        string $accessToken,
        string $spreadsheetId,
        string $range,
        array $values
    ): void {
        $this->googleClient->request(function () use ($accessToken, $spreadsheetId, $range, $values) {
            $resolved = $this->resolveRange($accessToken, $spreadsheetId, $range);
            $chunks = $this->expandSparseWrites($resolved, $values);
            if ($chunks === []) {
                return;
            }

            $this->applyValueChunks($accessToken, $spreadsheetId, $chunks);
        });
    }

    /**
     * 编辑指定行，并读回更新后的行数据.
     *
     * @param list<null|bool|scalar>|list<list<null|bool|scalar>> $values
     * @return null|array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     */
    public function updateRow(
        string $accessToken,
        string $spreadsheetId,
        string $range,
        array $values,
        ?int $row = null,
        string|int|null $column = null,
        mixed $match = null,
    ): ?array {
        return $this->googleClient->request(function () use ($accessToken, $spreadsheetId, $range, $values, $row, $column, $match) {
            $values = $this->normalizeRows($values);
            if ($values === []) {
                return null;
            }

            $targetRow = $row;
            if ($targetRow === null) {
                if ($column === null) {
                    throw new \InvalidArgumentException('updateRow requires $row or $column+$match');
                }
                $hits = $this->findRows($accessToken, $spreadsheetId, $range, $column, $match);
                if ($hits === []) {
                    return null;
                }
                $targetRow = (int) $hits[0]['row'];
            }

            if ($targetRow < 1) {
                throw new \InvalidArgumentException('updateRow $row must be >= 1');
            }

            $resolved = $this->resolveRange($accessToken, $spreadsheetId, $range, true);
            $sheetPrefix = $this->sheetPrefixFromRange($resolved);
            $rowCount = count($values);
            $writeRange = $sheetPrefix . 'A' . $targetRow;
            $chunks = $this->expandSparseWrites($writeRange, $values);
            if ($chunks !== []) {
                $this->applyValueChunks($accessToken, $spreadsheetId, $chunks);
            }

            $endRow = $targetRow + $rowCount - 1;
            $readRange = $sheetPrefix . 'A' . $targetRow . ':Z' . $endRow;
            $read = $this->getSheetsService($accessToken)
                ->spreadsheets_values
                ->get($spreadsheetId, $readRange);
            $readValues = $this->filterNonEmptyRows($read->getValues() ?? []);
            if ($readValues === []) {
                // 本行可能只写了稀疏列，仍读原始行
                $raw = $read->getValues() ?? [];
                $readValues = array_map(
                    fn ($row) => is_array($row) ? $this->trimTrailingEmptyCells(array_values($row)) : [],
                    $raw
                );
            }

            return [
                'row' => $targetRow,
                'range' => $readRange,
                'values' => $this->unwrapSingleRowValues($readValues, $rowCount),
            ];
        });
    }

    /**
     * 删除指定行.
     *
     * @return null|array{row: int}
     */
    public function deleteRow(
        string $accessToken,
        string $spreadsheetId,
        string $range,
        ?int $row = null,
        string|int|null $column = null,
        mixed $match = null,
    ): ?array {
        return $this->googleClient->request(function () use ($accessToken, $spreadsheetId, $range, $row, $column, $match) {
            $targetRow = $row;
            if ($targetRow === null) {
                if ($column === null) {
                    throw new \InvalidArgumentException('deleteRow requires $row or $column+$match');
                }
                $hits = $this->findRows($accessToken, $spreadsheetId, $range, $column, $match);
                if ($hits === []) {
                    return null;
                }
                $targetRow = (int) $hits[0]['row'];
            }

            if ($targetRow < 1) {
                throw new \InvalidArgumentException('deleteRow $row must be >= 1');
            }

            $sheetId = $this->resolveNumericSheetId($accessToken, $spreadsheetId, $range);
            $startIndex = $targetRow - 1; // API 0-based

            $body = new BatchUpdateSpreadsheetRequest([
                'requests' => [
                    new Request([
                        'deleteDimension' => new DeleteDimensionRequest([
                            'range' => new DimensionRange([
                                'sheetId' => $sheetId,
                                'dimension' => 'ROWS',
                                'startIndex' => $startIndex,
                                'endIndex' => $startIndex + 1,
                            ]),
                        ]),
                    ]),
                ],
            ]);

            $this->getSheetsService($accessToken)->spreadsheets->batchUpdate($spreadsheetId, $body);

            return ['row' => $targetRow];
        });
    }

    /**
     * 在表格已有内容后面追加行，并读回追加后的行数据.
     *
     * $range 可为整表：gid:123 / Sheet1，或指定列带：gid:123!A:F
     * 含 null 时会落到「下一空行」并只写有值的列（避开受保护列）.
     *
     * $values 支持：
     * - 单行：[null, null, '名称', 'id', true]
     * - 多行：[[...], [...]]
     *
     * @param list<null|bool|scalar>|list<list<null|bool|scalar>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     */
    public function appendCells(
        string $accessToken,
        string $spreadsheetId,
        string $range,
        array $values
    ): array {
        return $this->googleClient->request(function () use ($accessToken, $spreadsheetId, $range, $values) {
            $values = $this->normalizeRows($values);
            if ($values === []) {
                return [
                    'row' => 0,
                    'range' => '',
                    'values' => [],
                ];
            }

            $appendRange = $this->resolveAppendRange($accessToken, $spreadsheetId, $range);
            $sheetPrefix = $this->sheetPrefixFromRange($appendRange);
            $hasNull = $this->valuesContainNull($values);
            $rowCount = count($values);
            $startRow = 0;

            if ($hasNull) {
                $startRow = $this->nextEmptyRow($accessToken, $spreadsheetId, $appendRange);
                $chunks = $this->expandSparseWrites($sheetPrefix . 'A' . $startRow, $values);
                if ($chunks !== []) {
                    $this->applyValueChunks($accessToken, $spreadsheetId, $chunks);
                }
            } else {
                $startRow = $this->nextEmptyRow($accessToken, $spreadsheetId, $appendRange);
                $body = new ValueRange([
                    'values' => $this->encodeSheetValues($values),
                ]);

                $response = $this->getSheetsService($accessToken)->spreadsheets_values->append(
                    $spreadsheetId,
                    $appendRange,
                    $body,
                    [
                        'valueInputOption' => self::VALUE_INPUT_OPTION,
                        'insertDataOption' => 'INSERT_ROWS',
                        'includeValuesInResponse' => true,
                    ]
                );

                $updatedRange = (string) ($response->getUpdates()?->getUpdatedRange() ?? '');
                if ($updatedRange !== '' && preg_match('/![A-Za-z]+(\d+)/', $updatedRange, $m) === 1) {
                    $startRow = (int) $m[1];
                }

                $updatedValues = $response->getUpdates()?->getUpdatedData()?->getValues();
                if (is_array($updatedValues) && $updatedValues !== []) {
                    $endRow = $startRow + count($updatedValues) - 1;
                    $readRange = $sheetPrefix . 'A' . $startRow . ':Z' . $endRow;

                    return [
                        'row' => $startRow,
                        'range' => $readRange,
                        'values' => $this->unwrapSingleRowValues($updatedValues, $rowCount),
                    ];
                }
            }

            $endRow = $startRow + max(1, $rowCount) - 1;
            $readRange = $sheetPrefix . 'A' . $startRow . ':Z' . $endRow;
            $read = $this->getSheetsService($accessToken)
                ->spreadsheets_values
                ->get($spreadsheetId, $readRange);

            return [
                'row' => $startRow,
                'range' => $readRange,
                'values' => $this->unwrapSingleRowValues($read->getValues() ?? [], $rowCount),
            ];
        });
    }

    /**
     * 批量写入数据.
     *
     * @param array<int, array{range: string, values: array}> $data
     */
    public function batchWrite(
        string $accessToken,
        string $spreadsheetId,
        array $data
    ): void {
        $this->googleClient->request(function () use ($accessToken, $spreadsheetId, $data) {
            $chunks = [];
            foreach ($data as $item) {
                $resolved = $this->resolveRange($accessToken, $spreadsheetId, (string) $item['range']);
                foreach ($this->expandSparseWrites($resolved, $item['values'] ?? []) as $chunk) {
                    $chunks[] = $chunk;
                }
            }
            if ($chunks === []) {
                return;
            }

            $this->applyValueChunks($accessToken, $spreadsheetId, $chunks);
        });
    }

    /**
     * @param list<array{range: string, values: list<list<mixed>>}> $chunks
     */
    private function applyValueChunks(string $accessToken, string $spreadsheetId, array $chunks): void
    {
        if (count($chunks) === 1) {
            $chunk = $chunks[0];
            $body = new ValueRange([
                'values' => $this->encodeSheetValues($chunk['values']),
            ]);
            $this->getSheetsService($accessToken)->spreadsheets_values->update(
                $spreadsheetId,
                $chunk['range'],
                $body,
                ['valueInputOption' => self::VALUE_INPUT_OPTION]
            );

            return;
        }

        $batchData = [];
        foreach ($chunks as $chunk) {
            $batchData[] = new ValueRange([
                'range' => $chunk['range'],
                'values' => $this->encodeSheetValues($chunk['values']),
            ]);
        }

        $body = new BatchUpdateValuesRequest([
            'valueInputOption' => self::VALUE_INPUT_OPTION,
            'data' => $batchData,
        ]);

        $this->getSheetsService($accessToken)->spreadsheets_values->batchUpdate($spreadsheetId, $body);
    }

    /**
     * 把含 null 的矩阵拆成「只含实际要写的格子」的多个 range，避免请求覆盖受保护列.
     *
     * @param list<list<null|scalar>> $values
     * @return list<array{range: string, values: list<list<mixed>>}>
     */
    private function expandSparseWrites(string $resolvedRange, array $values): array
    {
        if ($values === []) {
            return [];
        }

        $hasNull = false;
        foreach ($values as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($row as $cell) {
                if ($cell === null) {
                    $hasNull = true;
                    break 2;
                }
            }
        }

        if (! $hasNull) {
            return [[
                'range' => $resolvedRange,
                'values' => array_map(static fn ($row) => array_values(is_array($row) ? $row : [$row]), $values),
            ]];
        }

        [$sheetPrefix, $startCol, $startRow] = $this->parseRangeOrigin($resolvedRange);
        $chunks = [];

        foreach (array_values($values) as $rowOffset => $row) {
            if (! is_array($row)) {
                continue;
            }

            $run = [];
            $runStartCol = null;
            $rowIndex = $startRow + (int) $rowOffset;

            foreach (array_values($row) as $colOffset => $cell) {
                $colIndex = $startCol + (int) $colOffset;
                if ($cell === null) {
                    if ($run !== []) {
                        $chunks[] = $this->makeWriteChunk($sheetPrefix, $runStartCol, $rowIndex, $run);
                        $run = [];
                        $runStartCol = null;
                    }
                    continue;
                }

                if ($run === []) {
                    $runStartCol = $colIndex;
                }
                $run[] = $cell;
            }

            if ($run !== []) {
                $chunks[] = $this->makeWriteChunk($sheetPrefix, $runStartCol, $rowIndex, $run);
            }
        }

        return $chunks;
    }

    /**
     * @param list<mixed> $cells
     * @return array{range: string, values: list<list<mixed>>}
     */
    private function makeWriteChunk(string $sheetPrefix, int $startCol, int $row, array $cells): array
    {
        $startA1 = $this->columnName($startCol) . $row;
        $endA1 = $this->columnName($startCol + count($cells) - 1) . $row;
        $a1 = $startA1 === $endA1 ? $startA1 : "{$startA1}:{$endA1}";

        return [
            'range' => $sheetPrefix . $a1,
            'values' => [$cells],
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: int} [sheetPrefix incl trailing !, startCol 1-based, startRow 1-based]
     */
    private function parseRangeOrigin(string $range): array
    {
        $sheetPrefix = '';
        $a1 = $range;

        if (preg_match("/^('[^']+'|[^!]+)!([A-Za-z]+\d+)/", $range, $matches) === 1) {
            $sheetPrefix = $matches[1] . '!';
            $a1 = $matches[2];
        } elseif (preg_match('/^([A-Za-z]+\d+)/', $range, $matches) === 1) {
            $a1 = $matches[1];
        } else {
            return ['', 1, 1];
        }

        if (preg_match('/^([A-Za-z]+)(\d+)$/', $a1, $matches) !== 1) {
            return [$sheetPrefix, 1, 1];
        }

        return [$sheetPrefix, $this->columnIndex($matches[1]), (int) $matches[2]];
    }

    private function columnIndex(string $column): int
    {
        $column = strtoupper($column);
        $index = 0;
        $length = strlen($column);
        for ($i = 0; $i < $length; ++$i) {
            $index = $index * 26 + (ord($column[$i]) - 64);
        }

        return $index;
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

    /**
     * 单行 [a, b] 或多行 [[a], [b]] 统一成二维行矩阵.
     *
     * @param list<mixed>|list<list<mixed>> $values
     * @return list<list<mixed>>
     */
    private function normalizeRows(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $first = reset($values);
        if (! is_array($first)) {
            return [array_values($values)];
        }

        $rows = [];
        foreach ($values as $row) {
            $rows[] = is_array($row) ? array_values($row) : [$row];
        }

        return $rows;
    }

    /**
     * 单行追加时 values 返回一维；多行仍返回二维.
     *
     * @param list<list<mixed>> $rows
     * @return list<mixed>|list<list<mixed>>
     */
    private function unwrapSingleRowValues(array $rows, int $rowCount): array
    {
        if ($rowCount === 1) {
            $first = $rows[0] ?? [];

            return is_array($first) ? array_values($first) : [];
        }

        return array_values($rows);
    }

    /**
     * Google PHP Client 会丢掉 PHP null；稀疏拆分后一般不再含 null。
     * 仍保留 NULL_VALUE 编码，防止漏网.
     *
     * @param list<list<null|scalar>> $values
     * @return list<list<mixed>>
     */
    private function encodeSheetValues(array $values): array
    {
        $encoded = [];
        foreach ($values as $row) {
            if (! is_array($row)) {
                $encoded[] = $row === null ? GoogleModel::NULL_VALUE : $row;
                continue;
            }

            $line = [];
            foreach (array_values($row) as $cell) {
                $line[] = $cell === null ? GoogleModel::NULL_VALUE : $cell;
            }
            $encoded[] = $line;
        }

        return $encoded;
    }

    /**
     * 按 URL 中的 gid（Sheets API 的 sheetId）查工作表标题.
     *
     * @throws \InvalidArgumentException
     */
    public function getSheetTitleByGid(string $accessToken, string $spreadsheetId, int|string $gid): string
    {
        return $this->googleClient->request(function () use ($accessToken, $spreadsheetId, $gid) {
            $target = (int) $gid;
            $spreadsheet = $this->getSheetsService($accessToken)->spreadsheets->get($spreadsheetId, [
                'fields' => 'sheets.properties(sheetId,title)',
            ]);

            foreach ($spreadsheet->getSheets() ?? [] as $sheet) {
                $properties = $sheet->getProperties();
                if ($properties && (int) $properties->getSheetId() === $target) {
                    return (string) $properties->getTitle();
                }
            }

            throw new \InvalidArgumentException("Google Sheet gid not found: {$target}");
        });
    }

    /**
     * 用 gid 拼 A1 range，例如 gid=0 + A1:Z100 → 'Sheet1'!A1:Z100.
     */
    public function rangeForGid(
        string $accessToken,
        string $spreadsheetId,
        int|string $gid,
        string $a1Range = 'A1:Z1000',
    ): string {
        $title = $this->getSheetTitleByGid($accessToken, $spreadsheetId, $gid);

        return $this->quoteSheetTitle($title) . '!' . ltrim($a1Range, '!');
    }

    /**
     * 将表格设置为知道链接的人可读.
     */
    public function shareSpreadsheetForAnyoneReader(string $accessToken, string $spreadsheetId): Permission
    {
        return $this->googleClient->request(function () use ($accessToken, $spreadsheetId) {
            $permission = new Permission([
                'type' => 'anyone',
                'role' => 'reader',
                'allowFileDiscovery' => false,
            ]);

            return $this->getDriveService($accessToken)->permissions->create($spreadsheetId, $permission, [
                'sendNotificationEmail' => false,
                'supportsAllDrives' => true,
            ]);
        });
    }

    /**
     * 将表格移动到「根目录 / 日期」结构中.
     */
    public function moveSpreadsheetToDateFolder(
        string $accessToken,
        string $spreadsheetId,
        ?string $rootFolderName = null,
        ?string $date = null
    ): array {
        return $this->googleClient->request(function () use ($accessToken, $spreadsheetId, $rootFolderName, $date) {
            $service = $this->getDriveService($accessToken);
            $rootName = $rootFolderName
                ?: (string) $this->config->get('docs.platforms.google.drive_root_folder', self::DEFAULT_ROOT_FOLDER);
            $dateName = $date ?? date('Y-m-d');
            $rootFolder = $this->getOrCreateFolder($service, $rootName);
            $dateFolder = $this->getOrCreateFolder($service, $dateName, $rootFolder->getId());
            $dateFolderId = $dateFolder->getId();

            $this->moveFileToFolder($service, $spreadsheetId, $dateFolderId);

            return $this->formatArchiveFolder($rootName, $rootFolder->getId(), $dateName, $dateFolderId);
        });
    }

    /**
     * 将 gid:...!A1 解析为 '标题'!A1；普通 range 原样返回（并修正错误引号）.
     *
     * @param bool $fullSheetDefault 仅传 gid / 表名时，默认读 A:Z（整列）而非 A1:Z1000
     */
    private function resolveRange(
        string $accessToken,
        string $spreadsheetId,
        string $range,
        bool $fullSheetDefault = false,
    ): string {
        $range = trim($range);
        $defaultA1 = $fullSheetDefault ? 'A:Z' : 'A1:Z1000';
        if ($range === '') {
            return self::DEFAULT_SHEET_TITLE . '!' . $defaultA1;
        }

        if (preg_match('/^(?:gid[=:]|#gid=)(\d+)(?:!(.*))?$/i', $range, $matches)) {
            $a1 = isset($matches[2]) && trim($matches[2]) !== '' ? trim($matches[2]) : $defaultA1;

            return $this->rangeForGid($accessToken, $spreadsheetId, $matches[1], $a1);
        }

        // 纯表名（无 !）→ 补默认 A1
        if (! str_contains($range, '!')) {
            if (preg_match("/^'[^']+'$/", $range) === 1 || preg_match('/^[A-Za-z0-9_ \p{L}-]+$/u', $range) === 1) {
                return (str_starts_with($range, "'") ? $range : $this->quoteSheetTitle($range)) . '!' . $defaultA1;
            }
        }

        // 'Title!A1' 误写成整段加引号时，改为 'Title'!A1
        if (preg_match("/^'([^']+![^']+)'$/", $range, $matches)) {
            $inner = $matches[1];
            $pos = strrpos($inner, '!');
            if ($pos !== false) {
                $title = substr($inner, 0, $pos);
                $a1 = substr($inner, $pos + 1);

                return $this->quoteSheetTitle($title) . '!' . $a1;
            }
        }

        return $range;
    }

    private function resolveReadStartRow(string $resolvedRange): int
    {
        if (preg_match('/![A-Za-z]+(\d+)/', $resolvedRange, $matches) === 1) {
            return (int) $matches[1];
        }

        return 1;
    }

    /**
     * 解析 Google 子表 sheetId（即 URL 中的 gid）.
     */
    private function resolveNumericSheetId(string $accessToken, string $spreadsheetId, string $range): int
    {
        $range = trim($range);
        if (preg_match('/^(?:gid[=:]|#gid=)(\d+)/i', $range, $matches) === 1) {
            return (int) $matches[1];
        }

        $resolved = $this->resolveRange($accessToken, $spreadsheetId, $range, true);
        $title = null;
        if (preg_match("/^'([^']+)'!/", $resolved, $matches) === 1) {
            $title = $matches[1];
        } elseif (preg_match('/^([^!]+)!/', $resolved, $matches) === 1) {
            $title = $matches[1];
        }

        if ($title === null || $title === '') {
            throw new \InvalidArgumentException("Unable to resolve sheet title for range: {$range}");
        }

        $spreadsheet = $this->getSheetsService($accessToken)->spreadsheets->get($spreadsheetId, [
            'fields' => 'sheets.properties(sheetId,title)',
        ]);

        foreach ($spreadsheet->getSheets() ?? [] as $sheet) {
            $properties = $sheet->getProperties();
            if ($properties && (string) $properties->getTitle() === $title) {
                return (int) $properties->getSheetId();
            }
        }

        throw new \InvalidArgumentException("Unable to resolve sheetId for range: {$range}");
    }

    private function toZeroBasedColumn(string|int $column): int
    {
        if (is_int($column)) {
            return max(0, $column);
        }

        $column = trim($column);
        if ($column !== '' && ctype_digit($column)) {
            return max(0, (int) $column);
        }

        return max(0, $this->columnIndex($column) - 1);
    }

    private function cellEquals(mixed $cell, mixed $expected): bool
    {
        if (is_bool($expected)) {
            if (is_bool($cell)) {
                return $cell === $expected;
            }
            $normalized = strtoupper(trim((string) $cell));

            return $expected
                ? in_array($normalized, ['TRUE', '1', 'YES'], true)
                : in_array($normalized, ['FALSE', '0', 'NO', ''], true);
        }

        return (string) $cell === (string) $expected;
    }

    /**
     * @param list<list<mixed>> $rows
     * @return list<list<mixed>>
     */
    private function filterNonEmptyRows(array $rows, int $minNonEmpty = 1): array
    {
        $minNonEmpty = max(1, $minNonEmpty);
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! $this->rowHasData($row, $minNonEmpty)) {
                continue;
            }
            $result[] = $this->trimTrailingEmptyCells(array_values($row));
        }

        return $result;
    }

    /**
     * @param list<mixed> $row
     */
    private function rowHasData(array $row, int $minNonEmpty = 1): bool
    {
        $count = 0;
        foreach ($row as $cell) {
            if ($cell === null || $cell === '') {
                continue;
            }
            ++$count;
            if ($count >= $minNonEmpty) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<mixed> $row
     * @return list<mixed>
     */
    private function trimTrailingEmptyCells(array $row): array
    {
        while ($row !== []) {
            $last = $row[array_key_last($row)];
            if ($last !== null && $last !== '') {
                break;
            }
            array_pop($row);
        }

        return array_values($row);
    }

    /**
     * append 用的 range：gid / 表名 → 整表；gid!A:F → 带列带.
     */
    private function resolveAppendRange(string $accessToken, string $spreadsheetId, string $range): string
    {
        $range = trim($range);
        if ($range === '') {
            return self::DEFAULT_SHEET_TITLE;
        }

        if (preg_match('/^(?:gid[=:]|#gid=)(\d+)(?:!(.*))?$/i', $range, $matches)) {
            $title = $this->getSheetTitleByGid($accessToken, $spreadsheetId, $matches[1]);
            $quoted = $this->quoteSheetTitle($title);
            $a1 = isset($matches[2]) ? trim($matches[2]) : '';

            return $a1 !== '' ? $quoted . '!' . $a1 : $quoted;
        }

        if (preg_match("/^'([^']+![^']+)'$/", $range, $matches)) {
            $inner = $matches[1];
            $pos = strrpos($inner, '!');
            if ($pos !== false) {
                return $this->quoteSheetTitle(substr($inner, 0, $pos)) . '!' . substr($inner, $pos + 1);
            }
        }

        return $range;
    }

    private function sheetPrefixFromRange(string $range): string
    {
        if (preg_match("/^('[^']+'|[^!]+)(?:!.*)?$/", $range, $matches) === 1) {
            return $matches[1] . '!';
        }

        return '';
    }

    /**
     * @param list<list<null|scalar>> $values
     */
    private function valuesContainNull(array $values): bool
    {
        foreach ($values as $row) {
            if (! is_array($row)) {
                if ($row === null) {
                    return true;
                }
                continue;
            }
            foreach ($row as $cell) {
                if ($cell === null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 取表内下一空行（按 A 列已有数据行数 + 1）.
     */
    private function nextEmptyRow(string $accessToken, string $spreadsheetId, string $appendRange): int
    {
        $sheetPrefix = $this->sheetPrefixFromRange($appendRange);
        $probe = $sheetPrefix . 'A:A';

        $response = $this->getSheetsService($accessToken)
            ->spreadsheets_values
            ->get($spreadsheetId, $probe);

        $rows = $response->getValues() ?? [];

        return count($rows) + 1;
    }

    private function quoteSheetTitle(string $title): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $title) === 1) {
            return $title;
        }

        return "'" . str_replace("'", "''", $title) . "'";
    }

    /**
     * 添加新的工作表.
     */
    public function addSheet(
        string $accessToken,
        string $spreadsheetId,
        string $title,
        ?int $sheetId = null
    ): void {
        $this->googleClient->request(function () use ($accessToken, $spreadsheetId, $title, $sheetId) {
            $properties = ['title' => $title];
            if (is_int($sheetId)) {
                $properties['sheetId'] = $sheetId;
            }

            $body = new BatchUpdateSpreadsheetRequest([
                'requests' => [
                    new Request([
                        'addSheet' => new AddSheetRequest([
                            'properties' => $properties,
                        ]),
                    ]),
                ],
            ]);

            $this->getSheetsService($accessToken)->spreadsheets->batchUpdate($spreadsheetId, $body);
        });
    }

    private function getOrCreateFolder(Drive $service, string $folderName, ?string $parentId = null): DriveFile
    {
        $folder = $this->findFolder($service, $folderName, $parentId);
        if ($folder instanceof DriveFile) {
            return $folder;
        }

        $folderData = [
            'name' => $folderName,
            'mimeType' => self::DRIVE_FOLDER_MIME_TYPE,
        ];
        if (is_string($parentId) && $parentId !== '') {
            $folderData['parents'] = [$parentId];
        }

        return $service->files->create(new DriveFile($folderData), [
            'fields' => 'id, name',
            'supportsAllDrives' => true,
        ]);
    }

    private function findFolder(Drive $service, string $folderName, ?string $parentId = null): ?DriveFile
    {
        $query = sprintf(
            "mimeType='%s' and name='%s' and trashed=false",
            self::DRIVE_FOLDER_MIME_TYPE,
            $this->escapeDriveQueryValue($folderName)
        );
        if (is_string($parentId) && $parentId !== '') {
            $query .= sprintf(" and '%s' in parents", $this->escapeDriveQueryValue($parentId));
        }

        $response = $service->files->listFiles([
            'q' => $query,
            'fields' => 'files(id, name)',
            'pageSize' => 1,
            'supportsAllDrives' => true,
        ]);

        $folders = $response->getFiles() ?? [];

        return $folders[0] ?? null;
    }

    private function escapeDriveQueryValue(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function buildSpreadsheet(string $title): Spreadsheet
    {
        return new Spreadsheet([
            'properties' => ['title' => $title],
            'sheets' => [
                [
                    'properties' => [
                        'sheetId' => 0,
                        'title' => self::DEFAULT_SHEET_TITLE,
                    ],
                ],
            ],
        ]);
    }

    private function moveFileToFolder(Drive $service, string $fileId, string $folderId): void
    {
        $file = $service->files->get($fileId, [
            'fields' => 'parents',
            'supportsAllDrives' => true,
        ]);

        $service->files->update($fileId, new DriveFile(), [
            'addParents' => $folderId,
            'removeParents' => implode(',', $file->parents ?? []),
            'fields' => 'id, parents',
            'supportsAllDrives' => true,
        ]);
    }

    private function formatArchiveFolder(
        string $rootName,
        string $rootFolderId,
        string $dateName,
        string $dateFolderId
    ): array {
        return [
            'path' => "{$rootName}/{$dateName}",
            'root_folder' => $this->formatFolder($rootName, $rootFolderId),
            'date_folder' => $this->formatFolder($dateName, $dateFolderId),
        ];
    }

    private function formatFolder(string $name, string $id): array
    {
        return [
            'folder_id' => $id,
            'folder_name' => $name,
            'folder_url' => "https://drive.google.com/drive/folders/{$id}",
        ];
    }
}
