<?php

declare(strict_types=1);

namespace Goletter\Docs\Config;

use Goletter\Docs\Contract\PlatformConfigRepositoryInterface;

/**
 * 用传入的数组作为平台配置（make(config) 专用，不读文件/库）.
 */
class ArrayPlatformConfigRepository implements PlatformConfigRepositoryInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        protected string $platform,
        protected array $options,
    ) {
    }

    public function get(string $platform, ?string $tenantId = null): array
    {
        unset($tenantId);

        if ($platform !== $this->platform) {
            return [];
        }

        return $this->options;
    }

    public function getOption(string $platform, string $key, mixed $default = null, ?string $tenantId = null): mixed
    {
        $all = $this->get($platform, $tenantId);

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }
}
