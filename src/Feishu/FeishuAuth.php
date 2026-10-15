<?php

declare(strict_types=1);

namespace Goletter\Docs\Feishu;

use Goletter\Docs\Feishu\Exceptions\FeishuApiException;

class FeishuAuth
{
    /** 用户授权页（新版） */
    private const AUTHORIZE_URL = FeishuClient::ACCOUNTS_URI . '/open-apis/authen/v1/authorize';

    /** 换取 / 刷新 user_access_token（新版） */
    private const TOKEN_PATH = '/open-apis/authen/v2/oauth/token';

    public function __construct(protected FeishuClient $client)
    {
    }

    /**
     * 生成授权 URL.
     *
     * scope 需在开发者后台开通，并在授权时声明；要拿 refresh_token 必须含 offline_access.
     */
    public function getAuthUrl(?string $state = null, ?string $scope = null): string
    {
        $scopes = $scope !== null && $scope !== ''
            ? $scope
            : implode(' ', $this->client->getScopes());

        $query = [
            'client_id' => $this->client->appId(),
            'response_type' => 'code',
            'redirect_uri' => (string) $this->client->option('redirect_uri'),
            'scope' => $scopes,
        ];

        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }

        return self::AUTHORIZE_URL . '?' . http_build_query($query);
    }

    /**
     * 授权码换取 user_access_token.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token?: string,
     *     expires_in?: int,
     *     refresh_token_expires_in?: int,
     *     token_type?: string,
     *     scope?: string,
     *     open_id?: string,
     *     union_id?: string,
     *     user_id?: string
     * }
     * @throws FeishuApiException
     */
    public function fetchToken(string $code): array
    {
        $token = $this->client->requestOAuth('POST', self::TOKEN_PATH, [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->appId(),
            'client_secret' => $this->client->appSecret(),
            'code' => $code,
            'redirect_uri' => (string) $this->client->option('redirect_uri'),
        ]);

        return $this->normalizeToken($token);
    }

    /**
     * 刷新 user_access_token（每次会返回新的 refresh_token，旧的立即失效）.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token?: string,
     *     expires_in?: int,
     *     refresh_token_expires_in?: int,
     *     token_type?: string,
     *     scope?: string
     * }
     * @throws FeishuApiException
     */
    public function refreshToken(string $refreshToken): array
    {
        $token = $this->client->requestOAuth('POST', self::TOKEN_PATH, [
            'grant_type' => 'refresh_token',
            'client_id' => $this->client->appId(),
            'client_secret' => $this->client->appSecret(),
            'refresh_token' => $refreshToken,
        ]);

        return $this->normalizeToken($token);
    }

    /**
     * @param array<string, mixed> $token
     * @return array<string, mixed>
     * @throws FeishuApiException
     */
    protected function normalizeToken(array $token): array
    {
        // 新版成功时数据可能在顶层，也可能包在 data
        if (isset($token['data']) && is_array($token['data'])) {
            $token = array_merge($token, $token['data']);
        }

        if (empty($token['access_token'])) {
            throw new FeishuApiException('Missing access_token in OAuth response', 400, $token);
        }

        return $token;
    }
}
