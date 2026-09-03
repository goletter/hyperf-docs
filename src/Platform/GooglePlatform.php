<?php

declare(strict_types=1);

namespace Goletter\Docs\Platform;

use Goletter\Docs\Contract\AuthInterface;
use Goletter\Docs\Contract\PlatformInterface;
use Goletter\Docs\Contract\SheetsInterface;
use Goletter\Docs\Google\GoogleAuth;
use Goletter\Docs\Google\GoogleSheets;
use Goletter\Docs\Support\ResolvesAccessToken;
use Google\Service\Sheets\Spreadsheet;

class GooglePlatform implements PlatformInterface, AuthInterface, SheetsInterface
{
    use ResolvesAccessToken;

    public const NAME = 'google';

    public function __construct(
        protected GoogleAuth $googleAuth,
        protected GoogleSheets $googleSheets,
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
        return $this->googleAuth->getAuthUrl($state);
    }

    public function fetchToken(string $code): array
    {
        return $this->googleAuth->fetchToken($code);
    }

    public function refreshToken(string $refreshToken): array
    {
        return $this->googleAuth->refreshToken($refreshToken);
    }

    public function createSpreadsheet(array $token, string $title, ?string $folderId = null): array
    {
        // Google 创建接口不支持直接指定 folderId；如需归档请再调 moveSpreadsheetToDateFolder
        unset($folderId);

        $spreadsheet = $this->googleSheets->createSpreadsheet($this->accessToken($token), $title);

        return $this->normalizeSpreadsheet($spreadsheet);
    }

    public function readCells(
        array $token,
        string $spreadsheetId,
        string $range = 'A1:Z1000',
        int $minNonEmpty = 1,
    ): array {
        return $this->googleSheets->readCells(
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
        return $this->googleSheets->findRows(
            $this->accessToken($token),
            $spreadsheetId,
            $range,
            $column,
            $value,
        );
    }

    public function writeCells(array $token, string $spreadsheetId, string $range, array $values): void
    {
        $this->googleSheets->writeCells($this->accessToken($token), $spreadsheetId, $range, $values);
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
        return $this->googleSheets->updateRow(
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
        return $this->googleSheets->deleteRow(
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
        return $this->googleSheets->appendCells($this->accessToken($token), $spreadsheetId, $range, $values);
    }

    /**
     * URL #gid=xxx → 工作表标题.
     */
    public function getSheetTitleByGid(array $token, string $spreadsheetId, int|string $gid): string
    {
        return $this->googleSheets->getSheetTitleByGid($this->accessToken($token), $spreadsheetId, $gid);
    }

    /**
     * 用 gid 生成 A1 range.
     */
    public function rangeForGid(
        array $token,
        string $spreadsheetId,
        int|string $gid,
        string $a1Range = 'A1:Z1000',
    ): string {
        return $this->googleSheets->rangeForGid($this->accessToken($token), $spreadsheetId, $gid, $a1Range);
    }

    public function batchWrite(array $token, string $spreadsheetId, array $data): void
    {
        $this->googleSheets->batchWrite($this->accessToken($token), $spreadsheetId, $data);
    }

    public function addSheet(array $token, string $spreadsheetId, string $title): void
    {
        $this->googleSheets->addSheet($this->accessToken($token), $spreadsheetId, $title);
    }

    public function moveSpreadsheetToDateFolder(
        array $token,
        string $spreadsheetId,
        ?string $rootFolderName = null,
        ?string $date = null,
    ): array {
        return $this->googleSheets->moveSpreadsheetToDateFolder(
            $this->accessToken($token),
            $spreadsheetId,
            $rootFolderName,
            $date,
        );
    }

    public function shareSpreadsheetForAnyoneReader(array $token, string $spreadsheetId): mixed
    {
        return $this->googleSheets->shareSpreadsheetForAnyoneReader(
            $this->accessToken($token),
            $spreadsheetId,
        );
    }

    /**
     * @return array{id: string, title: string, url: string, raw: Spreadsheet}
     */
    protected function normalizeSpreadsheet(Spreadsheet $spreadsheet): array
    {
        $title = '';
        $properties = $spreadsheet->getProperties();
        if ($properties) {
            $title = (string) $properties->getTitle();
        }

        return [
            'id' => (string) $spreadsheet->getSpreadsheetId(),
            'title' => $title,
            'url' => (string) ($spreadsheet->getSpreadsheetUrl() ?? ''),
            'raw' => $spreadsheet,
        ];
    }
}
