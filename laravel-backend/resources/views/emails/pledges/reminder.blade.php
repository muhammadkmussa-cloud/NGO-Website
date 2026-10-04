<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $monthLabel }} pledge reminder</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
{{-- Preheader: hidden preview text shown next to the subject in inboxes. --}}
<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">
    Your {{ $monthLabel }} pledge of {{ $amountLabel }} is due — one tap to pay securely.
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;border:1px solid #e2e8f0;">
                <tr>
                    <td style="padding:28px 32px 8px 32px;">
                        <p style="margin:0;font-size:13px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#64748b;">{{ $siteName }}</p>
                        <h1 style="margin:16px 0 0 0;font-size:24px;line-height:1.3;color:#0f172a;">Monthly pledge reminder</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:8px 32px 0 32px;">
                        <p style="margin:0 0 14px 0;font-size:16px;line-height:1.6;color:#334155;">Hi {{ $name }},</p>
                        <p style="margin:0 0 14px 0;font-size:16px;line-height:1.6;color:#334155;">
                            Your pledge for <strong>{{ $monthLabel }}</strong> of <strong>{{ $amountLabel }}</strong> is due.
                            One tap below opens your private payment page — you can complete it from your phone in under a minute.
                        </p>
                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 6px 0;">
                            <tr>
                                <td align="center" bgcolor="#15803d" style="border-radius:8px;">
                                    <a href="{{ $payUrl }}"
                                       style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">
                                        PAY {{ $amountLabel }} WITH M-PESA
                                    </a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:12px 0 0 0;font-size:13px;line-height:1.6;color:#64748b;">
                            Button not working? Copy and paste this link into your browser:<br>
                            <a href="{{ $payUrl }}" style="color:#2563eb;word-break:break-all;">{{ $payUrl }}</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 32px 0 32px;">
                        <div style="border-top:1px solid #e2e8f0;padding-top:16px;">
                            <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
                                This link is personal to this month's pledge and expires automatically — please don't share it.
                                Already paid? You can ignore this email.
                            </p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 32px 28px 32px;">
                        <p style="margin:0;font-size:13px;line-height:1.6;color:#94a3b8;">
                            You're receiving this because you set up a monthly pledge with {{ $siteName }}.
                            Reply to this email to pause or cancel your pledge — we're happy to help:
                            <a href="mailto:{{ $supportEmail }}" style="color:#2563eb;">{{ $supportEmail }}</a>.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
