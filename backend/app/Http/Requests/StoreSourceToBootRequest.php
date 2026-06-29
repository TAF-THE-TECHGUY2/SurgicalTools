<?php

namespace App\Http\Requests;

use App\Enums\StockLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Transfer 1 — source location → a rep's boot. */
class StoreSourceToBootRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transfer.create');
    }

    public function rules(): array
    {
        return [
            'from_location'        => ['nullable', Rule::in(StockLocation::values())],
            'from_holder_user_id'  => ['nullable', 'exists:users,id'],
            'to_holder_user_id'    => ['required', 'exists:users,id'],
            'notes'                => ['nullable', 'string', 'max:2000'],
            'submit'               => ['nullable', 'boolean'],

            'items'                       => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id'   => ['nullable', 'exists:inventory_items,id'],
            'items.*.ref_code'            => ['required_without:items.*.inventory_item_id', 'string'],
            'items.*.description'         => ['nullable', 'string'],
            'items.*.lot_number'          => ['nullable', 'string'],
            'items.*.quantity'            => ['required', 'integer', 'min:1'],
            'items.*.expiry_date'         => ['nullable', 'date'],
            'items.*.unit_price'          => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
