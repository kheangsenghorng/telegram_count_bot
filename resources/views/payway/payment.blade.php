<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>ABA PayWay Payment</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 24px;
            background: #f3f4f6;
            color: #111827;
            font-family: Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            width: 100%;
            max-width: 520px;
            margin: 0 auto;
        }

        .card {
            padding: 32px;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.08);
        }

        .title {
            margin: 0 0 8px;
            text-align: center;
        }

        .subtitle {
            margin: 0 0 28px;
            color: #6b7280;
            text-align: center;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 13px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .label {
            color: #6b7280;
        }

        .value {
            text-align: right;
            font-weight: 700;
            word-break: break-word;
        }

        .status {
            margin: 24px 0 16px;
            padding: 14px;
            border-radius: 12px;
            text-align: center;
            font-weight: 700;
        }

        .pending {
            color: #92400e;
            background: #fef3c7;
        }

        .approved {
            color: #166534;
            background: #dcfce7;
        }

        .failed,
        .expired,
        .cancelled {
            color: #991b1b;
            background: #fee2e2;
        }

        .message {
            margin: 0 0 20px;
            color: #6b7280;
            text-align: center;
        }

        .actions {
            display: grid;
            gap: 12px;
        }

        .button {
            display: block;
            width: 100%;
            padding: 14px 18px;
            border: 0;
            border-radius: 10px;
            text-align: center;
            text-decoration: none;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }

        .button:disabled {
            cursor: not-allowed;
            opacity: 0.65;
        }

        .primary {
            color: #ffffff;
            background: #dc2626;
        }

        .primary:hover:not(:disabled) {
            background: #b91c1c;
        }

        .secondary {
            color: #374151;
            background: #e5e7eb;
        }

        .secondary:hover {
            background: #d1d5db;
        }

        .hidden {
            display: none;
        }
    </style>
</head>

<body>
<div class="container">
    <div
        id="payment-data"
        class="card"
        data-initial-status="{{ $payment->status }}"
        data-status-url="{{ route(
            'payway.payments.status',
            [
                'merchantReference' => $payment->merchant_ref_no,
            ]
        ) }}"
        data-payment-link="{{ $payment->payment_link }}"
        data-cancel-url="{{ route(
            'payway.payment-cancelled',
            [
                'merchantReference' => $payment->merchant_ref_no,
            ]
        ) }}"
    >
        <h1 class="title">
            ABA PayWay Payment
        </h1>

        <p class="subtitle">
            Complete your payment securely.
        </p>

        <div class="row">
            <span class="label">Title</span>

            <span class="value">
                {{ $payment->title }}
            </span>
        </div>

        <div class="row">
            <span class="label">Reference</span>

            <span class="value">
                {{ $payment->merchant_ref_no }}
            </span>
        </div>

        <div class="row">
            <span class="label">Amount</span>

            <span class="value">
                {{ $payment->amount }}
                {{ $payment->currency }}
            </span>
        </div>

        <div
            id="payment-status"
            class="status {{ $payment->status }}"
        >
            {{ strtoupper($payment->status) }}
        </div>

        <p
            id="payment-message"
            class="message"
        >
            @if ($payment->status === 'approved')
                Payment completed successfully.
            @elseif ($payment->status === 'failed')
                Payment failed.
            @elseif ($payment->status === 'expired')
                This payment link has expired.
            @elseif ($payment->status === 'cancelled')
                Payment was cancelled.
            @else
                Click the payment button to continue.
            @endif
        </p>

        <div
            id="payment-actions"
            class="actions"
        >
            @if (
                $payment->status === 'pending'
                && $payment->payment_link
            )
                <button
                    type="button"
                    id="pay-now-button"
                    class="button primary"
                >
                    Pay now with ABA PayWay
                </button>

                
                    id="cancel-button"
                    href="{{ route(
                        'payway.payment-cancelled',
                        [
                            'merchantReference' =>
                                $payment->merchant_ref_no,
                        ]
                    ) }}"
                    class="button secondary"
                >
                    Cancel payment
                </a>
            @endif
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const paymentData =
            document.getElementById('payment-data');

        const statusElement =
            document.getElementById('payment-status');

        const messageElement =
            document.getElementById('payment-message');

        const actionsElement =
            document.getElementById('payment-actions');

        const payButton =
            document.getElementById('pay-now-button');

        if (
            !paymentData
            || !statusElement
            || !messageElement
        ) {
            return;
        }

        const initialStatus =
            paymentData.dataset.initialStatus ?? '';

        const statusUrl =
            paymentData.dataset.statusUrl ?? '';

        const paymentLink =
            paymentData.dataset.paymentLink ?? '';

        let paymentWindow = null;
        let statusTimer = null;
        let requestInProgress = false;

        const stopPolling = () => {
            if (statusTimer !== null) {
                window.clearInterval(statusTimer);
                statusTimer = null;
            }
        };

        const closePaymentWindow = () => {
            if (
                paymentWindow
                && !paymentWindow.closed
            ) {
                paymentWindow.close();
            }

            paymentWindow = null;
        };

        const hideActions = () => {
            if (actionsElement) {
                actionsElement.classList.add('hidden');
            }
        };

        const updateStatusDisplay = (status) => {
            statusElement.textContent =
                status.toUpperCase();

            statusElement.className =
                `status ${status}`;

            if (status === 'approved') {
                messageElement.textContent =
                    'Payment completed successfully.';
            } else if (status === 'expired') {
                messageElement.textContent =
                    'This payment link has expired.';
            } else if (status === 'cancelled') {
                messageElement.textContent =
                    'Payment was cancelled.';
            } else if (status === 'failed') {
                messageElement.textContent =
                    'Payment failed.';
            } else {
                messageElement.textContent =
                    'Waiting for payment confirmation...';
            }
        };

        const checkPaymentStatus = async () => {
            if (
                requestInProgress
                || statusUrl === ''
            ) {
                return;
            }

            requestInProgress = true;

            try {
                const response = await fetch(statusUrl, {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                    },
                    cache: 'no-store',
                });

                if (!response.ok) {
                    console.error(
                        'Payment status request failed:',
                        response.status
                    );

                    return;
                }

                const result = await response.json();

                const status =
                    result.data?.status ?? null;

                if (!status) {
                    return;
                }

                updateStatusDisplay(status);

                if (status === 'approved') {
                    stopPolling();
                    closePaymentWindow();
                    hideActions();

                    if (result.data?.success_url) {
                        window.location.assign(
                            result.data.success_url
                        );
                    }

                    return;
                }

                if (
                    status === 'failed'
                    || status === 'expired'
                    || status === 'cancelled'
                ) {
                    stopPolling();
                    closePaymentWindow();
                    hideActions();
                }
            } catch (error) {
                console.error(
                    'Unable to check payment status.',
                    error
                );
            } finally {
                requestInProgress = false;
            }
        };

        const startPolling = () => {
            if (
                statusTimer !== null
                || statusUrl === ''
            ) {
                return;
            }

            checkPaymentStatus();

            statusTimer = window.setInterval(
                checkPaymentStatus,
                3000
            );
        };

        const openPayWay = () => {
            if (paymentLink === '') {
                messageElement.textContent =
                    'Payment link is unavailable.';

                return;
            }

            paymentWindow = window.open(
                paymentLink,
                'aba-payway-payment',
                [
                    'width=520',
                    'height=760',
                    'scrollbars=yes',
                    'resizable=yes',
                    'noopener=no',
                ].join(',')
            );

            if (!paymentWindow) {
                messageElement.textContent =
                    'Popup was blocked. Please allow popups and try again.';

                return;
            }

            if (payButton) {
                payButton.disabled = true;
                payButton.textContent =
                    'Waiting for payment...';
            }

            updateStatusDisplay('pending');
            startPolling();
        };

        if (payButton) {
            payButton.addEventListener(
                'click',
                openPayWay
            );
        }

        if (initialStatus === 'pending') {
            startPolling();
        }

        window.addEventListener(
            'beforeunload',
            () => {
                stopPolling();
                closePaymentWindow();
            }
        );
    });
</script>
</body>
</html>