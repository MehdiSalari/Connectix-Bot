<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Administrator role stored on the legacy `admins` table.
 */
enum AdminRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'مدیر',
            self::Editor => 'ویرایشگر',
        };
    }
}
