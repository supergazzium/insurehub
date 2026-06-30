<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'zoho' => [
        // Region of your Zoho Mail account. Affects the API host:
        //   com → mail.zoho.com / accounts.zoho.com
        //   eu  → mail.zoho.eu  / accounts.zoho.eu
        //   in  → mail.zoho.in  / accounts.zoho.in
        //   com.au, com.cn, jp also valid.
        'region' => env('ZOHO_REGION', 'com'),

        // OAuth app credentials from https://api-console.zoho.com/
        'client_id' => env('ZOHO_CLIENT_ID'),
        'client_secret' => env('ZOHO_CLIENT_SECRET'),
        // One-time refresh token, generated with scope
        // ZohoMail.messages.ALL,ZohoMail.accounts.READ,ZohoMail.attachments.ALL
        'refresh_token' => env('ZOHO_REFRESH_TOKEN'),

        // Zoho Account ID — the integer the Mail API requires in the URL path.
        // Fetch once via `GET https://mail.zoho.<region>/api/accounts` with a valid
        // access token; copy the `accountId` of the mailbox you want to send from.
        'account_id' => env('ZOHO_ACCOUNT_ID'),

        // Identity used in `fromAddress`. Must match a verified Zoho Mail address
        // for the account above.
        'from_address' => env('ZOHO_FROM_ADDRESS', 'no-reply@insurehub.co.th'),
        'from_name' => env('ZOHO_FROM_NAME', 'InsureHub'),

        // Plus-addressing alias used in Reply-To. The poll worker greps
        // inbound mail for this prefix to thread replies back to a case.
        'reply_alias_prefix' => env('ZOHO_REPLY_ALIAS_PREFIX', 'no-reply'),

        // Polling: how often the artisan command runs is controlled in
        // routes/console.php; this is just the per-call window.
        'poll_window_minutes' => (int) env('ZOHO_POLL_WINDOW_MINUTES', 10),
    ],

];
