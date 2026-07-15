<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Payment Cancelled</title>

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
            font-size: 64px;
        }

        h1 {
            color: #dc2626;
        }

        .button {
            display: inline-block;
            margin-top: 20px;
            padding: 13px 20px;
            border-radius: 10px;
            color: #ffffff;
            background: #dc2626;
            text-decoration: none;
            font-weight: 700;
        }
    </style>
</head>

<body>
<div class="card">
    <div class="icon">❌</div>

    <h1>Payment Cancelled</h1>

    <p>
        The payment was cancelled or not completed.
    </p>

    <p>
        Reference:
        <strong>{{ $payment->merchant_ref_no }}</strong>
    </p>

    @if (
        $payment->status === 'pending'
        && $payment->payment_link
    )
        <a
            href="{{ route(
                'payway.payments.show',
                [
                    'merchantReference' =>
                        $payment->merchant_ref_no,
                ]
            ) }}"
            class="button"
        >
            Try again
        </a>
    @endif
</div>
</body>
</html>