<?php

declare(strict_types=1);

namespace Goletter\Docs\Tencent;

use Goletter\Docs\Tencent\Exceptions\TencentApiException;
use Hyperf\Contract\ConfigInterface;

class TencentSheets
{
    private const DEFAULT_ROOT_FOLDER = 'Goletter';

    private const ROOT_FOLDER_ID = '/';

    private const DEFAULT_RANGE = 'A1:Z1000';

    public function __construct(
        protected TencentClient $client,
        protected ConfigInterface $config,
    ) {
    }

    /**
     * 创建在线表格（type=sheet）.
     *
     * @return array<string, mixed> 文档信息（含 ID、url、title 等）
     * @throws TencentApiException
     */
    public function createSpreadsheet(
        string $accessToken,
        string $openId,
        string $title,
        ?string $folderId = null,
    ): array {
        $form = [
            'title' => $title,
            'type' => 'sheet',
        ];
        if (is_string($folderId) && $folderId !== '') {
            $form['folderID'] = $folderId;
        }

        $response = $this->client->request(
            'POST',
            '/openapi/drive/v2/files',
            $accessToken,
            $openId,
            form: $form,
        );

        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    /**
     * 读取单元格（spreadsheet v3 get_range）.
     *
     * $range 支持：
     * - A1:Z1000
     * - 工作表标题!A1:Z1000
     * - sheetId!A1:Z1000
     *
     * @param int $minNonEmpty 每行至少几个非空单元格才保留（默认 1，可调）
     * @return list<list<mixed>>
     * @throws TencentApiException
     */
    public function readCells(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range = self::DEFAULT_RANGE,
        int $minNonEmpty = 1,
    ): array {
        [$sheetRef, $a1Range] = $this->parseRange($range);
        $sheetId = $this->resolveSheetId($accessToken, $openId, $spreadsheetId, $sheetRef);

        $uri = sprintf(
            '/openapi/spreadsheet/v3/files/%s/%s/%s',
            rawurlencode($spreadsheetId),
            rawurlencode($sheetId),
            rawurlencode($a1Range)
        );

        $response = $this->client->request('GET', $uri, $accessToken, $openId);
        $gridData = $response['data']['gridData']
            ?? $response['gridData']
            ?? [];

        $rows = $this->flattenGridData(is_array($gridData) ? $gridData : []);

        return $this->filterNonEmptyRows($rows, $minNonEmpty);
    }

    /**
     * 按列内容查找行.
     *
     * @param string|int $column 列字母 F 或 0-based 下标（A=0）
     * @return list<array{row: int, range: string, values: list<mixed>}>
     * @throws TencentApiException
     */
    public function findRows(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range,
        string|int $column,
        mixed $value,
    ): array {
        [$sheetRef] = $this->parseRange($range);
        $probe = ($sheetRef !== null && $sheetRef !== '' ? $sheetRef . '!' : '') . 'A1:Z10000';
        $rows = $this->readCells($accessToken, $openId, $spreadsheetId, $probe);
        $colIndex = $this->toZeroBasedColumn($column);
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

            $rowNumber = (int) $offset + 1;
            $values = $this->trimTrailingEmptyCells(array_values($row));
            $matches[] = [
                'row' => $rowNumber,
                'range' => ($sheetRef !== null && $sheetRef !== '' ? $sheetRef . '!' : '') . 'A' . $rowNumber . ':Z' . $rowNumber,
                'values' => $values,
            ];
        }

        return $matches;
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

        $column = strtoupper($column);
        $index = 0;
        $length = strlen($column);
        for ($i = 0; $i < $length; ++$i) {
            $index = $index * 26 + (ord($column[$i]) - 64);
        }

        return max(0, $index - 1);
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
     * @param list<mixed> $row
     */
    private function rowHasData(array $row, int $minNonEmpty = 1): bool
    {
        $minNonEmpty = max(1, $minNonEmpty);
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
     * 查询工作表元数据（spreadsheet v3）.
     *
     * @return list<array<string, mixed>>
     * @throws TencentApiException
     */
    public function listSheets(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        bool $concise = true,
    ): array {
        $uri = sprintf('/openapi/spreadsheet/v3/files/%s', rawurlencode($spreadsheetId));
        $response = $this->client->request(
            'GET',
            $uri,
            $accessToken,
            $openId,
            query: ['concise' => $concise ? 1 : 0],
        );

        $properties = $response['data']['properties']
            ?? $response['properties']
            ?? [];

        return is_array($properties) ? array_values(array_filter($properties, 'is_array')) : [];
    }

    /**
     * 写入单元格（PUT values）.
     *
     * @param list<list<mixed>> $values
     * @throws TencentApiException
     */
    public function writeCells(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range,
        array $values,
    ): array {
        $uri = sprintf(
            '/openapi/sheetbook/v2/%s/values/%s',
            rawurlencode($spreadsheetId),
            rawurlencode($range)
        );

        return $this->client->request(
            'PUT',
            $uri,
            $accessToken,
            $openId,
            json: ['values' => $values],
        );
    }

    /**
     * 编辑指定行，并读回更新后的行数据.
     *
     * @param list<mixed>|list<list<mixed>> $values
     * @return null|array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws TencentApiException
     */
    public function updateRow(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range,
        array $values,
        ?int $row = null,
        string|int|null $column = null,
        mixed $match = null,
    ): ?array {
        $values = $this->normalizeRows($values);
        if ($values === []) {
            return null;
        }

        $targetRow = $row;
        if ($targetRow === null) {
            if ($column === null) {
                throw new \InvalidArgumentException('updateRow requires $row or $column+$match');
            }
            $hits = $this->findRows($accessToken, $openId, $spreadsheetId, $range, $column, $match);
            if ($hits === []) {
                return null;
            }
            $targetRow = (int) $hits[0]['row'];
        }

        if ($targetRow < 1) {
            throw new \InvalidArgumentException('updateRow $row must be >= 1');
        }

        [$sheetRef] = $this->parseRange($range);
        $sheetPrefix = ($sheetRef !== null && $sheetRef !== '') ? $sheetRef . '!' : '';
        $rowCount = count($values);
        $endRow = $targetRow + $rowCount - 1;
        $writeRange = $sheetPrefix . 'A' . $targetRow;
        $this->writeCells($accessToken, $openId, $spreadsheetId, $writeRange, $values);

        $readRange = $sheetPrefix . 'A' . $targetRow . ':Z' . $endRow;
        $readValues = $this->readCells($accessToken, $openId, $spreadsheetId, $readRange);

        return [
            'row' => $targetRow,
            'range' => $readRange,
            'values' => $this->unwrapSingleRowValues($readValues, $rowCount),
        ];
    }

    /**
     * 删除指定行（v3 deleteDimensionRequest，行号从 1 起）.
     *
     * @return null|array{row: int}
     * @throws TencentApiException
     */
    public function deleteRow(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range,
        ?int $row = null,
        string|int|null $column = null,
        mixed $match = null,
    ): ?array {
        $targetRow = $row;
        if ($targetRow === null) {
            if ($column === null) {
                throw new \InvalidArgumentException('deleteRow requires $row or $column+$match');
            }
            $hits = $this->findRows($accessToken, $openId, $spreadsheetId, $range, $column, $match);
            if ($hits === []) {
                return null;
            }
            $targetRow = (int) $hits[0]['row'];
        }

        if ($targetRow < 1) {
            throw new \InvalidArgumentException('deleteRow $row must be >= 1');
        }

        [$sheetRef] = $this->parseRange($range);
        $sheetId = $this->resolveSheetId($accessToken, $openId, $spreadsheetId, $sheetRef);
        $uri = sprintf('/openapi/spreadsheet/v3/files/%s/batchUpdate', rawurlencode($spreadsheetId));

        $this->client->request(
            'POST',
            $uri,
            $accessToken,
            $openId,
            json: [
                'requests' => [
                    [
                        'deleteDimensionRequest' => [
                            'sheetId' => $sheetId,
                            'dimension' => 'ROWS',
                            'startIndex' => $targetRow,
                            'endIndex' => $targetRow + 1,
                        ],
                    ],
                ],
            ],
        );

        return ['row' => $targetRow];
    }

    /**
     * 在已有内容后追加行，并读回追加后的行数据.
     *
     * $values 支持单行 [a,b] 或多行 [[a],[b]].
     * 单行时返回的 values 为一维；多行为二维.
     *
     * @param list<mixed>|list<list<mixed>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws TencentApiException
     */
    public function appendCells(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range,
        array $values,
    ): array {
        $values = $this->normalizeRows($values);
        if ($values === []) {
            return [
                'row' => 0,
                'range' => '',
                'values' => [],
            ];
        }

        [$sheetRef, $a1Range] = $this->parseRange($range);
        $sheetPrefix = ($sheetRef !== null && $sheetRef !== '') ? $sheetRef . '!' : '';
        $existing = $this->readCells(
            $accessToken,
            $openId,
            $spreadsheetId,
            $sheetPrefix . 'A1:A10000',
        );
        $nextRow = count($existing) + 1;
        $rowCount = count($values);
        $endRow = $nextRow + $rowCount - 1;

        $startA1 = 'A' . $nextRow;
        if (preg_match('/^([A-Za-z]+)(\d+)/', $a1Range, $m) === 1) {
            $startA1 = $m[1] . $nextRow;
        }
        $writeRange = $sheetPrefix . $startA1;
        $this->writeCells($accessToken, $openId, $spreadsheetId, $writeRange, $values);

        $readRange = $sheetPrefix . 'A' . $nextRow . ':Z' . $endRow;
        $readValues = $this->readCells($accessToken, $openId, $spreadsheetId, $readRange);

        return [
            'row' => $nextRow,
            'range' => $readRange,
            'values' => $this->unwrapSingleRowValues($readValues, $rowCount),
        ];
    }

    /**
     * 在表头（第 1 行）下方插入行，旧数据整体下推。支持批量.
     *
     * 腾讯开放接口未公开 insertDimension，采用「读取原数据行 + 合并写回 A2」实现下推.
     *
     * @param list<mixed>|list<list<mixed>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws TencentApiException
     */
    public function insertAfterHeader(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range,
        array $values,
    ): array {
        $values = $this->normalizeRows($values);
        if ($values === []) {
            return [
                'row' => 0,
                'range' => '',
                'values' => [],
            ];
        }

        return $this->insertAfterHeaderRows($accessToken, $openId, $spreadsheetId, $range, $values);
    }

    /**
     * 按列条件批量 upsert：存在则更新，不存在则插在表头下方.
     *
     * $column 支持单列 'F'，或多列 ['E', 'F']（AND）.
     *
     * @param list<mixed>|list<list<mixed>> $values
     * @param string|int|list<string|int> $column
     * @return array{
     *     updated: list<array{row: int, range: string, values: list<mixed>}>,
     *     inserted: null|array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * }
     * @throws TencentApiException
     */
    public function upsertRows(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range,
        array $values,
        string|int|array $column,
    ): array {
        $values = $this->normalizeRows($values);
        if ($values === []) {
            return [
                'updated' => [],
                'inserted' => null,
            ];
        }

        [$sheetRef] = $this->parseRange($range);
        $sheetPrefix = ($sheetRef !== null && $sheetRef !== '') ? $sheetRef . '!' : '';
        $probe = $sheetPrefix . 'A1:Z10000';
        $existingRows = $this->readCells($accessToken, $openId, $spreadsheetId, $probe, 1);
        $colIndexes = $this->normalizeMatchColumns($column);

        /** @var array<string, int> $index */
        $index = [];
        foreach ($existingRows as $offset => $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowNumber = (int) $offset + 1;
            if ($rowNumber <= 1) {
                continue;
            }
            $key = $this->rowMatchKey($row, $colIndexes);
            if ($key === null || isset($index[$key])) {
                continue;
            }
            $index[$key] = $rowNumber;
        }

        $toUpdate = [];
        $toInsert = [];
        foreach ($values as $row) {
            $key = $this->rowMatchKey($row, $colIndexes);
            if ($key === null) {
                throw new \InvalidArgumentException(
                    'upsertRows requires non-empty match values in columns: '
                    . $this->formatMatchColumns($column)
                );
            }
            if (isset($index[$key]) && $index[$key] > 0) {
                $toUpdate[] = [
                    'row' => $index[$key],
                    'values' => $row,
                ];
            } else {
                $toInsert[] = $row;
                $index[$key] = -1;
            }
        }

        $updated = [];
        foreach ($toUpdate as $item) {
            $result = $this->updateRow(
                $accessToken,
                $openId,
                $spreadsheetId,
                $range,
                $item['values'],
                row: $item['row'],
            );
            if ($result !== null) {
                $updated[] = [
                    'row' => $result['row'],
                    'range' => $result['range'],
                    'values' => is_array($result['values']) ? array_values($result['values']) : [],
                ];
            }
        }

        $inserted = null;
        if ($toInsert !== []) {
            $inserted = $this->insertAfterHeaderRows(
                $accessToken,
                $openId,
                $spreadsheetId,
                $range,
                $toInsert,
            );
            $shift = count($toInsert);
            foreach ($updated as $i => $item) {
                if ($item['row'] < 2) {
                    continue;
                }
                $newRow = $item['row'] + $shift;
                $updated[$i]['row'] = $newRow;
                $updated[$i]['range'] = $sheetPrefix . 'A' . $newRow . ':Z' . $newRow;
            }
        }

        return [
            'updated' => $updated,
            'inserted' => $inserted,
        ];
    }

    /**
     * 在列区块内插入行（只移动该列范围，不影响左右其它块）.
     *
     * 默认插在第 1 行表头下方（dataStartRow=2, position=prepend），可批量.
     *
     * @param list<mixed>|list<list<mixed>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws TencentApiException
     */
    public function insertBlock(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range,
        array $values,
        int $dataStartRow = 2,
        string $position = 'prepend',
    ): array {
        return $this->insertBlockRows(
            $accessToken,
            $openId,
            $spreadsheetId,
            $range,
            $this->normalizeRows($values),
            $dataStartRow,
            $position,
        );
    }

    /**
     * 列区块内 upsert：有则改，无则默认插在表头下方.
     *
     * @param list<mixed>|list<list<mixed>> $values
     * @param string|int|list<string|int> $column
     * @return array{
     *     updated: list<array{row: int, range: string, values: list<mixed>}>,
     *     inserted: null|array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * }
     * @throws TencentApiException
     */
    public function upsertBlock(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range,
        array $values,
        string|int|array $column,
        int $dataStartRow = 2,
        string $position = 'prepend',
    ): array {
        $values = $this->normalizeRows($values);
        if ($values === []) {
            return [
                'updated' => [],
                'inserted' => null,
            ];
        }

        $block = $this->resolveBlockMeta($range, $dataStartRow);
        $relIndexes = $this->normalizeBlockMatchColumns($column, $block['startCol']);
        $existing = $this->readCells(
            $accessToken,
            $openId,
            $spreadsheetId,
            $block['readRange'],
            1,
        );

        /** @var array<string, int> $index */
        $index = [];
        foreach ($existing as $offset => $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowNumber = $block['dataStartRow'] + (int) $offset;
            $key = $this->rowMatchKey($row, $relIndexes);
            if ($key === null || isset($index[$key])) {
                continue;
            }
            $index[$key] = $rowNumber;
        }

        $toUpdate = [];
        $toInsert = [];
        foreach ($values as $row) {
            $key = $this->rowMatchKey($row, $relIndexes);
            if ($key === null) {
                throw new \InvalidArgumentException(
                    'upsertBlock requires non-empty match values in columns: '
                    . $this->formatMatchColumns($column)
                );
            }
            if (isset($index[$key]) && $index[$key] > 0) {
                $toUpdate[] = [
                    'row' => $index[$key],
                    'values' => $row,
                ];
            } else {
                $toInsert[] = $row;
                $index[$key] = -1;
            }
        }

        $updated = [];
        foreach ($toUpdate as $item) {
            $writeRange = $block['sheetPrefix'] . $block['startColName'] . $item['row'];
            $this->writeCells($accessToken, $openId, $spreadsheetId, $writeRange, [$item['values']]);
            $readRange = $block['sheetPrefix']
                . $block['startColName'] . $item['row']
                . ':' . $block['endColName'] . $item['row'];
            $readValues = $this->readCells($accessToken, $openId, $spreadsheetId, $readRange, 1);
            $raw = $readValues[0] ?? [];
            $updated[] = [
                'row' => $item['row'],
                'range' => $readRange,
                'values' => is_array($raw) ? array_values($raw) : [],
            ];
        }

        $inserted = null;
        if ($toInsert !== []) {
            $inserted = $this->insertBlockRows(
                $accessToken,
                $openId,
                $spreadsheetId,
                $range,
                $toInsert,
                $dataStartRow,
                $position,
            );
            if ($position === 'prepend') {
                $shift = count($toInsert);
                foreach ($updated as $i => $item) {
                    if ($item['row'] < $block['dataStartRow']) {
                        continue;
                    }
                    $newRow = $item['row'] + $shift;
                    $updated[$i]['row'] = $newRow;
                    $updated[$i]['range'] = $block['sheetPrefix']
                        . $block['startColName'] . $newRow
                        . ':' . $block['endColName'] . $newRow;
                }
            }
        }

        return [
            'updated' => $updated,
            'inserted' => $inserted,
        ];
    }

    /**
     * @param list<list<mixed>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws TencentApiException
     */
    private function insertBlockRows(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range,
        array $values,
        int $dataStartRow,
        string $position,
    ): array {
        $values = $this->normalizeRows($values);
        if ($values === []) {
            return [
                'row' => 0,
                'range' => '',
                'values' => [],
            ];
        }

        $position = strtolower(trim($position));
        if (! in_array($position, ['prepend', 'append'], true)) {
            throw new \InvalidArgumentException("insertBlock \$position must be 'prepend' or 'append'");
        }
        if ($dataStartRow < 1) {
            throw new \InvalidArgumentException('insertBlock $dataStartRow must be >= 1');
        }

        $block = $this->resolveBlockMeta($range, $dataStartRow);
        $existing = $this->readCells(
            $accessToken,
            $openId,
            $spreadsheetId,
            $block['readRange'],
            1,
        );
        $rowCount = count($values);

        if ($position === 'prepend') {
            $startRow = $block['dataStartRow'];
            $combined = $this->padBlockRows(
                array_merge($values, $existing),
                $block['endCol'] - $block['startCol'] + 1,
            );
            $this->writeCells(
                $accessToken,
                $openId,
                $spreadsheetId,
                $block['sheetPrefix'] . $block['startColName'] . $startRow,
                $combined,
            );
        } else {
            $startRow = $block['dataStartRow'] + count($existing);
            $this->writeCells(
                $accessToken,
                $openId,
                $spreadsheetId,
                $block['sheetPrefix'] . $block['startColName'] . $startRow,
                $values,
            );
        }

        $endRow = $startRow + $rowCount - 1;
        $readRange = $block['sheetPrefix']
            . $block['startColName'] . $startRow
            . ':' . $block['endColName'] . $endRow;
        $readValues = $this->readCells($accessToken, $openId, $spreadsheetId, $readRange, 1);

        return [
            'row' => $startRow,
            'range' => $readRange,
            'values' => $this->unwrapSingleRowValues($readValues, $rowCount),
        ];
    }

    /**
     * @return array{
     *     sheetPrefix: string,
     *     startCol: int,
     *     endCol: int,
     *     startColName: string,
     *     endColName: string,
     *     dataStartRow: int,
     *     readRange: string
     * }
     */
    private function resolveBlockMeta(string $range, int $dataStartRow): array
    {
        [$sheetRef, $a1] = $this->parseRange($range);
        $sheetPrefix = ($sheetRef !== null && $sheetRef !== '') ? $sheetRef . '!' : '';
        $parsed = $this->parseBlockA1($a1);
        $startCol = $parsed['startCol'];
        $endCol = $parsed['endCol'];
        if ($endCol < $startCol) {
            [$startCol, $endCol] = [$endCol, $startCol];
        }

        $startRow = $dataStartRow;
        if ($parsed['startRow'] !== null && $parsed['startRow'] > 0) {
            $startRow = max($dataStartRow, $parsed['startRow']);
        }

        $startColName = $this->columnName($startCol);
        $endColName = $this->columnName($endCol);
        $endRow = $parsed['endRow'] ?? 10000;
        if ($endRow < $startRow) {
            $endRow = 10000;
        }

        return [
            'sheetPrefix' => $sheetPrefix,
            'startCol' => $startCol,
            'endCol' => $endCol,
            'startColName' => $startColName,
            'endColName' => $endColName,
            'dataStartRow' => $startRow,
            'readRange' => $sheetPrefix . $startColName . $startRow . ':' . $endColName . $endRow,
        ];
    }

    /**
     * @return array{startCol: int, endCol: int, startRow: null|int, endRow: null|int}
     */
    private function parseBlockA1(string $a1): array
    {
        $a1 = trim($a1);
        if (preg_match('/^([A-Za-z]+)(\d*):([A-Za-z]+)(\d*)$/', $a1, $m) === 1) {
            return [
                'startCol' => $this->columnIndex($m[1]),
                'endCol' => $this->columnIndex($m[3]),
                'startRow' => $m[2] !== '' ? (int) $m[2] : null,
                'endRow' => $m[4] !== '' ? (int) $m[4] : null,
            ];
        }
        if (preg_match('/^([A-Za-z]+)(\d*)$/', $a1, $m) === 1) {
            $col = $this->columnIndex($m[1]);

            return [
                'startCol' => $col,
                'endCol' => $col,
                'startRow' => $m[2] !== '' ? (int) $m[2] : null,
                'endRow' => null,
            ];
        }

        return [
            'startCol' => 1,
            'endCol' => 26,
            'startRow' => null,
            'endRow' => null,
        ];
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
     * @param string|int|list<string|int> $column
     * @return list<int>
     */
    private function normalizeBlockMatchColumns(string|int|array $column, int $blockStartCol1Based): array
    {
        $columns = is_array($column) ? array_values($column) : [$column];
        if ($columns === []) {
            throw new \InvalidArgumentException('upsertBlock requires at least one match column');
        }

        $blockStart0 = $blockStartCol1Based - 1;
        $indexes = [];
        foreach ($columns as $col) {
            if (is_int($col) || (is_string($col) && $col !== '' && ctype_digit($col))) {
                $indexes[] = max(0, (int) $col);
                continue;
            }
            if (! is_string($col)) {
                throw new \InvalidArgumentException('upsertBlock match column must be string|int');
            }
            $abs0 = $this->toZeroBasedColumn($col);
            $rel = $abs0 - $blockStart0;
            if ($rel < 0) {
                throw new \InvalidArgumentException(
                    "upsertBlock match column {$col} is left of the block start"
                );
            }
            $indexes[] = $rel;
        }

        return $indexes;
    }

    /**
     * @param list<list<mixed>> $rows
     * @return list<list<mixed>>
     */
    private function padBlockRows(array $rows, int $width): array
    {
        $width = max(1, $width);
        $out = [];
        foreach ($rows as $row) {
            $cells = array_values(is_array($row) ? $row : [$row]);
            while (count($cells) < $width) {
                $cells[] = '';
            }
            $cells = array_slice($cells, 0, $width);
            foreach ($cells as $i => $cell) {
                if ($cell === null) {
                    $cells[$i] = '';
                }
            }
            $out[] = $cells;
        }

        return $out;
    }

    /**
     * @param list<list<mixed>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws TencentApiException
     */
    private function insertAfterHeaderRows(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range,
        array $values,
    ): array {
        if ($values === []) {
            return [
                'row' => 0,
                'range' => '',
                'values' => [],
            ];
        }

        [$sheetRef] = $this->parseRange($range);
        $sheetPrefix = ($sheetRef !== null && $sheetRef !== '') ? $sheetRef . '!' : '';
        $startRow = 2;
        $rowCount = count($values);

        // 读出原有数据行（跳过表头），再与新行合并写回 A2，实现下推
        $existingData = $this->readCells(
            $accessToken,
            $openId,
            $spreadsheetId,
            $sheetPrefix . 'A2:Z10000',
            1,
        );
        $combined = array_merge($values, $existingData);
        $this->writeCells(
            $accessToken,
            $openId,
            $spreadsheetId,
            $sheetPrefix . 'A' . $startRow,
            $combined,
        );

        $endRow = $startRow + $rowCount - 1;
        $readRange = $sheetPrefix . 'A' . $startRow . ':Z' . $endRow;
        $readValues = $this->readCells($accessToken, $openId, $spreadsheetId, $readRange, 1);

        return [
            'row' => $startRow,
            'range' => $readRange,
            'values' => $this->unwrapSingleRowValues($readValues, $rowCount),
        ];
    }

    private function matchKey(mixed $cell): ?string
    {
        if ($cell === null) {
            return null;
        }
        if (is_bool($cell)) {
            return $cell ? '__bool:1' : '__bool:0';
        }
        $text = trim((string) $cell);
        if ($text === '') {
            return null;
        }

        return $text;
    }

    /**
     * @param string|int|list<string|int> $column
     * @return list<int>
     */
    private function normalizeMatchColumns(string|int|array $column): array
    {
        $columns = is_array($column) ? array_values($column) : [$column];
        if ($columns === []) {
            throw new \InvalidArgumentException('upsertRows requires at least one match column');
        }

        $indexes = [];
        foreach ($columns as $col) {
            if (! is_string($col) && ! is_int($col)) {
                throw new \InvalidArgumentException('upsertRows match column must be string|int');
            }
            $indexes[] = $this->toZeroBasedColumn($col);
        }

        return $indexes;
    }

    /**
     * @param list<mixed> $row
     * @param list<int> $colIndexes
     */
    private function rowMatchKey(array $row, array $colIndexes): ?string
    {
        $parts = [];
        foreach ($colIndexes as $idx) {
            $part = $this->matchKey($row[$idx] ?? null);
            if ($part === null) {
                return null;
            }
            $parts[] = $part;
        }

        return implode("\0", $parts);
    }

    /**
     * @param string|int|list<string|int> $column
     */
    private function formatMatchColumns(string|int|array $column): string
    {
        $columns = is_array($column) ? $column : [$column];

        return implode(', ', array_map(
            static fn ($col) => is_string($col) || is_int($col) ? (string) $col : '?',
            $columns
        ));
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
     * 批量写入（多次 PUT；腾讯 values 接口按 range 写入）.
     *
     * @param array<int, array{range: string, values: list<list<mixed>>}> $data
     * @return list<array<string, mixed>>
     * @throws TencentApiException
     */
    public function batchWrite(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        array $data,
    ): array {
        $results = [];
        foreach ($data as $item) {
            $results[] = $this->writeCells(
                $accessToken,
                $openId,
                $spreadsheetId,
                (string) $item['range'],
                $item['values'] ?? [],
            );
        }

        return $results;
    }

    /**
     * 通过 spreadsheet v3 batchUpdate 添加工作表.
     *
     * @return array<string, mixed>
     * @throws TencentApiException
     */
    public function addSheet(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $title,
        ?int $rowCount = null,
        ?int $columnCount = null,
    ): array {
        $request = ['title' => $title];
        if (is_int($rowCount)) {
            $request['rowCount'] = $rowCount;
        }
        if (is_int($columnCount)) {
            $request['columnCount'] = $columnCount;
        }

        $uri = sprintf('/openapi/spreadsheet/v3/files/%s/batchUpdate', rawurlencode($spreadsheetId));

        return $this->client->request(
            'POST',
            $uri,
            $accessToken,
            $openId,
            json: [
                'requests' => [
                    ['addSheetRequest' => $request],
                ],
            ],
        );
    }

    /**
     * 将表格设为任何人可查看（publicRead）.
     *
     * @return array<string, mixed>
     * @throws TencentApiException
     */
    public function shareSpreadsheetForAnyoneReader(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
    ): array {
        $uri = sprintf('/openapi/drive/v2/files/%s/permission', rawurlencode($spreadsheetId));

        return $this->client->request(
            'PATCH',
            $uri,
            $accessToken,
            $openId,
            form: [
                'policy' => 'publicRead',
                'copyEnabled' => 'true',
                'readerCommentEnabled' => 'true',
            ],
        );
    }

    /**
     * 将表格移动到「根目录名 / 日期」结构中.
     *
     * @return array{
     *     path: string,
     *     root_folder: array{folder_id: string, folder_name: string, folder_url: string},
     *     date_folder: array{folder_id: string, folder_name: string, folder_url: string}
     * }
     * @throws TencentApiException
     */
    public function moveSpreadsheetToDateFolder(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        ?string $rootFolderName = null,
        ?string $date = null,
        string $parentFolderId = self::ROOT_FOLDER_ID,
    ): array {
        $rootName = $rootFolderName
            ?: (string) $this->config->get('docs.platforms.tencent.drive_root_folder', self::DEFAULT_ROOT_FOLDER);
        $dateName = $date ?? date('Y-m-d');

        $rootFolder = $this->getOrCreateFolder($accessToken, $openId, $rootName, $parentFolderId);
        $dateFolder = $this->getOrCreateFolder($accessToken, $openId, $dateName, $rootFolder['folder_id']);

        $this->moveFile(
            $accessToken,
            $openId,
            $spreadsheetId,
            $dateFolder['folder_id'],
            $parentFolderId,
        );

        return [
            'path' => "{$rootName}/{$dateName}",
            'root_folder' => $rootFolder,
            'date_folder' => $dateFolder,
        ];
    }

    /**
     * 获取或创建文件夹（同名已存在则复用）.
     *
     * @return array{folder_id: string, folder_name: string, folder_url: string}
     * @throws TencentApiException
     */
    public function getOrCreateFolder(
        string $accessToken,
        string $openId,
        string $folderName,
        string $parentFolderId = self::ROOT_FOLDER_ID,
    ): array {
        $existing = $this->findFolder($accessToken, $openId, $folderName, $parentFolderId);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $response = $this->client->request(
                'POST',
                '/openapi/drive/v2/folders',
                $accessToken,
                $openId,
                form: array_filter([
                    'title' => $folderName,
                    'folderID' => $parentFolderId !== self::ROOT_FOLDER_ID ? $parentFolderId : null,
                ], static fn ($v) => $v !== null && $v !== ''),
            );
        } catch (TencentApiException $e) {
            // 10201 文件夹名称已存在
            if ($e->getCode() === 10201) {
                $existing = $this->findFolder($accessToken, $openId, $folderName, $parentFolderId);
                if ($existing !== null) {
                    return $existing;
                }
            }
            throw $e;
        }

        $id = (string) ($response['data']['ID'] ?? $response['data']['id'] ?? '');
        if ($id === '') {
            throw new TencentApiException('Create folder succeeded but folder ID missing', 500, $response);
        }

        return $this->formatFolder($folderName, $id);
    }

    /**
     * @return null|array{folder_id: string, folder_name: string, folder_url: string}
     * @throws TencentApiException
     */
    public function findFolder(
        string $accessToken,
        string $openId,
        string $folderName,
        string $parentFolderId = self::ROOT_FOLDER_ID,
    ): ?array {
        $start = 0;
        $limit = 50;

        do {
            $uri = $parentFolderId === self::ROOT_FOLDER_ID || $parentFolderId === ''
                ? '/openapi/drive/v2/folders/'
                : '/openapi/drive/v2/folders/' . rawurlencode($parentFolderId);

            $response = $this->client->request(
                'GET',
                $uri,
                $accessToken,
                $openId,
                query: [
                    'sortType' => 'browse',
                    'asc' => 0,
                    'start' => $start,
                    'limit' => $limit,
                ],
            );

            $list = $response['data']['list'] ?? [];
            if (! is_array($list)) {
                break;
            }

            foreach ($list as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (($item['type'] ?? '') === 'folder' && (string) ($item['title'] ?? '') === $folderName) {
                    return $this->formatFolder($folderName, (string) $item['ID']);
                }
            }

            $next = (int) ($response['data']['next'] ?? 0);
            if ($next <= $start || $list === []) {
                break;
            }
            $start = $next;
        } while (true);

        return null;
    }

    /**
     * @throws TencentApiException
     */
    public function moveFile(
        string $accessToken,
        string $openId,
        string $fileId,
        string $targetFolderId,
        string $parentFolderId = self::ROOT_FOLDER_ID,
    ): array {
        $uri = sprintf('/openapi/drive/v2/files/%s/move', rawurlencode($fileId));

        return $this->client->request(
            'PATCH',
            $uri,
            $accessToken,
            $openId,
            form: [
                'targetFolderID' => $targetFolderId,
                'parentFolderID' => $parentFolderId === '' ? self::ROOT_FOLDER_ID : $parentFolderId,
            ],
        );
    }

    /**
     * @return array{folder_id: string, folder_name: string, folder_url: string}
     */
    private function formatFolder(string $name, string $id): array
    {
        return [
            'folder_id' => $id,
            'folder_name' => $name,
            'folder_url' => "https://docs.qq.com/desktop/mydoc/folder/{$id}",
        ];
    }

    /**
     * @return array{0: ?string, 1: string} [sheetRef|null, a1Range]
     */
    private function parseRange(string $range): array
    {
        $range = trim($range);
        if ($range === '') {
            return [null, self::DEFAULT_RANGE];
        }

        $pos = strrpos($range, '!');
        if ($pos === false) {
            return [null, $range];
        }

        $sheetRef = trim(substr($range, 0, $pos), "'\" \t");
        $a1Range = trim(substr($range, $pos + 1));

        return [$sheetRef !== '' ? $sheetRef : null, $a1Range !== '' ? $a1Range : self::DEFAULT_RANGE];
    }

    /**
     * @throws TencentApiException
     */
    private function resolveSheetId(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        ?string $sheetRef,
    ): string {
        $sheets = $this->listSheets($accessToken, $openId, $spreadsheetId);
        if ($sheets === []) {
            throw new TencentApiException('Spreadsheet has no sheets', 404);
        }

        if ($sheetRef === null || $sheetRef === '') {
            $first = $sheets[0];
            $id = (string) ($first['sheetId'] ?? $first['sheetID'] ?? $first['ID'] ?? '');
            if ($id === '') {
                throw new TencentApiException('Missing sheetId in spreadsheet metadata', 500, ['sheets' => $sheets]);
            }

            return $id;
        }

        foreach ($sheets as $sheet) {
            $id = (string) ($sheet['sheetId'] ?? $sheet['sheetID'] ?? $sheet['ID'] ?? '');
            $title = (string) ($sheet['title'] ?? '');
            if ($id === $sheetRef || $title === $sheetRef) {
                return $id;
            }
        }

        throw new TencentApiException("Sheet not found: {$sheetRef}", 404, ['sheets' => $sheets]);
    }

    /**
     * 将 v3 gridData 转成二维值数组（兼容 Google Sheets values 形态）.
     *
     * @param array<string, mixed> $gridData
     * @return list<list<mixed>>
     */
    private function flattenGridData(array $gridData): array
    {
        $rows = $gridData['rows'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cells = $row['values'] ?? [];
            if (! is_array($cells)) {
                $result[] = [];
                continue;
            }

            $line = [];
            foreach ($cells as $cell) {
                $line[] = $this->extractCellValue(is_array($cell) ? $cell : []);
            }
            $result[] = $line;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $cell
     */
    private function extractCellValue(array $cell): mixed
    {
        $value = $cell['cellValue'] ?? null;
        if (! is_array($value) || $value === []) {
            return null;
        }

        if (array_key_exists('text', $value)) {
            return $value['text'];
        }
        if (array_key_exists('number', $value)) {
            return $value['number'];
        }
        if (array_key_exists('bool', $value)) {
            return $value['bool'];
        }
        if (isset($value['link']['text'])) {
            return $value['link']['text'];
        }
        if (isset($value['location']['name'])) {
            return $value['location']['name'];
        }

        $first = reset($value);

        return is_scalar($first) ? $first : null;
    }
}
