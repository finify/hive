@php
    use App\Support\Display;
@endphp
<div>
    <x-page-head title="Suppliers" :answer="$items->count().' suppliers, '.$items->where('is_approved', false)->count().' not yet approved.'" />
    <div class="filters"><label class="visually-hidden" for="sp-search">Search suppliers</label><input id="sp-search" type="search" class="form-control is-search" placeholder="Search suppliers" wire:model.live.debounce.300ms="search"></div>
    <div class="table-wrap">
        @if ($items->isEmpty())
            <x-empty title="No suppliers match">AV hire, staging, catering, venues, print and every other supplier, with insurance expiry and ratings after each event.</x-empty>
        @else
            <table class="table">
                <thead><tr><th>Supplier</th><th>Category</th><th class="num">Rating</th><th>Insurance expires</th><th>Terms</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($items as $s)
                        @php($expired = $s['insurance_expires_at'] < app(\App\Demo\Workspace::class)->today()->toDateString())
                        <tr>
                            <td class="fw-medium">{{ $s['name'] }}<span class="d-block small text-muted fw-normal">{{ $s['contacts'][0]['email'] ?? '' }}</span></td>
                            <td>{{ ucfirst(str_replace('-', ' ', $s['category'])) }}</td>
                            <td class="num">{{ $s['rating'] }}</td>
                            <td @class(['tnum', 'text-negative' => $expired])>{{ Display::date($s['insurance_expires_at']) }}</td>
                            <td>{{ $s['payment_terms_days'] }} days</td>
                            <td><x-pill :tone="$s['is_approved'] ? 'positive' : 'warning'">{{ $s['is_approved'] ? 'Approved' : 'Not approved' }}</x-pill></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
