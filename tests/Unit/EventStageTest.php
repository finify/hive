<?php

use App\Enums\EventStage;

it('moves one stage at a time, or out to lost or cancelled', function () {
    expect(EventStage::Enquiry->allowedTransitions())->toBe([EventStage::ProposalSent, EventStage::Lost, EventStage::Cancelled])
        ->and(EventStage::Wrap->allowedTransitions())->toBe([EventStage::Reconciled, EventStage::Cancelled])
        ->and(EventStage::Live->canMoveTo(EventStage::Lost))->toBeFalse()
        ->and(EventStage::Closed->allowedTransitions())->toBe([]);
});

it('knows which stages count as confirmed', function () {
    expect(EventStage::ProposalSent->isConfirmedOrLater())->toBeFalse()
        ->and(EventStage::Confirmed->isConfirmedOrLater())->toBeTrue()
        ->and(EventStage::Live->isConfirmedOrLater())->toBeTrue()
        ->and(EventStage::Cancelled->isConfirmedOrLater())->toBeFalse();
});
