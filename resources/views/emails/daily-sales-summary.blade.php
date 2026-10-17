@php
    // Indian digit grouping (8,60,588.68) without depending on the intl extension
    $in = function ($v) {
        [$int, $dec] = explode('.', number_format(abs((float) $v), 2, '.', ''));
        if (strlen($int) > 3) {
            $int = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3)) . ',' . substr($int, -3);
        }
        return ((float) $v < 0 ? '−' : '') . $int . '.' . $dec;
    };
    $inr = fn($v) => '₹' . $in($v);
    $ltr = fn($v) => $in($v) . ' L';
    $t   = $totals;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Sales Summary</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb;">
    <tr>
        <td style="background:#f59e0b;padding:20px 24px;">
            <div style="font-size:20px;font-weight:bold;color:#0a0c10;">Daily Sales Summary</div>
            <div style="font-size:13px;color:#0a0c10;margin-top:4px;">{{ $stationName }} · {{ $date->format('l, d M Y') }}</div>
        </td>
    </tr>
    <tr>
        <td style="padding:20px 24px 8px;font-size:14px;line-height:1.5;">
            Hello {{ $ownerName }},<br>
            <strong>{{ $managerName }}</strong> has recorded the sales entry for {{ $date->format('d M Y') }}.
            @if ($sales->count() > 1)
                The figures below combine all {{ $sales->count() }} entries for the day.
            @endif
        </td>
    </tr>

    {{-- Headline --}}
    <tr>
        <td style="padding:8px 24px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#92400e;">Gross Revenue</div>
                        <div style="font-size:24px;font-weight:bold;color:#b45309;">{{ $inr($t['revenue']) }}</div>
                    </td>
                    <td width="12"></td>
                    <td style="background:{{ $t['balance'] >= 0 ? '#ecfdf5' : '#fef2f2' }};border:1px solid {{ $t['balance'] >= 0 ? '#a7f3d0' : '#fecaca' }};border-radius:8px;padding:14px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#374151;">Balance</div>
                        <div style="font-size:24px;font-weight:bold;color:{{ $t['balance'] >= 0 ? '#047857' : '#b91c1c' }};">{{ $inr($t['balance']) }}</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Fuel sold --}}
    <tr>
        <td style="padding:12px 24px 0;font-size:13px;font-weight:bold;color:#374151;">Fuel Sold</td>
    </tr>
    <tr>
        <td style="padding:6px 24px;">
            <table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
                <tr style="border-bottom:1px solid #f3f4f6;"><td>MS (Petrol)</td><td align="right">{{ $ltr($t['ms']) }}</td></tr>
                <tr style="border-bottom:1px solid #f3f4f6;"><td>HSD (Diesel)</td><td align="right">{{ $ltr($t['hsd']) }}</td></tr>
                <tr style="border-bottom:1px solid #f3f4f6;"><td>Power</td><td align="right">{{ $ltr($t['speed']) }}</td></tr>
                <tr><td><strong>Total</strong></td><td align="right"><strong>{{ $ltr($t['ms'] + $t['hsd'] + $t['speed']) }}</strong></td></tr>
            </table>
        </td>
    </tr>

    {{-- Collection --}}
    <tr>
        <td style="padding:12px 24px 0;font-size:13px;font-weight:bold;color:#374151;">Collection &amp; Expenses</td>
    </tr>
    <tr>
        <td style="padding:6px 24px;">
            <table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
                <tr style="border-bottom:1px solid #f3f4f6;"><td>Cash</td><td align="right">{{ $inr($t['cash']) }}</td></tr>
                <tr style="border-bottom:1px solid #f3f4f6;"><td>PhonePe / UPI</td><td align="right">{{ $inr($t['phone_pe']) }}</td></tr>
                <tr style="border-bottom:1px solid #f3f4f6;"><td>Card</td><td align="right">{{ $inr($t['card']) }}</td></tr>
                @if ($t['credit'] > 0)
                    <tr style="border-bottom:1px solid #f3f4f6;"><td>Credit Sale</td><td align="right">{{ $inr($t['credit']) }}</td></tr>
                @endif
                <tr><td>Expenses</td><td align="right" style="color:#b91c1c;">−{{ $inr($t['expenses']) }}</td></tr>
            </table>
        </td>
    </tr>

    @php $notes = $sales->pluck('narration')->filter()->values(); @endphp
    @if ($notes->isNotEmpty())
        <tr>
            <td style="padding:12px 24px 0;font-size:13px;font-weight:bold;color:#374151;">Notes</td>
        </tr>
        <tr>
            <td style="padding:6px 24px;font-size:13px;color:#4b5563;">
                @foreach ($notes as $note)
                    <div style="margin-bottom:4px;">• {{ $note }}</div>
                @endforeach
            </td>
        </tr>
    @endif

    <tr>
        <td style="padding:20px 24px;font-size:11px;color:#9ca3af;border-top:1px solid #f3f4f6;">
            You're receiving this because "Daily Sales Summary" is turned on in the station's notification settings.
        </td>
    </tr>
</table>
</body>
</html>
