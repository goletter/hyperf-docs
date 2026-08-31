<?php

declare(strict_types=1);

namespace Goletter\Docs\Contract;

interface AuthInterface
{
    /**
     * 生成 OAuth 授权 URL.
     */
    public function getAuthUrl(?string $state = null): string;

    /**
     * 授权码换取 token.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token?: string,
     *     expires_in?: int|string,
     *     open_id?: string,
     *     user_id?: string,
     *     ...
     * }
     */
    public function fetchToken(string $code): array;

    /**
     * 刷新 access token.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token?: string,
     *     expires_in?: int|string,
     *     open_id?: string,
     *     user_id?: string,
     *     ...
     * }
     */
    public function refreshToken(string $refreshToken): array;
}
