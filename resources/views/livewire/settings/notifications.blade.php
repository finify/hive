<div>
    <x-page-head title="Notifications and calendar" answer="Everything appears under the bell in Hive. Choose what you also want by email, one at a time or in a single digest each morning." />

    @if (! $linked)
        <div class="card mw-form"><x-empty title="Nothing to set">Your sign-in isn’t linked to a person in Hive, so you don’t receive notices.</x-empty></div>
    @else
        <form class="card mw-form" wire:submit="save">
            <fieldset class="card-body">
                <legend class="card-title fs-6 mb-3">Email me about</legend>
                @foreach ($types as $type => $label)
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="np-{{ $type }}" wire:model="email.{{ $type }}">
                        <label class="form-check-label" for="np-{{ $type }}">{{ $label }}</label>
                    </div>
                @endforeach
            </fieldset>
            <fieldset class="card-body border-top border-rule">
                <legend class="card-title fs-6 mb-3">How</legend>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" id="np-each" name="np-how" value="0" @checked(! $digest) x-on:change="$wire.digest = false">
                    <label class="form-check-label" for="np-each">One email for each, as it happens</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" id="np-digest" name="np-how" value="1" @checked($digest) x-on:change="$wire.digest = true">
                    <label class="form-check-label" for="np-digest">A daily digest at 08:00 with everything from the day before</label>
                </div>
            </fieldset>
            <div class="card-body border-top border-rule">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Save settings</button>
            </div>
        </form>
    @endif

    <section class="card mw-form mt-3">
        <div class="card-header"><h2 class="card-title">Calendar feed</h2></div>
        <div class="card-body">
            <p>Subscribe in Outlook, Google or Apple Calendar to see your bookings, holds, leave and travel. It updates about every hour.</p>
            @if ($feedUrl)
                <label for="feed-url" class="form-label">Your private address (copy it now; it isn’t shown again)</label>
                <div class="d-flex gap-2 mb-2" x-data="{ copied: false }">
                    <input id="feed-url" class="form-control code" readonly value="{{ $feedUrl }}" x-ref="url" x-on:focus="$el.select()">
                    <button type="button" class="btn btn-secondary text-nowrap" x-on:click="navigator.clipboard.writeText($refs.url.value).then(() => copied = true)" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                </div>
                <p class="small text-muted">An event’s own calendar is at the same address with <span class="code">/events/EV-26117.ics</span> in place of <span class="code">.ics</span>. Anyone with the address can read it, so keep it private.</p>
            @elseif ($hasFeed)
                <p class="small text-muted">Your feed is on. For security the address is only shown when it’s made; get a new one if you’ve lost it.</p>
            @endif
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary" wire:click="issueFeed" @if ($hasFeed) wire:confirm="Make a new address? The old one stops working." @endif>{{ $hasFeed ? 'Get a new address' : 'Turn on my calendar feed' }}</button>
                @if ($hasFeed)
                    <button type="button" class="btn btn-quiet" wire:click="revokeFeed" wire:confirm="Turn off your calendar feed?">Turn off</button>
                @endif
            </div>
        </div>
    </section>
</div>
