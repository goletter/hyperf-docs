<?php

declare(strict_types=1);

namespace Goletter\Docs\Support;

use InvalidArgumentException;

trait ResolvesAccessToken
{
    /**
     * @param array{access_token?: string, open_id?: string, user_id?: string} $token
     */
    protected function accessToken(array $token): string
    {
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new InvalidArgumentException('token.access_token is required.');
        }

        return $accessToken;
    }

    /**
     * @param array{access_token?: string, open_id?: string, user_id?: string} $token
     */
    protected function openId(array $token): string
    {
        $openId = (string) ($token['open_id'] ?? $token['user_id'] ?? '');
        if ($openId === '') {
            throw new InvalidArgumentException('token.open_id (or user_id) is required for Tencent Docs.');
        }

        return $openId;
    }
}
