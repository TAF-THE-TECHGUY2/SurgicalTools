<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TransferType: string
{
    use HasOptions;

    case SourceToBoot   = 'source_to_boot';   // Transfer 1
    case BootToHospital = 'boot_to_hospital'; // Transfer 2

    public function label(): string
    {
        return match ($this) {
            self::SourceToBoot   => 'Source → Boot',
            self::BootToHospital => 'Boot → Hospital',
        };
    }
}
