<?php

declare(strict_types=1);

namespace Goletter\Docs\Support;

use Hyperf\Contract\ConfigInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * 记录各平台 HTTP / SDK 请求耗时（默认关闭，见 docs.logging）.
 */
class RequestLogger
{
    public function __construct(
        protected ConfigInterface $config,
        protected LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('docs.logging.enabled', false);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws Throwable
     */
    public function measure(string $platform, string $method, string $uri, callable $callback): mixed
    {
        if (! $this->enabled()) {
            return $callback();
        }

        $start = microtime(true);
        try {
            $result = $callback();
            $this->write($platform, $method, $uri, $this->elapsedMs($start), 'ok');

            return $result;
        } catch (Throwable $e) {
            $this->write(
                $platform,
                $method,
                $uri,
                $this->elapsedMs($start),
                'error',
                $e->getMessage(),
            );
            throw $e;
        }
    }

    protected function write(
        string $platform,
        string $method,
        string $uri,
        float $durationMs,
        string $status,
        ?string $message = null,
    ): void {
        $slowMs = (float) $this->config->get('docs.logging.slow_ms', 0);
        if ($slowMs > 0 && $durationMs < $slowMs && $status === 'ok') {
            return;
        }

        $context = [
            'platform' => $platform,
            'method' => strtoupper($method),
            'uri' => $uri,
            'duration_ms' => round($durationMs, 2),
            'status' => $status,
        ];
        if ($message !== null && $message !== '') {
            $context['message'] = mb_substr($message, 0, 500);
        }

        $line = sprintf(
            'docs.request platform=%s method=%s uri=%s duration_ms=%s status=%s',
            $context['platform'],
            $context['method'],
            $context['uri'],
            $context['duration_ms'],
            $context['status'],
        );

        if ($status === 'error') {
            $this->logger->warning($line, $context);
        } else {
            $this->logger->info($line, $context);
        }
    }

    protected function elapsedMs(float $start): float
    {
        return (microtime(true) - $start) * 1000;
    }
}
