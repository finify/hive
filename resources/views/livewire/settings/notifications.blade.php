<div>
    <x-page-head title="Notification settings" answer="Everything appears under the bell in Hive. Choose what you also want by email, one at a time or in a single digest each morning." />

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
                    <label class="form-check-label" for="np-digest">A daily digest at 07:30 with everything from the day before</label>
                </div>
            </fieldset>
            <div class="card-body border-top border-rule">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Save settings</button>
            </div>
        </form>
    @endif
</div>
