@php
    use App\Enums\VatRate;
    use App\Support\Display;
    use App\Support\Money;
    $contact = $po->supplier?->contacts->firstWhere('is_primary', true);
@endphp
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<title>{{ $po->code }}</title>
<style>
    @page { margin: 18mm 16mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #16181d; line-height: 1.4; }
    h1 { font-size: 16pt; margin: 0; }
    .muted { color: #5c6370; }
    .mono { font-family: "DejaVu Sans Mono", monospace; }
    .head { border-bottom: 1.5pt solid #16181d; padding-bottom: 8pt; margin-bottom: 14pt; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .rows th { text-align: left; font-size: 8.5pt; color: #4a505c; border-bottom: 0.75pt solid #16181d; padding: 4pt 6pt 4pt 0; }
    .rows td { border-bottom: 0.5pt solid #d4d7dd; padding: 5pt 6pt 5pt 0; }
    .num { text-align: right; font-family: "DejaVu Sans Mono", monospace; white-space: nowrap; }
    .totals td { padding: 3pt 0; }
    .totals .grand td { border-top: 1pt solid #16181d; font-weight: bold; padding-top: 5pt; }
    h2 { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.06em; color: #4a505c; margin: 14pt 0 4pt; }
    .foot { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7.5pt; color: #5c6370; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <div class="muted">Purchase order</div>
                <h1 class="mono">{{ $po->code }}</h1>
                <div>{{ $organisation['name'] }}, {{ $organisation['offices'][0]['address'] ?? '' }}</div>
            </td>
            <td style="text-align: right">
                <div>Raised {{ Display::date($po->created_at) }} by {{ $raisedBy }}</div>
                @if ($approvedBy)<div>Approved {{ Display::date($po->approved_at) }} by {{ $approvedBy }}</div>@endif
                <div class="muted">Currency {{ $po->currency }}</div>
            </td>
        </tr>
    </table>

    <table>
        <tr>
            <td style="width: 50%">
                <h2>Supplier</h2>
                <div style="font-weight: bold">{{ $po->supplier?->name }}</div>
                @if ($contact)<div>{{ $contact->name }}@if ($contact->email), {{ $contact->email }}@endif</div>@endif
                @if ($po->supplier?->vat_number)<div class="muted">VAT {{ $po->supplier->vat_number }}</div>@endif
            </td>
            <td>
                <h2>For</h2>
                <div style="font-weight: bold"><span class="mono">{{ $event['code'] ?? '' }}</span> {{ $event['name'] ?? '' }}</div>
                <div>{{ \App\Support\Display::eventDates($event ?? ['start_date' => null]) }}{{ ! empty($event['city']) ? ', '.$event['city'] : '' }}</div>
            </td>
        </tr>
    </table>

    <h2>Lines</h2>
    <table class="rows">
        <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">VAT</th><th class="num">Net</th></tr></thead>
        <tbody>
            @foreach ($po->lines as $line)
                @php($net = Money::of($line->unit_price_minor, $po->currency)->multiplyRatio((int) round((float) $line->quantity * 100), 100))
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ rtrim(rtrim($line->quantity, '0'), '.') }}</td>
                    <td class="num">{{ Money::of($line->unit_price_minor, $po->currency)->format() }}</td>
                    <td class="num">{{ VatRate::tryFrom($line->vat_rate)?->label() ?? $line->vat_rate }}</td>
                    <td class="num">{{ $net->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="width: 45%; margin-left: 55%; margin-top: 8pt">
        <tr><td>Net</td><td class="num">{{ Money::of($po->subtotal_minor, $po->currency)->format() }}</td></tr>
        <tr><td>VAT</td><td class="num">{{ Money::of($po->vat_minor, $po->currency)->format() }}</td></tr>
        <tr class="grand"><td>Total</td><td class="num">{{ Money::of($po->total_minor, $po->currency)->format() }}</td></tr>
    </table>

    @if ($po->notes)
        <h2>Notes</h2>
        <div>{{ $po->notes }}</div>
    @endif

    <h2>Invoicing</h2>
    <div>Quote {{ $po->code }} on your invoice. Invoices that differ from this order by more than 5% or £250, whichever is lower, are queried before payment.</div>

    <div class="foot">{{ $organisation['name'] }}. All companies, people and figures are fictional.</div>
</body>
</html>
