<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('AI_CHAT_ENABLED', false),
    'daily_limit' => 20,
    'history_messages' => 20,
    'auto_title' => (bool) env('AI_CHAT_AUTO_TITLE', true),
    'system_prompt' => env('AI_CHAT_SYSTEM_PROMPT', 'あなたは資格学習の補助をする相談相手です。日本語で分かりやすく説明してください。回答は参考情報であることを踏まえ、不確かなことは断定しないでください。'),
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'timeout' => 30,
        'connect_timeout' => 5,
    ],
];
