<?php

namespace App\Services;

use App\Enums\StockLocation;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Models\InventoryItem;
use App\Models\Transfer;
use App\Models\User;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drives the two-transfer workflow described in the spec.
 *
 * Transfer 1 (source → boot):
 *   draft → pending_approval → approved/awaiting_signature → signed → completed
 *   On completion: stock moves to the rep's boot, transfer note PDF generated,
 *   emails sent to office / stock controller / rep.
 *
 * Transfer 2 (boot → hospital):
 *   draft → pending_approval → awaiting_signature → signed →
 *   awaiting_admin_review → completed
 *   Hospital stock-controller signs, delivery note PDF generated + emailed,
 *   then admin review posts the movement to hospital inventory + audit trail.
 */
class TransferService
{
    public function __construct(
        protected InventoryService $inventory,
        protected PdfService $pdf,
        protected NotificationService $notifications,
    ) {}

    /** Transfer 1: source location → a rep's boot. */
    public function createSourceToBoot(array $data, User $requester): Transfer
    {
        return DB::transaction(function () use ($data, $requester) {
            $transfer = Transfer::create([
                'reference'           => ReferenceGenerator::next(Transfer::class, 'reference', 'TR1'),
                'type'                => TransferType::SourceToBoot->value,
                'status'              => TransferStatus::Draft->value,
                'from_location'       => $data['from_location'] ?? null,
                'from_holder_user_id' => $data['from_holder_user_id'] ?? null,
                'to_location'         => StockLocation::BootStock->value,
                'to_holder_user_id'   => $data['to_holder_user_id'], // the rep receiving into boot
                'requested_by'        => $requester->id,
                'notes'               => $data['notes'] ?? null,
            ]);

            $this->syncItems($transfer, $data['items'] ?? []);

            return $transfer->fresh('items');
        });
    }

    /** Transfer 2: a rep's boot → a hospital. */
    public function createBootToHospital(array $data, User $requester): Transfer
    {
        return DB::transaction(function () use ($data, $requester) {
            $transfer = Transfer::create([
                'reference'           => ReferenceGenerator::next(Transfer::class, 'reference', 'TR2'),
                'type'                => TransferType::BootToHospital->value,
                'status'              => TransferStatus::Draft->value,
                'from_location'       => StockLocation::BootStock->value,
                'from_holder_user_id' => $data['from_holder_user_id'] ?? $requester->id,
                'to_location'         => StockLocation::HospitalStock->value,
                'hospital_id'         => $data['hospital_id'],
                'doctor_id'           => $data['doctor_id'] ?? null,
                'hospital_stock_type' => $data['hospital_stock_type'],
                'requested_by'        => $requester->id,
                'notes'               => $data['notes'] ?? null,
            ]);

            $this->syncItems($transfer, $data['items'] ?? []);

            return $transfer->fresh('items');
        });
    }

    /** Move a draft into the approval queue and notify approvers. */
    public function submit(Transfer $transfer): Transfer
    {
        $this->assertStatus($transfer, [TransferStatus::Draft]);

        $transfer->update(['status' => TransferStatus::PendingApproval->value]);
        $this->notifications->transferAwaitingApproval($transfer);

        return $transfer;
    }

    /** Approve (source owner for T1, assigned rep/admin for T2). */
    public function approve(Transfer $transfer, User $approver, bool $override = false): Transfer
    {
        $this->assertStatus($transfer, [TransferStatus::PendingApproval]);

        $transfer->update([
            'status'         => TransferStatus::AwaitingSignature->value,
            'approved_by'    => $approver->id,
            'approved_at'    => now(),
            'admin_override' => $override,
        ]);

        $this->notifications->transferApproved($transfer);

        return $transfer;
    }

    public function reject(Transfer $transfer, User $user, ?string $reason = null): Transfer
    {
        $this->assertStatus($transfer, [
            TransferStatus::PendingApproval,
            TransferStatus::AwaitingAdminReview,
        ]);

        $transfer->update([
            'status'           => TransferStatus::Rejected->value,
            'rejected_by'      => $user->id,
            'rejected_at'      => now(),
            'rejection_reason' => $reason,
        ]);

        $this->notifications->transferRejected($transfer);

        return $transfer;
    }

    /**
     * Capture a digital signature. Advances the workflow:
     *  - Transfer 1: signed → apply movement to boot → generate transfer note
     *    → email → completed.
     *  - Transfer 2: signed → generate delivery note → email → awaiting admin
     *    review.
     */
    public function sign(Transfer $transfer, array $signature, User $user): Transfer
    {
        $this->assertStatus($transfer, [TransferStatus::AwaitingSignature]);

        $transfer->signatures()->create([
            'signer_name'       => $signature['signer_name'],
            'signer_role'       => $signature['signer_role'] ?? null,
            'signed_by_user_id' => $user->id,
            'signature_path'    => $signature['signature_path'],
            'ip_address'        => $signature['ip_address'] ?? null,
            'signed_at'         => now(),
        ]);

        $transfer->update(['status' => TransferStatus::Signed->value]);

        if ($transfer->type === TransferType::SourceToBoot) {
            return $this->complete($transfer, $user); // no admin review for Transfer 1
        }

        // Transfer 2 — generate delivery note, email it, then await admin review.
        $pdf = $this->pdf->generateDeliveryNote($transfer);
        $this->notifications->transferCompleted($transfer, $pdf); // emails delivery note
        $transfer->update(['status' => TransferStatus::AwaitingAdminReview->value]);

        return $transfer->fresh();
    }

    /** Admin final review for Transfer 2: posts the movement + completes. */
    public function adminReview(Transfer $transfer, User $admin): Transfer
    {
        $this->assertStatus($transfer, [TransferStatus::AwaitingAdminReview]);

        $transfer->update(['reviewed_by' => $admin->id, 'reviewed_at' => now()]);

        return $this->complete($transfer, $admin);
    }

    /** Post stock movement, generate the document, email it, mark complete. */
    protected function complete(Transfer $transfer, User $user): Transfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $this->inventory->applyTransfer($transfer);

            $transfer->update([
                'status'       => TransferStatus::Completed->value,
                'completed_at' => now(),
            ]);

            // Transfer 1 generates its note at completion; Transfer 2's delivery
            // note was already produced at signature time.
            if ($transfer->type === TransferType::SourceToBoot) {
                $pdf = $this->pdf->generateTransferNote($transfer);
                $this->notifications->transferCompleted($transfer, $pdf);
            }

            return $transfer->fresh(['items', 'documents', 'signatures']);
        });
    }

    /* -------------------------------------------------------------------- */
    /*  Internals                                                           */
    /* -------------------------------------------------------------------- */

    protected function syncItems(Transfer $transfer, array $items): void
    {
        foreach ($items as $row) {
            $source = ! empty($row['inventory_item_id'])
                ? InventoryItem::find($row['inventory_item_id'])
                : null;

            $transfer->items()->create([
                'inventory_item_id' => $source?->id,
                'ref_code'          => $row['ref_code'] ?? $source?->ref_code,
                'description'       => $row['description'] ?? $source?->description,
                'lot_number'        => $row['lot_number'] ?? $source?->lot_number,
                'quantity'          => $row['quantity'],
                'expiry_date'       => $row['expiry_date'] ?? $source?->expiry_date,
                'unit_price'        => $row['unit_price'] ?? $source?->unit_price,
            ]);
        }
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
