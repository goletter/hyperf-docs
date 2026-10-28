<?php

declare(strict_types=1);

namespace Goletter\Docs\Config;

use Goletter\Docs\Contract\PlatformConfigRepositoryInterface;
use Goletter\Docs\DocsContext;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\DbConnection\Db;
use RuntimeException;

/**
 * 从数据库读取平台配置，缺失字段回落到文件配置.
 *
 * 表约定见 publish/docs.php 中 docs.database.
 * 支持两种存法：
 * 1) JSON 列（columns.payload，默认 config）
 * 2) 平铺列（database.attributes，如 client_id / client_secret / redirect_uri）
 */
class DatabasePlatformConfigRepository implements PlatformConfigRepositoryInterface
{
    public function __construct(
        protected ConfigInterface $config,
        protected PlatformConfigRepositoryInterface $fallback,
    ) {
    }

    public function get(string $platform, ?string $tenantId = null): array
    {
        $tenantId ??= DocsContext::getTenantId();
        $cacheKey = $this->cacheKey($platform, $tenantId);

        if (Context::has($cacheKey)) {
            /** @var array<string, mixed> $cached */
            $cached = Context::get($cacheKey);

            return $cached;
        }

        $file = $this->fallback->get($platform, $tenantId);
        $db = $this->fetchFromDatabase($platform, $tenantId);
        $merged = array_replace($file, $db);
        Context::set($cacheKey, $merged);

        return $merged;
    }

    public function getOption(string $platform, string $key, mixed $default = null, ?string $tenantId = null): mixed
    {
        $all = $this->get($platform, $tenantId);

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchFromDatabase(string $platform, ?string $tenantId): array
    {
        if (! class_exists(Db::class)) {
            throw new RuntimeException(
                'docs.config_source=database 需要安装 hyperf/db-connection（及 hyperf/database）.'
            );
        }

        $connection = $this->config->get('docs.database.connection');
        $table = (string) $this->config->get('docs.database.table', 'docs_platform_configs');
        $platformCol = (string) $this->config->get('docs.database.columns.platform', 'platform');
        $tenantCol = (string) $this->config->get('docs.database.columns.tenant_id', 'tenant_id');
        $payloadCol = (string) $this->config->get('docs.database.columns.payload', 'config');

        $db = is_string($connection) && $connection !== ''
            ? Db::connection($connection)
            : Db::connection();

        $query = $db->table($table)->where($platformCol, $platform);

        if ($tenantId !== null && $tenantId !== '') {
            $query->where($tenantCol, $tenantId);
        } else {
            $query->where(static function ($q) use ($tenantCol) {
                $q->whereNull($tenantCol)->orWhere($tenantCol, '');
            });
        }

        $row = $query->first();
        if ($row === null) {
            return [];
        }

        $rowArr = $this->rowToArray($row);
        $fromPayload = $this->extractPayload($rowArr, $payloadCol);
        if ($fromPayload !== []) {
            return $fromPayload;
        }

        return $this->extractAttributes($rowArr);
    }

    /**
     * @param object|array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function rowToArray(object|array $row): array
    {
        if (is_array($row)) {
            return $row;
        }

        return get_object_vars($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function extractPayload(array $row, string $payloadCol): array
    {
        if ($payloadCol === '' || ! array_key_exists($payloadCol, $row)) {
            return [];
        }

        $payload = $row[$payloadCol];
        if (is_string($payload)) {
            $payload = trim($payload);
            if ($payload === '') {
                return [];
            }
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * 从平铺列组装配置；空字符串 / null 跳过，便于回落 file/.env.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function extractAttributes(array $row): array
    {
        /** @var list<string>|array<string, string> $attributes */
        $attributes = $this->config->get('docs.database.attributes', [
            'client_id',
            'client_secret',
            'redirect_uri',
            'app_id',
            'app_secret',
            'api_key',
            'drive_root_folder',
            'scopes',
        ]);

        $result = [];
        foreach ($attributes as $configKey => $column) {
            // list: ['client_id', ...] 或 map: ['client_id' => 'oauth_client_id']
            if (is_int($configKey)) {
                $configKey = $column;
            }
            if (! is_string($configKey) || $configKey === '' || ! is_string($column) || $column === '') {
                continue;
            }
            if (! array_key_exists($column, $row)) {
                continue;
            }
            $value = $row[$column];
            if ($value === null || $value === '') {
                continue;
            }
            $result[$configKey] = $value;
        }

        return $result;
    }

    protected function cacheKey(string $platform, ?string $tenantId): string
    {
        return 'docs.platform_config.' . $platform . '.' . ($tenantId ?? '_global');
    }
}
