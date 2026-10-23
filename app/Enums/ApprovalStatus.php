<?php

namespace App\Enums;

enum ApprovalStatus: string implements HasTone
{
    /** A later step of a sequential rule, not yet the approver's turn. */
    case Waiting = 'waiting';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    /** Closed without a decision: another approver decided, or it was resubmitted or cancelled. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Closed => 'Closed',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Waiting, self::Closed => Tone::Neutral,
            self::Pending => Tone::Warning,
            self::Approved => Tone::Positive,
            self::Rejected => Tone::Negative,
        };
    }
}
