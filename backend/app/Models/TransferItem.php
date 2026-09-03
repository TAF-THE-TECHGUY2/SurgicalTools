<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id', 'inventory_item_id', 'device_unit_id', 'ref_code',
        'description', 'serial_number', 'lot_number', 'quantity',
        'expiry_date', 'unit_price',
        'is_transfer_adjustment', 'adjustment_type', 'expected_lot_number',
    ];

    protected $casts = [
        'expiry_date'            => 'date',
        'unit_price'             => 'decimal:2',
        'is_transfer_adjustment' => 'boolean',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function deviceUnit(): BelongsTo
    {
        return $this->belongsTo(DeviceUnit::class);
    }
}
