<?php

namespace App\Services;

use App\Enums\DeviceUnitStatus;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Models\DeviceUnit;
use App\Models\Location;
use App\Models\Transfer;
use App\Models\User;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unified transfer workflow (spec v2):
 *
 *   Request: pick FROM location → select individual device units (serial/lot/
 *   expiry) → pick TO location → digital signature + date → submit.
 *   Status: pending_approval. Selected units are flagged `pending_transfer`
 *   (still counted at the source — inventory only changes on approval).
 *
 *   Approve: units move to the destination, the ledger is written, a
 *   transfer/delivery-note PDF is generated + emailed, status → completed.
 *
 *   Reject: units revert to `available`; requester is notified (with reason).
 */
class TransferService
{
    public function __construct(
        protected InventoryService $inventory,
        protected PdfService $pdf,
        protected NotificationService $notifications,
    ) {}

    /**
     * Create a transfer request.
     * $data: from_location_id, to_location_id, unit_ids[], signature_path,
     *        signer_name, ip_address?, notes?
     */
    public function request(array $data, User $requester): Transfer
    {
        return DB::transaction(function () use ($data, $requester) {
            $from = Location::findOrFail($data['from_location_id']);
            $to = Location::findOrFail($data['to_location_id']);

            if ($from->id === $to->id) {
                throw ValidationException::withMessages([
                    'to_location_id' => 'Source and destination must be different locations.',
                ]);
            }

            // Lock and validate the selected units: they must all be available
            // at the source. A unit already in a pending transfer cannot be
            // requested twice.
            $unitIds = array_values(array_unique($data['unit_ids']));
            $units = DeviceUnit::with('stockItem')
                ->whereIn('id', $unitIds)
                ->lockForUpdate()
                ->get();

            if ($units->count() !== count($unitIds)) {
                throw ValidationException::withMessages(['unit_ids' => 'One or more selected devices no longer exist.']);
            }

            foreach ($units as $unit) {
                if ($unit->location_id !== $from->id) {
                    throw ValidationException::withMessages([
                        'unit_ids' => "Device {$unit->serial_number} is not located at {$from->name}.",
                    ]);
                }
                if ($unit->status !== DeviceUnitStatus::Available) {
                    throw ValidationException::withMessages([
                        'unit_ids' => 'Device '.($unit->serial_number ?? $unit->stockItem?->name)
                            ." is not available (status: {$unit->status->value}).",
                    ]);
                }
            }

            $transfer = Transfer::create([
                'reference'        => ReferenceGenerator::next(Transfer::class, 'reference', 'TR'),
                'type'             => TransferType::Standard->value,
                'status'           => TransferStatus::PendingApproval->value,
                'from_location_id' => $from->id,
                'to_location_id'   => $to->id,
                'hospital_id'      => $to->hospital_id, // convenience link for hospital deliveries
                'requested_by'     => $requester->id,
                'notes'            => $data['notes'] ?? null,
            ]);

            foreach ($units as $unit) {
                $transfer->items()->create([
                    'device_unit_id' => $unit->id,
                    'ref_code'       => $unit->stockItem?->item_code ?? $unit->stockItem?->catalogue_number ?? (string) $unit->stock_item_id,
                    'description'    => $unit->stockItem?->name,
                    'serial_number'  => $unit->serial_number,
                    'lot_number'     => $unit->lot_number,
                    'expiry_date'    => $unit->expiry_date,
                    'quantity'       => 1,
                    'unit_price'     => $unit->stockItem?->unit_price,
                ]);

                // Reserve: still at the source (inventory unchanged) but cannot
                // be double-requested.
                $unit->update(['status' => DeviceUnitStatus::PendingTransfer->value]);
            }

            // Signature is mandatory at request time (spec step 7).
            $transfer->signatures()->create([
                'signer_name'       => $data['signer_name'],
                'signer_role'       => 'requester',
                'signed_by_user_id' => $requester->id,
                'signature_path'    => $data['signature_path'],
                'ip_address'        => $data['ip_address'] ?? null,
                'signed_at'         => now(),
            ]);

            $this->notifications->transferAwaitingApproval($transfer);

            return $transfer->fresh(['items', 'fromLocation', 'toLocation', 'signatures']);
        });
    }

