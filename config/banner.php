<?php
/** Product CLI banner with the immutable release tag and framework color support. */
$gateway = RC\Config::get('gateway');
$chinese = \app\gateway\model\GatewayPresentation::locale() === 'zh-CN';
$color = (string) rcEnv('gateway.banner_color', 'auto');
if (!in_array($color, ['auto', 'always', 'never'], true)) $color = 'auto';
return [
    'app' => ['name' => $chinese ? 'RCMAKER AI 智能网关' : 'RCMAKER AI Gateway',
        'version' => (string) RC\Config::get('release', 'tag')],
    'color' => $color,
    'width' => 100,
    'lines' => [
        ['type' => 'separator', 'text' => '{app.name} {app.version}', 'color' => 'bright_cyan'],
        ['text' => $chinese ? 'ChatGPT Web 的推理能力 × Codex 原生开发工具' : 'ChatGPT Web reasoning × native Codex development tools', 'color' => 'bright_white', 'align' => 'center'],
        ['type' => 'blank'],
        ['text' => ($chinese ? '控制台  ' : 'Console  ') . $gateway['console_origin'], 'color' => 'green'],
        ['text' => ($chinese ? '数据目录  ' : 'Data     ') . $gateway['data_directory'], 'color' => 'gray'],
        ['text' => $chinese ? '首次运行：选择网络 → 登录 ChatGPT → 连接工具 → 接入 Codex' : 'First run: network → ChatGPT sign-in → tools → Codex integration', 'color' => 'cyan'],
        ['text' => $chinese ? '默认：3× 历史 · 自动最多六段 · 最终执行保留所选推理档位' : 'Defaults: 3× history · up to 6 automatic parts · selected final reasoning', 'color' => 'yellow'],
        ['text' => 'PHP {php.version} · {os} {arch} · {datetime}', 'color' => 'gray'],
        ['type' => 'separator', 'text' => $chinese ? '按 Ctrl+C 安全退出' : 'Press Ctrl+C to stop safely', 'color' => 'bright_cyan'],
    ],
];
