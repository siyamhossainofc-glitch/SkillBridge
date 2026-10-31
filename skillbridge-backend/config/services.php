<?php

return [

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

    'payments' => [
        'default' => env('PAYMENT_PROVIDER', 'mock'),
        'currency' => env('PAYMENT_CURRENCY', 'BDT'),
        'mock' => [
            'webhook_secret' => env('MOCK_PAYMENT_WEBHOOK_SECRET', 'local-mock-payment-secret'),
            'fail_create' => env('MOCK_PAYMENT_FAIL_CREATE', false),
        ],
    ],

    'ai_parser' => [
        'key' => env('AI_PARSER_API_KEY', env('OPENAI_API_KEY')),
        'endpoint' => env('AI_PARSER_ENDPOINT', env('OPENAI_API_BASE', 'https://api.openai.com/v1').'/chat/completions'),
        'model' => env('AI_PARSER_MODEL', 'gpt-5-mini'),
    ],

    'ai_matching' => [
        'key' => env('AI_MATCHING_API_KEY', env('OPENAI_API_KEY')),
        'endpoint' => env('AI_MATCHING_ENDPOINT', env('OPENAI_API_BASE', 'https://api.openai.com/v1').'/chat/completions'),
        'model' => env('AI_MATCHING_MODEL', 'gpt-5-mini'),
    ],

    'ai_proposal' => [
        'key' => env('AI_PROPOSAL_API_KEY', env('OPENAI_API_KEY')),
        'endpoint' => env('AI_PROPOSAL_ENDPOINT', env('OPENAI_API_BASE', 'https://api.openai.com/v1').'/chat/completions'),
        'model' => env('AI_PROPOSAL_MODEL', 'gpt-5-mini'),
    ],

    'ai_fraud' => [
        'key' => env('AI_FRAUD_API_KEY', env('OPENAI_API_KEY')),
        'endpoint' => env('AI_FRAUD_ENDPOINT', env('OPENAI_API_BASE', 'https://api.openai.com/v1').'/chat/completions'),
        'model' => env('AI_FRAUD_MODEL', 'gpt-5-mini'),
    ],

];
