# Runtime log export / 运行日志导出

## Using the console / 控制台使用

Open **Runtime logs → Download diagnostic bundle**. Task center also provides
an **Export runtime logs** shortcut. Choose the last hour, 24 hours (default),
3 days or 7 days. The browser downloads a ZIP locally; no report is uploaded
automatically. A runtime without the ZIP extension downloads JSON instead.

打开 **运行日志 → 下载诊断包**，或使用任务中心的 **导出运行日志** 入口。
可选择最近 1 小时、24 小时（默认）、3 天或 7 天。文件下载到本机，不自动上传。
没有 ZIP 扩展的运行环境下载 JSON。浏览器、账号或模型未就绪时仍可导出。

The ZIP contains `README.txt`, `manifest.json`, `environment.json`,
`records.json` and `logs.json`. Start with the manifest. Correlate `task_id`,
`turn_id`, `response_id`, checkpoint identifiers and timestamps across the
records and logs. The manifest records UTC export time, the server timezone,
the selected time window, row caps, missing columns and unavailable sections.

先看 `manifest.json`，再通过任务、回合、响应和检查点编号关联记录。时间使用
Unix 秒或 ISO-8601；清单说明服务器时区、导出时刻、时间范围、采样上限与缺失项。

## Data boundary / 数据范围

Exports contain structural runtime evidence: program version and platform,
task/turn/response states, context-budget and multipart progress, checkpoint
and handoff states, tool-call delivery/completion state, and browser,
connector, tunnel and network health timestamps. Existing file logs are
projected into recognized diagnostic fields and error signatures.

诊断包保留程序和平台信息、任务/回合/响应状态、上下文预算与分段进度、压缩交接状态、
工具派发与完成状态，以及浏览器、连接器、隧道和网络的状态时间。
文件日志只提取可识别的结构字段和错误特征。

The database file, raw prompts, contracts, conversations, tool arguments or
result bodies, summary text, attachments, browser profiles, credentials,
cookies, capability tokens, URLs and absolute paths are excluded. Unknown
free-form log content is omitted, not guessed to be safe. Review a bundle
before sharing it publicly. This is diagnostic evidence, not a backup.

不导出数据库本体、原始对话和契约、工具参数或结果正文、摘要正文、附件、浏览器资料、
凭据、Cookie、能力令牌、URL 与绝对路径。未知自由文本会被省略。公开分享前请检查内容。
诊断包用于排错，不能用于恢复聊天。

## Implementation and limits / 实现与上限

`GET /api/diagnostics/export?hours=24` uses the existing loopback-only
`GatewayLocal` middleware, exact console origin and `X-Gateway-Request`
header. An optional `task_id` filters task-owned records; health snapshots
and file logs remain global. The route does not invoke schema migration,
task recovery, model requests, browser commands or task cancellation.

Structured JSON is capped at 4 MiB. Each table has an explicit newest-row
candidate cap, then its candidates are time-filtered. Each SQL projection
caps TEXT/BLOB fields before loading them into PHP. Each fixed log file
contributes at most a 64 KiB tail, and incomplete appended records are
excluded. Logs and collection metadata retain reserved space. Collection
checks a three-second budget between table reads; this is not a hard
deadline for an individual database call. Each query finishes without a
long-lived transaction or writer lock.

诊断数据最多 4 MiB；数据库先按每表上限取最新候选记录，再过滤时间，避免全表扫描。
大字段在 SQL 读取时即限制字节数；每个固定日志文件最多读取末尾 64 KiB，半条记录不纳入。
表查询之间检查三秒采集预算，单次数据库调用不承诺硬性三秒超时。
正在运行的任务可继续写入，因此各表是近实时采样，不是同一事务内的一致性快照。

ZIP staging uses a uniquely named private file under the configured external
data directory (`diagnostics/tmp`). The file is removed in `finally` after
the bounded archive is loaded for the framework response. There is no
export history table or permanent archive accumulation during normal
completion. Missing/corrupt databases and old-schema columns produce
explicit partial-collection metadata; independent file logs are still
attempted. No production service restart is performed by this feature.

临时 ZIP 在外部 data 目录的 `diagnostics/tmp` 中生成，返回前在 `finally` 清理；
不增加导出历史表。数据库缺失、损坏或旧表缺字段时标明不完整项，仍尝试独立采集文件日志。
导出功能不会重启服务。

## Verification / 验证

Development fixtures live in `data/tests/gateway/diagnostic-export.php` and
`data/tests/gateway/diagnostic-http.php`. They use disposable databases and
synthetic logs. The HTTP fixture starts only isolated API and console APP
processes on ports 49471 and 49470, never browser/tunnel/task workers. Its
three concurrent writers verify that exports neither lose events nor
change working-task status. Passing `hold` keeps those isolated servers
available for browser verification; enter `stop` to close them.

控制台内嵌页通过同源顶层页面触发下载，以兼容桌面应用内嵌浏览器；独立页面沿用自己的下载环境。
导出按钮会显示等待和失败状态，并阻止重复提交；中英切换会同步刷新 Layui 下拉框。
