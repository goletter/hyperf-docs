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
     * @return list<list<mixed>>
     */
    public function readCells(array $token, string $spreadsheetId, string $range = 'A1:Z1000'): array;

    /**
     * @param array{access_token: string, open_id?: string, user_id?: string} $token
     * @param list<list<mixed>> $values
     */
    public function writeCells(array $token, string $spreadsheetId, string $range, array $values): void;

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
