<?php

declare(strict_types=1);

namespace Goletter\Docs\Config;

use Goletter\Docs\Contract\PlatformConfigRepositoryInterface;
use Hyperf\Contract\ConfigInterface;

/**
 * 从 config/autoload/docs.php（docs.platforms.*）读取平台配置.
 */
class FilePlatformConfigRepository implements PlatformConfigRepositoryInterface
{
    public function __construct(protected ConfigInterface $config)
    {
    }

    public function get(string $platform, ?string $tenantId = null): array
    {
        unset($tenantId);

        $value = $this->config->get("docs.platforms.{$platform}", []);

        return is_array($value) ? $value : [];
    }

    public function getOption(string $platform, string $key, mixed $default = null, ?string $tenantId = null): mixed
    {
        $all = $this->get($platform, $tenantId);

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }
}
