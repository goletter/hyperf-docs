<?php

declare(strict_types=1);

namespace Goletter\Docs\Contract;

interface SheetsInterface
{
    /**
     * 创建表格.
     *
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @return array{id: string, title: string, url: string, raw: mixed}
     */
    public function createSpreadsheet(array $token, string $title, ?string $folderId = null): array;

    /**
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @param int $minNonEmpty 每行至少几个非空单元格才保留（默认 1）
     * @return list<list<mixed>>
     */
    public function readCells(
        array $token,
        string $spreadsheetId,
        string $range = 'A1:Z1000',
        int $minNonEmpty = 1,
    ): array;

    /**
     * 按列内容查找行（根据表格内容定位指定行）.
     *
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @param string|int $column 列字母（如 F）或 0-based 列下标
     * @return list<array{row: int, range: string, values: list<mixed>}>
     */
    public function findRows(
        array $token,
        string $spreadsheetId,
        string $range,
        string|int $column,
        mixed $value,
    ): array;

    /**
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @param list<list<null|scalar>> $values
     */
    public function writeCells(array $token, string $spreadsheetId, string $range, array $values): void;

    /**
     * 编辑指定行，并读回更新后的行数据.
     *
     * - 传 $row：直接更新该行号
     * - 不传 $row，传 $column + $match：按列内容找到第一行再更新
     *
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @param list<null|bool|scalar>|list<list<null|bool|scalar>> $values
     * @return null|array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     */
    public function updateRow(
        array $token,
        string $spreadsheetId,
        string $range,
        array $values,
        ?int $row = null,
        string|int|null $column = null,
        mixed $match = null,
    ): ?array;

    /**
     * 删除指定行.
     *
     * - 传 $row：直接删除该行号
     * - 不传 $row，传 $column + $match：按列内容找到第一行再删除
     *
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @return null|array{row: int}
     */
    public function deleteRow(
        array $token,
        string $spreadsheetId,
        string $range,
        ?int $row = null,
        string|int|null $column = null,
        mixed $match = null,
    ): ?array;

    /**
     * 在表格已有内容后面追加行，并读回追加后的行数据.
     *
     * $values 支持单行 [a,b,…] 或多行 [[…],[…]].
     *
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @param list<null|bool|scalar>|list<list<null|bool|scalar>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     */
    public function appendCells(array $token, string $spreadsheetId, string $range, array $values): array;

    /**
     * 在表头（第 1 行）下方插入行，旧数据整体下推。支持批量.
     *
     * $values 支持单行 [a,b,…] 或多行 [[…],[…]].
     *
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @param list<null|bool|scalar>|list<list<null|bool|scalar>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     */
    public function insertAfterHeader(array $token, string $spreadsheetId, string $range, array $values): array;

    /**
     * 按列条件批量 upsert：存在则更新，不存在则插在表头下方（旧数据下推）.
     *
     * 匹配值取自每行 $values 对应列；表头第 1 行不参与匹配.
     * $column 支持单列 'F'，或多列 ['E', 'F']（AND，全部相等才算命中）.
     *
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @param list<null|bool|scalar>|list<list<null|bool|scalar>> $values
     * @param string|int|list<string|int> $column 列字母 / 0-based 下标，或列列表
     * @return array{
     *     updated: list<array{row: int, range: string, values: list<mixed>}>,
     *     inserted: null|array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * }
     */
    public function upsertRows(
        array $token,
        string $spreadsheetId,
        string $range,
        array $values,
        string|int|array $column,
    ): array;

    /**
     * 在列区块内插入行（只移动该列范围数据，不影响左右其它块）.
     *
     * $range 如 gid:123!J:O / gid:123!A:E；$values 为相对区块的列值（首元素=区块首列）.
     * 默认插在第 1 行表头下方（$dataStartRow=2，$position=prepend），旧数据仅在本区块下推；可批量.
     * $position: prepend=插到 $dataStartRow；append=接到区块数据末尾.
     *
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @param list<null|bool|scalar>|list<list<null|bool|scalar>> $values
     * @return array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     */
    public function insertBlock(
        array $token,
        string $spreadsheetId,
        string $range,
        array $values,
        int $dataStartRow = 2,
        string $position = 'prepend',
    ): array;

    /**
     * 列区块内 upsert：有则只改该行区块列，无则按 $position 在区块内插入（默认插在表头下）.
     *
     * $column 支持表字母 'J' / ['J','K']，或相对区块的 0-based 下标 0 / [0,1].
     * 默认 $dataStartRow=2（第 1 行表头下方）；多行表头/合计行时自行上调.
     *
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @param list<null|bool|scalar>|list<list<null|bool|scalar>> $values
     * @param string|int|list<string|int> $column
     * @return array{
     *     updated: list<array{row: int, range: string, values: list<mixed>}>,
     *     inserted: null|array{row: int, range: string, values: list<mixed>|list<list<mixed>>}
     * }
     */
    public function upsertBlock(
        array $token,
        string $spreadsheetId,
        string $range,
        array $values,
        string|int|array $column,
        int $dataStartRow = 2,
        string $position = 'prepend',
    ): array;

    /**
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @param array<int, array{range: string, values: list<list<mixed>>}> $data
     */
    public function batchWrite(array $token, string $spreadsheetId, array $data): void;

    /**
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     */
    public function addSheet(array $token, string $spreadsheetId, string $title): void;

    /**
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @return array{path: string, root_folder: array, date_folder: array}
     */
    public function moveSpreadsheetToDateFolder(
        array $token,
        string $spreadsheetId,
        ?string $rootFolderName = null,
        ?string $date = null,
    ): array;

    /**
     * 公开只读分享.
     *
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     */
    public function shareSpreadsheetForAnyoneReader(array $token, string $spreadsheetId): mixed;
}
