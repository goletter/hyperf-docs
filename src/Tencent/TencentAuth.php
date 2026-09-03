<?php

declare(strict_types=1);

namespace Goletter\Docs\Tencent;

use Goletter\Docs\Tencent\Exceptions\TencentApiException;

class TencentAuth
{
    private const AUTHORIZE_PATH = '/oauth/v2/authorize';

    private const TOKEN_PATH = '/oauth/v2/token';

    public function __construct(protected TencentClient $client)
    {
    }

    /**
     * 生成授权 URL.
     */
    public function getAuthUrl(?string $state = null, ?string $scope = null): string
    {
        $query = [
            'client_id' => (string) $this->client->option('client_id'),
            'redirect_uri' => (string) $this->client->option('redirect_uri'),
            'response_type' => 'code',
            // 腾讯文档 OAuth 文档要求 scope 固定为 all（实际权限以开放平台已开通权限为准）
            'scope' => $scope ?: 'all',
            'new_login' => '1',
        ];

        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }

        return TencentClient::BASE_URI . self::AUTHORIZE_PATH . '?' . http_build_query($query);
    }

    /**
     * 通过授权码换取访问令牌.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token?: string,
     *     expires_in?: int,
     *     token_type?: string,
     *     scope?: string,
     *     user_id?: string,
     *     open_id?: string
     * }
     * @throws TencentApiException
     */
    public function fetchToken(string $code): array
    {
        $token = $this->client->requestOAuth('GET', self::TOKEN_PATH, [
            'client_id' => (string) $this->client->option('client_id'),
            'client_secret' => (string) $this->client->option('client_secret'),
            'redirect_uri' => (string) $this->client->option('redirect_uri'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        return $this->normalizeToken($token);
    }

    /**
     * 使用刷新令牌刷新访问令牌.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token?: string,
     *     expires_in?: int,
     *     token_type?: string,
     *     scope?: string,
     *     user_id?: string,
     *     open_id?: string
     * }
     * @throws TencentApiException
     */
    public function refreshToken(string $refreshToken): array
    {
        $token = $this->client->requestOAuth('GET', self::TOKEN_PATH, [
            'client_id' => (string) $this->client->option('client_id'),
            'client_secret' => (string) $this->client->option('client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        return $this->normalizeToken($token);
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     * @throws TencentApiException
     */
    protected function normalizeToken(array $token): array
    {
        if (isset($token['error'])) {
            $message = (string) ($token['error_description'] ?? $token['error'] ?? 'OAuth token error');
            $error = (string) $token['error'];
            $code = in_array($error, ['invalid_grant', 'invalid_token', 'expired_token'], true) ? 401 : 400;

            throw new TencentApiException($message, $code, $token);
        }

        if (empty($token['access_token'])) {
            throw new TencentApiException('Missing access_token in OAuth response', 400, $token);
        }

        // OpenAPI 头使用 Open-Id，对应 token 回包 user_id
        if (! empty($token['user_id']) && empty($token['open_id'])) {
            $token['open_id'] = (string) $token['user_id'];
        }

        return $token;
    }
}
