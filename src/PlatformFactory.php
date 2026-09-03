<?php

declare(strict_types=1);

namespace Goletter\Docs;

use Goletter\Docs\Contract\PlatformFactoryInterface;
use Goletter\Docs\Contract\PlatformInterface;
use Goletter\Docs\Platform\GooglePlatform;
use Goletter\Docs\Platform\TencentPlatform;
use Hyperf\Contract\ConfigInterface;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * 文档平台工厂：根据配置 / 名称创建 Google、Tencent 等 Platform.
 *
 * 驱动来源：
 * 1. 内置 google / tencent
 * 2. config docs.platforms 中的 key（需有对应 class，见 platforms_map 或内置）
 * 3. config docs.platforms_map 自定义 class 映射（可覆盖内置）
 */
class PlatformFactory implements PlatformFactoryInterface
{
    /**
     * @var array<string, class-string<PlatformInterface>>
     */
    protected array $drivers = [];

    /**
     * @var array<string, class-string<PlatformInterface>>
     */
    protected array $builtins = [
        GooglePlatform::NAME => GooglePlatform::class,
        TencentPlatform::NAME => TencentPlatform::class,
    ];

    public function __construct(
        protected ContainerInterface $container,
        protected ConfigInterface $config,
    ) {
        $this->bootDrivers();
    }

    public function make(?string $name = null): PlatformInterface
    {
        $name = $name ?: $this->getDefault();

        if (! $this->has($name)) {
            throw new InvalidArgumentException(sprintf(
                'Docs platform [%s] is not supported. Available: %s',
                $name,
                implode(', ', $this->available())
            ));
        }

        $platform = $this->container->get($this->drivers[$name]);

        if (! $platform instanceof PlatformInterface) {
            throw new InvalidArgumentException(sprintf(
                'Docs platform [%s] must implement %s.',
                $name,
                PlatformInterface::class
            ));
        }

        return $platform;
    }

    public function has(string $name): bool
    {
        return isset($this->drivers[$name]);
    }

    public function available(): array
    {
        return array_keys($this->drivers);
    }

    public function register(string $name, string $class): void
    {
        if (! is_subclass_of($class, PlatformInterface::class)) {
            throw new InvalidArgumentException(sprintf(
                'Platform class [%s] must implement %s.',
                $class,
                PlatformInterface::class
            ));
        }

        $this->drivers[$name] = $class;
    }

    public function getDefault(): string
    {
        $default = (string) $this->config->get('docs.default', GooglePlatform::NAME);

        if ($this->has($default)) {
            return $default;
        }

        $available = $this->available();
        if ($available === []) {
            throw new InvalidArgumentException('No docs platform is registered.');
        }

        return $available[0];
    }

    protected function bootDrivers(): void
    {
        $this->drivers = $this->builtins;

        $platforms = $this->config->get('docs.platforms', []);
        if (is_array($platforms)) {
            foreach (array_keys($platforms) as $name) {
                if (! is_string($name) || $name === '') {
                    continue;
                }
                if (isset($this->drivers[$name])) {
                    continue;
                }
                // platforms 里配置了但没有 class 映射的，跳过（等 platforms_map 补齐）
            }
        }

        $custom = $this->config->get('docs.platforms_map', []);
        if (is_array($custom)) {
            foreach ($custom as $name => $class) {
                if (is_string($name) && is_string($class) && $class !== '') {
                    $this->register($name, $class);
                }
            }
        }
    }
}
