<?php

namespace App\Services;

use App\Enums\DeviceUnitStatus;
use App\Enums\StockCountAdjustmentType;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Models\DeviceUnit;
use App\Models\HospitalContact;
use App\Models\Location;
use App\Models\StockItem;
use App\Models\Transfer;
use App\Models\User;
use App\Support\ReferenceGenerator;
use App\Support\SignatureStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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
     *
     * $data: from_location_id, to_location_id, unit_ids[], signature_path,
     *        signer_name, ip_address?, notes?, invoice_reference?,
     *        contact_person_name?, delivery_address?, transfer_date?,
     *        scanned_adjustments?
     *
     * `scanned_adjustments` carries items a scan turned up that the source's
     * authorised list does not hold — [{ref_code, description?, lot_number?,
     * expiry_date?, expected_lot_number?, adjustment_type?, quantity?}]. These
     * become flagged lines rather than a rejected request: the stock is
     * physically in the box either way, and refusing the voucher would just
     * push the discrepancy back onto paper.
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

            $units = $this->lockRequestedUnits($data['unit_ids'] ?? [], $from);

            if ($units->isEmpty() && empty($data['scanned_adjustments'])) {
                throw ValidationException::withMessages([
                    'unit_ids' => 'Select or scan at least one device to transfer.',
                ]);
            }

            $contact = $this->hospitalContact($to);

            $transfer = Transfer::create([
                'reference'      => ReferenceGenerator::next(Transfer::class, 'reference', 'TR'),
                'voucher_number' => ReferenceGenerator::nextSerial(
                    Transfer::class, 'voucher_number', (int) config('surgical.voucher.start_number', 130119),
                ),
                'type'             => TransferType::Standard->value,
                'status'           => TransferStatus::PendingApproval->value,
                'from_location_id' => $from->id,
                'to_location_id'   => $to->id,
                'hospital_id'      => $to->hospital_id, // convenience link for hospital deliveries
                'requested_by'     => $requester->id,
                'notes'            => $data['notes'] ?? null,

                // Voucher header. Address and contact are snapshotted from the
                // hospital now, so editing the hospital later cannot rewrite
                // where a past delivery went.
                'transfer_date'       => $data['transfer_date'] ?? now()->toDateString(),
                'invoice_reference'   => $data['invoice_reference'] ?? null,
                'delivery_address'    => $data['delivery_address'] ?? $to->hospital?->address,
                'contact_person_name' => $data['contact_person_name'] ?? $contact?->name,
            ]);

            foreach ($units as $unit) {
                $transfer->items()->create([
                    'device_unit_id' => $unit->id,
                    'ref_code'       => $unit->stockItem?->catalogue_number
                        ?? $unit->stockItem?->item_code
                        ?? (string) $unit->stock_item_id,
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

            $this->addScannedAdjustments($transfer, $data['scanned_adjustments'] ?? []);

            // The requester signs at request time (spec step 7). The recipient
            // signs separately at handover — see signDelivery().
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

    /**
     * Lock the selected units and check they can actually move.
     *
     * A unit that is missing, or already reserved by another pending transfer,
     * is still a hard error: that is a genuine conflict between two requests,
     * not a discrepancy between paperwork and a box. Only stock the dispatch
     * list never authorised becomes an adjustment line.
     *
     * @param  array<int, int>  $unitIds
     * @return \Illuminate\Support\Collection<int, DeviceUnit>
     */
    protected function lockRequestedUnits(array $unitIds, Location $from)
    {
        $unitIds = array_values(array_unique($unitIds));

        if ($unitIds === []) {
            return collect();
        }

        $units = DeviceUnit::with('stockItem')
            ->whereIn('id', $unitIds)
            ->lockForUpdate()
            ->get();

        if ($units->count() !== count($unitIds)) {
            throw ValidationException::withMessages([
                'unit_ids' => 'One or more selected devices no longer exist.',
            ]);
        }

        foreach ($units as $unit) {
            if ($unit->location_id !== $from->id) {
                throw ValidationException::withMessages([
                    'unit_ids' => 'Device '.($unit->serial_number ?? $unit->stockItem?->name ?? $unit->id)
                        ." is not located at {$from->name}.",
                ]);
            }
            if ($unit->status !== DeviceUnitStatus::Available) {
                throw ValidationException::withMessages([
                    'unit_ids' => 'Device '.($unit->serial_number ?? $unit->stockItem?->name)
                        ." is not available (status: {$unit->status->value}).",
                ]);
            }
        }

        return $units;
    }

    /**
     * Push scanned off-list items onto the voucher as flagged lines and alert
     * admin operations. Per the voucher spec, the row highlights orange in the
     * grid and fires an immediate warning; the line carries no device unit, so
     * approval moves no stock for it.
     *
     * @param  array<int, array<string, mixed>>  $adjustments
     */
    protected function addScannedAdjustments(Transfer $transfer, array $adjustments): void
    {
        foreach ($adjustments as $row) {
            $refCode = trim((string) ($row['ref_code'] ?? ''));

            if ($refCode === '') {
                continue;
            }

            $item = StockItem::where('catalogue_number', $refCode)->first()
                ?? StockItem::where('item_code', $refCode)->first();

            $type = $row['adjustment_type']
                ?? ($item ? StockCountAdjustmentType::LotMismatch->value : StockCountAdjustmentType::UnlistedItem->value);

            $line = $transfer->items()->create([
                'ref_code'               => $refCode,
                'description'            => $row['description'] ?? $item?->name,
                'lot_number'             => $row['lot_number'] ?? null,
                'expiry_date'            => $row['expiry_date'] ?? null,
                'quantity'               => max(1, (int) ($row['quantity'] ?? 1)),
                'unit_price'             => $item?->unit_price,
                'is_transfer_adjustment' => true,
                'adjustment_type'        => $type,
                // Only a lot mismatch has an expected lot. An item that isn't
                // on the dispatch list has nothing it was expected instead of,
                // and printing one would contradict the flag beside it.
                'expected_lot_number'    => $type === StockCountAdjustmentType::UnlistedItem->value
                    ? null
                    : ($row['expected_lot_number'] ?? null),
            ]);

            $this->notifications->transferDiscrepancy($line->fresh());
        }
    }

    /** The hospital contact whose name goes on the voucher's CONTACT PERSON row. */
    protected function hospitalContact(Location $to): ?HospitalContact
    {
        if (! $to->hospital_id) {
            return null;
        }

        return HospitalContact::where('hospital_id', $to->hospital_id)
            ->orderByDesc('is_primary')
            ->orderByRaw("CASE WHEN role LIKE '%stock%' THEN 0 ELSE 1 END")
            ->first();
    }

    /**
     * Capture the recipient's signature at handover — the paper voucher's
     * NAME OF RECIPIENT / SIGNATURE / DATE DELIVERED block.
     *
     * Per the voucher spec §3 this is the submission lock: it is refused
     * unless the recipient fields are structurally valid and the signature
     * payload actually decodes.
     */
    public function signDelivery(Transfer $transfer, array $data, User $user): Transfer
    {
        $recipient = trim((string) ($data['recipient_name'] ?? ''));

        if ($recipient === '') {
            throw ValidationException::withMessages([
                'recipient_name' => 'The recipient\'s name is required before the voucher can be signed off.',
            ]);
        }

        // storeBase64 throws on an undecodable payload, which is exactly the
        // "signature payload successfully generated" condition in the spec.
        try {
            $path = SignatureStorage::storeBase64($data['signature'] ?? '', "delivery-{$transfer->id}");
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'signature' => 'The signature could not be read. Please sign again.',
            ]);
        }

        return DB::transaction(function () use ($transfer, $data, $user, $recipient, $path) {
            $signedAt = isset($data['delivery_timestamp'])
                ? Carbon::parse($data['delivery_timestamp'])
                : now();

            $transfer->signatures()->create([
                'signer_name'       => $recipient,
                'signer_role'       => 'recipient',
                'signed_by_user_id' => $user->id,
                'signature_path'    => $path,
                'ip_address'        => $data['ip_address'] ?? null,
                'signed_at'         => $signedAt,
            ]);

            $transfer->update([
                'recipient_name'     => $recipient,
                'delivery_timestamp' => $signedAt,
                'invoice_reference'  => $data['invoice_reference'] ?? $transfer->invoice_reference,
            ]);

            return $transfer->fresh(['items', 'signatures', 'fromLocation', 'toLocation']);
        });
    }

    /** Has the recipient signed for this delivery? */
    public function hasRecipientSignature(Transfer $transfer): bool
    {
        return $transfer->signatures()->where('signer_role', 'recipient')->exists();
    }

    /** Approve: move the units, write the ledger, PDF + email, complete. */
    public function approve(Transfer $transfer, User $approver, bool $override = false): Transfer
    {
        $this->assertStatus($transfer, [TransferStatus::PendingApproval]);

        // A hospital delivery is not complete until someone at the hospital
        // has signed for it — the paper voucher's whole purpose. An admin
        // override exists for the case where the signed pad exists on paper
        // and the digital capture was missed.
        if ($transfer->toLocation?->type === 'hospital'
            && ! $override
            && ! $this->hasRecipientSignature($transfer)) {
            throw ValidationException::withMessages([
                'status' => 'This delivery has no recipient signature yet. Capture the signature at handover, or approve with an admin override.',
            ]);
        }

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
