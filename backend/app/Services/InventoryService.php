<?php

namespace App\Services;

use App\Enums\StockLocation;
use App\Enums\StockType;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns all mutations to physical stock. Every quantity change flows through
 * here so the stock_movements ledger stays the single source of truth for the
 * full movement history (Supplier → JHB Warehouse → Mike Boot → Arwyp).
 */
class InventoryService
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * Apply a transfer's line items to inventory: decrement the source holding
     * and create / increment the destination holding, logging each movement.
     * Idempotency is the caller's responsibility (only call once on completion).
     */
    public function applyTransfer(Transfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            $transfer->loadMissing('items');

            foreach ($transfer->items as $line) {
                $source = $line->inventory_item_id
                    ? InventoryItem::lockForUpdate()->find($line->inventory_item_id)
                    : $this->matchSourceHolding($transfer, $line->ref_code, $line->lot_number);

                if (! $source) {
                    throw new RuntimeException(
                        "No source holding found for {$line->ref_code} (lot {$line->lot_number}).");
                }

                if ($source->quantity < $line->quantity) {
                    throw new RuntimeException(
                        "Insufficient stock for {$line->ref_code}: have {$source->quantity}, need {$line->quantity}.");
                }

                // Decrement source.
                $source->decrement('quantity', $line->quantity);
                $this->maybeAlertLowStock($source);

                // Resolve destination attributes from the transfer routing.
                [$toLocation, $toHolderId, $toHospitalId, $stockType] = $this->resolveDestination($transfer);

                $destination = $this->findOrCreateDestination($source, [
                    'location'       => $toLocation,
                    'holder_user_id' => $toHolderId,
                    'hospital_id'    => $toHospitalId,
                    'stock_type'     => $stockType,
                ]);
                $destination->increment('quantity', $line->quantity);

                // Link the destination back to the line for traceability.
                if (! $line->inventory_item_id) {
                    $line->update(['inventory_item_id' => $destination->id]);
                }

                $this->log([
                    'inventory_item_id'   => $destination->id,
                    'ref_code'            => $line->ref_code,
                    'lot_number'          => $line->lot_number,
                    'quantity'            => $line->quantity,
                    'movement_type'       => 'transfer',
                    'from_location'       => $source->location?->value,
                    'to_location'         => $toLocation,
                    'from_holder_user_id' => $source->holder_user_id,
                    'to_holder_user_id'   => $toHolderId,
                    'from_hospital_id'    => $source->hospital_id,
                    'to_hospital_id'      => $toHospitalId,
                    'reference_type'      => Transfer::class,
                    'reference_id'        => $transfer->id,
                    'performed_by'        => $transfer->reviewed_by ?? $transfer->approved_by ?? $transfer->requested_by,
                    'notes'               => "Transfer {$transfer->reference}",
                ]);
            }
        });
    }

    /** Resolve [location, holderId, hospitalId, stockType] for the destination. */
    protected function resolveDestination(Transfer $transfer): array
    {
        if ($transfer->type->value === 'source_to_boot') {
            return [
                StockLocation::BootStock->value,
                $transfer->to_holder_user_id,
                null,
                StockType::Boot->value,
            ];
        }

        // boot_to_hospital
        return [
            StockLocation::HospitalStock->value,
            null,
            $transfer->hospital_id,
            $transfer->hospital_stock_type ?? StockType::Consignment->value,
        ];
    }

    protected function matchSourceHolding(Transfer $transfer, string $refCode, ?string $lot): ?InventoryItem
    {
        $query = InventoryItem::query()
            ->where('ref_code', $refCode)
            ->when($lot, fn ($q) => $q->where('lot_number', $lot))
            ->where('quantity', '>', 0)
            ->lockForUpdate();

        if ($transfer->from_holder_user_id) {
            $query->where('holder_user_id', $transfer->from_holder_user_id);
        } elseif ($transfer->from_location) {
            $query->where('location', $transfer->from_location);
        }

        return $query->orderBy('expiry_date')->first(); // FEFO: first-expiry-first-out
    }

    protected function findOrCreateDestination(InventoryItem $source, array $dest): InventoryItem
    {
        return InventoryItem::firstOrCreate(
            [
                'ref_code'       => $source->ref_code,
                'lot_number'     => $source->lot_number,
                'location'       => $dest['location'],
                'holder_user_id' => $dest['holder_user_id'],
                'hospital_id'    => $dest['hospital_id'],
                'stock_type'     => $dest['stock_type'],
            ],
            [
                'description' => $source->description,
                'expiry_date' => $source->expiry_date,
                'status'      => \App\Enums\StockStatus::Available->value,
                'unit_price'  => $source->unit_price,
                'uom'         => $source->uom,
                'quantity'    => 0,
            ],
        );
    }

    /**
     * Record a raw stock adjustment — manual edits ('adjustment') or stock-count
     * corrections ('count_correction'). Always writes a ledger entry so the
     * movement history stays the single source of truth.
     */
    public function adjust(
        InventoryItem $item,
        int $delta,
        string $reason,
        ?int $userId = null,
        $reference = null,
        string $movementType = 'adjustment',
    ): StockMovement {
        return DB::transaction(function () use ($item, $delta, $reason, $userId, $reference, $movementType) {
            $item->increment('quantity', $delta);

            $movement = $this->log([
                'inventory_item_id' => $item->id,
                'ref_code'          => $item->ref_code,
                'lot_number'        => $item->lot_number,
                'quantity'          => abs($delta),
                'movement_type'     => $movementType,
                'from_location'     => $delta < 0 ? $item->location?->value : null,
                'to_location'       => $delta > 0 ? $item->location?->value : null,
                'reference_type'    => $reference ? $reference::class : null,
                'reference_id'      => $reference?->id,
                'performed_by'      => $userId,
                'notes'             => $reason,
            ]);

            if ($delta < 0) {
                $this->maybeAlertLowStock($item);
            }

            return $movement;
        });
    }

    public function log(array $attributes): StockMovement
    {
        $attributes['moved_at'] ??= now();

        return StockMovement::create($attributes);
    }

    /**
     * Real-time low-stock alert (Module 11): if a holding has just dropped to or
     * below its threshold, notify staff immediately rather than waiting for the
     * daily batch check.
     */
    protected function maybeAlertLowStock(InventoryItem $item): void
    {
        $item->refresh();

        if ($item->quantity > 0 && $item->is_low_stock) {
            $this->notifications->inventoryAlert(
                $item,
                'low_stock',
                'low_stock',
                "{$item->ref_code} dropped to {$item->quantity} on hand"
                    .($item->min_threshold ? " (threshold {$item->min_threshold})" : '').'.',
            );
        }
    }
}
