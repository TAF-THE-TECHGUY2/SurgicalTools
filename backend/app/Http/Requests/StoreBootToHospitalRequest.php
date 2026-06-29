<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Transfer 2 — a rep's boot → a hospital. */
class StoreBootToHospitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transfer.create');
    }

    public function rules(): array
    {
        return [
            'hospital_id'          => ['required', 'exists:hospitals,id'],
            'doctor_id'            => ['nullable', 'exists:doctors,id'],
            'from_holder_user_id'  => ['nullable', 'exists:users,id'],
            // Hospital deliveries may only be consignment, bought or loan.
            'hospital_stock_type'  => ['required', Rule::in(config('surgical.hospital_stock_types'))],
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
