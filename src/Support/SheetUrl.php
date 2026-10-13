<?php

declare(strict_types=1);

namespace Goletter\Docs\Support;

use InvalidArgumentException;

/**
 * 从分享链接解析 / 生成表格 ID.
 */
final class SheetUrl
{
    /** 腾讯文档在线表格 fileId 常用前缀 */
    public const TENCENT_FILE_ID_PREFIX = '300000000$';

    /**
     * 解析腾讯文档表格 URL.
     *
     * 例：https://docs.qq.com/sheet/DSEhEVGJuQ1dFQXhM?tab=BB08J2
     * → spreadsheet_id = 300000000$HHDTbnCWEAxL
     * → sheet_id = BB08J2
     *
     * 也支持只传 pad：DSEhEVGJuQ1dFQXhM
     *
     * @return array{spreadsheet_id: string, sheet_id: string, pad: string, url: string}
     */
    public static function parseTencent(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('Tencent sheet url is empty');
        }

        $pad = null;
        if (preg_match('~/sheet/([^/?]+)~i', $url, $matches) === 1) {
            $pad = $matches[1];
        } elseif (preg_match('~^[A-Za-z0-9+/_-]+={0,2}$~', $url) === 1) {
            $pad = $url;
        }

        if (! is_string($pad) || strlen($pad) < 2) {
            throw new InvalidArgumentException('Invalid Tencent sheet url: missing /sheet/{pad}');
        }

        $decoded = base64_decode(substr($pad, 1), true);
        if (! is_string($decoded) || $decoded === '') {
            throw new InvalidArgumentException('Invalid Tencent sheet pad token');
        }

        $sheetId = '';
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            $sheetId = (string) ($params['tab'] ?? '');
        }

        $canonical = 'https://docs.qq.com/sheet/' . $pad;
        if ($sheetId !== '') {
            $canonical .= '?tab=' . rawurlencode($sheetId);
        }

        return [
            'spreadsheet_id' => self::TENCENT_FILE_ID_PREFIX . $decoded,
            'sheet_id' => $sheetId,
            'pad' => $pad,
            'url' => $canonical,
        ];
    }

    /**
     * 由 spreadsheetId（及可选 sheetId）生成腾讯文档分享链接.
     */
    public static function buildTencent(string $spreadsheetId, ?string $sheetId = null): string
    {
        $spreadsheetId = trim($spreadsheetId);
        if ($spreadsheetId === '') {
            throw new InvalidArgumentException('Tencent spreadsheet id is empty');
        }

        $pos = strpos($spreadsheetId, '$');
        $payload = $pos === false ? $spreadsheetId : substr($spreadsheetId, $pos + 1);
        if ($payload === '') {
            throw new InvalidArgumentException('Invalid Tencent spreadsheet id');
        }

        $pad = 'D' . rtrim(base64_encode($payload), '=');
        $url = 'https://docs.qq.com/sheet/' . $pad;
        if (is_string($sheetId) && $sheetId !== '') {
            $url .= '?tab=' . rawurlencode($sheetId);
        }

        return $url;
    }

    /**
     * 解析 Google Sheets URL.
     *
     * 例：
     * - https://docs.google.com/spreadsheets/d/{id}/edit#gid=0
     * - https://docs.google.com/spreadsheets/d/{id}/edit?gid=0#gid=0
     * - 也可直接传 spreadsheetId
     *
     * @return array{spreadsheet_id: string, gid: string, url: string}
     */
    public static function parseGoogle(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('Google sheet url is empty');
        }

        $spreadsheetId = '';
        $gid = '';

        if (preg_match('~/spreadsheets/d/([^/?]+)~', $url, $matches) === 1
            || preg_match('~/d/([^/?]+)~', $url, $matches) === 1
        ) {
            $spreadsheetId = $matches[1];
        } elseif (preg_match('~^[a-zA-Z0-9-_]+$~', $url) === 1) {
            // 直接传入 spreadsheetId
            $spreadsheetId = $url;
        }

        if (preg_match('~[?#&]gid=(\d+)~', $url, $matches) === 1) {
            $gid = $matches[1];
        }

        if ($spreadsheetId === '') {
            throw new InvalidArgumentException('Invalid Google sheet url: missing /d/{id}');
        }

        return [
            'spreadsheet_id' => $spreadsheetId,
            'gid' => $gid,
            'url' => self::buildGoogle($spreadsheetId, $gid !== '' ? $gid : null),
        ];
    }

    /**
     * 由 spreadsheetId（及可选 gid）生成 Google Sheets 分享链接.
     */
    public static function buildGoogle(string $spreadsheetId, string|int|null $gid = null): string
    {
        $spreadsheetId = trim($spreadsheetId);
        if ($spreadsheetId === '') {
            throw new InvalidArgumentException('Google spreadsheet id is empty');
        }

        $url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode($spreadsheetId) . '/edit';
        if ($gid !== null && $gid !== '') {
            $url .= '#gid=' . $gid;
        }

        return $url;
    }
}
