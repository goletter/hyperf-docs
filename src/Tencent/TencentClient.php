<?php

declare(strict_types=1);

namespace Goletter\Docs\Tencent;

use Goletter\Docs\Tencent\Exceptions\TencentApiException;
use Goletter\Docs\Tencent\Exceptions\TencentTokenExpiredException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Hyperf\Contract\ConfigInterface;
use Psr\Http\Message\ResponseInterface;

class TencentClient
{
    public const BASE_URI = 'https://docs.qq.com';

    protected ?GuzzleClient $http = null;

    public function __construct(protected ConfigInterface $config)
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
    public function authHeaders(string $accessToken, string $openId): array
    {
        return [
            'Access-Token' => $accessToken,
            'Client-Id' => (string) $this->option('client_id'),
            'Open-Id' => $openId,
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $json
     * @param array<string, mixed>|null $form
     * @return array<string, mixed>
     * @throws TencentApiException
     * @throws TencentTokenExpiredException
     */
    public function request(
        string $method,
        string $uri,
        string $accessToken,
        string $openId,
        ?array $json = null,
        ?array $form = null,
        array $query = [],
    ): array {
        $options = [
            'headers' => $this->authHeaders($accessToken, $openId),
            'query' => $query,
        ];

        if ($json !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['json'] = $json;
        } elseif ($form !== null) {
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            $options['form_params'] = $form;
        }

        try {
            $response = $this->http()->request($method, $uri, $options);
        } catch (RequestException $e) {
            throw $this->fromRequestException($e);
        } catch (GuzzleException $e) {
            throw new TencentApiException($e->getMessage(), (int) $e->getCode(), [], $e);
        }

        return $this->decodeResponse($response);
    }

    /**
     * OAuth / 无 Open-Id 头的请求（换 token 等）.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     * @throws TencentApiException
     * @throws TencentTokenExpiredException
     */
    public function requestOAuth(string $method, string $uri, array $query = []): array
    {
        try {
            $response = $this->http()->request($method, $uri, [
                'headers' => ['Accept' => 'application/json'],
                'query' => $query,
            ]);
        } catch (RequestException $e) {
            throw $this->fromRequestException($e);
        } catch (GuzzleException $e) {
            throw new TencentApiException($e->getMessage(), (int) $e->getCode(), [], $e);
        }

        return $this->decodeResponse($response, false);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->config->get("docs.platforms.tencent.{$key}", $default);
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        $scopes = $this->option('scopes', ['all']);

        if (is_string($scopes)) {
            return array_values(array_filter(explode(',', $scopes)));
        }

        return is_array($scopes) ? array_values($scopes) : ['all'];
    }

    /**
     * @throws TencentApiException
     * @throws TencentTokenExpiredException
     */
    protected function decodeResponse(ResponseInterface $response, bool $expectBizRet = true): array
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = $raw === '' ? [] : (json_decode($raw, true) ?? []);

        if (! is_array($decoded)) {
            $decoded = ['body' => $raw];
        }

        $message = $this->extractMessage($decoded, $raw);
        $bizCode = (int) ($decoded['ret'] ?? $decoded['code'] ?? 0);

        if ($status === 401 || $this->isTokenError($status, $bizCode, $message)) {
            throw new TencentTokenExpiredException($message ?: 'Tencent Docs token expired', $status ?: 401, $decoded);
        }

        if ($status >= 400) {
            throw new TencentApiException($message ?: 'Tencent Docs API error', $status, $decoded);
        }

        if ($expectBizRet && isset($decoded['ret']) && (int) $decoded['ret'] !== 0) {
            if ($this->isTokenError($status, (int) $decoded['ret'], $message)) {
                throw new TencentTokenExpiredException($message, (int) $decoded['ret'], $decoded);
            }
            throw new TencentApiException($message ?: 'Tencent Docs business error', (int) $decoded['ret'], $decoded);
        }

        // spreadsheet v3 部分接口用 code 而非 ret
        if ($expectBizRet && isset($decoded['code']) && (int) $decoded['code'] !== 0 && ! isset($decoded['ret'])) {
            throw new TencentApiException($message ?: 'Tencent Docs business error', (int) $decoded['code'], $decoded);
        }

        return $decoded;
    }

    protected function fromRequestException(RequestException $e): TencentApiException
    {
        $response = $e->getResponse();
        if (! $response) {
            return new TencentApiException($e->getMessage(), (int) $e->getCode(), [], $e);
        }

        try {
            $this->decodeResponse($response);
        } catch (TencentApiException $apiException) {
            return $apiException;
        }

        return new TencentApiException($e->getMessage(), $response->getStatusCode(), [], $e);
    }

    protected function isTokenError(int $httpStatus, int $bizCode, string $message): bool
    {
        if ($httpStatus === 401) {
            return true;
        }

        // 常见鉴权业务码：10313 Access-Token 空、37019 Token 校验失败
        if (in_array($bizCode, [10313, 37019, 400006], true)) {
            return true;
        }

        $upper = strtoupper($message);

        return str_contains($upper, 'TOKEN')
            && (str_contains($upper, 'EXPIRED')
                || str_contains($upper, 'INVALID')
                || str_contains($upper, '校验失败')
                || str_contains($upper, '过期'));
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

        return $fallback !== '' ? $fallback : 'Tencent Docs API error';
    }
}
