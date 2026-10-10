# rcmaker-codex-gateway — Frequently Asked Questions

**English** · [简体中文](faq.zh-CN.md) · [User guide](user-guide.en.md) · [README](../README.md)

Start with the user guide's [troubleshooting table](user-guide.en.md#troubleshooting), then use the sections below for details. Do not treat every delay as the same connection problem.

## Find the right section

| Symptom or question | Section |
| --- | --- |
| Console cannot open; proxy or download failure | [Startup and network](#network) |
| Tunnel/plugin cannot connect; rename or restart questions | [Connector](#connector) |
| Missing Web models, Pro or effort choices | [Models](#models) |
| Frequent compaction, six parts, unreadable old history | [Context and history](#history) |
| Stalled progress, 409, interrupted streams or JSON errors | [Tasks and recovery](#recovery) |
| Official quota versus local estimates | [Usage](#usage) |
| Task deletion, data growth and upgrades | [Cleanup and storage](#cleanup) |

<a id="network"></a>

## Startup and network

### The console does not open. What should I check first?

Check that the RCMAKER process is still running, that startup did not report a port conflict, and that you used the configured console port on the same computer. The default is `http://127.0.0.1:28910/`. Do not launch a second copy against the same ports/data to fix the first one. See [installation](user-guide.en.md#install).

### Which proxy types are supported?

The console supports direct access or an explicit **HTTP CONNECT** proxy. Use the actual HTTP host/port exposed by your proxy software. A SOCKS-only port, `socks5://...`, or an arbitrary system proxy setting is not a substitute. Host fields accept a hostname/IP without a URL scheme. Save the route before downloading components.

### Why can network detection succeed even when HTTP 403 is shown?

A target HTTP response after a successful TLS connection proves that the network route worked; it does not prove sign-in or model access. A proxy rejecting the CONNECT tunnel is different and is a network failure. Continue with the separate private-browser account/model checks only after connectivity is confirmed. The [network guide](user-guide.en.md#setup) shows the fields.

### Does Chrome download use the proxy too?

The application's browser, runtime download and tunnel consumers use the saved network configuration. Check that the route was saved/applied and that the proxy is running. Inspect the download error and disk space before retrying. Do not disable certificate checks or swap to an unrelated download source as a shortcut.

<a id="connector"></a>

## Connector

### ChatGPT cannot create the plugin. Which order should I follow?

Prepare components and sign in first. Save **Tunnel ID + ordinary runtime API key + connector name** in RCMAKER and wait for the tunnel to connect. Then create the custom MCP plugin in ChatGPT, choosing the matching Tunnel and name, and finally run **Check connector**. See the [screenshots](user-guide.en.md#connect-tools).

If the Tunnel is absent, check its account/workspace access rather than repeatedly creating duplicates. Current external setup requirements are documented by [OpenAI](https://developers.openai.com/api/docs/guides/secure-mcp-tunnels).

### Is the key an Admin key? Do Codex and ChatGPT need the same login?

Use an **ordinary runtime API key** with the Tunnel permissions required by your organization, not an Admin key. The console's intended setup aligns the Tunnel, key and private-browser ChatGPT identity. Desktop Codex may use another login. Organization/workspace permission checks still apply; matching an email alone is not a permission check.

### Is the connector always named RCMAKER Codex5?

No. A fresh installation defaults to **RCMAKER Codex**. `RCMAKER` is fixed and the suffix is editable. Existing installations retain their saved name. Copy the exact full name into ChatGPT. After changing it, create a matching new plugin and verify it; avoid connection changes while work is active.

### Do I need to recreate or check the connector after every restart?

Ordinary restarts reconnect using saved configuration. Recreating the plugin is not a normal startup step. **Check connector** tests the connection. ChatGPT's **Refresh tools / Refresh** updates its cached tool definitions and is relevant when a release changes schemas. They are different operations.

### ChatGPT has different menus or no Developer Mode. What now?

Use the current [Add custom MCP server instructions](https://developers.openai.com/api/docs/guides/custom-mcp-server). The interface may use Plugins, Apps or Developer Mode, depending on account/workspace. Check the [developer-mode guide](https://help.openai.com/en/articles/12584461-developer-mode-and-mcp-apps-in-chatgpt) and workspace permissions. RCMAKER cannot grant a feature absent from the account.

### The native browser is signed in, but Setup still looks stale.

Return to Setup, allow account/model detection to settle, and use **Refresh status** or **Refresh page** when needed. Ensure you signed into RCMAKER's private native window, not only system Chrome. Do not clear all profiles as a first response; that removes login state and requires a new sign-in.

<a id="models"></a>

## Models

### Web entries do not appear in Codex after saving integration.

Finish **Detect available models → Preview integration → Save integration**, then fully quit and reopen Codex, including background processes. Keep RCMAKER running. A new chat alone does not reload the catalog. Check the configured Codex home if you maintain more than one installation.

### How do I distinguish gateway models from native Codex models?

Gateway entries end in **RC (Web)**. The family name follows ChatGPT's picker; examples such as `GPT-6 · Thinking · RC (Web)` are not fixed guarantees. The available modes are separated where their context windows differ. Thinking retains its available effort selector.

### Where is Pro? Is Ultra required?

Choose the separate **… · Pro · RC (Web)** entry when detected. You do not need to enable Codex Ultra to select it. Pro can disappear from new choices when unavailable and reappear after detection; an old saved alias does not grant access. Check the private ChatGPT account's actual picker, refresh model detection, and reopen Codex if its catalog is stale. The gateway should not silently turn an unavailable Pro request into another tier.

### Why are the screenshots and my model list different?

Screenshot names and sample data illustrate the controls. Account plans, quota, model retirement and website changes affect the actual list. Use your current detection result. The gateway's context-budget figures are implementation policy, not a claim about OpenAI's maximum context size.

<a id="history"></a>

## Context and history

### Does 3× history triple the model's context window?

No. It expands history intake and associated transport handling. It does not change the model's actual memory window. Older execution records can remain available through a readable history handoff; long tasks still need compaction.

### Does a six-part maximum mean six messages every time?

No. New requests choose one message or a suitable minimum from **2–6 parts**, within the configured ceiling and budget. A saved transmission transaction retains its chosen count when resumed. More parts add transfer time; they do not multiply model capacity. The 3× setting requires the six-part ceiling.

### A short conversation already uses several parts or compacts. Is that wrong?

The visible user-message count is not the total context size. Instructions, skills, previous tool results, attachments and inherited/forked history also contribute. Compare the task timeline's **context delivery and compaction decisions** with the selected model/mode and configured history settings. Different modes may have different budgets. Frequent repeated compaction with no progress is worth reporting; frequency alone cannot establish data loss.

### Does compaction preserve everything verbatim?

The gateway aims to preserve usable continuity through summaries, retained user text, recent attachments, readable records and verifiable tool receipts. A summary is not a verbatim copy of all history. Inspect the retained evidence when an exact earlier value matters, and do not assume information in an unreadable encrypted block was recovered.

### Can Web models read official Codex encrypted history?

They cannot directly decrypt provider-private checkpoints. The default route preserves available readable history and marks unavailable portions. The optional **official-model handoff summary** can obtain a readable summary using the current official Codex login and can consume its quota. It is off by default. A fresh readable project request can still supply the context needed to continue; see [integration and restoration](user-guide.en.md#upgrade).

### Are images and skill files sent?

The current console describes up to **10 uploaded files per submission**. Recent images are selected automatically; optionally uploaded selected-skill files share those slots. Skill text is normally transported in the context instead of as uploaded files. This is the gateway's current transfer policy, not a universal ChatGPT limit. Inspect the task's original-message/attachment record when a specific image matters.

<a id="recovery"></a>

## Tasks and recovery

### Local tools return 502 and another connection returns MCP SSE 404. What should I check?

Check tunnel and connector readiness, confirm that Codex is still running, and identify which connector owns the failed tool. A 404 from another connection is not a test of the RCMAKER connection itself. When tool definitions need updating, refresh the tools in ChatGPT, then check the connector locally.

These errors mean the corresponding requests did not return normally; they do not establish that project files or context are corrupt. If an earlier write may have executed, inspect the files and task receipts before retrying. For persistent failures, provide the timestamp and a redacted timeline.

### Compaction has taken more than ten minutes. Should I resend?

Check whether context parts are progressing, an approval is pending, a summary receipt exists, or the Web model is still generating. Large histories can take time to stage and summarize; elapsed time alone establishes neither success nor failure. If progress stops, or the page finishes while Codex keeps waiting, preserve both states for diagnosis.

When a summary is already unconfirmed, inspect that original summary before starting another. Changing the waiting setting affects newly created summaries; it neither changes an existing wait nor repairs a missing receipt.

### The website says “connection interrupted” while tools keep running.

A visible stream warning can remain while the same task still generates or tools finish. Check actual tool receipts and the task timeline before cancelling. Do not repeatedly resend the task or click regenerate while a write may be executing. A final response should settle after required results arrive; if the page has truly ended but Codex never finishes, report both states and timestamps.

### What does a 409 conflict mean?

Use its exact message. “Unknown execution result,” “task cancelled,” “turn ID used for another request,” and “summary already in progress” describe different state conflicts. Open the affected task's timeline. A cancelled capability should not be reused, and a potentially executed operation should not be blindly replayed. After resolving/cancelling the old work, issue a fresh request through Codex. If ordinary continuation repeatedly hits 409, report the version and redacted timeline rather than deleting the database manually.

### What about `context_json` decoding errors or `SCHEMA_MISMATCH`?

Keep the exact error code and a redacted original-message export. They point to a format/protocol issue, not proof of a bad password or a need to recreate every account. Include the version, model and whether you switched from an official model or another gateway. Do not manually edit stored history to make the parser accept it.

An `invalid_id_prefix` error, such as expecting `ctc_` but receiving `fc_`, identifies a mismatch between that history item's ID and the requested type; it does not mean the entire conversation was lost. Keep the original conversation and exact error for a compatibility review. Do not bulk-replace IDs in session files.

### The model claims a safety block. How can I tell whether it happened?

Look for an explicit tool result, approval denial or platform error in the timeline. A sentence in the model's final answer alone is not proof that a tool ran or was blocked. Approvals and tool permissions belong to Codex and the connected platform. Review an actual denial before proceeding; do not reroute the denied action through another tool.

### Can I send a correction while a task is running?

Yes—use the same Codex conversation. Describe the correction or question explicitly. The gateway conveys mid-task guidance, and a handoff may use a new temporary page. For an incidental question, state that the original task should continue. If it unexpectedly ends after answering, preserve the timeline so the specific handoff can be diagnosed.

### Does every development task have to run for hours?

No. A small task may be complete quickly. Judge file changes, real tool results, testing and whether the requested outcome was met. Repeated gateway “continue” messages, a long clock or a large tool-call count are not sufficient evidence of quality.

<a id="usage"></a>

## Usage

### Is the console's Pro remaining number official?

No, unless explicitly backed by a provided official balance. The normal display is an estimate from this installation's rolling seven-day records and the tracking budget you entered. Other devices and direct website activity are excluded. “Unavailable” means no official balance was provided; it does not mean zero. Estimated tokens are not a bill.

### Does the gateway spend Pro messages on context intake and summaries?

The current policy uses non-Pro Medium for history intake/connector checks and non-Pro High for gateway summaries, using the selected family's supported modes. The actual user task uses the selected model/effort. Inspect the recorded purpose and actual sending tier rather than treating every page as a Pro call.

### Can official Codex quota still be used?

Yes, when you select an official model through the native forwarding route, or explicitly enable the optional official-model handoff summary. The latter is off by default. Console usage estimates cannot establish all official account charges; check the account's provider-facing usage information separately.

<a id="cleanup"></a>

## Cleanup and storage

### Does deleting a task delete the project or Codex conversation?

Task-center cleanup targets the gateway's eligible task records, not the project's source files or the Codex conversation itself. Running work and approval waits are skipped. Some checkpoint proofs and still-referenced attachments are retained to support future continuity. Read the confirmation; manual database deletion is not equivalent to the supported cleanup flow.

### Why does the SQLite file remain large after cleanup?

Deleted space may be reusable inside SQLite before the file shrinks. Reclamation can wait until active tasks are idle. Use the console's size/reusable-space/cleanup progress, not file size alone. Do not remove `-wal` or `-shm` sidecars while the application is running.

### What should I preserve during an upgrade or machine change?

For an in-place upgrade, preserve `.env`, the configured `data` directory and a rollback executable; stop active writes before backing up. Changing machines can affect protected credentials and browser sessions, so do not assume copying profiles will restore every login. Keep private backups private and sign in again when required. See [upgrade steps](user-guide.en.md#upgrade).

## Report a problem

Include the application version, operating system, selected model/effort, approximate time, exact error, whether it happened at startup/generation/tool waiting/compaction, and a redacted timeline excerpt. Remove API keys, cookies, turn tokens and private prompts. State which result was actually observed instead of inferring that a retry succeeded.

[User guide](user-guide.en.md) · [README](../README.md) · [中文版](faq.zh-CN.md)
