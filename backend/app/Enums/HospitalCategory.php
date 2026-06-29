<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum HospitalCategory: string
{
    use HasOptions;

    case Netcare    = 'netcare';
    case Life       = 'life';
    case Government = 'government';
    case Busamed    = 'busamed';
    case Private    = 'private';
}
