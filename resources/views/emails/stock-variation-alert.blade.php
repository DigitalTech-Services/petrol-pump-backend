@php
    // Indian digit grouping without depending on the intl extension
    $in = function ($v) {
        [$int, $dec] = explode('.', number_format(abs((float) $v), 2, '.', ''));
        if (strlen($int) > 3) {
            $int = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3)) . ',' . substr($int, -3);
        }
        return ((float) $v < 0 ? '−' : '') . $int . '.' . $dec;
    };
    $ltr      = fn($v) => $in($v) . ' L';
    $e        = $entry;
    $shortage = $e['variation'] < 0;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Variation Alert</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb;">
    <tr>
        <td style="background:#ef4444;padding:20px 24px;">
            <div style="font-size:20px;font-weight:bold;color:#ffffff;">Stock Variation Alert</div>
            <div style="font-size:13px;color:#ffffff;margin-top:4px;">{{ $stationName }} · {{ $fuelLabel }} · {{ $date->format('l, d M Y') }}</div>
        </td>
    </tr>
    <tr>
        <td style="padding:20px 24px 8px;font-size:14px;line-height:1.5;">
            Hello {{ $ownerName }},<br>
            The {{ $fuelLabel }} stock entry recorded by <strong>{{ $managerName }}</strong> for {{ $date->format('d M Y') }}
            shows a <strong>{{ $shortage ? 'shortage' : 'excess' }}</strong> of
            <strong>{{ $e['variation_pct'] }}%</strong> of the day's sale — above the allowed {{ $thresholdPct }}%.
        </td>
    </tr>

    <tr>
        <td style="padding:8px 24px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#991b1b;">Variation</div>
                        <div style="font-size:24px;font-weight:bold;color:#b91c1c;">{{ $e['variation'] > 0 ? '+' : '' }}{{ $ltr($e['variation']) }}</div>
                    </td>
                    <td width="12"></td>
                    <td style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#991b1b;">Of Sale</div>
                        <div style="font-size:24px;font-weight:bold;color:#b91c1c;">{{ $e['variation_pct'] }}%</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <tr>
        <td style="padding:12px 24px 0;font-size:13px;font-weight:bold;color:#374151;">Stock Details</td>
    </tr>
    <tr>
        <td style="padding:6px 24px;">
            <table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
                <tr style="border-bottom:1px solid #f3f4f6;"><td>Opening Stock</td><td align="right">{{ $ltr($e['open']) }}</td></tr>
                <tr style="border-bottom:1px solid #f3f4f6;"><td>Received</td><td align="right">{{ $ltr($e['recv']) }}</td></tr>
                <tr style="border-bottom:1px solid #f3f4f6;"><td>Net Stock</td><td align="right">{{ $ltr($e['net']) }}</td></tr>
                <tr style="border-bottom:1px solid #f3f4f6;"><td>Closing Stock</td><td align="right">{{ $ltr($e['close']) }}</td></tr>
                <tr style="border-bottom:1px solid #f3f4f6;"><td>Stock-based Sale (Net − Closing)</td><td align="right">{{ $ltr($e['net'] - $e['close']) }}</td></tr>
                <tr><td>Recorded Sale</td><td align="right">{{ $ltr($e['sale']) }}</td></tr>
            </table>
        </td>
    </tr>

    @if (!empty($e['remarks']))
        <tr>
            <td style="padding:12px 24px 0;font-size:13px;font-weight:bold;color:#374151;">Remarks</td>
        </tr>
        <tr>
            <td style="padding:6px 24px;font-size:13px;color:#4b5563;">{{ $e['remarks'] }}</td>
        </tr>
    @endif

    <tr>
        <td style="padding:20px 24px;font-size:11px;color:#9ca3af;border-top:1px solid #f3f4f6;">
            You're receiving this because "Stock Variation Alert" is turned on in the station's notification settings.
        </td>
    </tr>
</table>
</body>
</html>
