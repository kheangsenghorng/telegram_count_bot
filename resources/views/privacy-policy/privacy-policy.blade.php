<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="description"
        content="Privacy Policy for Telegram payment and subscription bot."
    >

    <title>Privacy Policy</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-slate-50 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-5">
            <a
                href="{{ url('/') }}"
                class="text-xl font-bold text-slate-900"
            >
                Sum Payment Bot
            </a>

            <a
                href="https://t.me/your_support_username"
                target="_blank"
                rel="noopener noreferrer"
                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
            >
                Contact Support
            </a>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-6 py-12">
        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-8 py-12 text-white">
                <div class="mb-4 inline-flex rounded-full bg-white/15 px-4 py-2 text-sm font-medium">
                    🔒 Your privacy matters
                </div>

                <h1 class="text-4xl font-bold tracking-tight">
                    Privacy Policy
                </h1>

                <p class="mt-4 max-w-2xl text-blue-100">
                    This policy explains how our Telegram bot collects,
                    uses, stores, and protects your information.
                </p>

                <p class="mt-6 text-sm text-blue-100">
                    Last updated: July 15, 2026
                </p>
            </div>

            <article class="space-y-10 px-8 py-10 leading-7 text-slate-700">
                <section>
                    <h2 class="mb-4 text-2xl font-bold text-slate-900">
                        1. Information We Collect
                    </h2>

                    <p class="mb-4">
                        When you use our Telegram bot and related services,
                        we may collect information required to operate the service.
                    </p>

                    <ul class="list-disc space-y-2 pl-6">
                        <li>Telegram user ID and username</li>
                        <li>First name and last name</li>
                        <li>Telegram group ID and group name</li>
                        <li>Package and subscription information</li>
                        <li>Payment amount, currency, reference, and date</li>
                        <li>Bot commands and service activity logs</li>
                    </ul>
                </section>

                <section>
                    <h2 class="mb-4 text-2xl font-bold text-slate-900">
                        2. Payment Information
                    </h2>

                    <p>
                        Our service may process payment notifications from ABA
                        PayWay, KHQR, and other supported payment providers.
                    </p>

                    <div class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-5">
                        <h3 class="font-semibold text-red-800">
                            We do not collect or store:
                        </h3>

                        <ul class="mt-3 list-disc space-y-1 pl-6 text-red-700">
                            <li>ABA Mobile password</li>
                            <li>Telegram password</li>
                            <li>PIN or OTP</li>
                            <li>CVV</li>
                            <li>Full card credentials</li>
                        </ul>
                    </div>

                    <p class="mt-5">
                        When recurring payments are enabled, we may store an
                        encrypted payment token returned by the payment provider.
                    </p>
                </section>

                <section>
                    <h2 class="mb-4 text-2xl font-bold text-slate-900">
                        3. How We Use Information
                    </h2>

                    <ul class="list-disc space-y-2 pl-6">
                        <li>Create and manage your account</li>
                        <li>Connect Telegram groups</li>
                        <li>Detect supported payment notifications</li>
                        <li>Prevent duplicate payment records</li>
                        <li>Calculate payment and group usage limits</li>
                        <li>Activate and renew subscriptions</li>
                        <li>Send payment and renewal notifications</li>
                        <li>Provide customer support</li>
                        <li>Prevent fraud and abuse</li>
                    </ul>
                </section>

                <section>
                    <h2 class="mb-4 text-2xl font-bold text-slate-900">
                        4. Telegram Group Messages
                    </h2>

                    <p>
                        When the bot is added to a Telegram group, it may read
                        messages required to identify supported payment
                        notifications and bot commands.
                    </p>

                    <p class="mt-4">
                        Unrelated group messages are not used for advertising
                        or marketing purposes.
                    </p>
                </section>

                <section>
                    <h2 class="mb-4 text-2xl font-bold text-slate-900">
                        5. Data Sharing
                    </h2>

                    <p>
                        We do not sell your personal information.
                    </p>

                    <p class="mt-4">
                        Limited information may be shared with payment providers,
                        hosting providers, infrastructure providers, or legal
                        authorities when required to operate the service or
                        comply with applicable law.
                    </p>
                </section>

                <section>
                    <h2 class="mb-4 text-2xl font-bold text-slate-900">
                        6. Data Security
                    </h2>

                    <p>
                        We use reasonable technical and organizational measures
                        to protect your information.
                    </p>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                            <h3 class="font-semibold text-slate-900">
                                Encrypted connections
                            </h3>

                            <p class="mt-2 text-sm">
                                Network communication is protected using secure
                                HTTPS connections where available.
                            </p>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                            <h3 class="font-semibold text-slate-900">
                                Restricted access
                            </h3>

                            <p class="mt-2 text-sm">
                                Database and administrative access is limited to
                                authorized systems and personnel.
                            </p>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                            <h3 class="font-semibold text-slate-900">
                                Encrypted tokens
                            </h3>

                            <p class="mt-2 text-sm">
                                Supported payment tokens are encrypted before
                                being stored.
                            </p>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                            <h3 class="font-semibold text-slate-900">
                                Activity monitoring
                            </h3>

                            <p class="mt-2 text-sm">
                                Logs may be used to detect errors, abuse, and
                                unauthorized activity.
                            </p>
                        </div>
                    </div>
                </section>

                <section>
                    <h2 class="mb-4 text-2xl font-bold text-slate-900">
                        7. Data Retention
                    </h2>

                    <p>
                        We retain information only for as long as reasonably
                        necessary to provide the service, resolve disputes,
                        prevent fraud, and comply with legal or accounting
                        obligations.
                    </p>
                </section>

                <section>
                    <h2 class="mb-4 text-2xl font-bold text-slate-900">
                        8. Your Rights
                    </h2>

                    <p class="mb-4">
                        You may contact us to request:
                    </p>

                    <ul class="list-disc space-y-2 pl-6">
                        <li>Access to your stored information</li>
                        <li>Correction of inaccurate information</li>
                        <li>Deletion of eligible account data</li>
                        <li>Disconnection of a Telegram group</li>
                        <li>Cancellation of a subscription</li>
                        <li>Removal of a saved payment token</li>
                    </ul>
                </section>

                <section>
                    <h2 class="mb-4 text-2xl font-bold text-slate-900">
                        9. Third-Party Services
                    </h2>

                    <p>
                        Our service may integrate with Telegram, ABA PayWay,
                        KHQR-supported payment providers, hosting services,
                        and other infrastructure providers.
                    </p>

                    <p class="mt-4">
                        These third parties have their own privacy policies and
                        security practices.
                    </p>
                </section>

                <section>
                    <h2 class="mb-4 text-2xl font-bold text-slate-900">
                        10. Changes to This Policy
                    </h2>

                    <p>
                        We may update this Privacy Policy when our services,
                        legal obligations, or data-processing practices change.
                        The latest version will be published on this page.
                    </p>
                </section>

                <section class="rounded-2xl bg-slate-900 p-8 text-white">
                    <h2 class="text-2xl font-bold">
                        Contact Us
                    </h2>

                    <p class="mt-3 text-slate-300">
                        Contact us for privacy questions, account deletion,
                        payment-token removal, or support.
                    </p>

                    <div class="mt-6 space-y-3">
                        <p>
                            <strong>Business:</strong>
                            Sum Payment Bot
                        </p>

                        <p>
                            <strong>Email:</strong>

                            <a
                                href="mailto:support@yourdomain.com"
                                class="text-blue-300 hover:underline"
                            >
                                support@yourdomain.com
                            </a>
                        </p>

                        <p>
                            <strong>Telegram:</strong>

                            <a
                                href="https://t.me/your_support_username"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-blue-300 hover:underline"
                            >
                                @your_support_username
                            </a>
                        </p>
                    </div>
                </section>
            </article>
        </section>

        <p class="mt-8 text-center text-sm text-slate-500">
            © {{ now()->year }} Sum Payment Bot. All rights reserved.
        </p>
    </main>
</body>
</html>