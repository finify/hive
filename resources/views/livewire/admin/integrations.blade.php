@php
    use App\Support\Display;
@endphp
<div>
    <x-page-head title="Integrations" answer="What Hive is connected to. Credentials live in the server’s environment and are never shown here." />

    <div class="grid-12">
        <section class="card span-6">
            <div class="card-header">
                <h2 class="card-title">Microsoft sign-in</h2>
                <x-pill :tone="$sso->isConfigured() ? 'positive' : 'neutral'" class="ms-auto">{{ $sso->isConfigured() ? 'On' : 'Not set up' }}</x-pill>
            </div>
            <div class="card-body small">
                <p class="mb-2">Staff sign in with their Microsoft work account (Entra ID). Only people with an active Hive account get in; invitations still decide who has one. Entra’s own multi-factor policy applies to these sign-ins.</p>
                <p class="text-muted mb-0">Set <span class="code">ENTRA_TENANT_ID</span>, <span class="code">ENTRA_CLIENT_ID</span> and <span class="code">ENTRA_CLIENT_SECRET</span>; the redirect URI is <span class="code">{{ route('sso.callback') }}</span>.</p>
            </div>
        </section>

        <section class="card span-6">
            <div class="card-header">
                <h2 class="card-title">{{ $accounting->name() }}</h2>
                <x-pill :tone="$accounting->isConfigured() ? 'positive' : 'neutral'" class="ms-auto">{{ $accounting->isConfigured() ? 'Connected' : 'Not set up' }}</x-pill>
            </div>
            <div class="card-body small">
                <p class="mb-2">Approved supplier invoices go to {{ $accounting->name() }} as bills, coded by budget category; payments come back and mark them paid. Runs every hour.</p>
                <p class="mb-2 tnum">{{ $pushed }} sent, {{ $waiting }} waiting{{ $lastSync ? ', last synced '.Display::dateTime($lastSync) : '' }}.</p>
                @if ($accounting->isConfigured() && $canManage)
                    <button type="button" class="btn btn-secondary btn-sm" wire:click="syncAccounting" wire:loading.attr="disabled">Sync now</button>
                @else
                    <p class="text-muted mb-0">Set <span class="code">XERO_CLIENT_ID</span> and <span class="code">XERO_CLIENT_SECRET</span> from a Xero custom connection.</p>
                @endif
            </div>
        </section>

        <section class="card span-12">
            <div class="card-header"><h2 class="card-title">Outbound webhooks</h2><span class="small text-muted ms-auto">Signed with HMAC-SHA256 (Standard Webhooks headers)</span></div>
            @if ($newSecret)
                <div class="card-body border-bottom border-rule" x-data="{ copied: false }">
                    <label for="wh-secret" class="form-label">Signing secret (shown once)</label>
                    <div class="d-flex gap-2"><input id="wh-secret" class="form-control code" readonly value="{{ $newSecret }}" x-ref="s"><button type="button" class="btn btn-secondary" x-on:click="navigator.clipboard.writeText($refs.s.value).then(() => copied = true)" x-text="copied ? 'Copied' : 'Copy'">Copy</button></div>
                </div>
            @endif
            @if ($endpoints->isEmpty())
                <x-empty title="No webhooks">Add an HTTPS address to be told when an event changes stage or a booking is confirmed.</x-empty>
            @else
                <ul class="list-rows">
                    @foreach ($endpoints as $e)
                        <li class="flex-wrap" wire:key="wh-{{ $e->id }}">
                            <div class="row-main">
                                <div class="row-title code text-break">{{ $e->url }}</div>
                                <div class="row-sub">{{ $e->description ? $e->description.' · ' : '' }}{{ collect($e->events)->map(fn ($k) => $events[$k] ?? $k)->implode(', ') }} · {{ $e->deliveries_count }} deliveries</div>
                            </div>
                            <x-pill :tone="$e->is_active ? 'positive' : 'neutral'">{{ $e->is_active ? 'Active' : 'Paused' }}</x-pill>
                            @if ($canManage)
                                <button type="button" class="btn btn-quiet btn-sm" wire:click="testWebhook({{ $e->id }})">Send test</button>
                                <button type="button" class="btn btn-quiet btn-sm" wire:click="toggleWebhook({{ $e->id }})">{{ $e->is_active ? 'Pause' : 'Resume' }}</button>
                                <button type="button" class="btn btn-quiet btn-sm text-negative" wire:click="deleteWebhook({{ $e->id }})" wire:confirm="Remove this webhook?">Remove</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($canManage)
                <form class="card-body border-top border-rule row g-3" wire:submit="addWebhook" novalidate>
                    <x-form-field name="url" model="hookForm.url" label="HTTPS address" col="col-md-6" placeholder="https://" />
                    <x-form-field name="description" model="hookForm.description" label="What it’s for (optional)" col="col-md-6" />
                    <fieldset class="col-12">
                        <legend class="form-label fs-6">Send</legend>
                        @foreach ($events as $key => $label)
                            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="wh-{{ $key }}" value="{{ $key }}" wire:model="hookForm.events"><label class="form-check-label" for="wh-{{ $key }}">{{ $label }} <span class="code small text-muted">{{ $key }}</span></label></div>
                        @endforeach
                        @error('hookForm.events')<div class="small text-negative">{{ $message }}</div>@enderror
                    </fieldset>
                    <div class="col-12"><button type="submit" class="btn btn-primary">Add webhook</button></div>
                </form>
            @endif
        </section>

        @if ($deliveries->isNotEmpty())
            <div class="table-wrap span-12">
                <table class="table">
                    <caption class="visually-hidden">Recent webhook deliveries</caption>
                    <thead><tr><th>When</th><th>Event</th><th>To</th><th class="num">Tries</th><th>Result</th></tr></thead>
                    <tbody>
                        @foreach ($deliveries as $d)
                            <tr wire:key="whd-{{ $d->id }}">
                                <td class="tnum text-nowrap">{{ Display::dateTime($d->created_at) }}</td>
                                <td class="code">{{ $d->event }}</td>
                                <td class="code text-break">{{ $d->endpoint->url }}</td>
                                <td class="num">{{ $d->attempts }}</td>
                                <td>
                                    @if ($d->delivered_at)
                                        <x-pill tone="positive">Delivered {{ $d->response_status }}</x-pill>
                                    @elseif ($d->error)
                                        <x-pill tone="negative">{{ $d->error }}</x-pill>
                                    @else
                                        <x-pill tone="neutral">Queued</x-pill>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <section class="card span-12">
            <div class="card-header"><h2 class="card-title">Calendar feeds and CSV import</h2></div>
            <div class="card-body small">
                <p class="mb-2">Everyone can turn on a private calendar feed of their bookings, leave and travel from <a href="{{ route('notification-settings') }}" wire:navigate>Notifications and calendar</a>.</p>
                <p class="mb-0">People, clients, suppliers and venues can be imported from a spreadsheet on <a href="{{ route('import') }}" wire:navigate>Import from CSV</a>.</p>
            </div>
        </section>
    </div>
</div>
