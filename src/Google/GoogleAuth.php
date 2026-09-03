<?php

declare(strict_types=1);

namespace Goletter\Docs\Google;

use Google\Client;

class GoogleAuth
{
    public function __construct(protected GoogleClient $googleClient)
    {
    }

    public function getClient(): Client
    {
        return $this->googleClient->createOAuthClient();
    }

    /**
     * 生成授权 URL.
     */
    public function getAuthUrl(?string $state = null): string
    {
        return $this->googleClient->request(function () use ($state) {
            $client = $this->getClient();
            if ($state !== null && $state !== '') {
                $client->setState($state);
            }

            return $client->createAuthUrl();
        });
    }

    /**
     * 通过授权码换取访问令牌.
     */
    public function fetchToken(string $code): array
    {
        return $this->googleClient->request(function () use ($code) {
            $client = $this->getClient();
            $token = $client->authenticate($code);

            return $this->googleClient->assertTokenResponse(is_array($token) ? $token : []);
        });
    }

    /**
     * 使用刷新令牌刷新访问令牌.
     */
    public function refreshToken(string $refreshToken): array
    {
        return $this->googleClient->request(function () use ($refreshToken) {
            $client = $this->getClient();
            $token = $client->refreshToken($refreshToken);

            return $this->googleClient->assertTokenResponse(is_array($token) ? $token : []);
        });
    }
}
