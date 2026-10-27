<?php

declare(strict_types=1);

namespace Goletter\Docs;

use Hyperf\Context\Context;

/**
 * 协程上下文：多租户读库配置时设置 tenant_id.
 */
final class DocsContext
{
    public const TENANT_ID_KEY = 'docs.tenant_id';

    public static function setTenantId(?string $tenantId): void
    {
        if ($tenantId === null || $tenantId === '') {
            Context::set(self::TENANT_ID_KEY, null);

            return;
        }

        Context::set(self::TENANT_ID_KEY, $tenantId);
    }

    public static function getTenantId(): ?string
    {
        $id = Context::get(self::TENANT_ID_KEY);

        return is_string($id) && $id !== '' ? $id : null;
    }
}
