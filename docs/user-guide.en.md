# RCMAKER Codex Gateway · User Guide

**English** · [简体中文](user-guide.zh-CN.md) · [Project overview](../README.md) · [FAQ](faq.en.md)

First-time setup: **choose an installation method → prepare the gateway → connect and restart Codex → run a task**. Already configured? Jump to everyday use or troubleshooting.

| What you need | Start here |
| --- | --- |
| Check the requirements | [1. Before you start](#before-start) |
| Download a ready-to-run application | [2. Binary installation](#binary-install) |
| Run the PHP source | [3. Source installation](#source-install) |
| Configure network, components and sign-in | [4. Setup](#setup) |
| Create the connector and enable Codex models | [5. Tool connection and Codex integration](#connect-tools) |
| Start work and inspect its progress | [6. First task](#first-task) · [7. Everyday use](#daily-use) |
| Change language, context or usage settings | [8. Settings](#settings) |
| Update, restore or resolve a problem | [9. Upgrades and recovery](#upgrade) · [10. Troubleshooting](#troubleshooting) |

Controls were checked against the project files on **10 October 2026**. Configuration screenshots use console templates and sample data to illustrate the controls. Use your own interface for actual connection status, account permissions and available models.

<a id="before-start"></a>

## 1. Before you start

### Understand the three windows

| Window | What you do there |
| --- | --- |
| **Codex** | Open the project, submit requests, handle approvals, review changes and results |
| **RCMAKER local console** | Configure the gateway and inspect readiness, task records and browser activity |
| **RCMAKER private Chrome** | Sign in to ChatGPT and create its plugin; the gateway manages task pages |

The embedded view and **Open native browser** window share the private browser profile. They are separate from your regular system Chrome. **Signing in through system Chrome alone does not sign the gateway in.**

### Prepare access, networking and a folder

Use the computer where Codex is installed and choose a folder you can write to. Initial setup needs network access to download components, sign in to ChatGPT and connect the tool tunnel. The binary includes PHP; it does not make the gateway an offline application.

Use a ChatGPT account with the required Web models and custom MCP plugin access, plus a Platform account/workspace with the necessary Tunnel permissions. Follow the console's account-matching guidance for the private ChatGPT login, Tunnel and ordinary runtime API key; the desktop Codex login can differ. **The same email address does not establish all workspace permissions.** Check Tunnel organization/workspace associations and the separate create/use permissions in [OpenAI's guide](https://developers.openai.com/api/docs/guides/secure-mcp-tunnels).

<a id="install"></a>

| Installation | Best for | Install PHP / Composer yourself? |
| --- | --- | --- |
| [Binary](#binary-install) | Using the gateway directly | No |
| [Source](#source-install) | Contributing or maintaining a PHP environment | Yes |

**Choose one.** Both continue with [Setup](#setup) after startup.

<a id="binary-install"></a>

## 2. Binary installation

### 2.1 Automatic installation

**Windows x64:** run in PowerShell.

```powershell
irm https://raw.githubusercontent.com/runchance/rcmaker-codex-gateway/master/.github/scripts/install.ps1 | iex
```

**Linux / macOS:** run in Bash. Both x64 and ARM64 are supported by the installer.

```bash
curl -fsSL https://raw.githubusercontent.com/runchance/rcmaker-codex-gateway/master/.github/scripts/install.sh | bash
```

The installer downloads the latest stable release and verifies SHA-256 checksums for the archive and executable. Windows needs system `tar.exe`; the Linux/macOS installer checks its download, extraction and checksum tools. Install any missing prerequisite identified in an error before retrying. Script sources: [Windows](../.github/scripts/install.ps1) · [Linux/macOS](../.github/scripts/install.sh).

**Destination:** an executable in the current folder is upgraded there; otherwise the default is `./rcmaker-codex-gateway/`. A first interactive installation lets you enter a different directory. Non-interactive installation uses the default. Check the script's final output for the actual location.

<details>
<summary>Choose a custom installation directory</summary>

Set the directory before running the installer. Replace the example path with your preferred location.

Windows:

```powershell
$env:RCMAKER_INSTALL_DIR = 'D:\Apps\RCMAKER-Gateway'
irm https://raw.githubusercontent.com/runchance/rcmaker-codex-gateway/master/.github/scripts/install.ps1 | iex
```

Linux / macOS:

```bash
export RCMAKER_INSTALL_DIR="$HOME/Apps/rcmaker-codex-gateway"
curl -fsSL https://raw.githubusercontent.com/runchance/rcmaker-codex-gateway/master/.github/scripts/install.sh | bash
```

To install into the current directory, set the variable to `.`. Relative paths resolve from the directory where you run the command. In Bash, `export` the variable first; assigning it only to `curl` on the left of the pipe does not pass it to `bash`.

</details>

### 2.2 Manual installation

Open [Releases](https://github.com/runchance/rcmaker-codex-gateway/releases/latest) and choose the matching release archive. For the ready-to-run application, do not choose GitHub's automatically generated **Source code** archives.

| Computer | Archive filename ending |
| --- | --- |
| Windows x64 | `windows-x86_64.zip` |
| Linux x64 | `linux-x86_64.tar.gz` |
| Linux ARM64 | `linux-aarch64.tar.gz` |
| macOS Intel | `macos-x86_64.tar.gz` |
| macOS Apple Silicon | `macos-aarch64.tar.gz` |

Extract the archive into a writable folder and keep its license files and configuration example. You do not need an existing `data/` directory or another user's browser profile. Available downloads are those attached to the selected Release.

### 2.3 Start the application

The installer **does not start** the gateway. Open the installation or extraction directory:

| System | Start command or action |
| --- | --- |
| Windows | Double-click `rcmaker-codex-gateway.exe`, or run `.\rcmaker-codex-gateway.exe` in PowerShell |
| Linux / macOS | Run `./rcmaker-codex-gateway` in a terminal |

If a manually extracted executable lacks execute permission, run `chmod +x ./rcmaker-codex-gateway` first. Resolve missing browser libraries or graphical-session requirements from the actual error; a platform-specific binary does not include every operating-system component.

Keep the process running, open the **[local console](http://127.0.0.1:28910/)** on the same computer, and continue with [section 4](#setup). The default `data/` directory is created beside the executable on first use.

<a id="source-install"></a>

## 3. Source installation

**Skip this section if you installed a binary.**

### 3.1 Prepare PHP

Install Git, Composer and PHP 8.1–8.5 for CLI use. The release build uses PHP 8.5 with `curl`, `openssl`, `zip`, `mbstring`, `pdo_sqlite`, `sqlite3`, `sockets` and `sodium`; see the [build configuration](../.github/workflows/release.yml). Enable the runtime framework's required Unix process extensions as well.

The [RCMAKER environment guide](https://rcmaker.runchance.com/doc/#/md/quick-start?id=_1-%E5%87%86%E5%A4%87%E7%8E%AF%E5%A2%83) covers environment preparation. Check the terminal you will use to run the gateway:

```bash
php -v
php -m
composer --version
```

The CLI PHP must have the required extensions. Enabling them only in a different web-server/FPM PHP installation is not sufficient.

### 3.2 Get the source and start

```bash
git clone https://github.com/runchance/rcmaker-codex-gateway.git
cd rcmaker-codex-gateway
composer install
php index.php start
```

If you already downloaded the source, start with `composer install` in its root directory. Do not skip Composer's install scripts: this project uses them to apply its framework compatibility patch.

Default configuration can start as supplied. For deployment overrides, create `.env` using [`.env.example`](../.env.example); do not overwrite an existing `.env`. The default `data/` directory is inside the project root, not beside the PHP executable.

Keep the process running and open the **[local console](http://127.0.0.1:28910/)**. The remaining setup is the same as for a binary installation.

<a id="setup"></a>

## 4. Setup: network → components → sign-in

A fresh installation uses English. Choose the language at the top right of the setup wizard. After setup, use the top language menu or **Settings → Console language**. The preference is saved immediately without a restart.

First use opens a required overlay: **network → runtime → sign-in → tunnel → connector → Codex integration**. Only the current step's controls are available. Saved progress resumes after a refresh or restart. Select **Open workspace** after completing setup.

Existing installations see connection cards in Setup and can manage individual settings.

![Setup cards for network, components, sign-in and tool connection](images/user-guide/en/setup.jpg)

*Console illustration uses demo data. Account, model and usage values follow your actual detection results.*

### 4.1 Configure the network

Select **Configure network**. Choose **Direct connection** when the services are reachable directly, or **Use HTTP proxy** and enter its host and port. Select **Save and test**.

| Field | What to enter |
| --- | --- |
| Proxy IP / host | For example `127.0.0.1`, without `http://`, a username or a path |
| Port | The HTTP CONNECT port actually provided by your proxy software |
| Proxy authentication | Expand **Proxy authentication (optional)** for username and password; leave a saved password blank to keep it |

The saved route is used by the application browser, component downloads and tool tunnel. It does not change your system proxy. A SOCKS-only port cannot be entered as an HTTP port.

Choose the browser timezone in step 1 (network settings) or on the Network and proxy page, without editing configuration files. The default matches the proxy exit automatically. You can search for a city and select a timezone, such as Tokyo (`Asia/Tokyo`), or use the computer timezone. Automatic mode keeps the computer timezone for direct connections. Applying a new revision reinitializes a running private Chrome while preserving sign-in data. If Web tasks or browser operations are active, the revision is saved but remains pending until you apply it after the work finishes. A stopped browser uses the settings on its next launch. Return to step 1 whenever the timezone needs adjustment.

Automatic detection uses the browser's selected HTTP proxy to contact `ipwho.is`, then `ipapi.co` on failure, without sign-in data or chat content. For domain-based routing, send these sites and ChatGPT through the same exit, or select the exit location's timezone manually. If both lookups fail, startup reports `BROWSER_TIMEZONE_LOOKUP_FAILED` without guessing or bypassing the proxy. The timezone applies before scripts run on sign-in, task and connector-check pages; the Windows timezone is unchanged. Matching timezones does not guarantee that the site's `Model not found` error is resolved; verify the actual model request separately.

The timezone is resolved once per browser initialization and reused for new pages. A temporary page-control failure preserves healthy pages' overrides and rechecks the failed control sessions. New Web requests may report that the browser is not ready until recovery. `BROWSER_TIMEZONE_APPLY_FAILED` identifies a failure in the control workflow, not necessarily an invalid timezone; runtime logs record the specific CDP method and error. Recovery does not refresh pages, resend tasks or replay tools.

**Success:** the network check passes. A target website returning HTTP 403 differs from a proxy refusing the tunnel; connectivity also does not establish sign-in. See the [network FAQ](faq.en.md#network).

<details>
<summary>Show network configuration screenshot</summary>

![Network dialog with proxy fields and Save and test](images/user-guide/en/network.jpg)

The screenshot port is illustrative. Use your own proxy's port.

</details>

### 4.2 Prepare runtime components

The browser and tool connection components are prepared separately. The tool component installs in the background; the console registers and observes the same durable operation. Repeated clicks, refreshes and reopening rejoin pending work; after a restart the installer first verifies existing files. Tool downloads allow 30 minutes in total, 15 seconds to connect and a two-minute low-speed limit below one byte per second. Integrity checks and atomic publication remain in place. An observation timeout or interrupted request does not declare installation failed or automatically resubmit it; check the status later. Confirmed backend download, integrity or version errors remain visible in both console languages.

Select **Prepare runtime components → Start preparation** and wait for private Chrome and the tool tunnel components. Configure networking before downloading components.

The browser archive download allows up to two hours in total; connecting is limited to 15 seconds, and a transfer below one byte per second for two minutes fails early. The console follows the backend installation budget and observes the same command. Reaching 60% does not trigger a timeout. Slow transfers keep the worker heartbeat active, including when the server does not provide a total file size. Failed partial downloads are removed; check the error and network settings before retrying.

**Success:** the Runtime card reports ready. If a download fails, inspect networking, disk space and the exact error before continuing; creating another connector does not repair missing components.

<details>
<summary>Show runtime preparation screenshot</summary>

![Runtime preparation dialog](images/user-guide/en/runtime.jpg)

</details>

### 4.3 Sign in to ChatGPT

Select **Sign in to ChatGPT → Open sign-in page** and complete sign-in. For easier typing or scrolling, choose **Open native browser** and use the separate private Chrome window.

Account detection and model sync run automatically after sign-in; you do not need to open the page again. **Hide native browser** hides the window; use **Reload page** if the browser image itself needs refreshing.

**Success:** the account is recognized and model checks finish. Continue to the tool connection.

<details>
<summary>Show sign-in and native browser controls</summary>

![Sign-in controls: open page, native browser and refresh](images/user-guide/en/login.jpg)

</details>

**Clear private Chrome sign-in data is a reset.** It removes the application's saved ChatGPT/Google login state and profiles, requiring another sign-in. Do not use it for a routine refresh. Finish active tasks before deliberately resetting an account.

Confirming a reset immediately opens the sign-in step. After sign-in and model sync, the saved connector is checked automatically; follow the manual check prompt if it fails. Tasks and gateway settings remain saved, including the Tunnel ID and key.

<a id="connect-tools"></a>

## 5. Tool connection and Codex integration

During initialization, use the Tunnel and Connector steps. Afterwards, open **Setup → Manage connection**. Follow this order: **create a Tunnel → save the key and connect → create the ChatGPT plugin → check the connector → enable Codex models**.

### 5.1 Create and connect the Tunnel

Select **Open tunnel creation**, create a Tunnel and copy its `tunnel_...` identifier. Select **Open API key creation** and create an **ordinary runtime API key**, not an Admin key, with the required access.

These two buttons open tabs in the browser displaying the local console. Check its signed-in account so it matches the intended private ChatGPT account and workspace access.

| Local field | What to enter |
| --- | --- |
| Tunnel ID | The `tunnel_...` identifier you created |
| API Key | An ordinary runtime key allowed to use the Tunnel; leave blank when the key is already saved |
| Connector name | Fixed prefix `RCMAKER` plus your suffix; default `RCMAKER Codex` |

Select **Save and connect tunnel** and wait for Connected before proceeding. A normal first save activates the tunnel without a gateway restart.

![Tool connection fields: Tunnel ID, API key and connector name](images/user-guide/en/tools.jpg)

### 5.2 Create the ChatGPT plugin and verify it

The connector step also offers **Web chat mode**. Temporary Chat remains the default. If the check can read the temporary page but cannot find the plugin, it tests an ordinary chat once. Ordinary mode is saved only after that page completes the real connector round trip; the console explains the change. Login failures and network timeouts do not by themselves switch modes. You can save Temporary Chat again in this step.

In ordinary mode, each new task is bound to its own saved Web conversation and account/workspace. After closing a tab or restarting RCMAKER, the gateway opens that conversation again and sends only current instructions and necessary new receipts. Normal compaction, steering, model changes and the full-context preference do not replay saved history. A visible Stop button keeps the gateway waiting; a disconnected banner alone does not trigger recovery. After Stop disappears, an owned Retry button, or Send together with an explicit error, can confirm a failed response. An empty composer may show Voice instead of Send. Recovery tries native Retry and a short continuation on that page if it recovers. If it still fails, the gateway refreshes the exact saved chat and sends a short continuation. Only another failure after that refreshed continuation permits isolating the old page and restoring complete context and exact tool receipts in a new ordinary chat. The original task binds to the new conversation ID only after its initial message and account ownership are verified. Accepted tools settle first. Existing receipts remain available during retry; new host actions wait until the exact continuation is accepted. Unknown effects or unverified chat/account ownership prohibit automatic replay. Durable intents prevent duplicate actions after lost acknowledgements or restart. A confirmed deleted Web record or a task removed by manual/automatic cleanup also permits a new chat initialized from Codex history. Local cleanup does not delete the old ChatGPT history record.

The task center also shows consecutive gateway errors **for each independent Codex task and branch**. Tracking starts with this upgrade; historical errors are not imported. Reconnecting the same failed request counts once, and a successful response or native tool-call batch resets only that task's count. User stops, account sign-in and rate-limit waiting do not count. After three consecutive failures, the next explicit user continuation can retire that task's page and initialize a fresh chat of the same mode using complete readable history and exact receipts. Accepted tools, active MCP requests, unknown effects and unconfirmed receipts block this fallback until their state is settled. Another task's errors or success never change this task's count. Restarting RCMAKER preserves the counters.

Ordinary chats and ordinary-mode connector probes are saved in ChatGPT history. After switching to ordinary mode, an existing temporary task also initializes its own ordinary chat with the complete readable context on its next continuation. Accepted tools settle first, exact receipts are retained, and the old temporary page is isolated before sending. Once bound, only new instructions are sent. Existing ordinary bindings are retained even when the preference changes again. Tasks that remain in temporary mode keep their existing steering, compaction, recovery and full-context resend behavior.

When the current ordinary-chat response explicitly shows “Stopped thinking,” Stop disappears and the composer returns to an idle control, the gateway sends a short continuation in that same bound chat. Only after three accepted continuations fail again without new tool progress may it isolate the old page and restore complete context and exact receipts in a new ordinary chat. Normal completion and an explicit user stop do not trigger this recovery. New user instructions take priority, and in-flight tools settle first. Temporary Chat and compaction keep their existing flows.

After several continuations in an ordinary chat, the latest short message need not repeat the first message's token. The gateway verifies the saved conversation ID, current document, fresh account and task ownership before sending new instructions through the existing steering/follow-up flow. A document change during verification requires another check.

If a new, unsent message cannot find its exact connector in a saved ordinary chat, the gateway refreshes that same chat once and verifies its account before searching again. It keeps the saved conversation ID, current instructions and tool receipts. A missing connector after refresh does not by itself authorize another chat or Temporary Chat recovery; the task reports the connector problem and retains its original conversation.

Select **ChatGPT Connector settings**, or **Open native browser** in that area, to create the plugin using the private Chrome account.

The current official flow is **Plugins → + → Add custom MCP server**. If your account still uses Apps / Developer Mode, follow that interface and any developer-mode or workspace permission requirements. See the [official creation steps](https://developers.openai.com/api/docs/guides/custom-mcp-server).

| ChatGPT field | Value for this integration |
| --- | --- |
| Name | The full value from **Copy connector name**, for example `RCMAKER Codex` |
| Connection | **Tunnel**, then choose the Tunnel from the previous step |
| Authentication | **No authentication / None** |

Review the page's notices, create the plugin and complete its installation/connection steps. Return to RCMAKER and select **Check connector**.

The first check verifies the real MCP permission round trip and saves an account-bound receipt. Later checks, including normal RCMAKER restarts, only confirm the uniquely matching connector in the private browser; they send no model message. A changed connector, account or connection configuration requires initial verification again. Clearing private sign-in data also removes the receipt. Older versions did not save its account binding, so the first check after upgrading establishes it once. A visibility check confirms availability in the page, not a new live MCP round trip.

Transient account-read failures and rate limits use backoff on the same check page, with at most four reads within one 90-second account verification window. This neither refreshes task pages nor resends probe messages. Invalid sign-in, account changes and mismatched connector names still fail verification. Failure diagnostics retain only classifications, HTTP status and timing, never sign-in credentials.

**Success:** both tunnel and connector checks pass. If the Tunnel is missing, check organization/workspace associations and permissions. If creation succeeds but the check fails, use the [connector FAQ](faq.en.md#connector).

<details>
<summary>Show the connector checklist</summary>

![Connector name, creation checklist and verification controls](images/user-guide/en/connector.jpg)

</details>

An existing installation keeps its connector name; there is no need to change it to the default. After changing the suffix, create and verify a matching plugin. Ordinary restarts do not require a new plugin. When release notes require updated tool definitions, use **Refresh / Refresh tools** in ChatGPT, then check the connection locally. These are separate actions.

<a id="codex-integration"></a>

### 5.3 Save the integration and restart Codex

In the same dialog, find **Enable Web models in Codex**:

1. Select **Detect available models** and wait for account detection to complete.
2. Select **Preview integration** and review the target configuration and routing changes.
3. Select **Save integration**.

Saving backs up and replaces model routing while preserving unrelated MCP, project and permission settings. When switching from another gateway, inspect the old route in the preview.

![Model detection, integration preview, save and restart reminder](images/user-guide/en/codex.jpg)

> **Wait for Ready in RCMAKER's startup window, then fully quit Codex, including background processes, and reopen it. Keep RCMAKER running. Closing a chat tab or opening a new chat is not a restart.**

**Configuration detection:** actual `# RCMAKER BEGIN` and `# RCMAKER END` comments identify an existing integration, including older identifier suffixes. Setup also checks that the current route reaches this RCMAKER instance and has compatible settings. If other software changed the route, preview and save the integration again to replace it automatically. Existing configuration is backed up, unrelated settings are preserved, and status checks never rewrite the file.

**Starting with v1.6.0, binary packages from [Releases](https://github.com/runchance/rcmaker-codex-gateway/releases) support zstd request compression.** For source deployments, support depends on the running PHP's zstd extension and the gateway's decoder self-test.

This is lossless transport compression between Codex and the gateway, for request bodies carrying conversation history, tool definitions and tool results. Compaction requests can also carry compressed bodies. The gateway restores the complete original content before the existing task workflow processes it. This reduces transferred bytes; it does not reduce model tokens, expand the context window, replace summary-based compaction or compress messages entered into the Web chat. The client decides when to send compressed requests.

**Request compression:** when the running PHP passes the zstd decoder self-test, new integrations omit `features.enable_request_compression` and use Codex's [enabled-by-default behavior](https://learn.chatgpt.com/docs/config-file/config-reference). After an upgrade, the independent maintenance worker removes older compression overrides from existing gateway configurations, preserving the API route, unrelated settings and an exact backup. The console shows the compression restart reminder only while the integration requires a restart: save your work, fully quit Codex including background processes, then reopen it. The reminder hides automatically after client contact is detected; compression status remains available in the Codex integration details. A runtime without zstd support continues to write `false`. Old uncompressed clients remain supported. Compressed and decoded bodies both retain the 256 MiB limit; corrupt or incomplete compressed data is rejected before a task is created.

The green **zstd enabled** indicator beside the home page connection status means the gateway can decode zstd and Codex uses its default compression configuration. An amber **zstd restart needed** indicator appears while a restart is pending. Hover over or click the indicator for details. Codex decides whether to compress each request according to its authentication and transport mode; enabling the setting does not force every request to be compressed.

This check covers only the final Codex step. An unfinished earlier step keeps the setup overlay open, even when Codex configuration is already present.

After a full Codex restart, model names ending in **RC (Web)** should appear. A first real task verifies tool communication; it is not required to unlock the console. If Ready never appears, complete the remaining setup step.

<a id="first-task"></a>

## 6. Run and check your first task

Open the intended project in Codex and choose a detected **RC (Web)** model. Thinking entries offer the available effort choices; Web Pro uses a separate **Pro · RC (Web)** entry when available. Family names follow the current detection result.

Start with an easy-to-check read-only request:

```text
Read this project's README and directory structure. Explain how to run the project
and identify any missing setup steps. Do not modify files. Use actual file contents.
```

| Where to look | What should appear |
| --- | --- |
| Codex | Real tool calls and file results, followed by the answer |
| Browser workspace | An automatically created ChatGPT task page |
| Task center | Messages, tool receipts and status for the task |

After this succeeds, submit a concrete development request and handle approvals in Codex. Review the diff for file changes and actual command output for verification; a final “done” message is not enough on its own.

<a id="daily-use"></a>

## 7. Everyday use

### Console tabs and automatic updates

Opened tabs and the active page are remembered. Returning to Setup or Browser workspace reads current status while keeping scroll position and unsaved form input. A whole-browser refresh does not retain unsaved drafts.

Automatic redraw waits while you copy, type, scroll or drag; explicit actions still work. Inactive tabs pause polling and resume when reopened. Models are grouped by family, duplicate website notices appear once, and context capacities use a table. Detailed explanations can be expanded when needed.

### Start, steer and stop

Start RCMAKER first and let it restore readiness before using Codex. Saved sign-in and connection settings are reused; you do not recreate the plugin each time. After integration or model catalog changes, fully restart Codex as instructed by the console.

For tool-enabled tasks, visibly rendered stage text reaches Codex as progress once the page observation settles. The last ordinary paragraph need not wait for another paragraph or tool call. Growing paragraphs send only new text, with durable deduplication across tool receipt rounds. Progress does not complete a task: final answers, exact tool receipts and compaction retain their existing checks. Slow page rendering or browser reads can still delay delivery.

Web rich text reaches Codex as standard Markdown structure; webpage layout, colors and interactive components are not reproduced exactly. Recognized metric cards preserve their label/value relationships in a table, or labelled list items when code cannot be represented faithfully in a table. A card group is sent after it settles rather than as separate headings. Upgrades retain the format of an already published response prefix; new replies use the new conversion, without rewriting displayed history.

Codex runtimes with Code Mode can run independent reads, queries or tests concurrently inside one native `exec`, while outer tool requests still respect the client's serial setting. The model chooses groups based on actual dependencies; not every call runs in parallel. Dependencies, edits to the same file, shared browser actions and approvals remain sequential. Each result is retained independently; partial failures do not replay the whole batch, and tools or pages are never borrowed from another task. Older clients or runtimes without the native orchestrator continue using direct tools.

`parallel_tool_calls=false` controls outer calls; it does not disable concurrency inside native `exec`. The gateway preserves the client's value. Exact tool discovery (`codex_tool_inventory` with `query=<wire_name>, exact=true`) returns a bound tool's current schema without another native discovery round; unknown names still use the current task's nested registry. Keyword search retains its existing behavior. The execution contract encourages focused reads, bounded output, reuse of current tool definitions and proportionate verification, without trimming stored receipts or skipping required checks.

When the current Codex runtime provides `request_user_input_async`, the Web model can ask in Codex and continue authorized work that does not depend on the answer. An unanswered optional question does not prevent task completion. Acknowledgement and preselected options are not a user answer or approval; steps that require the missing decision or authorization still wait for a real reply, without repeating the question or polling for an answer. Availability and mode restrictions come from the current runtime; a Plan-only question tool is not enabled in other modes. This does not guarantee that a model will choose to ask.

Submitted answers are checked against the original question ID in the same task. When an exact tool receipt is awaiting return, the answer travels as a separate user message alongside that MCP receipt, without an additional Web submission or changes to the tool result. Otherwise, a short answer contract is sent on the verified original page. The model should briefly acknowledge the answer, apply its intent to the current task state, and reuse completed work. Late answers after completion follow the same rules and do not authorize unrelated work to resume. Ordinary interjections and user-edited protocols retain their existing behavior. Missing pages or unverified ownership use the existing full-context recovery path rather than seeding a blank task with only an answer.

Tool registration, dispatch, result receipt and delivery events include `observed_at_us` for new activity in diagnostic exports. These are gateway wall-clock observations: dispatch-to-receipt includes native execution and transport, and receipt-to-next-call includes model/UI waiting. Neither interval is pure model reasoning or CPU time. Older events keep their original second-level timestamps.

Both nested discovery and execution exclude other Codex bridge connectors, including `codex_exec`, `codex_tool_call` and related operations under another integration name. This prevents a nested call from entering another bridge chain. Use native `exec_command` for local commands; normal native tools and other authorized application capabilities remain available.

In saved ordinary chats, a native `exec` receipt saying `Script running with cell ID …` is pending script work, even though the outer call delivered successfully. Its exact output is retained with a task-scoped continuation that invokes the advertised native `wait` for that cell. Retries reuse the same wait call; a lost receipt acknowledgement first redelivers the original result. Neither the script nor successful batch members are replayed. Missing native wait support retains the runtime's existing behavior. Discovery paused for page recovery, a newer user instruction or compaction returns that pause notice rather than a false “invalid nested catalog” error.

A follow-up also follows the intent, reply, then action sequence. After execution ended, failed or was manually stopped, a standalone discussion question requires investigation and an answer, then ends. Resume or adjust work only when the latest intent requests it; historical unfinished work alone is insufficient. During executing work, answering an interjection does not complete the original task: continue its still-applicable steps unless the user changes that boundary. A reference attachment alone is not a request to implement it.

A new human follow-up on a verified saved ordinary chat uses a concise contract: infer intent, briefly acknowledge it in visible commentary before tools, then follow that intent. Questions/discussion lead to necessary investigation and an answer; explicit stopping ends new task actions; execution, continuation or scope changes lead to authorized work with brief progress at meaningful stages, explaining the current assessment, next step and verified outcome. Original history is queried only for missing facts. These progress explanations do not request private reasoning. Custom resume templates are preserved with a scoped notice; Temporary Chat, active interjection and compaction contracts keep their existing paths.

While a task runs, send corrections or questions in the **same Codex conversation**. Related changes guide the task; explicitly say when you want to stop or replace the goal. **Settings → Long tasks and context → Resend full context when steering** controls Temporary Chat delivery. It is off by default: after confirming that the original page stopped, send only new instructions, instruction updates and required tool receipts while retaining completed progress. Enabling it preserves Temporary Chat's existing full-context flow. Ordinary mode always reuses its bound Web conversation regardless of this preference.

For each interjection, the model first infers intent from the supplied information, then gives a brief reply in your language acknowledging the request and stating its immediate next step, before investigating or taking further action. This must be ordinary visible prose; a thinking summary, status title or tool call does not replace it. Web search, Connector discovery and result lookups also follow this reply. If facts need checking, the model says what it will investigate without guessing or waiting for findings, then investigates and answers from evidence; a question does not authorize changing files or the task goal. An already verified short answer or a stop report requiring no further action may itself be the reply. When authorized work resumes, reuse supplied exact tool receipts; if needed, query the original calls through the current Connector or original-context reader with a valid current capability. Wait for pending work and verify unknown or failed outcomes instead of resubmitting accepted calls because of an interjection.

Stop a task in Codex and check its Task center status. Before closing the gateway, finish tasks and pending approvals, then use **Ctrl+C** in the startup terminal. Closing a running Web page is not a substitute for cancelling a task.

When Codex and RCMAKER share the configured Codex home, an explicit manual interruption is matched to its session and turn. Manual stop and steering preserve already accepted tool calls, including queued calls, and their exact receipts; additional actions pause while they finish. When a human update is queued, the gateway first attempts to stop generation on the verified page while accepted native tools continue. It sends the new instruction after their exact receipts settle. A manual stop without a follow-up still waits for tools and receipt delivery before completing the Web stop. A follow-up can join during this wait without revoking the original capability or replaying a host action. Explicit cancellation of the entire task remains separate.

A new Temporary Chat request after manual stop uses the same setting. It controls normal continuation only. When the original page cannot be verified after a restart, is missing, or has actually failed, recovery still uses verified full context and exact tool receipts, isolating the old page before sending. Ordinary mode reopens its bound Web conversation as described in section 5.2; a missing tab does not replay its history. A brief failure to observe the stop does not by itself replace the page or resend full context; confirmation continues within the turn's original deadline. Unconfirmed host effects are never replayed automatically. An inaccessible local session file does not authorize stopping a page. Settings affect future requests, not requests already queued.

During manual stop, a browser-control read timeout proves neither an invalid page or control session nor a successful stop. The gateway retains the session and reads the original page when the click outcome is uncertain; another stop attempt requires confirmed ongoing generation and unchanged ownership. An invalid control session is reattached while retaining the original task and exact tool receipts.

ChatGPT may stop immediately or show a “Stop generating this response?” confirmation. On the verified task page, the gateway recognizes the English or Chinese stop dialog, clicks its unique Stop button, then reads back the page to confirm generation ended. A pending dialog or uncertain click does not allow the next interjection or premature checkpoint completion. It never selects Keep thinking or treats tool permissions or chat deletion dialogs as stop confirmations. Existing tool receipts, task isolation and manual-stop priority remain unchanged.

Send may remain briefly disabled after text entry. Ordinary and attachment messages wait within a preparation window of at most 60 seconds, bounded by the original task deadline; the settled-state check uses that same window. Waiting does not reinsert or send the draft again. Cancellation, changed page ownership or expiry prevents old callbacks from advancing the send.

**Codex compaction does not mean the Web model has exhausted its context.** Compaction drains accepted tool receipts, stops the old generation, then sends a separate summary request. After the checkpoint completes, the gateway retains the same Web page when its document, task ownership and checkpoint can be verified exactly. It sends only a resume instruction and new content, without retransmitting retained history. Temporary summary controls end and the remaining task continues; this also applies across repeated compactions. Missing or unverifiable pages and actual capacity or request failures still follow the existing recovery flow. Ordinary task continuation cannot enter an active summary operation.

A delayed Web acknowledgement alone does not block compaction when the tool result is complete and a later round in the same task has verifiably consumed that exact result. The accepted summary handoff retains this evidence so the same old acknowledgement cannot block subsequent compactions. The gateway does not forge delivery acknowledgements or repeat tools. Running tools, unknown outcomes and inconsistent receipts still require confirmation.

If compaction is still rejected, export the diagnostic logs. They record the admission phase, exact code location, tool-state counts, bounded task/turn identifiers and checkpoint state to distinguish active tools, delayed acknowledgements and summary conflicts. Conversation text, tool results and capability tokens are excluded. Failed diagnostic reads still preserve the original guard location without changing the task or resending messages.

For ordinary chats, an empty composer may display **Start voice** instead of Send after generation stops. Compaction recognizes that idle state only with one visible composer, no ongoing generation, stable page ownership and verified tool results. A visible Stop button still means generation is running. If an older ordinary-chat request already timed out before any summary was submitted, a later compaction retry can recover after verifying the complete history and exact delivered receipts. It revokes the old tool token, retains the saved conversation and requests only a summary; repeated retries join the same producer. Submitted summaries, active tools and unknown results keep their existing recovery restrictions. Temporary Chat behavior and timeout budgets are unchanged.

On the first resumed execution after each checkpoint, the model should briefly state verified progress and the immediate remaining step in your language before starting new tools, then continue working. This is progress, not a final answer or a claim of unverified completion. Ordinary tool receipts and later follow-ups do not repeat this handoff reply; newer question-only, pause or changed-goal instructions still take priority. This requirement adds no retries, extra tool calls or completion gate.

Repeating the same read-only compaction request with a different temporary conversation ID joins its existing summary or returns its stored result when the integration, model, effort, branch and complete history match. It does not open another page to resend the request. Failed responses retain their actual error; a controlled recovery requires proof of the complete source, an unpublished summary and no host operations. This also covers older records that failed because a private summary draft changed, without rewriting the original failure or completed tool receipts.

A standalone “Connection interrupted / Waiting for the full response” notice does not invalidate the page or finish the task. The gateway continues observing tool receipts, generation state and the Send control. Once Send returns, tools must also be settled and the answer stable, or an actual request failure established, before the turn can finish or enter recovery.

Both interjection delivery modes and continuation after a manual stop follow the intent-first order. Changes to goals, scope, priorities or constraints require a brief explanation of the adjustment before the remaining steps, preserving completed work that still applies. During executing work, after discussion or a side question, continue the still-applicable original task. A standalone discussion after execution ended does not restart it. An explicit stop ends new task actions, followed by a factual account of completed work, unfinished work and tools still settling. Respect explicit cancellations, replacements and investigation-only requests. Contracts do not accumulate each round or change tool receipts, context budgets or compaction.

After selecting another Web model or reasoning level, send “Continue” or a new request. If that request also returns results from the previous model's tools, the gateway settles them under their original call identities first. Already accepted queued tools finish before the new selection takes over. Exact results are retained without replaying tools, and ordinary mode keeps the task's bound Web conversation. The selected model must be available to the signed-in Web account; a bare old tool receipt or compaction request cannot independently change its source model.

### Browser workspace

The workspace retains up to five task pages, labeled active/idle and managed by the gateway. At capacity, wait for work to release a page or close an idle page the interface permits you to close. **Closing a page and deleting a task record are different actions.**

Do not enter a competing request directly into an automation-owned ChatGPT task page. Send it through Codex to keep it associated with the task.

<details>
<summary>Show Browser workspace and Task center screenshots</summary>

![Browser workspace; an empty view before the first task is normal](images/user-guide/en/browser.jpg)

![Task center status filters and cleanup controls](images/user-guide/en/tasks.jpg)

</details>

### Inspect the full sent messages

Open **Task center → the task → Timeline** to inspect messages, tool results, context delivery and compaction decisions. Expand the full-message records to **copy or download** the sent contract and context, including evidence relevant to attachments or mid-task guidance.

A recorded send intent is different from confirmed Web receipt. Compare it with receipts and subsequent status. Exports may contain project information; redact them before sharing.

### Clear task records

Use **Clear all idle tasks** in Task center and review its confirmation. Running tasks, executing tools and approval waits are skipped. Cleanup does not target project source files or Codex chats. Referenced checkpoints or attachments may remain. Freed database space is reused by later writes; the running gateway does not automatically compact the entire database. See the [cleanup FAQ](faq.en.md#cleanup).

A separate maintenance process checkpoints database logs; browser and API commits no longer also process an accumulated log backlog. Maintenance uses `PASSIVE` checkpoints, leaving pages blocked by active readers or writers for a later pass without ending their snapshots or waiting for their locks. Commits retain full synchronization to disk. Safely recycled logs retain at most 16 MiB for reuse; this does not cap logs still awaiting a checkpoint. Maintenance and business processes still share the disk, so slow storage or long business write transactions can affect responsiveness.

<a id="settings"></a>

## 8. Settings, context and usage

Keep the defaults for initial use. **Preferences** controls normal operation; **Model contracts** edits the templates sent to Web models and does not need adjustment for ordinary use.

Upgrades automatically update default contracts. Templates customized and saved in the console remain unchanged and do not automatically merge new default wording; edit them or restore defaults when needed. The gateway adds the first execution progress instruction after compaction outside the template, including for custom templates, but check custom wording for conflicting instructions. Updated code applies to subsequent requests after loading and does not change content already sent to the Web page.

| Setting | Meaning and when to change it |
| --- | --- |
| Console language | English/Chinese changes save immediately; user messages and model contracts remain unchanged |
| Up to 3× history (experimental) | On for fresh installations; requires a connector and the six-part ceiling; does not expand model capacity and is not used by every mode |
| Maximum parts | Defaults to at most six; the actual count depends on context and budget, not a fixed six messages per request |
| Model response wait | Applies to one model response and restarts after tool results; not the entire project's time limit |
| Part acknowledgement / summary waits | Separate settings for context-part acknowledgements and compaction summaries |
| Official-model handoff summary | Off by default; can obtain a readable summary of inaccessible official encrypted history and may use official quota |
| Upload skill files | Skills normally travel as text; enabling file upload shares up to 10 slots with recent images |
| Automatically select Always allow for the connector | Selects persistent permission only for the exact connector on its owned task or verification page; Codex sandboxing and local approvals remain in effect |
| Weekly Pro tracking budget | Used only for a local balance estimate; it does not limit submissions or represent the official balance |

The gateway recognizes native permission cards, action groups, menus and keyboard hints, with localized labels only as a compatibility aid. It does not guess button positions or send a global Enter. An uncertain click is followed by a page read, not another approval or task submission. If ChatGPT offers no Always allow option or the controls are ambiguous, the task is preserved with an explicit error; the gateway does not repeatedly approve once. Existing setting values are retained. Turning the setting off stops future automatic approval but does not revoke permission already saved in ChatGPT; revoke it in ChatGPT app permissions. Availability and scope of persistent permission depend on ChatGPT account and workspace rules.

New requests use saved changes. Already-started work retains its budget and waiting settings. If the catalog has changed but Codex still shows old values, fully reopen Codex.

<details>
<summary>Show settings screenshot</summary>

![Preferences for language, context and usage tracking](images/user-guide/en/settings.jpg)

</details>

### Context parts, compaction and Pro

**Multipart delivery** sends longer history in batches. **Compaction** creates a summary for subsequent work. Both can add waiting time without implying failure. History includes instructions, skills and tool results, not just the few messages you typed.

If an ordinary task failed during history staging before its final task message was submitted, an explicit continuation first checks that no host tool ran and no other round is active. It can then reselect a bounded history view under the current account/model budget, including updated Codex session instructions. The full source remains queryable, and every human requirement and system/developer instruction is retained verbatim. Bound ordinary chats keep their existing incremental path. Current requirements that exceed the budget still require compaction.

When an ordinary Web chat reaches its conversation limit, its replacement page uses a working history that fits the existing message and context budgets. Every human and privileged instruction, plus the current binding's verified completed calls and exact receipts, stays intact; omitted older records remain available through the same task's original-context reader. This also covers an explicit continuation after an older release failed before sending the replacement message: verified history selection happens before request admission, even when the full archive exceeds the current account budget. Pending or unknown tool results, an unavailable reader, and requirements that cannot fit are never silently discarded. History selection runs outside the database writer transaction, and ownership is checked again before committing it. Temporary Chat and normal same-page continuations retain their existing behavior.

While waiting for a native tool result, a normal tool-round transition does not end the wait. Before reporting that the wait scope changed, the gateway rechecks the exact call and current turn in one short transaction. An already saved result remains usable without executing the tool again or claiming that the Web page has received it. Actual cancellation, invalid ownership and unknown results retain their existing safeguards.

If an older turn's result-wait connection failed conclusively, its unacknowledged result does not keep a later stopped page waiting forever when the current context or a verified checkpoint covers the exact receipt. The gateway verifies that the old turn is terminal and has no pending tools or active MCP requests, then applies the current turn's normal recovery checks. It preserves the old delivery record; current pending work still blocks recovery.

Saved ordinary chats also check historical results consumed by an exact completed successor Web round when the old MCP acknowledgement never reached the gateway. The old turn must have completed, its tools and connections must have settled, and the exact receipt must remain covered by the current context or a verified checkpoint. The current empty stopped answer can then use the existing same-page continuation rules without inventing a delivery timestamp or replaying tools. Temporary Chat and unsettled current work keep their existing behavior.

Instructions to pause tools and submit only a summary apply to that compaction operation. The next ordinary request labels the summary as handoff data and asks the model to continue the original human task's remaining steps while preserving completed results and exact tool receipts. Genuine user pauses, cancellations and approval requirements remain in force.

After a checkpoint is saved, enabling control of the retained page uses the configured browser preparation budget (180 seconds by default), capped by the turn's existing deadline. A delayed `Page.enable` acknowledgement does not fail the task after three seconds or request the summary again. Explicit initialization failures retain the CDP error category and actual wait budget in the task timeline; obsolete callbacks and user stops cannot advance the new message.

For a new Free conversation without prior assistant/tool history, the complete first request is sent in one message when text, attachments and reserved context all fit. Larger requests still use multipart delivery without removing instructions or context. Already started transactions and native tool continuations keep their pinned transport budget.

Web message acceptance depends on content and Connector use, not a universal character limit or the API model's context size. New Sol-family requests consider both characters and tokens when preferring multipart delivery. The planner preserves complete records and the configured part cap; it does not trim history to meet its preferred message size. A proven inert-stage size rejection reduces recovery limits from the actual rejected stage. Discarded page timers cannot advance or fail its replacement. A physical upload rejection is reported separately from `context_length_exceeded`, including when replaying older saved failures.

A multipart ACK confirms transport, not accurate model recall of every earlier record. Long-history tasks expose the existing original-context reader so the model can check the human goal, exact tool receipts and remaining work. Safe recovery after a proven size rejection may use a smaller working view while preserving the complete archive and every human requirement and system/developer instruction verbatim.

A new saved chat acquires its own conversation URL while receiving historical parts. After verifying every stage acknowledgement for the same transaction, the gateway identifies and records the task's page ID once, then selects the connector and sends the final part there. Subsequent editing uses that verified URL without repeatedly reading historical ACKs, returning to the blank root or repeating acknowledged stages. An unverified initial identity or a subsequent URL change explicitly stops submission instead of silently stalling or sending to another task.

A disconnection notice alone does not end a task while the page is generating or tools remain active. After Stop disappears, Send returns and all accepted tools and receipts settle, an explicitly failed task first sends a short continuation contract on the original page and selects the Connector again. The same bounded path applies when a tool-only response remains empty after stopping, even without a red error card: it does not replay completed tools or infer a network fault from a cancelled stream. It continues only authorized remaining work, returns a verified final answer if finished and respects a human pause. An unusable page uses the existing full-context recovery. Durable intents prevent duplicate submissions and consecutive recovery attempts are bounded to three. Idle bindings left behind by conclusively failed historical turns no longer block current work; actual active calls still must drain.

Historical staging normally uses the selected family's non-Pro Medium mode; gateway summaries use non-Pro High. Actual tasks use your chosen model and effort. Inspect the recorded purpose and tier to confirm a submission. 3× does not triple memory, and a summary is not a verbatim copy of all previous records.

**Setup → Account and usage** covers this installation's last seven days of Web submissions, Pro use and estimated tokens. Other devices and direct Web conversations are excluded. “Official balance unavailable” does not mean zero. See [context FAQ](faq.en.md#history) and [usage FAQ](faq.en.md#usage).

<a id="upgrade"></a>

## 9. Upgrades, configuration and recovery

Release builds check GitHub for a newer stable version in the background after startup, then every six hours, using the applied network/proxy settings. A new version produces one shell notice and a dismissible **Update available** link in the console header. Dismissing it hides that version in this browser; a later version can appear again. Checks do not open dialogs, download files, restart services or interrupt tasks. Offline or rate-limited checks retry quietly after an hour.

To disable checks, set `update_check_enabled = false` under `[gateway]` in `.env` and restart RCMAKER when idle. Source builds marked `dev` do not guess a release version and skip checks. Use the steps below to upgrade when convenient.

### 9.1 Update a binary in place

1. Finish active tasks and approvals, stop RCMAKER, and privately back up `.env` and the full `data/` directory.
2. **Enter the existing installation folder**, or set `RCMAKER_INSTALL_DIR` to it, then rerun the installer. For manual installations, replace the corresponding release files.
3. Preserve the data directory and ports, start the new version and check Setup.
4. Fully restart Codex after routing or model catalog changes. Refresh ChatGPT's tool catalog only when release notes require it.

Do not open the same data with old and new executables simultaneously. Stop writes before backing up; a copy of one actively written SQLite file is not a complete backup. The installer attempts to retain a `.previous` executable, but keep a pre-upgrade data backup for rollback too.

### 9.2 Update a source installation

Stop the gateway, back up data and inspect local changes. Obtain the desired source version, run `composer install` in the project root, and start with `php index.php start`. Do not use a forced reset over unpreserved local changes.

### 9.3 Data and optional deployment settings

The default layout is below; the Linux/macOS executable has no `.exe` suffix:

```text
installation/
  rcmaker-codex-gateway.exe
  .env                   # optional deployment settings; beside index.php for source
  data/                  # persistent login, credentials, tasks and attachments
  runtime/               # rebuildable operating files
```

Use the console for preferences and credentials; ordinary operation does not require manual database edits. Deployment overrides use the **INI sections** shown in [`.env.example`](../.env.example):

| `[gateway]` setting | Default / purpose |
| --- | --- |
| `console_port` | `28910`, local console |
| `api_port` | `28911`, Codex integration |
| `browser_debug_port` | `28912`, private browser control |
| `native_api_port` | `28913`, internal native API worker |
| `web_api_port` | `28914`, internal Web API worker |
| `data_directory` | `data`, a directory name beside the program, not an arbitrary absolute path |
| `default_locale` | `en`, used before a language preference has been saved |
| `codex_home` | Empty for automatic detection; can target another Codex configuration directory |

Codex configuration is resolved in this order: `.env` → `[gateway] codex_home`, then `CODEX_HOME`, then the current user's `.codex` directory. Windows uses `USERPROFILE` or `HOMEDRIVE` + `HOMEPATH`; Linux/macOS use `HOME`, with the effective user's home as a fallback. The gateway reads `config.toml` from that directory, independently of its own `data/` directory. Browser installation selects the host OS and CPU package; it preserves Unix executable permissions and internal application-bundle links.

All five ports must differ and listen only on the local computer. Codex continues to use `api_port`, which routes requests and relays responses asynchronously. Native forwarding and Web task handling run in separate API processes while sharing the existing business code and data. The two internal ports do not need to be added to Codex configuration. Process isolation reduces mutual blocking; CPU, memory and the database remain shared resources.

Fully stop and start RCMAKER after deployment changes so the new processes are loaded. If the Codex endpoint changes, preview/save integration again and restart Codex. Changing `data_directory` selects another store; **it does not migrate existing login state or tasks**.

### 9.4 Restore previous or official routing

In **Configure tool connection**, choose the appropriate action:

| Action | Result |
| --- | --- |
| Restore pre-integration configuration | Restores the backed-up route, which may belong to another gateway |
| Restore Codex official route | Clears gateway routing and Web defaults to restore official routing |

Follow the confirmation/save flow and fully restart Codex. Restoring a route does not delete project files or chats, but encrypted history may not be readable across model providers. Supply a readable handoff or use the optional official summary when needed.

<a id="troubleshooting"></a>

## 10. Troubleshooting at a glance

**Check Setup first, then the affected task's timeline.** Distinguish generation, tool/approval waiting, context handoff and explicit failure before choosing a retry.

| Symptom | First check | Next step |
| --- | --- | --- |
| The console will not open | Confirm the process is running, ports are correct and unused, and the address is on this computer | [Startup and network FAQ](faq.en.md#network) |
| Chrome download or component preparation fails | Save the correct network route; inspect the download error and disk space | Keep the component name and full error; do not recreate the plugin |
| Private Chrome is signed in but status is stale | Return to Setup, allow detection, then refresh status/page | Confirm the login is in this application's private browser |
| Connector failure, tool 502 or MCP SSE 404 | Check tunnel, plugin name/installation and the running Codex client | Compare the failing connection name with its timeline; the error alone does not prove project corruption |
| Missing Web models or Pro in Codex | Detect models, preview/save and fully restart Codex | Check whether the Web account offers that mode; see [model FAQ](faq.en.md#models) |
| Connection warning while tools still run | Compare both interfaces with the latest tool receipts; do not repeatedly regenerate | If the page has ended but Codex has not, record both states and timestamps |
| Compaction takes longer than expected | Check context-part progress, summary receipts, approvals and actual continuing activity | Do not start another summary while one is unconfirmed; retain the full 409 message |
| 409 for an unfinished turn or unknown result | Inspect real file changes, receipts and the old task's status | Resolve or end the old task before continuing; report persistent conflicts with redacted records |
| Encrypted history, `context_json`, `SCHEMA_MISMATCH` or ID-prefix errors | Save the exact error and whether you switched from an official model or another gateway | [History and protocol checks](faq.en.md#recovery); do not edit history or delete the database to force recovery |

Confirm the outcome of potentially executed writes before retrying. Clearing sign-in data, deleting a database or repeatedly recreating plugins is not a general recovery procedure.

When a Temporary Chat task encounters an explicit page network error after making tool progress, the gateway waits for in-flight tools and receipts to settle, then restores the complete context and exact receipts on a new page and revokes the old page's tool token. Recovery may continue only the remaining authorized work; incomplete receipts or unknown outcomes prevent automatic replay. Tasks without tool progress may still use native page Retry. Context compaction keeps its separate recovery rules. Ordinary mode retains its saved conversation binding instead of replaying full history after a network error; an unconfirmed submission reports its actual state.

### Include this in a problem report

An observed HTTP 429 on an owned conversation load or message submission immediately records a shared cooldown. New requests are retained in the durable queue instead of being rejected solely because that cooldown is active. Browser work waits asynchronously; accepted tool calls/results and HTTP heartbeats continue. The task timeline shows the waiting state in the selected console language.

For a saved ordinary chat, the gateway keeps its exact conversation ID and waits before refreshing the stopped page. It honors `Retry-After`; otherwise it starts at 30 seconds and increases the delay up to five minutes, adding a small jitter. Persistent limits keep waiting rather than failing after three reloads. Recovery is bounded by the current turn deadline and 64 attempts; a longer server delay is never shortened to fit that budget. Refreshes and new sends are spaced across tasks after a cooldown. A visible Stop prevents automatic refresh. Workspace status/screenshot updates do not reload ChatGPT pages.

An ordinary tool task whose message submission was rate limited uses the existing stopped-page, ownership and receipt checks before recovery. Completed tools are retained for a short continuation instead of regenerated. A rate limit alone does not authorize a new conversation or full-context replay. Explicit quota/billing restrictions that require account action, unknown tool outcomes, changed ownership and expired deadlines still stop unsafe retries and preserve results. ChatGPT website limits are not inferred from the OpenAI API's published RPM/TPM values; only actual response evidence drives this behavior.

For recurring **connection interrupted** notices, the task timeline records the browser protocol,
request duration and received byte counts. Matching background recovery requests and MCP reply sizes,
output-lock waits and peak worker memory are included in diagnostic exports. Missing observations
remain unknown; a flushed tool reply does not prove that the browser's separate answer stream is healthy.

Diagnostics keep browser-clock durations separate from gateway observation times to help distinguish
network interruption from delayed local event handling. When supplied by the server, validated request
trace IDs, numeric `Retry-After` seconds and cache flags help investigate recovery rate limits. Raw
headers, cookies and bodies are not stored. These observations do not trigger retries or change tasks.

Attachment upload and processing wait for up to 10 minutes by default, within the current task deadline.
Set `attachment_upload_timeout_seconds` (60–1800 seconds) under `[gateway]` in `.env` to adjust it;
the setting takes effect after a normal restart. The task timeline records confirmed counts, elapsed time
and upload status. The gateway does not send partial batches or automatically upload them again.

When historical images are uploaded again in ordinary chats, ChatGPT may append `(1)`, `(2)` or another number to gateway-generated image filenames. The gateway recognizes these aliases by the original content-hash filename, while still requiring every unique decoded preview in the current task's composer and no upload errors. Initial multipart commits in new ordinary chats keep their ownership marker at the start so collapsed long messages do not prevent binding. Complete history, part checksums and exact tool receipts remain intact.

If the recorded error is `net::ERR_HTTP2_PROTOCOL_ERROR`, you can test `browser_http_protocol = http1`
under `[gateway]` in `.env` after tasks finish and the private Chrome is restarted normally. The default
`auto` preserves existing behavior. This option affects only private Chrome, not native Codex forwarding
or MCP. It is a compatibility workaround, not a guarantee against server/proxy timeouts; compare long-task
results before keeping it. Do not refresh or regenerate an active task just to change the protocol.

```text
RCMAKER version:
Operating system / CPU architecture:
Installation: binary / source
Model and effort:
Stage: startup / sign-in / tool call / compaction / completion
Other concurrent tasks or a recent model switch:
Timestamp and full error:
Status and actual file results already checked:
Redacted screenshots or task timeline:
```

Report through [GitHub Issues](https://github.com/runchance/rcmaker-codex-gateway/issues). Remove API keys, cookies, local tokens and private project content first. Do not upload the entire `data/` directory.

[Back to README](../README.md) · [Full FAQ](faq.en.md) · [简体中文](user-guide.zh-CN.md)
