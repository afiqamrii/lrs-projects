<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>{{ $q['reference'] }} v{{ $q['revision'] }}</title>
<style>
@page { margin: 100px 42px 60px; }
body { font-family: DejaVu Sans, sans-serif; color: #18202d; font-size: 9px; line-height: 1.45; }
.pdf-header { position: fixed; top: -72px; left: 0; right: 0; height: 57px; border-bottom: 1px solid #dfe5ed; }
.brand { position:absolute; top:0; left:0; width:24px; height:24px; padding:8px; background:#0866e5; color:white; font-size:18px; font-weight:bold; text-align:center; line-height:1; border-radius:10px; }
.brandtable { width: 100%; border-collapse: collapse; }
.company { font-size: 14px; font-weight: bold; }
.muted { color: #616d7e; }
.label { color: #616d7e; font-size: 8px; text-transform: uppercase; letter-spacing: 1px; }
h1 { font-size: 25px; letter-spacing: -1px; margin: 0; line-height: 1.3; }
h2 { font-size: 11px; margin: 16px 0 7px; color: #0866e5; }
p { margin: 5px 0; white-space: pre-line; overflow-wrap: break-word; }
.meta { width: 100%; border-collapse: collapse; margin: 18px 0; }
.meta td { vertical-align: top; padding: 0 16px 0 0; width: 50%; }
.shipment { padding: 12px; background: #f3f6fa; border: 1px solid #e4e9f0; border-radius: 8px; }
.services { border-collapse: collapse; width: 100%; margin-top: 12px; }
.services th { background: #eef3fa; font-size: 8px; padding: 10px; text-align: left; }
.services td { padding: 11px 10px; border-bottom: 1px solid #e8edf3; vertical-align: top; }
.services thead { display: table-header-group; }
.services tr { page-break-inside: avoid; }
.amount { text-align: right !important; width: 105px; white-space: nowrap; }
.totals { margin-top: 15px; margin-left: 45%; width: 55%; border-collapse: collapse; page-break-inside: avoid; }
.totals td { padding: 5px 8px; }
.total td { background: #0866e5; color: white; padding: 13px 8px; font-size: 12px; font-weight: bold; }
.instructions { margin-top: 22px; padding: 14px; border: 1px solid #dbe4f0; border-radius: 8px; page-break-inside: avoid; }
.notice { margin: 12px 0; color: #8b5b00; font-size: 10px; }
h2 { page-break-after: avoid; }
footer { position: fixed; bottom: -35px; font-size: 7px; color: #616d7e; right: 0; }
</style></head><body>

<footer>Commercial estimate · booking requires separate confirmation</footer>
<h1>Your freight quotation.</h1>
<p class="muted">{{ $q['reference'] }} / revision {{ $q['revision'] }}</p>
@if($draft)<p class="notice">DRAFT - for review only. This version is not approved for release.</p>@endif
<table class="meta"><tr><td><span class="label">Prepared for</span><p style="font-size:12px;font-weight:bold">{{ $q['client']['name'] ?: 'Client identity unresolved' }}</p><p>{{ $q['to']['name'] ?? 'Contact unresolved' }}<br>{{ $q['to']['email'] ?? '' }}</p><p class="muted">{{ $q['client']['address'] }}</p></td><td><span class="label">Issue & validity</span><p>Issued {{ $q['issue_date'] }}</p><p><strong>Valid until {{ $q['valid_until'] ? \Carbon\CarbonImmutable::parse($q['valid_until'])->setTimezone($q['company']['timezone'])->format('d M Y, H:i') : 'Unresolved' }}</strong><br>{{ $q['company']['timezone'] }}</p><p class="muted">{{ $q['company']['address'] }}</p></td></tr></table>
<div class="shipment"><span class="label">Shipment & service</span><p style="font-size:12px;font-weight:bold">{{ $q['shipment']['origin_location'] }} → {{ $q['shipment']['destination_location'] }}</p><p>{{ $q['shipment']['mode'] }} · {{ \App\Support\Shipment::SCOPES[$q['shipment']['scope']] }} · {{ $q['shipment']['incoterm'] ?? '' }} {{ $q['shipment']['named_place'] ?? '' }}</p><p>{{ $q['shipment']['cargo_description'] }}</p><p>{{ $q['quantity_summary'] }}</p></div>
<h2>Quoted services</h2><table class="services"><thead><tr><th>Service description</th><th class="amount">{{ $q['currency'] }}</th></tr></thead><tbody>@forelse($q['lines'] as $line)<tr><td>{{ $line['description'] ?: 'Description awaiting review' }}</td><td class="amount">{{ $line['amount'] ?? 'Unresolved' }}</td></tr>@empty<tr><td colspan="2">Pricing basis awaiting review</td></tr>@endforelse</tbody></table>
<table class="totals"><tr><td>Selling subtotal</td><td class="amount">{{ $q['selling_subtotal'] ?? 'Unresolved' }}</td></tr>
@if($q['tax_charge'] !== '0.00' && \Brick\Math\BigDecimal::of($q['tax_charge'])->isGreaterThan('0'))<tr><td>{{ $q['tax_charge_description'] }}</td><td class="amount">{{ $q['tax_charge'] }}</td></tr>@endif
<tr><td>Stated tax @if($q['tax_rate']!==null)({{ $q['tax_rate'] }}%)@endif</td><td class="amount">{{ $q['tax'] ?? 'Unresolved' }}</td></tr><tr class="total"><td>Total · {{ $q['currency'] }}</td><td class="amount">{{ $q['total'] ?? 'Unresolved' }}</td></tr></table>
@if($q['optional_lines'])<h2>Optional services - excluded from the total</h2><table class="services"><thead><tr><th>Optional service</th><th class="amount">{{ $q['currency'] }}</th></tr></thead><tbody>@foreach($q['optional_lines'] as $line)<tr><td>{{ $line['description'] }}</td><td class="amount">{{ $line['amount'] }}</td></tr>@endforeach</tbody></table><p class="muted">Optional services require a revised quotation confirming scope and applicable tax before acceptance.</p>@endif
<h2>Inclusions</h2><p>{{ $q['inclusions'] ?: 'Awaiting staff confirmation' }}</p>
<h2>Exclusions</h2><p>{{ $q['exclusions'] ?: 'Awaiting staff confirmation' }}</p>
<h2>Conditions</h2>@foreach(explode("\n",$q['conditions'] ?: 'Awaiting staff confirmation') as $paragraph)<p>{{ $paragraph }}</p>@endforeach
<div class="instructions"><strong>To proceed</strong><p>{{ $q['instructions'] }}</p><p>Contact {{ $q['company']['reply_name'] }} · {{ $q['company']['reply_email'] }}</p></div>
</body></html>
