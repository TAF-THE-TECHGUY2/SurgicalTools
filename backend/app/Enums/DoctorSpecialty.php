<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DoctorSpecialty: string
{
    use HasOptions;

    case GeneralSurgeon = 'general_surgeon';
    case Gynaecologist  = 'gynaecologist';
    case Other          = 'other';
}
