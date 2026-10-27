<?php

declare(strict_types=1);

namespace Goletter\Docs\Feishu;

use Goletter\Docs\Contract\PlatformConfigRepositoryInterface;
use Goletter\Docs\Feishu\Exceptions\FeishuApiException;
use Goletter\Docs\Feishu\Exceptions\FeishuTokenExpiredException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class FeishuClient
{
    public const BASE_URI = 'https://open.feishu.cn';

    public const ACCOUNTS_URI = 'https://accounts.feishu.cn';

    protected ?GuzzleClient $http = null;

    public function __construct(protected PlatformConfigRepositoryInterface $platformConfig)
    {
    }

    public function http(): GuzzleClient
    {
        return $this->http ??= new GuzzleClient([
            'base_uri' => self::BASE_URI,
            'timeout' => 30,
            'http_errors' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function authHeaders(string $accessToken): array
    {
        return [
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     * @throws FeishuApiException
     * @throws FeishuTokenExpiredException
     */
    public function request(
        string $method,
        string $uri,
        string $accessToken,
        ?array $json = null,
        array $query = [],
    ): array {
        $options = [
            'headers' => $this->authHeaders($accessToken),
            'query' => $query,
        ];
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->http()->request($method, $uri, $options);
        } catch (RequestException $e) {
            throw $this->fromRequestException($e);
        } catch (GuzzleException $e) {
            throw new FeishuApiException($e->getMessage(), (int) $e->getCode(), [], $e);
        }

        return $this->decodeResponse($response);
    }

    /**
     * OAuth 换 token（无需业务 Authorization）.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     * @throws FeishuApiException
     * @throws FeishuTokenExpiredException
     */
    public function requestOAuth(string $method, string $uri, array $json = []): array
    {
        try {
            $response = $this->http()->request($method, $uri, [
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Accept' => 'application/json',
                ],
                'json' => $json,
            ]);
        } catch (RequestException $e) {
            throw $this->fromRequestException($e);
        } catch (GuzzleException $e) {
            throw new FeishuApiException($e->getMessage(), (int) $e->getCode(), [], $e);
        }

        return $this->decodeResponse($response, true);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->platformConfig->getOption('feishu', $key, $default);
    }

    /**
     * 飞书开放平台凭证：优先 app_id / app_secret，兼容 client_id / client_secret.
     */
    public function appId(): string
    {
        return (string) ($this->option('app_id') ?: $this->option('client_id') ?: '');
    }

    public function appSecret(): string
    {
        return (string) ($this->option('app_secret') ?: $this->option('client_secret') ?: '');
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        $scopes = $this->option('scopes', [
            'sheets:spreadsheet',
            'drive:drive',
            'offline_access',
        ]);

        if (is_string($scopes)) {
            return array_values(array_filter(preg_split('/[\s,]+/', $scopes) ?: []));
        }

        return is_array($scopes) ? array_values(array_filter($scopes)) : [];
    }

    /**
     * @throws FeishuApiException
     * @throws FeishuTokenExpiredException
     */
    protected function decodeResponse(ResponseInterface $response, bool $expectBizCode = true): array
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = $raw === '' ? [] : (json_decode($raw, true) ?? []);
        if (! is_array($decoded)) {
            $decoded = ['body' => $raw];
        }

        $message = $this->extractMessage($decoded, $raw);
        $bizCode = (int) ($decoded['code'] ?? 0);

        if ($status === 401 || $this->isTokenError($status, $bizCode, $message)) {
            throw new FeishuTokenExpiredException(
                $message ?: 'Feishu token expired',
                $status ?: 401,
                $decoded
            );
        }

        if ($status >= 400) {
            throw new FeishuApiException($message ?: 'Feishu API error', $status, $decoded);
        }

        // OAuth v2 成功时可能没有 code 字段，或 code=0
        if ($expectBizCode && isset($decoded['code']) && (int) $decoded['code'] !== 0) {
            if ($this->isTokenError($status, (int) $decoded['code'], $message)) {
                throw new FeishuTokenExpiredException($message, (int) $decoded['code'], $decoded);
            }
            throw new FeishuApiException($message ?: 'Feishu business error', (int) $decoded['code'], $decoded);
        }

        // OAuth 错误形态：error / error_description
        if (! $expectBizCode && isset($decoded['error']) && is_string($decoded['error']) && $decoded['error'] !== '') {
            $err = (string) $decoded['error'];
            $code = in_array($err, ['invalid_grant', 'invalid_token', 'expired_token'], true) ? 401 : 400;
            throw new FeishuApiException(
                (string) ($decoded['error_description'] ?? $decoded['error']),
                $code,
                $decoded
            );
        }

        return $decoded;
    }

    protected function fromRequestException(RequestException $e): FeishuApiException
    {
        $response = $e->getResponse();
        if (! $response) {
            return new FeishuApiException($e->getMessage(), (int) $e->getCode(), [], $e);
        }

        try {
            $this->decodeResponse($response);
        } catch (FeishuApiException $apiException) {
            return $apiException;
        }

        return new FeishuApiException($e->getMessage(), $response->getStatusCode(), [], $e);
    }

    protected function isTokenError(int $httpStatus, int $bizCode, string $message): bool
    {
        if ($httpStatus === 401) {
            return true;
        }

        // 99991663/99991668 token 无效或过期；20001 鉴权失败
        if (in_array($bizCode, [99991663, 99991668, 99991661, 20001, 401], true)) {
            return true;
        }

        $upper = strtoupper($message);

        return str_contains($upper, 'TOKEN')
            && (str_contains($upper, 'EXPIRED')
                || str_contains($upper, 'INVALID')
                || str_contains($upper, '过期')
                || str_contains($upper, '无效'));
    }

    /**
     * @param array<string, mixed> $decoded
     */
    protected function extractMessage(array $decoded, string $fallback): string
    {
        foreach (['msg', 'message', 'error_description', 'error'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key]) && $decoded[$key] !== '') {
                return $decoded[$key];
            }
        }

        return $fallback !== '' ? $fallback : 'Feishu API error';
    }
}