    /** Approve: move the units, write the ledger, PDF + email, complete. */
    public function approve(Transfer $transfer, User $approver, bool $override = false): Transfer
    {
        $this->assertStatus($transfer, [TransferStatus::PendingApproval]);

        return DB::transaction(function () use ($transfer, $approver, $override) {
            $transfer->loadMissing(['items.deviceUnit.stockItem', 'fromLocation', 'toLocation']);

            foreach ($transfer->items as $line) {
                $unit = $line->deviceUnit;
                if (! $unit) {
                    continue;
                }

                $unit->update([
                    'location_id' => $transfer->to_location_id,
                    'status'      => DeviceUnitStatus::Available->value,
                ]);

                $this->inventory->log([
                    'device_unit_id'   => $unit->id,
                    'ref_code'         => $line->ref_code,
                    'lot_number'       => $line->lot_number,
                    'quantity'         => 1,
                    'movement_type'    => 'transfer',
                    'from_location'    => $transfer->fromLocation?->name,
                    'to_location'      => $transfer->toLocation?->name,
                    'from_location_id' => $transfer->from_location_id,
                    'to_location_id'   => $transfer->to_location_id,
                    'to_hospital_id'   => $transfer->toLocation?->hospital_id,
                    'reference_type'   => Transfer::class,
                    'reference_id'     => $transfer->id,
                    'performed_by'     => $approver->id,
                    'notes'            => "Transfer {$transfer->reference}"
                        .($unit->serial_number ? " · SN {$unit->serial_number}" : ''),
                ]);
            }

            $transfer->update([
                'status'         => TransferStatus::Completed->value,
                'approved_by'    => $approver->id,
                'approved_at'    => now(),
                'admin_override' => $override,
                'completed_at'   => now(),
            ]);

            // Low-stock check on the items that just left the source location.
            foreach ($transfer->items->pluck('deviceUnit.stockItem')->filter()->unique('id') as $stockItem) {
                $this->inventory->maybeAlertLowStock($stockItem, $transfer->fromLocation);
            }

            // Delivery note when the destination is a hospital; transfer note otherwise.
            $pdf = $transfer->toLocation?->type === 'hospital'
                ? $this->pdf->generateDeliveryNote($transfer)
                : $this->pdf->generateTransferNote($transfer);

            $this->notifications->transferCompleted($transfer, $pdf);

            return $transfer->fresh(['items', 'documents', 'signatures', 'fromLocation', 'toLocation']);
        });
    }

    /** Reject: release the reserved units back to the source. */
    public function reject(Transfer $transfer, User $user, ?string $reason = null): Transfer
    {
        $this->assertStatus($transfer, [TransferStatus::PendingApproval]);

        return DB::transaction(function () use ($transfer, $user, $reason) {
            $transfer->loadMissing('items.deviceUnit');

            foreach ($transfer->items as $line) {
                $line->deviceUnit?->update(['status' => DeviceUnitStatus::Available->value]);
            }

            $transfer->update([
                'status'           => TransferStatus::Rejected->value,
                'rejected_by'      => $user->id,
                'rejected_at'      => now(),
                'rejection_reason' => $reason,
            ]);

            $this->notifications->transferRejected($transfer);

            return $transfer->fresh();
        });
    }

    protected function assertStatus(Transfer $transfer, array $allowed): void
    {
        $allowedValues = array_map(fn (TransferStatus $s) => $s->value, $allowed);

        if (! in_array($transfer->status->value, $allowedValues, true)) {
            throw ValidationException::withMessages([
                'status' => "Action not allowed while transfer is '{$transfer->status->value}'.",
            ]);
        }
    }
}
