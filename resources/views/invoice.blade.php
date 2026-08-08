<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $invoice['number'] }} — Nexmile</title>

    {{-- Deliberately plain and self-contained: an invoice gets printed, saved
         as a PDF and emailed to an accountant, and none of those places have a
         CDN. Black on white because that is what a printer expects. --}}
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px; background: #fff; color: #111;
               font: 13px/1.5 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        .sheet { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 20px; margin: 0 0 2px; }
        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .08em;
             color: #666; margin: 24px 0 8px; }
        .head { display: flex; justify-content: space-between; gap: 24px;
                border-bottom: 2px solid #111; padding-bottom: 16px; }
        .muted { color: #666; }
        .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { padding: 8px 6px; border-bottom: 1px solid #e5e5e5; text-align: left; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #666; }
        td.n, th.n { text-align: right; white-space: nowrap; }
        .totals { margin-left: auto; width: 300px; margin-top: 12px; }
        .totals td { border: 0; padding: 4px 6px; }
        .totals .grand td { border-top: 2px solid #111; font-weight: 700; font-size: 15px; padding-top: 8px; }
        .opts { color: #666; font-size: 11px; }
        .foot { margin-top: 32px; padding-top: 12px; border-top: 1px solid #e5e5e5;
                color: #666; font-size: 11px; }
        .noprint { margin-bottom: 20px; }
        @media print { .noprint { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="sheet">

    <div class="noprint">
        <button onclick="window.print()"
                style="padding:8px 16px;border:1px solid #111;background:#111;color:#fff;
                       border-radius:6px;font-weight:600;cursor:pointer">
            Print or save as PDF
        </button>
    </div>

    <div class="head">
        <div>
            <h1>{{ $invoice['seller']['name'] }}</h1>
            <div class="muted">{{ $invoice['seller']['address'] }}</div>
            @if ($invoice['seller']['gstin'])
                <div class="muted">GSTIN: {{ $invoice['seller']['gstin'] }}</div>
            @endif
            @if ($invoice['seller']['fssai'])
                <div class="muted">FSSAI: {{ $invoice['seller']['fssai'] }}</div>
            @endif
        </div>
        <div class="right">
            <strong>Tax invoice</strong>
            <div class="muted">{{ $invoice['number'] }}</div>
            <div class="muted">{{ $invoice['date']?->format('d M Y, g:i a') }}</div>
            <div class="muted">Order {{ $invoice['order']->order_number }}</div>
        </div>
    </div>

    <h2>Billed to</h2>
    <div>{{ $invoice['buyer']['name'] ?: '—' }}</div>
    @if ($invoice['buyer']['phone'])
        <div class="muted">{{ $invoice['buyer']['phone'] }}</div>
    @endif
    @if ($invoice['buyer']['address'])
        <div class="muted">{{ $invoice['buyer']['address'] }}</div>
    @endif

    <h2>Items</h2>
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="n">Qty</th>
                <th class="n">Taxable</th>
                <th class="n">GST</th>
                <th class="n">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice['lines'] as $line)
                <tr>
                    <td>
                        {{ $line['name'] }}
                        @foreach ($line['options'] as $option)
                            <div class="opts">{{ $option }}</div>
                        @endforeach
                    </td>
                    <td class="n">{{ $line['quantity'] }}</td>
                    <td class="n">{{ number_format($line['taxable'], 2) }}</td>
                    <td class="n">{{ rtrim(rtrim(number_format($line['gst_rate'], 2), '0'), '.') }}%</td>
                    <td class="n">{{ number_format($line['total'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Tax summary</h2>
    <table>
        <thead>
            <tr>
                <th>Rate</th>
                <th class="n">Taxable value</th>
                <th class="n">CGST</th>
                <th class="n">SGST</th>
                <th class="n">Total tax</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice['tax_groups'] as $group)
                <tr>
                    <td>
                        {{ rtrim(rtrim(number_format($group['rate'], 2), '0'), '.') }}%
                        @if ($group['is_delivery'] ?? false)
                            <span class="opts">delivery</span>
                        @endif
                    </td>
                    <td class="n">{{ number_format($group['taxable'], 2) }}</td>
                    <td class="n">{{ number_format($group['cgst'], 2) }}</td>
                    <td class="n">{{ number_format($group['sgst'], 2) }}</td>
                    <td class="n">{{ number_format($group['tax'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Taxable value</td><td class="n">₹{{ number_format($invoice['totals']['taxable'], 2) }}</td></tr>
        @if ($invoice['totals']['delivery_fee'] > 0)
            <tr><td>Delivery fee</td><td class="n">₹{{ number_format($invoice['totals']['delivery_fee'], 2) }}</td></tr>
        @endif
        @if ($invoice['totals']['discount'] > 0)
            <tr><td>Discount</td><td class="n">−₹{{ number_format($invoice['totals']['discount'], 2) }}</td></tr>
        @endif
        <tr><td>GST</td><td class="n">₹{{ number_format($invoice['totals']['tax'], 2) }}</td></tr>
        <tr class="grand"><td>Total</td><td class="n">₹{{ number_format($invoice['totals']['grand_total'], 2) }}</td></tr>
    </table>

    <div class="foot">
        <div>
            Payment: {{ strtoupper($invoice['payment']['method'] ?? '—') }}
            · {{ ucfirst($invoice['payment']['status'] ?? '—') }}
        </div>
        <div style="margin-top:6px">
            Supply is intra-state, so GST is shown as CGST and SGST in equal parts.
        </div>
        <div style="margin-top:6px">
            Delivered by Nexmile on behalf of {{ $invoice['seller']['name'] }}.
            The delivery fee is charged by Nexmile; food is supplied by the restaurant named above.
        </div>
    </div>

</div>
</body>
</html>
