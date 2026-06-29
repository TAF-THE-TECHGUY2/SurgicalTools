<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TransferStatus: string
{
    use HasOptions;

    case Draft               = 'draft';
    case PendingApproval     = 'pending_approval';
    case Approved            = 'approved';
    case AwaitingSignature   = 'awaiting_signature';
    case Signed              = 'signed';
    case AwaitingAdminReview = 'awaiting_admin_review';
    case Completed           = 'completed';
    case Rejected            = 'rejected';

    /** Statuses that still count as "open" work in queues. */
    public static function open(): array
    {
        return [
            self::PendingApproval->value,
            self::Approved->value,
            self::AwaitingSignature->value,
            self::Signed->value,
            self::AwaitingAdminReview->value,
        ];
    }
}
