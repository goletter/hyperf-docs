<?php

declare(strict_types=1);

namespace Goletter\Docs\Feishu;

use Goletter\Docs\Feishu\Exceptions\FeishuApiException;
use Hyperf\Contract\ConfigInterface;

/**
 * 飞书电子表格（Sheets v2/v3 + Drive）.
 *
 * range 约定：
 * - A1:Z1000（默认第一个工作表）
 * - {sheetId}!A1:Z1000
 * - sheet:{sheetId}!A1:Z1000
 * - {工作表标题}!A1:Z1000
 */
class FeishuSheets
{
    private const DEFAULT_ROOT_FOLDER = 'Goletter';

    private const DEFAULT_RANGE = 'A1:Z1000';

    public function __construct(
        protected FeishuClient $client,
        protected ConfigInterface $config,
    ) {
    }

    /**
     * @return array{spreadsheet_token: string, title: string, url: string, folder_token?: string}
     * @throws FeishuApiException
     */
    public function createSpreadsheet(
        string $accessToken,
        string $title,
        ?string $folderId = null,
    ): array {
        $body = ['title' => $title];
        if (is_string($folderId) && $folderId !== '') {
            $body['folder_token'] = $folderId;
        }

        $response = $this->client->request(
            'POST',
            '/open-apis/sheets/v3/spreadsheets',
            $accessToken,
            $body,
        );

        $sheet = is_array($response['data']['spreadsheet'] ?? null)
            ? $response['data']['spreadsheet']
            : (is_array($response['data'] ?? null) ? $response['data'] : []);

        return [
            'spreadsheet_token' => (string) ($sheet['spreadsheet_token'] ?? ''),
            'title' => (string) ($sheet['title'] ?? $title),
            'url' => (string) ($sheet['url'] ?? ''),
            'folder_token' => (string) ($sheet['folder_token'] ?? ''),
            'raw' => $response,
        ];
    }

    /**
     * @return list<list<mixed>>
     * @throws FeishuApiException
     */
    public function readCells(
        string $accessToken,
        string $spreadsheetToken,
        string $range = self::DEFAULT_RANGE,
        int $minNonEmpty = 1,
    ): array {
        $resolved = $this->resolveRange($accessToken, $spreadsheetToken, $range);
        $uri = sprintf(
            '/open-apis/sheets/v2/spreadsheets/%s/values/%s',
            rawurlencode($spreadsheetToken),
            rawurlencode($resolved),
        );

        $response = $this->client->request('GET', $uri, $accessToken);
        $values = $response['data']['valueRange']['values']
            ?? $response['data']['values']
            ?? [];

        if (! is_array($values)) {
            return [];
        }

        $rows = [];
        foreach ($values as $row) {
            $rows[] = is_array($row) ? $this->normalizeCellRow(array_values($row)) : [];
        }

        return $this->filterNonEmptyRows($rows, $minNonEmpty);
    }

    /**
     * @param string|int $column
     * @return list<array{row: int, range: string, values: list<mixed>}>
     * @throws FeishuApiException
     */
    public function findRows(
        string $accessToken,
        string $spreadsheetToken,
        string $range,
        string|int $column,
        mixed $value,
    ): array {
        [$sheetRef] = $this->parseRange($range);
        $sheetId = $this->resolveSheetId($accessToken, $spreadsheetToken, $sheetRef);
        $probe = $sheetId . '!A1:Z10000';
        $rows = $this->readCells($accessToken, $spreadsheetToken, $probe, 1);
        $colIndex = $this->toZeroBasedColumn($column);
        $matches = [];

        foreach ($rows as $offset => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! $this->cellEquals($row[$colIndex] ?? null, $value)) {
                continue;
            }
            if (! $this->rowHasData($row, 1)) {
                continue;
            }
            $rowNumber = (int) $offset + 1;
            $matches[] = [
                'row' => $rowNumber,
                'range' => $sheetId . '!A' . $rowNumber . ':Z' . $rowNumber,
                'values' => $this->trimTrailingEmptyCells(array_values($row)),
            ];
        }

