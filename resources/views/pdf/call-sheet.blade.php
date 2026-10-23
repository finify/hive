<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<title>{{ $event['code'] }} call sheet, {{ $dateLabel }}</title>
<style>
    @page { margin: 18mm 16mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #16181d; line-height: 1.4; }
    h1 { font-size: 16pt; margin: 0 0 2pt; }
    h2 { font-size: 10pt; text-transform: uppercase; letter-spacing: 0.06em; margin: 16pt 0 6pt; color: #4a505c; }
    .muted { color: #5c6370; }
    .code { font-family: "DejaVu Sans Mono", monospace; }
    .head { border-bottom: 1.5pt solid #16181d; padding-bottom: 8pt; }
    .head td { vertical-align: top; }
    table { width: 100%; border-collapse: collapse; }
    .rows th { text-align: left; font-weight: bold; font-size: 8.5pt; color: #4a505c; border-bottom: 0.75pt solid #16181d; padding: 4pt 6pt 4pt 0; }
    .rows td { border-bottom: 0.5pt solid #d4d7dd; padding: 5pt 6pt 5pt 0; vertical-align: top; }
    .num { font-family: "DejaVu Sans Mono", monospace; white-space: nowrap; }
    .times td { padding: 2pt 18pt 2pt 0; }
    .times .big { font-size: 14pt; font-weight: bold; font-family: "DejaVu Sans Mono", monospace; }
    .note { border-left: 2pt solid #16181d; padding: 2pt 0 2pt 8pt; margin: 4pt 0; }
    .foot { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7.5pt; color: #5c6370; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <div class="muted">Call sheet</div>
                <h1>{{ $event['name'] }}</h1>
                <div><span class="code">{{ $event['code'] }}</span>@if ($client) · {{ $client }}@endif</div>
            </td>
            <td style="text-align: right">
                <div style="font-size: 12pt; font-weight: bold">{{ $dateLabel }}</div>
                <div class="muted">{{ $phases ? implode(', ', $phases) : 'Event day' }}</div>
            </td>
        </tr>
    </table>

    <h2>Key times</h2>
    <table class="times" style="width: auto">
        <tr>
            <td><div class="muted">First call</div><div class="big">{{ $firstCall ?? '—' }}</div></td>
            <td><div class="muted">Last wrap</div><div class="big">{{ $lastWrap ?? '—' }}</div></td>
            <td><div class="muted">Timezone</div><div style="font-size: 11pt; font-weight: bold">{{ $event['timezone'] }}</div></td>
        </tr>
    </table>

    <h2>Venue</h2>
    @if ($venue)
        <div style="font-weight: bold">{{ $venue['name'] }}</div>
        <div>{{ $venue['address'] }}</div>
        @if ($venue['access_notes'])
            <div class="note"><strong>Access:</strong> {{ $venue['access_notes'] }}</div>
        @endif
        @if ($venue['power_notes'])
            <div class="note"><strong>Power:</strong> {{ $venue['power_notes'] }}</div>
        @endif
    @else
        <div class="muted">Venue to be confirmed. {{ $event['city'] }}</div>
    @endif

    <h2>Crew, by call time</h2>
    <table class="rows">
        <thead><tr><th>Call</th><th>Wrap</th><th>Name</th><th>Role</th><th>Report to</th></tr></thead>
        <tbody>
            @foreach ($crew as $row)
                <tr>
                    <td class="num">{{ $row['call'] }}</td>
                    <td class="num">{{ $row['wrap'] }}</td>
                    <td>{{ $row['name'] }}@if ($row['status'] !== 'Confirmed')<div class="muted" style="font-size: 8pt">{{ $row['status'] }}</div>@endif</td>
                    <td>{{ $row['role'] }}</td>
                    <td>{{ $row['location'] ?? ($venue['name'] ?? '') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Contacts</h2>
    <table class="rows">
        <thead><tr><th>Name</th><th>Role</th><th>Phone</th></tr></thead>
        <tbody>
            @foreach ($contacts as $c)
                <tr><td>{{ $c['name'] }}</td><td>{{ $c['role'] }}</td><td class="num">{{ $c['phone'] ?? '—' }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <div class="foot">Issued {{ $issuedAt }} from Hive, Lumen &amp; Co Live. Times are local to the venue. All companies, people and figures are fictional.</div>
</body>
</html>
