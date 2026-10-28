<?php

declare(strict_types=1);

namespace Goletter\Docs\Support;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Throwable;

class RequestLoggerFactory
{
    public function __invoke(ContainerInterface $container): RequestLogger
    {
        $config = $container->get(ConfigInterface::class);
        $logger = new NullLogger();

        if (class_exists(LoggerFactory::class) && $container->has(LoggerFactory::class)) {
            $name = (string) $config->get('docs.logging.name', 'docs');
            $group = (string) $config->get('docs.logging.group', 'default');
            try {
                $logger = $container->get(LoggerFactory::class)->get(
                    $name !== '' ? $name : 'docs',
                    $group !== '' ? $group : 'default'
                );
            } catch (Throwable) {
                // 未配置对应 channel 时退回 NullLogger，避免拖垮业务
            }
        }

        return new RequestLogger($config, $logger);
    }
}
