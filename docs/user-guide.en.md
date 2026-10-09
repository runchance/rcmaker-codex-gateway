# rcmaker-codex-gateway — Illustrated User Guide

**English** · [简体中文](user-guide.zh-CN.md) · [Project README](../README.md) · [FAQ](faq.en.md)

This guide takes you from a fresh installation to a verified Codex task. The console controls and examples were checked against the project on **9 October 2026**. Website labels, available models and account permissions can change; use your account's current controls.

**About the screenshots:** these are browser screenshots rendered from the application's current console templates, with sample values and no live service connection. Setup dialogs are shown as focused regions. They explain where to click and what to enter; they do not claim that a sample account, connection or task has been verified. No real API key, cookie or private conversation is included.

## Contents

- [Before you start](#before-start)
- [Install and open the console](#install)
- [Configure network, components and sign-in](#setup)
- [Connect the tools and ChatGPT plugin](#connect-tools)
- [Enable Web models in Codex](#codex-integration)
- [Run and verify a first task](#first-task)
- [Daily operation, settings and usage](#daily-use)
- [Upgrade, restore and troubleshoot](#upgrade)

<a id="before-start"></a>
## 1. Before you start

You will use three interfaces:

| Interface | What you do there |
| --- | --- |
| **Codex** | Open the project, choose a model, submit work, review approvals and file changes |
| **RCMAKER console** | Configure the connection, inspect browser activity, tasks and local usage |
| **Private ChatGPT browser** | Sign in and create the custom plugin used by the Web model |

Install Codex first. Use a ChatGPT account that can access the models and custom MCP apps you need, and a Platform account/workspace with the required Tunnel access. The console guides you through a Tunnel and ordinary runtime API key. It expects these to correspond to the private browser's ChatGPT account; the desktop Codex login can differ. Workspace permissions still apply.

Do not assume a subscription alone guarantees every feature. When a Tunnel or custom-app option is absent, check the current [OpenAI custom MCP app instructions](https://developers.openai.com/api/docs/guides/custom-mcp-server) and [Secure MCP Tunnel documentation](https://developers.openai.com/api/docs/guides/secure-mcp-tunnels), including workspace permissions.

<a id="install"></a>
## 2. Install and open the console

### Packaged application

Put the downloaded executable in a writable folder, such as `D:\Apps\RCMAKER-Gateway`, and **double-click the `.exe` to start it**. No `start` parameter is needed. PowerShell is optional:

```powershell
cd D:\Apps\RCMAKER-Gateway
.\rcmaker-codex-gateway.exe
```

Keep the running console window open. The program creates external persistent storage on first use; you do not need to copy another installation's `data` folder. Open **http://127.0.0.1:28910/** on the same machine. When you changed `console_port` in `.env`, use that port instead.

The application supports multiple platforms. Use a release built for your operating system and architecture.

### Source installation

Source installation supports **PHP 8.1–8.5 (inclusive)** on Windows, macOS and Linux. First follow the [RCMAKER official quick start — prepare your environment](https://rcmaker.runchance.com/doc/#/md/quick-start?id=_1-%E5%87%86%E5%A4%87%E7%8E%AF%E5%A2%83) to install PHP, required extensions and Composer. Then, from the application's source directory:

```sh
composer install
php index.php start
```

The root `.env.example` shows optional INI-style deployment settings. Keep your existing `.env` when upgrading.

### Find your way around

The console has four main destinations: **Setup**, **Browser workspace**, **Task center** and **Settings**. A fresh installation uses English; the language selector switches the console without translating user conversations or model contracts.

![Setup overview with the four preparation steps and local usage panel](images/user-guide/en/setup.jpg)

*Figure 1. Work through the numbered setup cards. Their status, not the example screenshot, tells you whether your installation is ready.*

<a id="setup"></a>
## 3. Configure network, components and sign-in

### Step 1 — Network

Open **Setup → Configure network**. Choose **Direct connection** when the application can reach the required services directly. Otherwise choose **Use HTTP proxy**, enter the proxy host and port, then select **Save and test**.

![HTTP proxy fields and Save and test](images/user-guide/en/network.jpg)

*Figure 2. `127.0.0.1:7890` is only an example. Use the HTTP listening port configured by your own proxy software.*

| Field | Enter |
| --- | --- |
| Proxy IP/host | A host or IP, such as `127.0.0.1`; no `http://`, path or embedded username |
| Port | Your proxy's HTTP CONNECT listening port |
| Authentication, when required | Configure it in the detailed **Network and proxy** settings |

The supported proxy mode here is **HTTP CONNECT**. A SOCKS-only port is not interchangeable with it. This configuration belongs to RCMAKER; it does not change the system proxy. Browser traffic, runtime downloads and the tool tunnel reuse the selected route.

**Success:** the console confirms network connectivity. An HTTP 403 from the target can still demonstrate a successful network/TLS connection; ChatGPT sign-in and model access are checked separately. A 403 rejecting the proxy's CONNECT tunnel is a different failure. See [network FAQ](faq.en.md#network).

### Step 2 — Runtime components

Open **Prepare runtime components**, then **Start preparation**. Wait for the private Chrome and tunnel components to finish preparation.

![Runtime component preparation dialog](images/user-guide/en/runtime.jpg)

*Figure 3. Prepare components after saving the network route, so downloads use the intended connection.*

**Success:** the Runtime card reports readiness. Components belong to this application's data directory. A failed download should be diagnosed before proceeding; repeatedly recreating a connector will not repair a missing browser runtime.

### Step 3 — Sign in to ChatGPT

Open **Sign in to ChatGPT → Open sign-in page**. Complete sign-in using the account intended for the Web model.

![Private ChatGPT sign-in controls, including native browser access](images/user-guide/en/login.jpg)

*Figure 4. The embedded view and native browser window share RCMAKER's private browser profile.*

For direct typing, scrolling and pasting, choose **Open native browser**. This opens the project's private Chrome in its own window—not your regular system Chrome profile. After signing in, return to the console and wait for account/model detection. **Hide native browser** hides the visible setup window while preserving the private login. When the panel looks stale, use **Reload page** or **Refresh status** before attempting another sign-in.

**Success:** account and model checks pass. Simply seeing the ChatGPT homepage is not proof that tool access works; connector confirmation comes next.

**Clear private Chrome sign-in data** removes RCMAKER's saved ChatGPT/Google login state and profiles. It is a deliberate account-reset action, not a routine refresh. Finish active work first. It does not target system Chrome or project source files.

<a id="connect-tools"></a>
## 4. Connect the tools and ChatGPT plugin

Open **Setup → Configure tool connection**. Complete the following sequence; the tunnel must be running before ChatGPT can discover its tools.

### 4.1 Create a Tunnel and save its connection

Use **Open tunnel creation** and create a Tunnel in the appropriate Platform organization. Copy its `tunnel_...` identifier. Then use **Open API key creation** to create an **ordinary runtime API key**, not an Admin key, with the required access.

![Tunnel ID, API key and connector name fields](images/user-guide/en/tools.jpg)

*Figure 5. The public screenshot contains placeholders. Enter your own values only in your local console.*

| Field | Requirement |
| --- | --- |
| Tunnel ID | The identifier of the Tunnel you created |
| API Key | Ordinary runtime key with access to that Tunnel; an existing saved key is not displayed again |
| Connector name | Fixed `RCMAKER` prefix plus your suffix; the fresh-install default is `RCMAKER Codex` |

Select **Save and connect tunnel**. Wait for the tunnel to report connected. Saving activates its configuration; a normal first-time connection should not require an RCMAKER restart.

The Tunnel/API-key buttons open in the browser displaying the console. Check which account that browser uses. Plugin creation uses the private ChatGPT account. Keep the identities and workspace access consistent; do not paste a key from an unrelated account simply because its format looks correct.

### 4.2 Create the matching ChatGPT plugin

![Connector creation checklist and confirmation controls](images/user-guide/en/connector.jpg)

*Figure 6. The local checklist shows the expected connector name and the Check connector button.*

Open **ChatGPT Connector settings**, or its **Open native browser** option. In the account's current ChatGPT interface, open **Plugins → + → Add custom MCP server**. Accounts with an older layout may expose creation through Developer Mode or Apps settings. Follow the current [official creation flow](https://developers.openai.com/api/docs/guides/custom-mcp-server).

Use these values:

| ChatGPT field | Value |
| --- | --- |
| Name | Exactly the full name shown by RCMAKER, for example `RCMAKER Codex` |
| Connection/source | **Tunnel**, then choose your created Tunnel |
| Authentication | **No authentication / None**, as required by this local integration |

Review the permissions and warnings shown by ChatGPT, create the plugin, and install/connect it when the current interface requires that step. Return to RCMAKER and select **Check connector**.

**Success:** both the tunnel and connector checks pass. This verifies the actual tool path; a saved plugin name alone does not establish connectivity.

When updating tool definitions, ChatGPT's **Refresh tools / Refresh** refreshes its cached tool catalog. RCMAKER's **Check connector** verifies the connection; it does not perform that catalog refresh. Ordinary restarts do not require recreating the plugin. After changing the connector suffix, create a matching new plugin and verify it; see [connector FAQ](faq.en.md#connector).

<a id="codex-integration"></a>
## 5. Enable Web models in Codex

In the same configuration dialog, find **Enable Web models in Codex**. Choose **Detect available models**, then **Preview integration**. Review the proposed changes before selecting **Save integration**.

![Model detection, integration preview and the Codex restart reminder](images/user-guide/en/codex.jpg)

*Figure 7. The sample shows the restart reminder after saving. Model names are illustrative; your account's detection result is authoritative.*

The integration maintains its own routing and model metadata, with a backup for restoration. It preserves unrelated project, MCP and permission settings. Switching from another gateway can replace that gateway's model route, which is why you review the preview.

> **After configuration, wait for the RCMAKER shell to display `Ready. Start Codex; if it is already open, fully quit and restart it.` Only then start Codex, or fully quit Codex (including background processes) and reopen it. Keep RCMAKER running.**

The readiness line confirms that network, browser, ChatGPT sign-in, model availability and connector checks have completed. If it is absent, return to **Setup** and finish the pending step. Opening another chat does not reload the model catalog.

Available Web entries end in **RC (Web)**. The website's family name is followed by a mode, for example `GPT-6 · Thinking · RC (Web)`. Thinking offers its supported effort choices; Instant and Pro have separate fixed-mode entries because their context budgets can differ. Model examples are not promises of access. Choose the separate **Pro** entry to use Web Pro when detected; you do not need to enable Codex Ultra just to select it.

**Success:** the new entries appear after restarting Codex, and the console's integration check succeeds.

<a id="first-task"></a>
## 6. Run and verify a first task

Open the intended project in Codex. Choose a detected **RC (Web)** model and start with a small read-only request:

```text
Inspect this project's directory structure and README. Explain the startup command
and missing configuration. Do not modify files yet. Base the answer on actual files.
```

Confirm the full path:

| Where to look | What should happen |
| --- | --- |
| Codex | Native tool calls and actual results appear, followed by the answer |
| Browser workspace | A task-owned ChatGPT page appears automatically |
| Task center | The corresponding task records messages, receipts and status |

Then submit a concrete development request:

```text
Fix the selected bug in this project. First inspect the relevant implementation and
tests, make the required change, run the appropriate checks, and correct failures.
Report the actual changes and verified results, plus anything still unverified.
```

Handle permission prompts in Codex when required. The gateway does not grant the model broader local access than Codex allows. A final success claim is not a substitute for file changes and test results; inspect the diff and relevant outputs.

![Browser workspace before a first task](images/user-guide/en/browser.jpg)

*Figure 8. An empty workspace is normal before a Web task starts. Real task pages are created by the gateway.*

<a id="daily-use"></a>
## 7. Daily operation

### Browser workspace and multiple tasks

Start tasks from Codex. Do not type competing instructions directly into automation-owned ChatGPT pages. The workspace supports up to five task pages, distinguishes active/idle pages and prioritizes active work. Close only eligible idle pages or wait for capacity; do not close a running page to make room. Closing a page and deleting a task record are different operations.

For a mid-task correction, send the message in the **same Codex conversation**. The gateway forwards it as guidance for the active task. Related changes should be incorporated; an incidental question should not replace the original goal unless you explicitly request that. A fresh temporary browser page may be used for handoff while the Codex conversation continues.

### Task center, original messages and cleanup

![Task center filters and cleanup controls](images/user-guide/en/tasks.jpg)

*Figure 9. The empty example shows the status filters. Actual tasks populate the table after use.*

Use the task list's timeline action to inspect the Web interaction, tool results, context-delivery decisions and any unresolved status. Expand the full-message record to inspect the exact sent contract and context; use its **Copy** or **Download** action. A recorded send intent is distinct from a confirmed page receipt.

Filters separate continuable tasks, tasks needing attention, completed tasks and cancelled tasks. **Clear all idle tasks** removes eligible non-working records; running, tool-executing and approval-waiting tasks are skipped. Read the confirmation before deleting. Project files are not cleanup targets, and some checkpoint/attachment proofs remain for continuity. Database space reclamation can be deferred while tasks work. See [cleanup FAQ](faq.en.md#cleanup).

### Preferences, context and Pro

![Preferences: language, optional official handoff and context options](images/user-guide/en/settings.jpg)

*Figure 10. The example shows the new-install 3× / six-part combination. Existing installations keep saved preferences.*

| Setting | Practical meaning |
| --- | --- |
| Console language | Saves English/Chinese UI preference; conversation and contract text remain original |
| Up to 3× history | Allows longer history intake; does not triple the model's memory window |
| Maximum parts | An upper bound; the gateway chooses one message or the smallest suitable 2–6-part plan |
| Official handoff summary | Off by default; enabling it may consume official Codex quota when encrypted history needs a readable handoff |
| Upload skill files | Optional; selected skill files share the upload limit with recent images |
| Weekly Pro tracking budget | Supplies a local estimate only; use your actual allowance, not an example from a screenshot |

Keep defaults for an initial setup. The 3× option requires the six-part ceiling; lowering that ceiling is not equivalent to keeping 3× unchanged. Already-running work keeps its saved budget/settings snapshot. When model catalog metadata changes, fully reopen Codex.

Historical staging uses non-Pro Medium on the selected family; gateway summaries use non-Pro High; the actual execution submission uses the selected task model and effort, including Pro. Compaction is a handoff, not an automatic task failure. Review its decision evidence if it becomes unexpectedly frequent; do not solve it by arbitrarily raising every timeout or deleting history.

**Model contracts** are in a separate settings tab. New users should keep the defaults. If you change a template, preserve its required placeholders and never put real credentials into it.

### Read usage correctly

**Setup → Account and usage** displays the detected account, model choices, recent Web submissions, Pro usage and estimated tokens. Statistics cover this installation over a rolling seven days; they exclude other devices and direct website conversations. An estimated Pro balance is not the provider's official remaining quota or reset time. See [usage FAQ](faq.en.md#usage).

<a id="upgrade"></a>
## 8. Upgrade, restore and troubleshoot

### Upgrade in place

1. Let active work and approvals finish. Save your Codex work and stop RCMAKER using your normal method.
2. Keep a private backup of the existing `.env` and persistent `data` directory. Preserve the old executable for rollback. Do not copy an actively written SQLite file as though it were a consistent full backup.
3. Put the new executable beside the same data directory. Keep the same `data_directory` and port configuration. Do not run old and new versions simultaneously on the same ports/storage.
4. Start the new executable, check Setup, then fully restart Codex when model metadata or integration changes require it. Refresh the ChatGPT plugin's tools only when that release changes its tool definitions.

Changing `data_directory` selects different storage; it does not automatically migrate your login and tasks. A packaged binary does not include your development data. Documentation is delivered separately; never assemble a public documentation package by copying the entire application `data` directory.

### Restore the Codex route

In **Configure tool connection**, **Restore pre-integration configuration** restores the backed-up prior routing choice; that choice may be another gateway. **Restore Codex official route** clears the bridge route and Web defaults. Review the preview and save/confirm the intended operation, then fully restart Codex.

Restoring routing is not deletion of your project files or chat history. Unreadable provider-encrypted context cannot be magically decrypted by the Web model; see [history FAQ](faq.en.md#history).

### When something stops progressing

Check **Setup**, then the affected task's timeline. Determine whether the task is generating, waiting for a tool/approval, handing off context, or explicitly failed. A lingering website connection message is not on its own proof that tool execution stopped. Do not repeatedly resend a possibly executed mutation.

Use the [FAQ](faq.en.md) for connector failures, missing models, 409 conflicts, interrupted streams and JSON decoding failures. A useful issue report includes version, operating system, time, selected model/effort, exact error code and a redacted timeline excerpt. Remove keys, cookies, local tokens and private prompts before sharing.

## Sources and further reading

Project resources: [README](../README.md), [FAQ](faq.en.md), and [deployment example](../.env.example).

External setup: [RCMAKER framework](https://github.com/runchance/rcmaker), [OpenAI Secure MCP Tunnel](https://developers.openai.com/api/docs/guides/secure-mcp-tunnels), [Add a custom MCP server](https://developers.openai.com/api/docs/guides/custom-mcp-server), and [Developer mode](https://help.openai.com/en/articles/12584461-developer-mode-and-mcp-apps-in-chatgpt).

[Back to README](../README.md) · [FAQ](faq.en.md) · [中文版](user-guide.zh-CN.md)
