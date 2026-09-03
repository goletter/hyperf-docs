<?php

declare(strict_types=1);

namespace Goletter\Docs\Google;

use Google\Client;
use Google\Exception as GoogleException;
use Google\Service\Exception as GoogleServiceException;
use Goletter\Docs\Google\Exceptions\GoogleApiException;
use Goletter\Docs\Google\Exceptions\GoogleTokenExpiredException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Hyperf\Contract\ConfigInterface;

class GoogleClient
{
    public function __construct(protected ConfigInterface $config)
    {
    }

    /**
     * 创建已配置 OAuth 凭证的 Google Client（用于授权流程）。
     */
    public function createOAuthClient(): Client
    {
        $client = $this->createBaseClient();

        $client->setClientId((string) $this->option('client_id'));
        $client->setClientSecret((string) $this->option('client_secret'));
        $client->setRedirectUri((string) $this->option('redirect_uri'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        foreach ($this->getScopes() as $scope) {
            $client->addScope($scope);
        }

        return $client;
    }

    /**
     * 创建已设置 access token 的 Google Client（用于 API 调用）。
     */
    public function createWithAccessToken(string $accessToken): Client
    {
        $client = $this->createBaseClient();
        $client->setAccessToken($accessToken);

        return $client;
    }

    /**
     * 执行 Google API 调用，统一转换为 GoogleApiException.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws GoogleApiException
     * @throws GoogleTokenExpiredException
     */
    public function request(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (GoogleApiException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            $this->handleServiceException($e);
        } catch (RequestException $e) {
            $this->handleRequestException($e);
        } catch (GoogleException $e) {
            throw new GoogleApiException($e->getMessage(), (int) $e->getCode(), [], $e);
        }
    }

    /**
     * 检查 OAuth token 响应，失败时抛出 GoogleApiException.
     *
     * @throws GoogleApiException
     * @throws GoogleTokenExpiredException
     */
    public function assertTokenResponse(array $token): array
    {
        if (! isset($token['error'])) {
            return $token;
        }

        $message = (string) ($token['error_description'] ?? $token['error'] ?? 'OAuth token error');
        $error = (string) ($token['error'] ?? '');
        $code = in_array($error, ['invalid_grant', 'invalid_token', 'expired_token'], true) ? 401 : 400;

        $response = [
            'error' => $error,
            'error_description' => $token['error_description'] ?? null,
            'token' => $token,
        ];

        if ($code === 401) {
            throw new GoogleTokenExpiredException($message, $code, $response);
        }

        throw new GoogleApiException($message, $code, $response);
    }

    protected function createBaseClient(): Client
    {
        $client = new Client();
        $client->setHttpClient(new GuzzleClient([
            'headers' => [
                'Accept-Encoding' => 'identity',
            ],
        ]));

        $apiKey = $this->option('api_key');
        if (is_string($apiKey) && $apiKey !== '') {
            $client->setDeveloperKey($apiKey);
        }

        return $client;
    }

    protected function option(string $key, mixed $default = null): mixed
    {
        return $this->config->get("docs.platforms.google.{$key}", $default);
    }

    /**
     * @return list<string>
     */
    protected function getScopes(): array
    {
        $scopes = $this->option('scopes', [
            'https://www.googleapis.com/auth/documents',
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/spreadsheets',
        ]);

        return is_array($scopes) ? array_values($scopes) : [];
    }

    /**
     * @throws GoogleApiException
     * @throws GoogleTokenExpiredException
     */
    protected function handleServiceException(GoogleServiceException $e): never
    {
        $code = (int) $e->getCode();
        $message = $this->normalizeMessage($e->getMessage());
        $errors = $e->getErrors() ?? [];
        $response = [
            'message' => $message,
            'errors' => $errors,
        ];

        if ($this->isTokenExpired($code, $errors, $message)) {
            throw new GoogleTokenExpiredException($message, $code, $response, $e);
        }

        throw new GoogleApiException($message, $code, $response, $e);
    }

    /**
     * @throws GoogleApiException
     * @throws GoogleTokenExpiredException
     */
    protected function handleRequestException(RequestException $e): never
    {
        $response = $e->getResponse();
        $status = $response ? $response->getStatusCode() : (int) $e->getCode();
        $raw = $response ? (string) $response->getBody() : $e->getMessage();
        $decoded = $raw === '' ? [] : (json_decode($raw, true) ?? []);
        $message = $this->normalizeMessage(
            is_array($decoded['error'] ?? null)
                ? (string) ($decoded['error']['message'] ?? $raw)
                : (is_string($decoded['error'] ?? null) ? $decoded['error'] : $raw)
        );

        $payload = [
            'message' => $message,
            'body' => $decoded !== [] ? $decoded : $raw,
        ];

        if ($status === 401 || $this->isTokenExpired($status, $decoded['error']['errors'] ?? [], $message)) {
            throw new GoogleTokenExpiredException($message, $status, $payload, $e);
        }

        throw new GoogleApiException($message, $status, $payload, $e);
    }

    protected function isTokenExpired(int $code, array $errors, string $message): bool
    {
        if ($code === 401) {
            return true;
        }

        $upper = strtoupper($message);
        if (str_contains($upper, 'UNAUTHENTICATED')
            || str_contains($upper, 'INVALID CREDENTIALS')
            || str_contains($upper, 'TOKEN EXPIRED')
            || str_contains($upper, 'ACCESS TOKEN')
        ) {
            return true;
        }

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }
            $reason = (string) ($error['reason'] ?? '');
            if (in_array($reason, ['authError', 'expired', 'invalidCredentials'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeMessage(string $message): string
    {
        if (str_starts_with($message, "\x1f\x8b")) {
            $decoded = gzdecode($message);
            if ($decoded !== false) {
                $message = $decoded;
            }
        }

        $json = json_decode($message, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            $error = $json['error'] ?? null;
            if (is_array($error) && isset($error['message'])) {
                return (string) $error['message'];
            }
            if (is_string($error)) {
                return $error;
            }
        }

        return $message;
    }
}
