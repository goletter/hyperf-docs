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
