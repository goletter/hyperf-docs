<?php

declare(strict_types=1);

namespace Goletter\Docs\Contract;

interface PlatformFactoryInterface
{
    /**
     * 按名称创建平台实例；未传则使用默认平台.
     *
     * @param array<string, mixed>|null $config 传入则按该凭证新建独立实例（仅内置 google/tencent/feishu）
     */
    public function make(?string $name = null, ?array $config = null): PlatformInterface;

    /**
     * 是否已注册该平台.
     */
    public function has(string $name): bool;

    /**
     * 已注册平台名称列表.
     *
     * @return list<string>
     */
    public function available(): array;

    /**
     * 注册自定义平台（可扩展）.
     *
     * @param class-string<PlatformInterface> $class
     */
    public function register(string $name, string $class): void;

    /**
     * 默认平台名称.
     */
    public function getDefault(): string;
}
