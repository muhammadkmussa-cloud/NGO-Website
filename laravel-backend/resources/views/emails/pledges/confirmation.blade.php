<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment received — {{ $monthLabel }} pledge</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
{{-- Preheader: hidden preview text shown next to the subject in inboxes. --}}
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">
    Thank you — we've received {{ $amountLabel }} for your {{ $monthLabel }} pledge.
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;border:1px solid #e2e8f0;">
                <tr>
                    <td style="padding:28px 32px 8px 32px;">
                        <p style="margin:0;font-size:13px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#64748b;">{{ $siteName }}</p>
                        <h1 style="margin:16px 0 0 0;font-size:24px;line-height:1.3;color:#0f172a;">Payment received</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 32px 0 32px;">
                        <p style="margin:0 0 14px 0;font-size:16px;line-height:1.6;color:#334155;">Hi {{ $name }},</p>
                        <p style="margin:0 0 20px 0;font-size:16px;line-height:1.6;color:#334155;">
                            Thank you! We've received your payment for the
                            <strong>{{ $monthLabel }}</strong> pledge. Here are the details:
                        </p>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                               style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:0 0 20px 0;">
                            <tr>
                                <td style="padding:12px 16px;font-size:14px;color:#64748b;border-bottom:1px solid #e2e8f0;">Amount</td>
                                <td align="right" style="padding:12px 16px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{ $amountLabel }}</td>
                            </tr>
                            <tr>
                                <td style="padding:12px 16px;font-size:14px;color:#64748b;border-bottom:1px solid #e2e8f0;">Month</td>
                                <td align="right" style="padding:12px 16px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{ $monthLabel }}</td>
                            </tr>
                            <tr>
                                <td style="padding:12px 16px;font-size:14px;color:#64748b;border-bottom:1px solid #e2e8f0;">Paid on</td>
                                <td align="right" style="padding:12px 16px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{ $paidAtLabel ?? '—' }}</td>
                            </tr>
                            @if ($payment->paystack_reference)
                                <tr>
                                    <td style="padding:12px 16px;font-size:14px;color:#64748b;">Reference</td>
                                    <td align="right" style="padding:12px 16px;font-size:14px;font-weight:700;color:#0f172a;">{{ $payment->paystack_reference }}</td>
                                </tr>
                            @endif
                        </table>
                        @if ($pledge->next_payment_date)
                            <p style="margin:0 0 14px 0;font-size:16px;line-height:1.6;color:#334155;">
                                Your next pledge payment of {{ $amountLabel }} is scheduled for
                                <strong>{{ $pledge->next_payment_date->format('d M Y') }}</strong>.
                                We'll remind you before it's due.
                            </p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 32px 0 32px;">
                        <div style="border-top:1px solid #e2e8f0;padding-top:16px;">
                            <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
                                Questions about your pledge? Just reply — we're happy to help.
                            </p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 32px 28px 32px;">
                        <p style="margin:0;font-size:13px;line-height:1.6;color:#94a3b8;">
                            You're receiving this because you set up a monthly pledge with {{ $siteName }}.
                            <a href="mailto:{{ $supportEmail }}" style="color:#2563eb;">{{ $supportEmail }}</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
