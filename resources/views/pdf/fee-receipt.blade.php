<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment receipt</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        h1 { font-size: 18px; margin-bottom: 6px; }
        .box { border: 1px solid #ccc; padding: 14px; margin-top: 12px; max-width: 480px; }
        dt { font-weight: bold; margin-top: 6px; }
        dd { margin: 0 0 4px 12px; }
    </style>
</head>
<body>
    <h1>{{ $appName }}</h1>
    <p>Payment receipt #{{ $payment->id }}</p>
    <div class="box">
        <dl>
            <dt>Paid at</dt>
            <dd>{{ $payment->paid_at->format('Y-m-d H:i') }}</dd>
            <dt>Student</dt>
            <dd>{{ $payment->fee?->student->name ?? '—' }} @if($payment->fee?->student?->admission_number) ({{ $payment->fee->student->admission_number }}) @endif</dd>
            <dt>Fee title</dt>
            <dd>{{ $payment->fee?->title ?? '—' }}</dd>
            <dt>Amount paid</dt>
            <dd>{{ $payment->amount }}</dd>
            <dt>Method</dt>
            <dd>{{ $payment->payment_method ?? '—' }}</dd>
            <dt>Reference</dt>
            <dd>{{ $payment->reference ?? '—' }}</dd>
            @if($payment->recordedBy)
                <dt>Recorded by</dt>
                <dd>{{ $payment->recordedBy->name }}</dd>
            @endif
        </dl>
    </div>
</body>
</html>
