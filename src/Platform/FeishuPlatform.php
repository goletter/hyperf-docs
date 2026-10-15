<?php

declare(strict_types=1);

namespace Goletter\Docs\Platform;

use Goletter\Docs\Contract\AuthInterface;
use Goletter\Docs\Contract\PlatformInterface;
use Goletter\Docs\Contract\SheetsInterface;
use Goletter\Docs\Feishu\FeishuAuth;
use Goletter\Docs\Feishu\FeishuSheets;
use Goletter\Docs\Support\ResolvesAccessToken;
use Goletter\Docs\Support\SheetUrl;

class FeishuPlatform implements PlatformInterface, AuthInterface, SheetsInterface
{
    use ResolvesAccessToken;

    public const NAME = 'feishu';

    public function __construct(
        protected FeishuAuth $feishuAuth,
        protected FeishuSheets $feishuSheets,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function auth(): AuthInterface
    {
        return $this;
    }

    public function sheets(): SheetsInterface
    {
        return $this;
    }

    public function getAuthUrl(?string $state = null): string
    {
        return $this->feishuAuth->getAuthUrl($state);
    }

    public function fetchToken(string $code): array
    {
        return $this->feishuAuth->fetchToken($code);
    }

    public function refreshToken(string $refreshToken): array
    {
        return $this->feishuAuth->refreshToken($refreshToken);
    }

    public function createSpreadsheet(array $token, string $title, ?string $folderId = null): array
    {
        $file = $this->feishuSheets->createSpreadsheet(
            $this->accessToken($token),
            $title,
            $folderId,
        );

        return [
            'id' => (string) ($file['spreadsheet_token'] ?? ''),
            'title' => (string) ($file['title'] ?? $title),
            'url' => (string) ($file['url'] ?? ''),
            'raw' => $file,
        ];
    }

    public function readCells(
        array $token,
        string $spreadsheetId,
        string $range = 'A1:Z1000',
        int $minNonEmpty = 1,
    ): array {
        return $this->feishuSheets->readCells(
            $this->accessToken($token),
            $spreadsheetId,
            $range,
            $minNonEmpty,
        );
    }

    public function findRows(
        array $token,
        string $spreadsheetId,
        string $range,
        string|int $column,
        mixed $value,
    ): array {
        return $this->feishuSheets->findRows(
            $this->accessToken($token),
            $spreadsheetId,
            $range,
            $column,
            $value,
        );
    }

    public function writeCells(array $token, string $spreadsheetId, string $range, array $values): void
    {
        $this->feishuSheets->writeCells(
            $this->accessToken($token),
            $spreadsheetId,
            $range,
            $values,
        );
    }

    public function updateRow(
        array $token,
        string $spreadsheetId,
        string $range,
        array $values,
        ?int $row = null,
        string|int|null $column = null,
        mixed $match = null,
    ): ?array {
        return $this->feishuSheets->updateRow(
            $this->accessToken($token),
            $spreadsheetId,
            $range,
            $values,
            $row,
            $column,
            $match,
        );
    }

    public function deleteRow(
        array $token,
        string $spreadsheetId,
        string $range,
        ?int $row = null,
        string|int|null $column = null,
        mixed $match = null,
    ): ?array {
        return $this->feishuSheets->deleteRow(
            $this->accessToken($token),
            $spreadsheetId,
            $range,
            $row,
            $column,
            $match,
        );
    }

    public function appendCells(array $token, string $spreadsheetId, string $range, array $values): array
    {
        return $this->feishuSheets->appendCells(
            $this->accessToken($token),
            $spreadsheetId,
            $range,
            $values,
        );
    }

    public function insertAfterHeader(array $token, string $spreadsheetId, string $range, array $values): array
    {
        return $this->feishuSheets->insertAfterHeader(
            $this->accessToken($token),
            $spreadsheetId,
            $range,
            $values,
        );
    }

    public function upsertRows(
        array $token,
        string $spreadsheetId,
        string $range,
        array $values,
        string|int|array $column,
        int $dataStartRow = 2,
    ): array {
        return $this->feishuSheets->upsertRows(
            $this->accessToken($token),
            $spreadsheetId,
            $range,
            $values,
            $column,
            $dataStartRow,
        );
    }

    public function insertBlock(
        array $token,
        string $spreadsheetId,
        string $range,
        array $values,
        int $dataStartRow = 2,
        string $position = 'prepend',
    ): array {
        return $this->feishuSheets->insertBlock(
            $this->accessToken($token),
            $spreadsheetId,
            $range,
            $values,
            $dataStartRow,
            $position,
        );
    }

    public function upsertBlock(
        array $token,
        string $spreadsheetId,
        string $range,
        array $values,
        string|int|array $column,
        int $dataStartRow = 2,
        string $position = 'prepend',
    ): array {
        return $this->feishuSheets->upsertBlock(
            $this->accessToken($token),
            $spreadsheetId,
            $range,
            $values,
            $column,
            $dataStartRow,
            $position,
        );
    }

    public function batchWrite(array $token, string $spreadsheetId, array $data): void
    {
        $this->feishuSheets->batchWrite(
            $this->accessToken($token),
            $spreadsheetId,
            $data,
        );
    }

    public function addSheet(array $token, string $spreadsheetId, string $title): void
    {
        $this->feishuSheets->addSheet(
            $this->accessToken($token),
            $spreadsheetId,
            $title,
        );
    }

    public function moveSpreadsheetToDateFolder(
        array $token,
        string $spreadsheetId,
        ?string $rootFolderName = null,
        ?string $date = null,
    ): array {
        return $this->feishuSheets->moveSpreadsheetToDateFolder(
            $this->accessToken($token),
            $spreadsheetId,
            $rootFolderName,
            $date,
        );
    }

    public function shareSpreadsheetForAnyoneReader(array $token, string $spreadsheetId): mixed
    {
        return $this->feishuSheets->shareSpreadsheetForAnyoneReader(
            $this->accessToken($token),
            $spreadsheetId,
        );
    }

    /**
     * @return array{spreadsheet_id: string, sheet_id: string, url: string}
     */
    public function parseSpreadsheetUrl(string $url): array
    {
        return SheetUrl::parseFeishu($url);
    }
}
