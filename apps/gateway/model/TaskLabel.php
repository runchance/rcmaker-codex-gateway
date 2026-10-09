<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Plain user-authored labels for task lists and browser tabs; never changes execution messages. */
final class TaskLabel
{
    /** Remove operational envelopes and markup, preserving the human request and its language. */
    public static function summarize(string $text, int $limit = 96): string
    {
        $text = preg_replace('~<(in-app-browser-context|environment_context|recommended_plugins|external_codex_apps_open_page|app-context|permissions|skills_instructions|collaboration_mode|multi_agent_role|multi_agent_mode)\b[^>]*>.*?</\1>~siu', '', $text) ?? $text;
        if (preg_match('~(?:^|\n)##\s*My request:\s*\n?(.*)~su', $text, $match)) $text = $match[1];
        $text = preg_replace('~```[^\n]*\n.*?```~su', '', $text) ?? $text;
        $text = preg_replace('~!\[[^\]]*\]\([^)]*\)|\[([^\]]+)\]\([^)]*\)~u', '$1', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/(?:^|\n)\s*(?:#{1,6}\s+|[-*]\s+)/u', ' ', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', str_replace(['`', '**'], '', $text)) ?? $text);
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
    }
}
