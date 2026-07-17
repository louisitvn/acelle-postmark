<?php

return [
    'name'        => 'Postmark',
    'description' => 'Send via Postmark (postmarkapp.com) — server token + auto-registered webhook',
    'intro'       => '<a href="https://postmarkapp.com" target="_blank">Postmark</a> is a transactional email service with strong deliverability. Generate a server token from your Postmark server\'s "API Tokens" tab.',

    'fields.server_token'             => 'Server Token',
    'fields.server_token_help'        => 'Per-Postmark-server token (from your Postmark server → API Tokens tab). Used for sending email and managing webhooks on this server.',
    'fields.account_token'            => 'Account Token (optional)',
    'fields.account_token_help'       => 'Account-wide token (from Postmark → Account → API Tokens). Optional — only needed if you want the platform to sync sender domains/signatures. Without it, send + webhook still work.',
    'fields.message_stream'           => 'Message Stream',
    'fields.message_stream_help'      => 'Postmark stream id to send on (default: "outbound"). Change only if you have a non-default transactional or broadcast stream.',
    'fields.webhook_url'              => 'Webhook URL',
    'fields.webhook_url_help'         => 'The platform auto-registers this URL with Postmark on first send. Bounce + spam-complaint events flow back via this URL.',
    'fields.webhook_url_after_save'   => '— Save the server first; the webhook URL will appear here after the row is created.',
    'fields.webhook_status_active'    => 'Webhook registered with Postmark (id #:id)',
    'fields.webhook_status_pending'   => 'Will be auto-registered on first send',
];
