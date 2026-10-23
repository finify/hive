<?php

namespace App\Enums;

enum PurchaseOrderStatus: string implements HasTone
{
    case Draft = 'draft';
    case PendingApproval = 'pending-approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case PartiallyInvoiced = 'partially-invoiced';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Sent => 'Sent',
            self::PartiallyInvoiced => 'Partially invoiced',
            self::Invoiced => 'Invoiced',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Draft, self::Invoiced => Tone::Neutral,
            self::PendingApproval => Tone::Warning,
            self::Approved => Tone::Positive,
            self::Sent, self::PartiallyInvoiced => Tone::Info,
            self::Cancelled => Tone::Negative,
        };
    }

    /**
     * Open POs still carry committed spend and block reconciliation.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::PendingApproval, self::Approved, self::Sent, self::PartiallyInvoiced], true);
    }
}
