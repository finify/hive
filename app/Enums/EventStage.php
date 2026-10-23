<?php

namespace App\Enums;

enum EventStage: string implements HasTone
{
    case Enquiry = 'enquiry';
    case ProposalSent = 'proposal-sent';
    case Confirmed = 'confirmed';
    case PreProduction = 'pre-production';
    case Live = 'live';
    case Wrap = 'wrap';
    case Reconciled = 'reconciled';
    case Closed = 'closed';
    case Lost = 'lost';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Enquiry => 'Enquiry',
            self::ProposalSent => 'Proposal sent',
            self::Confirmed => 'Confirmed',
            self::PreProduction => 'Pre-production',
            self::Live => 'Live',
            self::Wrap => 'Wrap',
            self::Reconciled => 'Reconciled',
            self::Closed => 'Closed',
            self::Lost => 'Lost',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Enquiry, self::ProposalSent, self::Reconciled, self::Closed => Tone::Neutral,
            self::Confirmed, self::PreProduction => Tone::Info,
            self::Live => Tone::Positive,
            self::Wrap => Tone::Warning,
            self::Lost, self::Cancelled => Tone::Negative,
        };
    }

    /**
     * The normal lifecycle, in order. Lost and Cancelled sit outside it.
     *
     * @return list<self>
     */
    public static function lifecycle(): array
    {
        return [
            self::Enquiry, self::ProposalSent, self::Confirmed, self::PreProduction,
            self::Live, self::Wrap, self::Reconciled, self::Closed,
        ];
    }

    /**
     * Stages shown as columns on the pipeline board.
     *
     * @return list<self>
     */
    public static function pipeline(): array
    {
        return [self::Enquiry, self::ProposalSent, self::Confirmed];
    }

    public function position(): int
    {
        $index = array_search($this, self::lifecycle(), true);

        return $index === false ? -1 : $index;
    }

    /**
     * True for Confirmed and every later stage of the normal lifecycle.
     */
    public function isConfirmedOrLater(): bool
    {
        return $this->position() >= self::Confirmed->position();
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Lost, self::Cancelled], true);
    }

    /**
     * Stages this one may move to. Forward one step, or out to Lost or Cancelled.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        if ($this->isTerminal()) {
            return [];
        }

        $next = self::lifecycle()[$this->position() + 1] ?? null;
        $targets = $next ? [$next] : [];

        if (in_array($this, [self::Enquiry, self::ProposalSent], true)) {
            $targets[] = self::Lost;
        }

        $targets[] = self::Cancelled;

        return $targets;
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
