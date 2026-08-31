<?php

declare(strict_types=1);

namespace Goletter\Docs\Tencent;

use Goletter\Docs\Tencent\Exceptions\TencentApiException;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;

class TencentSheets
{
    private const DEFAULT_ROOT_FOLDER = 'Goletter';

    private const ROOT_FOLDER_ID = '/';

    private const DEFAULT_RANGE = 'A1:Z1000';

    #[Inject]
    protected TencentClient $client;

    #[Inject]
    protected ConfigInterface $config;

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
     * 读取单元格（sheetbook values API）.
     *
     * @return list<list<mixed>>
     * @throws TencentApiException
     */
    public function readCells(
        string $accessToken,
        string $openId,
        string $spreadsheetId,
        string $range = self::DEFAULT_RANGE,
    ): array {
        $uri = sprintf(
            '/openapi/sheetbook/v2/%s/values/%s',
            rawurlencode($spreadsheetId),
            rawurlencode($range)
        );

        $response = $this->client->request('GET', $uri, $accessToken, $openId);
        $values = $response['data']['values'] ?? $response['values'] ?? [];

        return is_array($values) ? $values : [];
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
}
