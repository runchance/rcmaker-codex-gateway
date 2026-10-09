<?php
declare(strict_types=1);
namespace support\browser\adapter;

use support\browser\ChatGptPage;

/** Current ChatGPT Web DOM/profile adapter. Version changes invalidate old verification evidence. */
final class ChatGptWebAdapter implements WebModelAdapterInterface
{
    public static function id(): string { return 'chatgpt-web'; }
    public static function version(): string { return '2026-09-21.1'; }
    public static function provider(): string { return 'OpenAI ChatGPT Web'; }
    public static function pageClass(): string { return ChatGptPage::class; }

    public static function declaredCapabilities(): array
    {
        return [
            'text', 'temporary_chat', 'model_effort', 'image_input', 'skill_inline', 'skill_file',
            'tool_roundtrip', 'artifact_download',
        ];
    }
}
