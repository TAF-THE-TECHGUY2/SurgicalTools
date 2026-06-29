<?php

namespace App\Enums\Concerns;

trait HasOptions
{
    /** All backing values, e.g. ['available','reserved',...]. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Human label for the case (override per-enum for custom wording). */
    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /** [{value, label}] — convenient for API/select inputs. */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
