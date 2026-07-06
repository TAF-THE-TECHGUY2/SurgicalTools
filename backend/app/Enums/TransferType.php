<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TransferType: string
{
    use HasOptions;

    case Standard       = 'standard';          // unified location → location transfer
    case SourceToBoot   = 'source_to_boot';    // legacy (Transfer 1)
    case BootToHospital = 'boot_to_hospital';  // legacy (Transfer 2)

    public function label(): string
    {
        return match ($this) {
            self::Standard       => 'Transfer',
            self::SourceToBoot   => 'Source → Boot',
            self::BootToHospital => 'Boot → Hospital',
        };
    }
}
