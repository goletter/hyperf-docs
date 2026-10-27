<?php

declare(strict_types=1);

namespace Goletter\Docs\Contract;

/**
 * 平台凭证 / 选项配置源（文件或数据库）.
 */
interface PlatformConfigRepositoryInterface
{
    /**
     * @return array<string, mixed>
     */
    public function get(string $platform, ?string $tenantId = null): array;

    public function getOption(string $platform, string $key, mixed $default = null, ?string $tenantId = null): mixed;
}
