<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Monthly Pledge — Reaching Out Initiative</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.25rem;
        }
        .card {
            width: 100%;
            max-width: 26rem;
            background: #111a33;
            border: 1px solid #1e293b;
            border-radius: 1.5rem;
            padding: 2rem 1.5rem;
            text-align: center;
        }
        .eyebrow {
            font-size: .7rem;
            font-weight: 800;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: #38bdf8;
            margin-bottom: .35rem;
        }
        h1 { font-size: 1.35rem; font-weight: 800; margin-bottom: .15rem; }
        .month { color: #94a3b8; font-size: .95rem; margin-bottom: 1.25rem; }
        .amount {
            font-size: 2.3rem;
            font-weight: 900;
            color: #f8fafc;
            margin-bottom: 1.25rem;
        }
        .rows {
            background: #0b1226;
            border: 1px solid #1e293b;
            border-radius: 1rem;
            padding: .9rem 1rem;
            text-align: left;
            font-size: .85rem;
            display: grid;
            gap: .55rem;
            margin-bottom: 1.25rem;
        }
        .rows div { display: flex; justify-content: space-between; gap: 1rem; }
        .rows span:first-child { color: #94a3b8; }
        .rows span:last-child { color: #e2e8f0; font-weight: 600; text-align: right; }
        label {
            display: block;
            text-align: left;
            font-size: .75rem;
            font-weight: 700;
            color: #94a3b8;
            margin-bottom: .35rem;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        input[type="tel"] {
            width: 100%;
            background: #0b1226;
            border: 1px solid #334155;
            border-radius: .75rem;
            color: #f8fafc;
            font-size: 1rem;
            padding: .8rem .9rem;
            margin-bottom: 1rem;
        }
        input[type="tel"]:focus { outline: 2px solid #38bdf8; border-color: transparent; }
        button {
            width: 100%;
            background: #15803d;
            color: #fff;
            border: 0;
            border-radius: .9rem;
            font-size: .95rem;
            font-weight: 800;
            letter-spacing: .03em;
            padding: .95rem 1rem;
            cursor: pointer;
        }
        button:hover { background: #166534; }
        button:disabled { background: #334155; cursor: not-allowed; }
        .msg { margin-top: 1rem; font-size: .9rem; line-height: 1.45; min-height: 1.3rem; }
        .msg.ok { color: #4ade80; }
        .msg.err { color: #fb7185; }
        .note { margin-top: 1.1rem; font-size: .72rem; color: #94a3b8; line-height: 1.5; }
        .icon { font-size: 2.4rem; margin-bottom: .6rem; }
        /* Secondary/destructive action — overrides the primary green button. */
        .btn-cancel {
            background: transparent;
            border: 1px solid #fb7185;
            color: #fb7185;
            margin-top: .85rem;
            font-size: .78rem;
            letter-spacing: .06em;
        }
        .btn-cancel:hover { background: rgba(251, 113, 133, .12); }
        .btn-cancel:disabled { background: transparent; border-color: #334155; color: #64748b; cursor: not-allowed; }
    </style>
</head>
<body>
<main class="card" role="main">
    @php
        // KES amounts are usually whole shillings; show decimals only when needed.
        $amountLabel = number_format(
            (float) ($payment->amount_due ?? 0),
            fmod((float) ($payment->amount_due ?? 0), 1.0) === 0.0 ? 0 : 2,
        );
    @endphp

    @if ($state === 'invalid')
        <div class="icon" aria-hidden="true">&#128274;</div>
        <div class="eyebrow">Monthly Pledge</div>
        <h1>Link unavailable</h1>
        <p class="month">This payment link is invalid or has expired.</p>
        <p class="note">Request a fresh link from the reminder email, or contact us and we will resend it.</p>
    @elseif ($state === 'paid')
        <div class="icon" aria-hidden="true">&#10004;</div>
        <div class="eyebrow">Monthly Pledge</div>
        <h1>Thank you!</h1>
        <p class="month">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $payment->billing_month)->format('F Y') }} is paid.</p>
        <div class="rows">
            <div><span>Amount</span><span>KSh {{ $amountLabel }}</span></div>
            <div><span>Status</span><span>PAID</span></div>
            @if ($payment->paid_at)
                <div><span>Paid on</span><span>{{ $payment->paid_at->format('d M Y') }}</span></div>
            @endif
        </div>
        <p class="note">Your next pledge payment will be requested automatically. No action is needed now.</p>

        {{-- §17: paid months stay cancellable — stopping is always possible. --}}
        <form id="cancel-form" action="/api/pledges/pay/{{ $token }}/cancel" method="post">
            <button id="cancel-btn" class="btn-cancel" type="submit">CANCEL MY MONTHLY PLEDGE</button>
        </form>
        <p id="cancel-msg" class="msg" role="status" aria-live="polite"></p>
    @else
        <div class="eyebrow">Monthly Pledge</div>
        <h1>Complete my pledge</h1>
        <p class="month">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $payment->billing_month)->format('F Y') }}</p>

        <div class="amount">KSh {{ $amountLabel }}</div>

        <div class="rows">
            <div><span>Payment method</span><span>M-Pesa</span></div>
            <div><span>Supporter</span><span>{{ \Illuminate\Support\Str::limit($pledge->name ?? 'Friend', 24) }}</span></div>
            @if ($pledge->phone)
                <div>
                    <span>Phone</span>
                    <span>{{ substr($pledge->phone, 0, 4) }}*****{{ substr($pledge->phone, -3) }}</span>
                </div>
            @endif
        </div>

        {{-- action/method give a no-JS fallback (the API accepts form-encoded bodies). --}}
        <form id="pay-form" action="/api/pledges/pay/{{ $token }}" method="post" novalidate>
            <label for="phone">M-Pesa phone number</label>
            {{-- Prefilled for the link owner's convenience; the visible row above stays masked. --}}
            <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel"
                   placeholder="07XX XXX XXX" value="{{ $pledge->phone }}" required>
            <button id="pay-btn" type="submit">PAY KSh {{ $amountLabel }} WITH M-PESA</button>
        </form>

        <p id="pay-msg" class="msg" role="status" aria-live="polite"></p>
        <p class="note">You will receive an M-Pesa prompt on your phone. Enter your PIN to authorise the payment.
            This link is secure and expires automatically.</p>

        {{-- §17: cancel the whole pledge from the same secure link. --}}
        <form id="cancel-form" action="/api/pledges/pay/{{ $token }}/cancel" method="post">
            <button id="cancel-btn" class="btn-cancel" type="submit">CANCEL MY MONTHLY PLEDGE</button>
        </form>
        <p id="cancel-msg" class="msg" role="status" aria-live="polite"></p>

        <script>
            (function () {
                var form = document.getElementById('pay-form');
                var btn = document.getElementById('pay-btn');
                var msg = document.getElementById('pay-msg');
                var phone = document.getElementById('phone');
                var token = decodeURIComponent(location.pathname.split('/pay/')[1] || '');
                var polls = 0;
                var MAX_POLLS = 6;

                function show(text, ok) {
                    msg.textContent = text;
                    msg.className = 'msg ' + (ok ? 'ok' : 'err');
                }

                // The app returns validation failures as {detail:[{loc,msg,type}]}
                // (FastAPI parity) — coerce to a human string (spec §7).
                function detailText(detail) {
                    if (Array.isArray(detail)) {
                        return detail.map(function (d) { return (d && d.msg) || ''; })
                            .filter(Boolean).join(' ') || 'Invalid input.';
                    }
                    return detail;
                }

                // §10: after dispatching, poll server-side status so the page
                // reflects settlement without a manual reload (bounded window).
                // The budget resets on every dispatch; on exhaustion the button
                // is re-enabled so a supporter is never left stuck.
                function poll() {
                    if (polls >= MAX_POLLS) {
                        show('Still waiting for M-Pesa. Check your phone for a prompt, or tap pay to resend.', false);
                        btn.disabled = false;
                        btn.textContent = 'PAY WITH M-PESA';
                        return;
                    }
                    polls++;
                    setTimeout(function () {
                        fetch('/api/pledges/pay/' + encodeURIComponent(token) + '/status', {
                            headers: { 'Accept': 'application/json' }
                        }).then(function (res) {
                            if (res.status === 429) {
                                // Rate-limited — keep polling within the budget.
                                poll();
                                return;
                            }
                            if (!res.ok) return; // gone/invalid — stop.
                            return res.json();
                        }).then(function (s) {
                            if (!s) return;
                            if (s.status === 'PAID') {
                                show('Payment received — thank you!', true);
                                btn.textContent = 'PAID';
                                setTimeout(function () { location.reload(); }, 1500);
                                return;
                            }
                            if (s.status === 'FAILED') {
                                show('The M-Pesa request failed. Please try again.', false);
                                btn.disabled = false;
                                btn.textContent = 'PAY WITH M-PESA';
                                return;
                            }
                            poll();
                        }).catch(poll); // transient network error — keep trying
                    }, 5000);
                }

                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    btn.disabled = true;
                    btn.textContent = 'SENDING…';
                    msg.textContent = '';
                    msg.className = 'msg';

                    fetch('/api/pledges/pay/' + encodeURIComponent(token), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ phone: phone.value })
                    }).then(function (res) {
                        return res.json().then(function (data) {
                            if (res.status === 202) {
                                show(data.message || 'M-Pesa request sent. Check your phone and enter your M-Pesa PIN.', true);
                                btn.textContent = 'REQUEST SENT';
                                polls = 0; // fresh budget per dispatch
                                poll();
                                return;
                            }
                            if (res.status === 409) {
                                show(data.detail || "This month's pledge is already paid. Thank you!", true);
                                btn.textContent = 'ALREADY PAID';
                                return;
                            }
                            show(detailText(data.detail) || 'We could not start the M-Pesa request. Please try again.', false);
                            btn.disabled = false;
                            btn.textContent = 'PAY WITH M-PESA';
                        });
                    }).catch(function () {
                        show('Network error — please check your connection and try again.', false);
                        btn.disabled = false;
                        btn.textContent = 'PAY WITH M-PESA';
                    });
                });
            })();
        </script>
    @endif

    <script>
        // Shared §17 cancellation handler (paid + due states; invalid has no form).
        (function () {
            var cancelForm = document.getElementById('cancel-form');
            if (!cancelForm) return;
            var cancelBtn = document.getElementById('cancel-btn');
            var cancelMsg = document.getElementById('cancel-msg');
            var token = decodeURIComponent(location.pathname.split('/pay/')[1] || '');

            cancelForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!window.confirm('Cancel your monthly pledge? No further monthly payments will be requested.')) {
                    return;
                }
                cancelBtn.disabled = true;
                fetch('/api/pledges/pay/' + encodeURIComponent(token) + '/cancel', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' }
                }).then(function (res) {
                    return res.json().then(function (data) {
                        if (res.ok) {
                            var payForm = document.getElementById('pay-form');
                            if (payForm) payForm.style.display = 'none';
                            cancelForm.style.display = 'none';
                            cancelMsg.textContent = data.message || 'Your monthly pledge has been cancelled.';
                            cancelMsg.className = 'msg ok';
                            return;
                        }
                        cancelBtn.disabled = false;
                        cancelMsg.textContent = (data && data.detail) || 'Could not cancel — please try again.';
                        cancelMsg.className = 'msg err';
                    });
                }).catch(function () {
                    cancelBtn.disabled = false;
                    cancelMsg.textContent = 'Network error — please check your connection and try again.';
                    cancelMsg.className = 'msg err';
                });
            });
        })();
    </script>
</main>
</body>
</html>
