<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Payment Successful</title>

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #f3f4f6;
            font-family: Arial, sans-serif;
        }

        .card {
            width: 100%;
            max-width: 480px;
            padding: 36px;
            border-radius: 20px;
            background: #ffffff;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .08);
        }

        .icon {
            margin-bottom: 16px;
            font-size: 64p x;
        }

        h1 {
            color: #16a34a;
        }

        .details {
            margin-top: 24px;
            padding: 20px;
            border-radius: 12px;
            background: #f9fafb;
            text-align: left;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 8px 0;
        }
    </style>
</head>

<body>
<div class="card">
    @if ($payment->status === 'approved')
        <div class="icon">✅</div>

        <h1>Payment Successful</h1>

        <p>
            Your payment was completed successfully.
        </p>
    @else
        <div class="icon">⏳</div>

        <h1>Payment Processing</h1>

        <p>
            Your payment is not approved yet.
        </p>
    @endif

    <div class="details">
        <div class="row">
            <span>Reference</span>
            <strong>{{ $payment->merchant_ref_no }}</strong>
        </div>

        <div class="row">
            <span>Amount</span>

            <strong>
                {{ $payment->amount }}
                {{ $payment->currency }}
            </strong>
        </div>

        <div class="row">
            <span>Status</span>
            <strong>{{ strtoupper($payment->status) }}</strong>
        </div>

        @if ($payment->tran_id)
            <div class="row">
                <span>Transaction</span>
                <strong>{{ $payment->tran_id }}</strong>
            </div>
        @endif
    </div>
</div>
</body>
</html>