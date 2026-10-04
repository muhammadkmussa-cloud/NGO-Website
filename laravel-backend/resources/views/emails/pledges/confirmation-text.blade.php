{{ $siteName }} — payment received
=================================

Hi {!! $name !!},

Thank you! We've received your payment for the {{ $monthLabel }} pledge.

  Amount : {{ $amountLabel }}
  Month  : {{ $monthLabel }}
  Paid on: {{ $paidAtLabel ?? '—' }}
@if ($payment->paystack_reference)
  Ref    : {{ $payment->paystack_reference }}
@endif

@if ($pledge->next_payment_date)
Your next pledge payment of {{ $amountLabel }} is scheduled for
{{ $pledge->next_payment_date->format('d M Y') }}. We'll remind you before it's due.
@endif

Questions about your pledge? Just reply — we're happy to help.

----------------------------------------

You're receiving this because you set up a monthly pledge with {{ $siteName }}.
{{ $supportEmail }}
