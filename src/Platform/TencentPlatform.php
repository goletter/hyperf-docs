<?php

declare(strict_types=1);

namespace Goletter\Docs\Platform;

use Goletter\Docs\Contract\AuthInterface;
use Goletter\Docs\Contract\PlatformInterface;
use Goletter\Docs\Contract\SheetsInterface;
use Goletter\Docs\Support\ResolvesAccessToken;
use Goletter\Docs\Tencent\TencentAuth;
use Goletter\Docs\Tencent\TencentSheets;
use Hyperf\Di\Annotation\Inject;

class TencentPlatform implements PlatformInterface, AuthInterface, SheetsInterface
{
    use ResolvesAccessToken;

    public const NAME = 'tencent';

    #[Inject]
    protected TencentAuth $tencentAuth;

    #[Inject]
    protected TencentSheets $tencentSheets;

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
        return $this->tencentAuth->getAuthUrl($state);
    }

    public function fetchToken(string $code): array
    {
        return $this->tencentAuth->fetchToken($code);
    }

    public function refreshToken(string $refreshToken): array
    {
        return $this->tencentAuth->refreshToken($refreshToken);
    }

    public function createSpreadsheet(array $token, string $title, ?string $folderId = null): array
    {
        $file = $this->tencentSheets->createSpreadsheet(
            $this->accessToken($token),
            $this->openId($token),
            $title,
            $folderId,
        );

        return [
            'id' => (string) ($file['ID'] ?? $file['id'] ?? ''),
            'title' => (string) ($file['title'] ?? $title),
            'url' => (string) ($file['url'] ?? ''),
            'raw' => $file,
        ];
    }

    public function readCells(array $token, string $spreadsheetId, string $range = 'A1:Z1000'): array
    {
        return $this->tencentSheets->readCells(
            $this->accessToken($token),
            $this->openId($token),
            $spreadsheetId,
            $range,
        );
    }

    public function writeCells(array $token, string $spreadsheetId, string $range, array $values): void
    {
        $this->tencentSheets->writeCells(
            $this->accessToken($token),
            $this->openId($token),
            $spreadsheetId,
            $range,
            $values,
        );
    }

    public function batchWrite(array $token, string $spreadsheetId, array $data): void
    {
        $this->tencentSheets->batchWrite(
            $this->accessToken($token),
            $this->openId($token),
            $spreadsheetId,
            $data,
        );
    }

    public function addSheet(array $token, string $spreadsheetId, string $title): void
    {
        $this->tencentSheets->addSheet(
            $this->accessToken($token),
            $this->openId($token),
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
        return $this->tencentSheets->moveSpreadsheetToDateFolder(
            $this->accessToken($token),
            $this->openId($token),
            $spreadsheetId,
            $rootFolderName,
            $date,
        );
    }

    public function shareSpreadsheetForAnyoneReader(array $token, string $spreadsheetId): mixed
    {
        return $this->tencentSheets->shareSpreadsheetForAnyoneReader(
            $this->accessToken($token),
            $this->openId($token),
            $spreadsheetId,
        );
    }
}
