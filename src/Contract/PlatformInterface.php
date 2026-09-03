<?php

declare(strict_types=1);

namespace Goletter\Docs\Contract;

interface PlatformInterface
{
    public function name(): string;

    public function auth(): AuthInterface;

    public function sheets(): SheetsInterface;
}
