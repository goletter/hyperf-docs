<?php

declare(strict_types=1);

namespace Goletter\Docs;

use Goletter\Docs\Contract\AuthInterface;
use Goletter\Docs\Contract\PlatformFactoryInterface;
use Goletter\Docs\Contract\PlatformInterface;
use Goletter\Docs\Contract\SheetsInterface;

/**
 * 文档平台门面：基于工厂创建 Google / 腾讯等平台.
 *
 * @example
 * $docs->platform('google')->auth()->getAuthUrl();
 * $docs->platform('tencent')->sheets()->createSpreadsheet($token, '报表');
 * $docs->factory()->make('google');
 */
class DocsManager
{
    public function __construct(
        protected PlatformFactoryInterface $factory,
    ) {
    }

    public function factory(): PlatformFactoryInterface
    {
        return $this->factory;
    }

    public function platform(?string $name = null): PlatformInterface
    {
        return $this->factory->make($name);
    }

    public function auth(?string $platform = null): AuthInterface
    {
        return $this->platform($platform)->auth();
    }

    public function sheets(?string $platform = null): SheetsInterface
    {
        return $this->platform($platform)->sheets();
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return $this->factory->available();
    }

    public function getDefaultPlatform(): string
    {
        return $this->factory->getDefault();
    }
}
