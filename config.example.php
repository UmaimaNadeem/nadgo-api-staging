<?php
/**
 * Configuration template.
 *
 * 1. Copy this file to `config.php`  (cp config.example.php config.php)
 * 2. Fill in your real GoDaddy / cPanel credentials below.
 * 3. NEVER commit config.php — it is git-ignored.
 *
 * Generate strong secrets:  php -r "echo bin2hex(random_bytes(32));"
 */

return [

    // --- Database (create in cPanel > MySQL Databases) ---------------------
    'db' => [
        'host'    => 'localhost',          // cPanel MySQL is almost always localhost
        'name'    => 'cpaneluser_nadgo',   // full DB name incl. cPanel prefix
        'user'    => 'cpaneluser_nadgo',   // DB user (also prefixed)
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // --- Keys & secrets ----------------------------------------------------
    // Frontend key: nad-go.com sends this in "X-API-Key" to create orders.
    'api_key' => 'CHANGE_ME_FRONTEND_KEY',
    // Admin key: the (future) admin portal sends this in "X-Admin-Key".
    'admin_api_key' => 'CHANGE_ME_ADMIN_KEY',
    // App secret: signs login session tokens. Keep private.
    'app_secret' => 'CHANGE_ME_APP_SECRET',

    // --- Branding (used in emails + admin portal) -------------------------
    'brand' => [
        'name'          => 'Nadgo',
        'app_url'       => 'https://api.nad-go.com',
        // Public URL of the logo (must be absolute for emails). Drop your file
        // at assets/logo.png so it is served from <app_url>/assets/logo.png.
        'logo_url'      => 'https://api.nad-go.com/assets/logo.png',
        'color'         => '#0F2057',   // primary brand colour (navy)
        'support_email' => 'admin@nad-go.com',
        'website_url'   => 'https://nad-go.com',
    ],

    // --- Email delivery ----------------------------------------------------
    // transport:
    //   'resend' = Resend HTTP API (https://resend.com). Recommended: only needs
    //              outbound HTTPS (never blocked on cPanel), simple + reliable.
    //   'brevo'  = Brevo HTTP API.
    //   'smtp'   = PHPMailer over SMTP (needs outbound SMTP ports open).
    'mail' => [
        'transport'    => 'resend',
        'from_email'   => 'noreply@nad-go.com',  // must be on a domain you've verified with the provider
        'from_name'    => 'Nadgo Orders',

        // Order/payment admin notifications go to ALL of these addresses.
        'admin_emails' => ['admin@nad-go.com', 'accounts@nad-go.com'],

        // "Request more info" / contact-form submissions go here.
        // Leave empty ([]) to fall back to admin_emails.
        'contact_emails' => ['info@nad-go.com'],

        // Trustpilot AFS (Automatic Feedback Service): BCC'd on the "order
        // delivered" email so Trustpilot triggers a review invite. Copy the
        // address from Trustpilot > Get reviews > Automatic Feedback Service.
        // Leave empty ('') to disable.
        'trustpilot_bcc' => 'nad-go.com+a6a3c8f412@invite.trustpilot.com',

        // Resend: resend.com -> API Keys. Verify nad-go.com under Domains first
        // (until then, you can send FROM onboarding@resend.dev for testing).
        'resend' => [
            'api_key' => 'CHANGE_ME_RESEND_API_KEY',   // starts with re_
            'api_url' => 'https://api.resend.com/emails',
        ],

        // Brevo (only if transport = 'brevo').
        'brevo' => [
            'api_key' => 'CHANGE_ME_BREVO_API_KEY',
            'api_url' => 'https://api.brevo.com/v3/smtp/email',
        ],

        // SMTP (only if transport = 'smtp').
        'smtp' => [
            'host'       => 'smtp.office365.com',
            'port'       => 587,             // 587 = STARTTLS, 465 = SMTPS
            'encryption' => 'tls',           // 'tls' for 587, 'ssl' for 465
            'username'   => 'CHANGE_ME',
            'password'   => 'CHANGE_ME',
        ],
    ],

    // --- Payment instructions shown to the customer in step 2 -------------
    'payment_instructions' => [
        'bank' => [
            'enabled'        => true,
            'account_name'   => 'NadGo Limited',
            'account_number' => '20000504',
            'sort_code'      => '04-13-59',
            'iban'           => 'GB48OPCD04135920000504',
            'swift'          => 'OPCDGB22XXX',
            'instructions'   => 'Use your order reference as the transfer note.',
        ],
        'crypto' => [
            'enabled' => true,
            'wallets' => [
                ['network' => 'USDT ERC20', 'address' => '0xd92815d58f69f9402a4122de930c3b3a196dd3af'],
                ['network' => 'USDT TRC20', 'address' => 'TPyeVQ6J1jjw16MzXb24Ejq7fuoUrpumBB'],
            ],
            'instructions' => 'Send the exact total, then submit your transaction ID.',
        ],
        'fena' => [
            'enabled' => false,  // set true once fena.terminal_id/terminal_secret are configured below
        ],
        'wallid' => [
            'enabled' => false,  // set true once wallid.api_key_id/secret are configured below
        ],
    ],

    // --- Fena Open Banking (Toolkit Payments API) --------------------------
    // Hosted-redirect Open Banking payments. A webhook marks the order paid
    // automatically (no admin verification). Set up at toolkit.fena.co.
    'fena' => [
        'enabled'         => false,
        'terminal_id'     => 'CHANGE_ME_FENA_TERMINAL_ID',
        'terminal_secret' => 'CHANGE_ME_FENA_TERMINAL_SECRET',   // same key works for sandbox + live

        // Fena has no separate sandbox API — the bank account you send as
        // `bankAccount` is what decides sandbox vs live, so switching between
        // them is just this flag plus the matching account ID below.
        // Set to 'live' only once bank_account_live is a verified account.
        'mode'                 => 'sandbox',
        'bank_account_sandbox' => '',   // optional: leave empty to use Fena's auto-created sandbox account
        'bank_account_live'    => '',   // required before switching mode to 'live'

        // Optional shared secret: append it as ?secret=xxx on the webhook URL
        // you register in the Fena dashboard (the endpoint URL is
        // https://api.nad-go.com/?action=fena_webhook&secret=xxx ). Fena does
        // not sign webhook bodies, so this is the only verification available.
        'webhook_secret'  => '',
        // Where Fena redirects the customer back. {ORDER_REF} is substituted.
        'success_url'     => 'https://nad-go.com/order/{ORDER_REF}?fena=1',
    ],

    // --- Wallid Payment Gateway ---------------------------------------------
    // Hosted-redirect Open Banking payments. A webhook marks the order paid
    // automatically (no admin verification). Set up at wallid.co.
    'wallid' => [
        'enabled'        => false,
        'api_key_id'     => 'CHANGE_ME_WALLID_API_KEY_ID',
        'api_key_secret' => 'CHANGE_ME_WALLID_API_KEY_SECRET',
        // Webhook signing secret from your Wallid dashboard (the endpoint URL
        // is  https://api.nad-go.com/?action=wallid_webhook ).
        'webhook_secret' => 'CHANGE_ME_WALLID_WEBHOOK_SECRET',
        'success_url'    => 'https://nad-go.com/order/{ORDER_REF}?wallid=1',
        'fail_url'       => 'https://nad-go.com/checkout?canceled=1',
    ],

    // --- Stripe card payments (in addition to manual bank/crypto) ----------
    // Card orders are paid on Stripe's hosted Checkout page; a webhook marks the
    // order paid automatically (no admin verification). Manual methods are
    // unchanged. Set up at dashboard.stripe.com.
    'stripe' => [
        'enabled'         => false,
        'secret_key'      => 'sk_live_or_test_xxx',
        'publishable_key' => 'pk_live_or_test_xxx',   // frontend reference only
        // Webhook signing secret from Stripe → Developers → Webhooks (the
        // endpoint URL is  https://api.nad-go.com/?action=stripe_webhook ).
        'webhook_secret'  => 'whsec_xxx',
        // Where Stripe sends the customer back. {ORDER_REF} is substituted.
        'success_url'     => 'https://nad-go.com/order/{ORDER_REF}?paid=1',
        'cancel_url'      => 'https://nad-go.com/checkout?canceled=1',
    ],

    // --- Royal Mail Click & Drop API --------------------------------------
    // Get your API key from Click & Drop > Settings > Integrations > Add New Integration.
    // No sandbox — this always hits the live Royal Mail endpoint.
    'royal_mail' => [
        'api_key' => 'CHANGE_ME_ROYAL_MAIL_API_KEY',
    ],

    // --- Receipt uploads ---------------------------------------------------
    'uploads' => [
        'dir'           => __DIR__ . '/uploads/receipts', // not web-served (see .htaccess)
        'max_bytes'     => 5 * 1024 * 1024,               // 5 MB
        'allowed_mime'  => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    ],

    // --- Login session (issued after register / login) ---------------------
    'session' => [
        'ttl_seconds' => 2592000,  // 30 days
    ],

    // --- Password reset (emailed code) -------------------------------------
    'password_reset' => [
        'code_length'  => 6,
        'ttl_seconds'  => 900,   // code valid for 15 minutes
        'max_attempts' => 5,     // wrong-code tries before the code is locked
    ],

    // --- Rate limiting -----------------------------------------------------
    // Sliding window per action. limit = max requests within window seconds.
    // Set limit to 0 (or remove an action) to disable that limit.
    'rate_limits' => [
        'create_order'    => ['limit' => 30, 'window' => 3600], // per IP / hour
        'register'        => ['limit' => 10, 'window' => 3600], // per IP / hour
        'login'           => ['limit' => 10, 'window' => 900],  // per IP+email / 15 min (anti brute-force)
        'submit_payment'  => ['limit' => 20, 'window' => 3600], // per IP / hour
        'contact'         => ['limit' => 10, 'window' => 3600], // per IP / hour
        'notify_me_toggle'=> ['limit' => 30, 'window' => 3600], // per IP / hour
        'request_password_reset'    => ['limit' => 5,  'window' => 900],  // per IP+email / 15 min
        'request_password_reset_ip' => ['limit' => 20, 'window' => 3600], // per IP / hour (anti email-bomb)
        'reset_password'            => ['limit' => 10, 'window' => 900],  // per IP+email / 15 min
    ],

    // --- Honeypot anti-bot -------------------------------------------------
    // A field with this name must be present-but-empty on register/order forms.
    // Bots that auto-fill every field will populate it and get rejected.
    'honeypot_field' => 'website',

    // --- Cloudflare Turnstile (bot check on register / guest checkout) -----
    // Create a widget at https://dash.cloudflare.com -> Turnstile.
    // The frontend uses site_key; the server verifies with secret_key.
    // Set 'enabled' => true once both keys are filled in.
    'turnstile' => [
        'enabled'    => false,
        'site_key'   => 'CHANGE_ME_TURNSTILE_SITE_KEY',   // frontend reference only
        'secret_key' => 'CHANGE_ME_TURNSTILE_SECRET',
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

    // --- Request logging (developer view at /logs, admin only) -------------
    'logging' => [
        'enabled'        => true,
        'retention_days' => 14,   // older log rows are pruned automatically
    ],

    // --- CORS --------------------------------------------------------------
    // Origins allowed to call this API from a browser.
    'cors_allowed_origins' => [
        'https://nad-go.com',
        'https://www.nad-go.com',
    ],

    // --- Behaviour ---------------------------------------------------------
    // When true, full PHP errors are shown in the response. Keep false in prod.
    'debug' => false,
];