        return $matches;
    }

    /**
     * @param list<mixed>|list<list<mixed>> $values
     * @throws FeishuApiException
     */
    public function writeCells(
        string $accessToken,
        string $spreadsheetToken,
        string $range,
        array $values,
    ): array {
        $values = $this->normalizeRows($values);
        $resolved = $this->resolveRange($accessToken, $spreadsheetToken, $range);
        // 飞书要求 range 覆盖 values 占用范围
        $writeRange = $this->expandResolvedRange($resolved, $values);

        return $this->client->request(
            'PUT',
            sprintf('/open-apis/sheets/v2/spreadsheets/%s/values', rawurlencode($spreadsheetToken)),
            $accessToken,
            [
                'valueRange' => [
                    'range' => $writeRange,
                    'values' => $values,
                ],
            ],
        );
    }

    /**
     * @param list<mixed>|list<list<mixed>> $values
     * @return null|array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws FeishuApiException
     */
    public function updateRow(
        string $accessToken,
        string $spreadsheetToken,
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
            $hits = $this->findRows($accessToken, $spreadsheetToken, $range, $column, $match);
            if ($hits === []) {
                return null;
            }
            $targetRow = (int) $hits[0]['row'];
        }
        if ($targetRow < 1) {
            throw new \InvalidArgumentException('updateRow $row must be >= 1');
        }

        $sheetId = $this->resolveSheetId($accessToken, $spreadsheetToken, $this->parseRange($range)[0]);
        $rowCount = count($values);
        $endRow = $targetRow + $rowCount - 1;
        $writeRange = $sheetId . '!A' . $targetRow;
        $this->writeCells($accessToken, $spreadsheetToken, $writeRange, $values);

        $readRange = $sheetId . '!A' . $targetRow . ':Z' . $endRow;
        $readValues = $this->readCells($accessToken, $spreadsheetToken, $readRange, 1);

        return [
            'row' => $targetRow,
            'range' => $readRange,
            'values' => $this->unwrapSingleRowValues($readValues, $rowCount),
        ];
    }

    /**
     * @return null|array{row: int}
     * @throws FeishuApiException
     */
    public function deleteRow(
        string $accessToken,
        string $spreadsheetToken,
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
            $hits = $this->findRows($accessToken, $spreadsheetToken, $range, $column, $match);
            if ($hits === []) {
                return null;
            }
            $targetRow = (int) $hits[0]['row'];
        }
        if ($targetRow < 1) {
            throw new \InvalidArgumentException('deleteRow $row must be >= 1');
        }

        $sheetId = $this->resolveSheetId($accessToken, $spreadsheetToken, $this->parseRange($range)[0]);
        // 飞书 dimension 下标从 0 起，行号 1 → startIndex=0
        $this->client->request(
            'DELETE',
            sprintf(
                '/open-apis/sheets/v2/spreadsheets/%s/dimension_range',
                rawurlencode($spreadsheetToken)
            ),
            $accessToken,
            [
                'dimension' => [
                    'sheetId' => $sheetId,
                    'majorDimension' => 'ROWS',
                    'startIndex' => $targetRow - 1,
                    'endIndex' => $targetRow,
                ],
            ],
        );

        return ['row' => $targetRow];
    }

    /**
     * @param list<mixed>|list<list<mixed>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws FeishuApiException
     */
    public function appendCells(
        string $accessToken,
        string $spreadsheetToken,
        string $range,
        array $values,
    ): array {
        $values = $this->normalizeRows($values);
        if ($values === []) {
            return ['row' => 0, 'range' => '', 'values' => []];
        }

        $sheetId = $this->resolveSheetId($accessToken, $spreadsheetToken, $this->parseRange($range)[0]);
        $appendRange = $sheetId; // 整表追加到末尾空行
        $response = $this->client->request(
            'POST',
            sprintf(
                '/open-apis/sheets/v2/spreadsheets/%s/values_append',
                rawurlencode($spreadsheetToken)
            ),
            $accessToken,
            [
                'valueRange' => [
                    'range' => $appendRange,
                    'values' => $values,
                ],
            ],
            ['insertDataOption' => 'INSERT_ROWS'],
        );

        $updatedRange = (string) ($response['data']['updates']['updatedRange']
            ?? $response['data']['updatedRange']
            ?? '');
        $startRow = $this->parseStartRowFromRange($updatedRange) ?? 0;
        $rowCount = count($values);
        $endRow = $startRow > 0 ? $startRow + $rowCount - 1 : 0;
        $readRange = $startRow > 0
            ? $sheetId . '!A' . $startRow . ':Z' . $endRow
            : $sheetId;
        $readValues = $startRow > 0
            ? $this->readCells($accessToken, $spreadsheetToken, $readRange, 1)
            : $values;

        return [
            'row' => $startRow,
            'range' => $readRange,
            'values' => $this->unwrapSingleRowValues($readValues, $rowCount),
        ];
    }

    /**
     * 在表头下方插入行（insert_dimension_range + 写入）.
     *
     * @param list<mixed>|list<list<mixed>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws FeishuApiException
     */
    public function insertAfterHeader(
        string $accessToken,
        string $spreadsheetToken,
        string $range,
        array $values,
    ): array {
        return $this->insertAfterHeaderRows(
            $accessToken,
            $spreadsheetToken,
            $range,
            $this->normalizeRows($values),
            2,
        );
    }

    /**
     * @param list<mixed>|list<list<mixed>> $values
     * @param string|int|list<string|int> $column
     * @return array{
     *     updated: list<array{row: int, range: string, values: list<mixed>}>,
     *     inserted: null|array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * }
     * @throws FeishuApiException
     */
    public function upsertRows(
        string $accessToken,
        string $spreadsheetToken,
        string $range,
        array $values,
        string|int|array $column,
        int $dataStartRow = 2,
    ): array {
        if ($dataStartRow < 1) {
            throw new \InvalidArgumentException('upsertRows $dataStartRow must be >= 1');
        }
        $values = $this->normalizeRows($values);
        if ($values === []) {
            return ['updated' => [], 'inserted' => null];
        }

        $sheetId = $this->resolveSheetId($accessToken, $spreadsheetToken, $this->parseRange($range)[0]);
        $existingRows = $this->readCells($accessToken, $spreadsheetToken, $sheetId . '!A1:Z10000', 1);
        $colIndexes = $this->normalizeMatchColumns($column);

        /** @var array<string, array{row: int, values: list<mixed>}|int> $index */
        $index = [];
        foreach ($existingRows as $offset => $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowNumber = (int) $offset + 1;
            if ($rowNumber < $dataStartRow) {
                continue;
            }
            $key = $this->rowMatchKey($row, $colIndexes);
            if ($key === null || isset($index[$key])) {
                continue;
            }
            $index[$key] = ['row' => $rowNumber, 'values' => array_values($row)];
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
            $hit = $index[$key] ?? null;
            if (is_array($hit)) {
                if ($this->rowNeedsUpdate($row, $hit['values'])) {
                    $toUpdate[] = ['row' => $hit['row'], 'values' => $row];
                }
            } elseif ($hit !== -1) {
                $toInsert[] = $row;
                $index[$key] = -1;
            }
        }

        $updated = [];
        foreach ($toUpdate as $item) {
            $result = $this->updateRow(
                $accessToken,
                $spreadsheetToken,
                $sheetId,
                $item['values'],
                row: $item['row'],
            );
            if ($result !== null) {
                $rowValues = $result['values'] ?? [];
                $updated[] = [
                    'row' => $result['row'],
                    'range' => $result['range'],
                    'values' => is_array($rowValues) ? array_values($rowValues) : [],
                ];
            }
        }

        $inserted = null;
        if ($toInsert !== []) {
            $inserted = $this->insertAfterHeaderRows(
                $accessToken,
                $spreadsheetToken,
                $sheetId,
                $toInsert,
                $dataStartRow,
            );
            $shift = count($toInsert);
            foreach ($updated as $i => $item) {
                if ($item['row'] < $dataStartRow) {
                    continue;
                }
                $newRow = $item['row'] + $shift;
                $updated[$i]['row'] = $newRow;
                $updated[$i]['range'] = $sheetId . '!A' . $newRow . ':Z' . $newRow;
            }
        }

        return ['updated' => $updated, 'inserted' => $inserted];
    }

    /**
     * 区块插入：飞书 insert_dimension 是整行，区块场景用「读区块 + 合并写回」避免影响左右列.
     *
     * @param list<mixed>|list<list<mixed>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws FeishuApiException
     */
    public function insertBlock(
        string $accessToken,
        string $spreadsheetToken,
        string $range,
        array $values,
        int $dataStartRow = 2,
        string $position = 'prepend',
    ): array {
        return $this->insertBlockRows(
            $accessToken,
            $spreadsheetToken,
            $range,
            $this->normalizeRows($values),
            $dataStartRow,
            $position,
        );
    }

    /**
     * @param list<mixed>|list<list<mixed>> $values
     * @param string|int|list<string|int> $column
     * @return array{
     *     updated: list<array{row: int, range: string, values: list<mixed>}>,
     *     inserted: null|array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * }
     * @throws FeishuApiException
     */
    public function upsertBlock(
        string $accessToken,
        string $spreadsheetToken,
        string $range,
        array $values,
        string|int|array $column,
        int $dataStartRow = 2,
        string $position = 'prepend',
    ): array {
        $values = $this->normalizeRows($values);
        if ($values === []) {
            return ['updated' => [], 'inserted' => null];
        }
        if ($dataStartRow < 1) {
            throw new \InvalidArgumentException('upsertBlock $dataStartRow must be >= 1');
        }

        $block = $this->resolveBlockMeta($accessToken, $spreadsheetToken, $range, $dataStartRow);
        $relIndexes = $this->normalizeBlockMatchColumns($column, $block['startCol']);
        $existing = $this->readBlockRows($accessToken, $spreadsheetToken, $block);

        /** @var array<string, array{row: int, values: list<mixed>}|int> $index */
        $index = [];
        foreach ($existing as $offset => $row) {
            $rowNumber = $block['dataStartRow'] + (int) $offset;
            $key = $this->rowMatchKey($row, $relIndexes);
            if ($key === null || isset($index[$key])) {
                continue;
            }
            $index[$key] = ['row' => $rowNumber, 'values' => array_values($row)];
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
            $hit = $index[$key] ?? null;
            if (is_array($hit)) {
                if ($this->rowNeedsUpdate($row, $hit['values'])) {
                    $toUpdate[] = ['row' => $hit['row'], 'values' => $row];
                }
            } elseif ($hit !== -1) {
                $toInsert[] = $row;
                $index[$key] = -1;
            }
        }

        $updated = [];
        foreach ($toUpdate as $item) {
            $writeRange = $block['sheetPrefix'] . $block['startColName'] . $item['row'];
            $this->writeCells($accessToken, $spreadsheetToken, $writeRange, [$item['values']]);
            $readRange = $block['sheetPrefix'] . $block['startColName'] . $item['row']
                . ':' . $block['endColName'] . $item['row'];
            $read = $this->readCells($accessToken, $spreadsheetToken, $readRange, 1);
            $updated[] = [
                'row' => $item['row'],
                'range' => $readRange,
                'values' => $read[0] ?? [],
            ];
        }

        $inserted = null;
        if ($toInsert !== []) {
            $inserted = $this->insertBlockRows(
                $accessToken,
                $spreadsheetToken,
                $range,
                $toInsert,
                $dataStartRow,
                $position,
            );
            if ($position === 'prepend') {
                $shift = count($toInsert);
                foreach ($updated as $i => $item) {
                    if ($item['row'] < $dataStartRow) {
                        continue;
                    }
                    $newRow = $item['row'] + $shift;
                    $updated[$i]['row'] = $newRow;
                    $updated[$i]['range'] = $block['sheetPrefix'] . $block['startColName'] . $newRow
                        . ':' . $block['endColName'] . $newRow;
                }
            }
        }

        return ['updated' => $updated, 'inserted' => $inserted];
    }

    /**
     * @param array<int, array{range: string, values: list<list<mixed>>}> $data
     * @throws FeishuApiException
     */
    public function batchWrite(
        string $accessToken,
        string $spreadsheetToken,
        array $data,
    ): array {
        $valueRanges = [];
        foreach ($data as $item) {
            $values = $this->normalizeRows($item['values'] ?? []);
            $resolved = $this->resolveRange($accessToken, $spreadsheetToken, (string) $item['range']);
            $valueRanges[] = [
                'range' => $this->expandResolvedRange($resolved, $values),
                'values' => $values,
            ];
        }

        return $this->client->request(
            'POST',
            sprintf(
                '/open-apis/sheets/v2/spreadsheets/%s/values_batch_update',
                rawurlencode($spreadsheetToken)
            ),
            $accessToken,
            ['valueRanges' => $valueRanges],
        );
    }

    /**
     * @throws FeishuApiException
     */
    public function addSheet(
        string $accessToken,
        string $spreadsheetToken,
        string $title,
    ): array {
        return $this->client->request(
            'POST',
            sprintf(
                '/open-apis/sheets/v2/spreadsheets/%s/sheets_batch_update',
                rawurlencode($spreadsheetToken)
            ),
            $accessToken,
            [
                'requests' => [
                    [
                        'addSheet' => [
                            'properties' => [
                                'title' => $title,
                            ],
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * 公开链接可读（任何人可查看）.
     *
     * @throws FeishuApiException
     */
    public function shareSpreadsheetForAnyoneReader(
        string $accessToken,
        string $spreadsheetToken,
    ): array {
        return $this->client->request(
            'PATCH',
            sprintf(
                '/open-apis/drive/v1/permissions/%s/public',
                rawurlencode($spreadsheetToken)
            ),
            $accessToken,
            [
                'external_access' => true,
                'security_entity' => 'anyone_can_view',
                'comment_entity' => 'anyone_can_view',
                'share_entity' => 'anyone',
                'link_share_entity' => 'anyone_readable',
                'invite_external' => true,
            ],
            ['type' => 'sheet'],
        );
    }

    /**
     * @return array{
     *     path: string,
     *     root_folder: array{folder_id: string, folder_name: string, folder_url: string},
     *     date_folder: array{folder_id: string, folder_name: string, folder_url: string}
     * }
     * @throws FeishuApiException
     */
    public function moveSpreadsheetToDateFolder(
        string $accessToken,
        string $spreadsheetToken,
        ?string $rootFolderName = null,
        ?string $date = null,
        ?string $parentFolderToken = null,
    ): array {
        $rootName = $rootFolderName
            ?: (string) $this->config->get(
                'docs.platforms.feishu.drive_root_folder',
                self::DEFAULT_ROOT_FOLDER
            );
        $dateName = $date ?? date('Y-m-d');

        $rootFolder = $this->getOrCreateFolder($accessToken, $rootName, $parentFolderToken);
        $dateFolder = $this->getOrCreateFolder($accessToken, $dateName, $rootFolder['folder_id']);

        $this->client->request(
            'POST',
            sprintf('/open-apis/drive/v1/files/%s/move', rawurlencode($spreadsheetToken)),
            $accessToken,
            [
                'type' => 'sheet',
                'folder_token' => $dateFolder['folder_id'],
            ],
        );

        return [
            'path' => "{$rootName}/{$dateName}",
            'root_folder' => $rootFolder,
            'date_folder' => $dateFolder,
        ];
    }

    /**
     * @return array{folder_id: string, folder_name: string, folder_url: string}
     * @throws FeishuApiException
     */
    public function getOrCreateFolder(
        string $accessToken,
        string $folderName,
        ?string $parentFolderToken = null,
    ): array {
        $existing = $this->findFolder($accessToken, $folderName, $parentFolderToken);
        if ($existing !== null) {
            return $existing;
        }

        $body = ['name' => $folderName];
        if (is_string($parentFolderToken) && $parentFolderToken !== '') {
            $body['folder_token'] = $parentFolderToken;
        }

        $response = $this->client->request(
            'POST',
            '/open-apis/drive/v1/files/create_folder',
            $accessToken,
            $body,
        );

        $token = (string) ($response['data']['token'] ?? $response['data']['folder_token'] ?? '');
        if ($token === '') {
            throw new FeishuApiException('Create folder succeeded but folder token missing', 500, $response);
        }

        return [
            'folder_id' => $token,
            'folder_name' => $folderName,
            'folder_url' => (string) ($response['data']['url'] ?? ''),
        ];
    }

    /**
     * @return null|array{folder_id: string, folder_name: string, folder_url: string}
     * @throws FeishuApiException
     */
    public function findFolder(
        string $accessToken,
        string $folderName,
        ?string $parentFolderToken = null,
    ): ?array {
        $pageToken = null;
        do {
            $query = [
                'page_size' => 50,
                'folder_token' => $parentFolderToken ?? '',
            ];
            if (is_string($pageToken) && $pageToken !== '') {
                $query['page_token'] = $pageToken;
            }

            $response = $this->client->request(
                'GET',
                '/open-apis/drive/v1/files',
                $accessToken,
                null,
                $query,
            );

            $files = $response['data']['files'] ?? [];
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (! is_array($file)) {
                        continue;
                    }
                    if (($file['type'] ?? '') !== 'folder') {
                        continue;
                    }
                    if ((string) ($file['name'] ?? '') !== $folderName) {
                        continue;
                    }

                    return [
                        'folder_id' => (string) ($file['token'] ?? ''),
                        'folder_name' => $folderName,
                        'folder_url' => (string) ($file['url'] ?? ''),
                    ];
                }
            }

            $pageToken = $response['data']['next_page_token'] ?? null;
            if (! is_string($pageToken) || $pageToken === '') {
                break;
            }
        } while (true);

        return null;
    }

    /**
     * @return list<array{sheet_id: string, title: string, index: int}>
     * @throws FeishuApiException
     */
    public function listSheets(string $accessToken, string $spreadsheetToken): array
    {
        $response = $this->client->request(
            'GET',
            sprintf(
                '/open-apis/sheets/v2/spreadsheets/%s/metainfo',
                rawurlencode($spreadsheetToken)
            ),
            $accessToken,
        );

        $sheets = $response['data']['sheets'] ?? [];
        $result = [];
        if (! is_array($sheets)) {
            return $result;
        }

        foreach ($sheets as $i => $sheet) {
            if (! is_array($sheet)) {
                continue;
            }
            $result[] = [
                'sheet_id' => (string) ($sheet['sheetId'] ?? $sheet['sheet_id'] ?? ''),
                'title' => (string) ($sheet['title'] ?? ''),
                'index' => (int) ($sheet['index'] ?? $i),
            ];
        }

        return $result;
    }

    // ── helpers ──────────────────────────────────────────────

    /**
     * @param list<list<mixed>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws FeishuApiException
     */
    private function insertAfterHeaderRows(
        string $accessToken,
        string $spreadsheetToken,
        string $range,
        array $values,
        int $startRow = 2,
    ): array {
        if ($values === []) {
            return ['row' => 0, 'range' => '', 'values' => []];
        }

        $sheetId = $this->resolveSheetId($accessToken, $spreadsheetToken, $this->parseRange($range)[0]);
        $rowCount = count($values);

        $this->client->request(
            'POST',
            sprintf(
                '/open-apis/sheets/v2/spreadsheets/%s/insert_dimension_range',
                rawurlencode($spreadsheetToken)
            ),
            $accessToken,
            [
                'dimension' => [
                    'sheetId' => $sheetId,
                    'majorDimension' => 'ROWS',
                    'startIndex' => $startRow - 1,
                    'endIndex' => $startRow - 1 + $rowCount,
                ],
                'inheritStyle' => 'AFTER',
            ],
        );

        $writeRange = $sheetId . '!A' . $startRow;
        $this->writeCells($accessToken, $spreadsheetToken, $writeRange, $values);

        $endRow = $startRow + $rowCount - 1;
        $readRange = $sheetId . '!A' . $startRow . ':Z' . $endRow;
        $readValues = $this->readCells($accessToken, $spreadsheetToken, $readRange, 1);

        return [
            'row' => $startRow,
            'range' => $readRange,
            'values' => $this->unwrapSingleRowValues($readValues, $rowCount),
        ];
    }

    /**
     * @param list<list<mixed>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * @throws FeishuApiException
     */
    private function insertBlockRows(
        string $accessToken,
        string $spreadsheetToken,
        string $range,
        array $values,
        int $dataStartRow,
        string $position,
    ): array {
        if ($values === []) {
            return ['row' => 0, 'range' => '', 'values' => []];
        }
        if (! in_array($position, ['prepend', 'append'], true)) {
            throw new \InvalidArgumentException("insertBlock \$position must be 'prepend' or 'append'");
        }
        if ($dataStartRow < 1) {
            throw new \InvalidArgumentException('insertBlock $dataStartRow must be >= 1');
        }

        $block = $this->resolveBlockMeta($accessToken, $spreadsheetToken, $range, $dataStartRow);
        $width = $block['endCol'] - $block['startCol'] + 1;
        $existing = $this->readBlockRows($accessToken, $spreadsheetToken, $block);
        $paddedNew = $this->padBlockRows($values, $width);

        if ($position === 'prepend') {
            $combined = array_merge($paddedNew, $this->padBlockRows($existing, $width));
            $startRow = $dataStartRow;
        } else {
            $combined = array_merge($this->padBlockRows($existing, $width), $paddedNew);
            $startRow = $dataStartRow + count($existing);
        }

        $writeRange = $block['sheetPrefix'] . $block['startColName'] . $dataStartRow;
        $this->writeCells($accessToken, $spreadsheetToken, $writeRange, $combined);

        $rowCount = count($values);
        $endRow = $startRow + $rowCount - 1;
        $readRange = $block['sheetPrefix'] . $block['startColName'] . $startRow
            . ':' . $block['endColName'] . $endRow;
        $readValues = $this->readCells($accessToken, $spreadsheetToken, $readRange, 1);

        return [
            'row' => $startRow,
            'range' => $readRange,
            'values' => $this->unwrapSingleRowValues($readValues, $rowCount),
        ];
    }

    /**
     * @return array{
     *     sheetId: string,
     *     sheetPrefix: string,
     *     startCol: int,
     *     endCol: int,
     *     startColName: string,
     *     endColName: string,
     *     dataStartRow: int
     * }
     * @throws FeishuApiException
     */
    private function resolveBlockMeta(
        string $accessToken,
        string $spreadsheetToken,
        string $range,
        int $dataStartRow,
    ): array {
        $parsed = $this->parseBlockA1($range);
        $sheetId = $this->resolveSheetId($accessToken, $spreadsheetToken, $parsed['sheetRef']);

        return [
            'sheetId' => $sheetId,
            'sheetPrefix' => $sheetId . '!',
            'startCol' => $parsed['startCol'],
            'endCol' => $parsed['endCol'],
            'startColName' => $this->columnName($parsed['startCol']),
            'endColName' => $this->columnName($parsed['endCol']),
            'dataStartRow' => $dataStartRow,
        ];
    }

    /**
     * @return array{sheetRef: null|string, startCol: int, endCol: int}
     */
    private function parseBlockA1(string $range): array
    {
        [$sheetRef, $a1] = $this->parseRange($range);
        $a1 = strtoupper(trim($a1));
        if (preg_match('/^([A-Z]+)(?::([A-Z]+))?$/', $a1, $m) === 1) {
            $start = $this->columnIndex($m[1]);
            $end = isset($m[2]) && $m[2] !== '' ? $this->columnIndex($m[2]) : $start;

            return [
                'sheetRef' => $sheetRef,
                'startCol' => min($start, $end),
                'endCol' => max($start, $end),
            ];
        }
        if (preg_match('/^([A-Z]+)\d*(?::([A-Z]+)\d*)?$/', $a1, $m) === 1) {
            $start = $this->columnIndex($m[1]);
            $end = isset($m[2]) && $m[2] !== '' ? $this->columnIndex($m[2]) : $start;

            return [
                'sheetRef' => $sheetRef,
                'startCol' => min($start, $end),
                'endCol' => max($start, $end),
            ];
        }

        throw new \InvalidArgumentException("Invalid block range: {$range}");
    }

    /**
     * @param array{
     *     sheetId: string,
     *     sheetPrefix: string,
     *     startCol: int,
     *     endCol: int,
     *     startColName: string,
     *     endColName: string,
     *     dataStartRow: int
     * } $block
     * @return list<list<mixed>>
     * @throws FeishuApiException
     */
    private function readBlockRows(string $accessToken, string $spreadsheetToken, array $block): array
    {
        $range = $block['sheetPrefix']
            . $block['startColName'] . $block['dataStartRow']
            . ':' . $block['endColName'] . '10000';

        return $this->readCells($accessToken, $spreadsheetToken, $range, 1);
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
            $cells = array_values($row);
            if (count($cells) < $width) {
                $cells = array_pad($cells, $width, null);
            } elseif (count($cells) > $width) {
                $cells = array_slice($cells, 0, $width);
            }
            $out[] = $cells;
        }

        return $out;
    }

    /**
     * @param string|int|list<string|int> $column
     * @return list<int>
     */
    private function normalizeBlockMatchColumns(string|int|array $column, int $blockStartCol): array
    {
        $columns = is_array($column) ? array_values($column) : [$column];
        if ($columns === []) {
            throw new \InvalidArgumentException('upsertBlock requires at least one match column');
        }

        $indexes = [];
        foreach ($columns as $col) {
            if (is_int($col) || (is_string($col) && ctype_digit($col))) {
                $indexes[] = max(0, (int) $col);
                continue;
            }
            if (! is_string($col)) {
                throw new \InvalidArgumentException('upsertBlock match column must be string|int');
            }
            $abs = $this->columnIndex(strtoupper(trim($col)));
            $indexes[] = max(0, $abs - $blockStartCol);
        }

        return $indexes;
    }

    /**
     * @throws FeishuApiException
     */
    private function resolveRange(string $accessToken, string $spreadsheetToken, string $range): string
    {
        [$sheetRef, $a1] = $this->parseRange($range);
        $sheetId = $this->resolveSheetId($accessToken, $spreadsheetToken, $sheetRef);

        return $a1 === '' ? $sheetId : $sheetId . '!' . $a1;
    }

    /**
     * @return array{0: null|string, 1: string}
     */
    private function parseRange(string $range): array
    {
        $range = trim($range);
        if ($range === '') {
            return [null, self::DEFAULT_RANGE];
        }

        if (str_contains($range, '!')) {
            [$left, $right] = explode('!', $range, 2);

            return [trim($left), trim($right) !== '' ? trim($right) : self::DEFAULT_RANGE];
        }

        // 只有 sheetId / sheet:xxx / 标题
        if (preg_match('/^[A-Za-z0-9_-]{6,}$/', $range) === 1 || str_starts_with($range, 'sheet:')) {
            return [$range, self::DEFAULT_RANGE];
        }

        return [null, $range];
    }

    /**
     * @throws FeishuApiException
     */
    private function resolveSheetId(
        string $accessToken,
        string $spreadsheetToken,
        ?string $sheetRef,
    ): string {
        $sheets = $this->listSheets($accessToken, $spreadsheetToken);
        if ($sheets === []) {
            throw new FeishuApiException('Spreadsheet has no sheets', 404);
        }

        if ($sheetRef === null || $sheetRef === '') {
            return $sheets[0]['sheet_id'];
        }

        $ref = $sheetRef;
        if (str_starts_with(strtolower($ref), 'sheet:')) {
            $ref = substr($ref, 6);
        }

        foreach ($sheets as $sheet) {
            if ($sheet['sheet_id'] === $ref || $sheet['title'] === $ref) {
                return $sheet['sheet_id'];
            }
        }

        // 直接当作 sheetId（飞书 sheetId 多为短字符串）
        return $ref;
    }

    /**
     * @param list<list<mixed>> $values
     */
    private function expandResolvedRange(string $resolved, array $values): string
    {
        if ($values === []) {
            return $resolved;
        }

        $sheetId = $resolved;
        $a1 = '';
        if (str_contains($resolved, '!')) {
            [$sheetId, $a1] = explode('!', $resolved, 2);
        }

        $startCol = 'A';
        $startRow = 1;
        if (preg_match('/^([A-Za-z]+)(\d+)/', $a1, $m) === 1) {
            $startCol = strtoupper($m[1]);
            $startRow = (int) $m[2];
        } elseif (preg_match('/^([A-Za-z]+)/', $a1, $m) === 1) {
            $startCol = strtoupper($m[1]);
        }

        $rowCount = count($values);
        $colCount = 1;
        foreach ($values as $row) {
            $colCount = max($colCount, count($row));
        }
        $startColIndex = $this->columnIndex($startCol);
        $endColName = $this->columnName($startColIndex + $colCount - 1);
        $endRow = $startRow + $rowCount - 1;

        return $sheetId . '!' . $startCol . $startRow . ':' . $endColName . $endRow;
    }

    private function parseStartRowFromRange(string $range): ?int
    {
        if (preg_match('/![A-Za-z]+(\d+)/', $range, $m) === 1) {
            return (int) $m[1];
        }

        return null;
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

        return max(0, $this->columnIndex(strtoupper($column)) - 1);
    }

    /** 1-based column index：A=1 */
    private function columnIndex(string $column): int
    {
        $column = strtoupper(trim($column));
        $index = 0;
        $length = strlen($column);
        for ($i = 0; $i < $length; ++$i) {
            $index = $index * 26 + (ord($column[$i]) - 64);
        }

        return max(1, $index);
    }

    private function columnName(int $index): string
    {
        $index = max(1, $index);
        $name = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
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
     * @param list<mixed> $newRow
     * @param list<mixed> $existingRow
     */
    private function rowNeedsUpdate(array $newRow, array $existingRow): bool
    {
        foreach ($newRow as $i => $cell) {
            if ($cell === null) {
                continue;
            }
            if (! $this->cellEquals($existingRow[$i] ?? null, $cell)) {
                return true;
            }
        }

        return false;
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

        return $text === '' ? null : $text;
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
     * 飞书单元格可能是对象（link / mention 等），读成标量方便业务比较.
     *
     * @param list<mixed> $row
     * @return list<mixed>
     */
    private function normalizeCellRow(array $row): array
    {
        $out = [];
        foreach ($row as $cell) {
            if (is_array($cell)) {
                if (isset($cell['text'])) {
                    $out[] = $cell['text'];
                } elseif (isset($cell['link'])) {
                    $out[] = $cell['link'];
                } else {
                    $out[] = json_encode($cell, JSON_UNESCAPED_UNICODE);
                }
            } else {
                $out[] = $cell;
            }
        }

        return $out;
    }

    /**
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
}
