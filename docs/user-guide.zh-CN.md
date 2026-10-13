# RCMAKER Codex Gateway · 用户操作手册

**简体中文** · [English](user-guide.en.md) · [项目介绍](../README.zh-CN.md) · [常见问题](faq.zh-CN.md)

第一次使用：**选择安装方式 → 完成运行准备 → 接入并重启 Codex → 发起任务**。已经配置过的用户，可以直接查看日常操作或故障速查。

| 你现在要做什么 | 从这里开始 |
| --- | --- |
| 了解需要准备什么 | [1. 开始前准备](#before-start) |
| 下载程序直接使用 | [2. 二进制安装](#binary-install) |
| 使用 PHP 源码运行 | [3. 源码安装](#source-install) |
| 配置网络、组件与登录 | [4. 运行准备](#setup) |
| 创建连接器、接入 Codex | [5. 工具连接与 Codex 接入](#connect-tools) |
| 发起任务、查看进度和记录 | [6. 首次任务](#first-task) · [7. 日常操作](#daily-use) |
| 调整语言、上下文和用量设置 | [8. 设置说明](#settings) |
| 更新、恢复或解决故障 | [9. 升级与恢复](#upgrade) · [10. 故障速查](#troubleshooting) |

操作入口按 **2026 年 10 月 10 日**的项目文件核对。配置截图使用控制台模板和示例数据，仅说明按钮位置；真实连接状态、账号权限和模型列表以你的界面为准。

<a id="before-start"></a>

## 1. 开始前准备

### 分清三个操作窗口

| 窗口 | 用途 |
| --- | --- |
| **Codex** | 打开项目、发送需求、处理授权、查看文件变更与最终结果 |
| **RCMAKER 本机控制台** | 完成配置，查看运行状态、任务记录和网页实况 |
| **RCMAKER 私有 Chrome** | 登录 ChatGPT、创建该账号使用的插件；任务网页由网关管理 |

私有 Chrome 的内嵌画面与“打开原生浏览器”窗口共用登录资料，和你日常使用的系统 Chrome 分开。**只在系统 Chrome 登录 ChatGPT，不等于网关已登录。**

### 准备账号、网络与安装目录

使用已安装 Codex 的电脑，并准备一个自己有写入权限的安装目录。首次配置需要联网下载运行组件、登录 ChatGPT 和连接工具隧道；二进制包含 PHP，但不意味着安装后可以完全离线使用。

准备具有所需模型和自定义 MCP 插件权限的 ChatGPT 账号，以及有相应 Tunnel 权限的 Platform 账号/工作区。按控制台引导，使用与私有 ChatGPT 登录相对应的账号配置 Tunnel 与普通运行 API Key；Codex 桌面端登录可以不同。**同一邮箱不等于拥有所有工作区权限**，Tunnel 的组织/工作区关联和创建、使用权限需分别核对，见 [OpenAI 官方说明](https://developers.openai.com/api/docs/guides/secure-mcp-tunnels)。

<a id="install"></a>

| 安装方式 | 适合谁 | 是否需要自行安装 PHP / Composer |
| --- | --- | --- |
| [二进制](#binary-install) | 直接使用网关 | 不需要 |
| [源码](#source-install) | 参与开发、自行维护运行环境 | 需要 |

**二选一即可。** 两种方式启动后都从[运行准备](#setup)继续。

<a id="binary-install"></a>

## 2. 二进制安装

### 2.1 自动安装

**Windows x64：**在 PowerShell 中执行。

```powershell
irm https://raw.githubusercontent.com/runchance/rcmaker-codex-gateway/master/.github/scripts/install.ps1 | iex
```

**Linux / macOS：**在 Bash 中执行，支持 x64 和 ARM64。

```bash
curl -fsSL https://raw.githubusercontent.com/runchance/rcmaker-codex-gateway/master/.github/scripts/install.sh | bash
```

脚本下载最新正式版并校验压缩包及可执行文件的 SHA-256。Windows 脚本需要系统 `tar.exe`；Linux/macOS 脚本会检查下载、解压和校验工具，缺少时按错误提示补齐后再运行。脚本源码：[Windows](../.github/scripts/install.ps1) · [Linux/macOS](../.github/scripts/install.sh)。

**安装位置：**当前目录已有程序时原位更新；否则使用当前目录下的 `rcmaker-codex-gateway/`。首次在交互终端安装时可输入其他目录，非交互运行使用默认值。最终位置以脚本输出为准。

<details>
<summary>需要指定目录？展开查看命令</summary>

在执行安装脚本前设置目录。以下只是路径示例，请换成自己的目标位置。

Windows：

```powershell
$env:RCMAKER_INSTALL_DIR = 'D:\Apps\RCMAKER-Gateway'
irm https://raw.githubusercontent.com/runchance/rcmaker-codex-gateway/master/.github/scripts/install.ps1 | iex
```

Linux / macOS：

```bash
export RCMAKER_INSTALL_DIR="$HOME/Apps/rcmaker-codex-gateway"
curl -fsSL https://raw.githubusercontent.com/runchance/rcmaker-codex-gateway/master/.github/scripts/install.sh | bash
```

要安装到当前目录，可将该变量设为 `.`。相对路径以执行命令时的目录为基准。Bash 的变量必须先 `export`，不能只把变量赋给管道左侧的 `curl`。

</details>

### 2.2 手动下载安装

打开 [Releases](https://github.com/runchance/rcmaker-codex-gateway/releases/latest)，在发行附件中选择与机器匹配的压缩包；直接使用的用户不要下载 GitHub 自动生成的 **Source code** 包。

| 机器 | 发行包文件名后缀 |
| --- | --- |
| Windows x64 | `windows-x86_64.zip` |
| Linux x64 | `linux-x86_64.tar.gz` |
| Linux ARM64 | `linux-aarch64.tar.gz` |
| macOS Intel | `macos-x86_64.tar.gz` |
| macOS Apple Silicon | `macos-aarch64.tar.gz` |

解压到有写入权限的目录，保留包内许可证和配置示例。无需提前建立 `data/`，也不要复制其他用户的登录资料。发行文件以所选 Release 实际附件为准。

### 2.3 启动程序

安装脚本**不会自动启动**网关。进入脚本输出或手动解压的目录：

| 系统 | 启动方式 |
| --- | --- |
| Windows | 双击 `rcmaker-codex-gateway.exe`，或在 PowerShell 执行 `.\rcmaker-codex-gateway.exe` |
| Linux / macOS | 在终端执行 `./rcmaker-codex-gateway` |

手动解压后若缺少执行权限，可先运行 `chmod +x ./rcmaker-codex-gateway`。系统提示缺少浏览器运行库或图形环境时，按具体报错准备环境；对应平台的二进制不包含所有操作系统组件。

保持运行窗口开启，在同一台电脑打开 **[本机控制台](http://127.0.0.1:28910/)**，继续[第 4 节](#setup)。默认 `data/` 位于可执行文件旁，首次使用时自动创建。

<a id="source-install"></a>

## 3. 源码安装

**已经采用二进制的用户跳过本节。**

### 3.1 准备 PHP 环境

准备 Git、Composer 和 PHP 8.1–8.5 的 CLI 环境。项目发行构建使用 PHP 8.5，扩展配置包括 `curl`、`openssl`、`zip`、`mbstring`、`pdo_sqlite`、`sqlite3`、`sockets`、`sodium`，见[构建配置](../.github/workflows/release.yml)。Unix 常驻进程所需扩展也应按运行框架要求启用。

可先参照 [RCMAKER 环境准备](https://rcmaker.runchance.com/doc/#/md/quick-start?id=_1-%E5%87%86%E5%A4%87%E7%8E%AF%E5%A2%83)，再在准备运行网关的终端检查：

```bash
php -v
php -m
composer --version
```

终端中的 PHP 必须具备所需扩展；仅在网站/FPM 使用的另一套 PHP 中启用扩展无效。

### 3.2 获取源码并启动

```bash
git clone https://github.com/runchance/rcmaker-codex-gateway.git
cd rcmaker-codex-gateway
composer install
php index.php start
```

已经下载源码时，在其根目录从 `composer install` 开始。不要跳过 Composer 的安装脚本；项目通过它应用所需的框架兼容补丁。

默认配置可以直接启动。需要覆盖部署参数时，参考根目录 [`.env.example`](../.env.example) 创建 `.env`；已有 `.env` 不要覆盖。默认数据目录是项目根目录下的 `data/`，不是 PHP 可执行文件旁。

保持进程运行，打开 **[本机控制台](http://127.0.0.1:28910/)**。后续配置与二进制相同。

<a id="setup"></a>

## 4. 运行准备：网络 → 组件 → 登录

新安装默认英文，可在初始化向导右上角选择简体中文。完成后也可使用顶部语言菜单或 **Settings → Console language**。语言切换立即保存，不需要重启。

首次进入控制台会显示初始化遮罩，依次完成**网络 → 组件 → 登录 → 隧道 → 连接器 → Codex 接入**。只显示当前步骤的操作，其他页面暂不可切换。每步保存成功后记录进度，刷新或重启后从未完成处继续；最后点击“进入控制台”。

已完成初始化时，运行准备显示连接状态卡片，可单独管理某项配置。

![运行准备：网络、运行组件、登录与工具连接](images/user-guide/zh-CN/setup.jpg)

*控制台示意使用演示数据；账号、模型和用量以实际检测为准。*

### 4.1 设置网络

点击**设置网络**。可以直连时选择**直接连接**；需要代理时选择**使用 HTTP 代理**，填写主机和端口，点击**保存并测试**。

| 字段 | 填写方式 |
| --- | --- |
| 代理 IP / 主机 | 如 `127.0.0.1`，不带 `http://`、用户名或路径 |
| 端口 | 代理软件实际提供的 HTTP CONNECT 端口 |
| 代理认证 | 展开“代理认证（可选）”填写用户名和密码；已有密码留空保留 |

保存的网络配置供应用浏览器、组件下载和工具隧道使用，不修改系统代理。仅支持 SOCKS 的端口不能当作 HTTP 端口填写。

**完成标志：**网络检查通过。目标网站返回 HTTP 403 与代理拒绝建立隧道是不同情况；网络可达也不代表账号已登录，详见[网络 FAQ](faq.zh-CN.md#network)。

<details>
<summary>查看网络配置截图</summary>

![网络弹层中的代理字段与保存按钮](images/user-guide/zh-CN/network.jpg)

截图端口仅为示例，使用自己的代理端口。

</details>

### 4.2 准备运行组件

点击**准备运行组件 → 开始准备**，等待私有 Chrome 与工具隧道组件准备完成。先设置网络，再下载组件。

**完成标志：**运行组件卡片显示就绪。下载失败先查网络、磁盘空间与完整报错，不要转去重复创建连接器。

<details>
<summary>查看运行组件截图</summary>

![运行组件准备弹层](images/user-guide/zh-CN/runtime.jpg)

</details>

### 4.3 登录 ChatGPT

点击**登录 ChatGPT → 打开登录页面**，完成账号登录。内嵌画面不方便输入或滚动时，点击**打开原生浏览器**，在独立的私有 Chrome 窗口操作。

登录后控制台自动检测账号并同步模型，无需重新点击打开页面。可以用**收起原生浏览器**收起窗口；画面本身未更新时使用**刷新页面**。

**完成标志：**账号识别成功、模型检查完成。接着进入工具连接。

<details>
<summary>查看登录与原生浏览器按钮</summary>

![登录控制：打开页面、打开原生浏览器与刷新](images/user-guide/zh-CN/login.jpg)

</details>

**“清空内置 Chrome 登录资料”是重置操作。** 它会删除本应用保存的 ChatGPT/Google 登录和 Profiles，之后需要重新登录。普通刷新不要使用这个按钮；确需换号重置时先结束任务。

确认清空后立即进入登录步骤。重新登录并同步模型后，会自动检查已保存的连接器；失败时按提示手动检查。任务和网关设置保留，不需要重新填写已保存的 Tunnel ID 和 Key。

<a id="connect-tools"></a>

## 5. 工具连接与 Codex 接入

初始化时进入“隧道”和“连接器”步骤；日常管理从**运行准备 → 管理连接**打开。顺序是：**创建 Tunnel → 保存 Key 并连接 → 创建 ChatGPT 插件 → 检查连接器 → 接入 Codex**。

### 5.1 创建 Tunnel 并连接

点击**打开 Tunnel 创建页面**，创建 Tunnel 并复制 `tunnel_...` 标识；再点击**打开 API Key 创建页面**，创建有对应权限的**普通运行 API Key**，不是 Admin key。

这两个按钮在你正在使用的控制台浏览器中新开标签。检查该浏览器的登录账号，避免与私有 ChatGPT 账号、目标工作区混淆。

| 本机字段 | 填写内容 |
| --- | --- |
| Tunnel ID | 刚创建的 `tunnel_...` 标识 |
| API Key | 有权使用该 Tunnel 的普通运行 Key；已保存时可以留空 |
| 连接器名称 | 固定前缀 `RCMAKER` 加自定义后缀，默认 `RCMAKER Codex` |

点击**保存并连接工具隧道**，等待已连接，再继续下一步。正常首次保存会让隧道开始连接，无需为这一步重启网关。

![工具连接：填写 Tunnel ID、API Key 和名称](images/user-guide/zh-CN/tools.jpg)

### 5.2 创建 ChatGPT 插件并检查

点击**打开 ChatGPT Connector 设置**，或该区域的**打开原生浏览器**，使用私有 Chrome 的账号创建插件。

当前官方入口为 **Plugins → ＋ → Add custom MCP server**。账号仍使用 Apps / Developer Mode 界面时按其实际入口操作；需要开发者模式或工作区授权时，先完成对应要求。参见[官方创建步骤](https://developers.openai.com/api/docs/guides/custom-mcp-server)。

| ChatGPT 字段 | 本项目填写方式 |
| --- | --- |
| Name / 名称 | 使用“复制连接器名称”得到的完整名称，如 `RCMAKER Codex` |
| Connection / 来源 | 选择 **Tunnel**，并选中上一步的 Tunnel |
| Authentication / 身份验证 | **No authentication / None** |

阅读页面提示后创建插件，并完成界面要求的安装/连接操作。回到 RCMAKER，点击**检查连接器**。

**完成标志：**隧道和连接器检查均通过。Tunnel 不在列表中时，先核对组织/工作区关联与权限；插件创建成功但检查失败时，按[连接器 FAQ](faq.zh-CN.md#connector)排查。

<details>
<summary>查看连接器检查清单</summary>

![连接器名称、创建要求与检查按钮](images/user-guide/zh-CN/connector.jpg)

</details>

已有安装保留原连接器名称，不必改成默认值。修改后缀后应新建名称匹配的插件再检查。普通重启不用重建插件；发行说明要求更新工具定义时，在 ChatGPT 点 **Refresh / Refresh tools**，再回本机检查连接。两者不是同一个操作。

<a id="codex-integration"></a>

### 5.3 保存 Codex 接入并完整重启

在同一配置弹层找到**在 Codex 中启用 Web 模型**：

1. 点击**检测可用模型**，等待当前网页账号检测完成。
2. 点击**预览接入配置**，核对目标配置与路由变更。
3. 点击**保存接入配置**。

保存会备份并替换模型路由，保留无关的 MCP、项目与权限配置。从其他网关切换时，先核对预览中的旧路由。

![检测模型、预览接入、保存与重启提醒](images/user-guide/zh-CN/codex.jpg)

> **等待 RCMAKER 启动窗口显示“准备就绪”，然后完全退出 Codex，包括后台进程，再重新打开。保持 RCMAKER 运行。只关闭聊天标签或新建聊天不能替代重启。**

**配置接入标志：**Codex 配置中存在 `# RCMAKER BEGIN` 和 `# RCMAKER END` 两条实际注释，即显示已接入。忽略后面的标识值，不要求与当前安装记录或 URL 后缀一致；字符串里的示例文字和单独的压缩标记不计为接入。检测不重写配置。

此检查只代表最后的 Codex 步骤完成。前面任一步未通过，初始化遮罩仍会打开并继续对应步骤。

完整重启 Codex 后，应看到以 **RC (Web)** 结尾的模型。第一次真实任务用于确认工具通信，不是解锁控制台的条件。没有就绪提示时，回到运行准备处理尚未完成的配置。

<a id="first-task"></a>

## 6. 发起并核对首次任务

在 Codex 打开要处理的项目，选择检测通过的 **RC (Web)** 模型。Thinking 条目提供可用推理档位；Pro 可用时选独立的 **Pro · RC (Web)** 条目。具体家族名称以当前检测结果为准。

先发送一个只读、容易核对的请求：

```text
读取这个项目的 README 和目录结构，说明如何启动，以及缺少哪些配置。
这次先不要修改文件，回答要基于实际读取结果。
```

| 查看位置 | 应看到什么 |
| --- | --- |
| Codex | 实际工具调用与读取结果，随后给出回答 |
| 浏览器工作区 | 自动创建的 ChatGPT 任务页面 |
| 任务中心 | 对应任务的消息、工具回执与状态 |

通过后再提出具体开发需求，并在 Codex 中处理需要的授权。文件变更看 diff，验证结果看真实输出，不能只凭最后一句“已完成”判断。

<a id="daily-use"></a>

## 7. 日常操作

### 控制台标签与自动刷新

打开的页面标签和当前页面会记住。切回运行准备或浏览器工作区时自动读取最新状态，保留页面滚动和未保存的表单。刷新整个浏览器不保留未保存草稿。

复制、输入、滚动或拖动时，自动重绘暂缓；显式点击的操作正常执行。非活动页面暂停轮询，返回后继续。模型按家族分组，重复官网提醒只显示一次；设置中的上下文容量以表格显示，详细解释可以展开阅读。

### 启动、插话与停止

日常启动先打开 RCMAKER，等运行条件恢复，再使用 Codex；已保存的登录和连接配置会复用，不必每次创建插件。修改过接入或模型目录后，按控制台提示完整重启 Codex。

任务进行中，直接在**同一条 Codex 对话**补充要求或提问。相关修改作为任务引导；需要停止或替换目标时明确说明。交接可能新建临时网页，浏览器换页本身不代表 Codex 历史丢失。

停止任务在 Codex 操作，再查看任务中心状态。准备关闭网关时，先结束任务和等待中的授权，再在启动终端按 **Ctrl+C**。不要通过直接关掉执行中的网页来代替任务取消。

Codex 与 RCMAKER 使用同一配置的 Codex 目录时，网关会将明确的手动中断记录匹配到具体会话和回合，停止对应网页生成并保留标签与可读历史。之后在原 Codex 对话继续即可；原页面缺失或失效时，用完整上下文开启新页面。已完成的工具回执保留，未确认的本地执行结果不会重放。短暂断线、自动重连和网页异常继续沿用原恢复逻辑；读取不到本地会话记录时，不会据此擅自停止网页。

### 浏览器工作区

最多保留五个任务页面，标记活动/非活动；任务页面由网关自动管理。容量满时等任务释放页面，或关闭界面允许关闭的非活动页。**关闭网页与删除任务记录是两个操作。**

不要在自动化使用的 ChatGPT 任务页面直接输入另一份需求；从 Codex 发送，才能保留任务关联。

<details>
<summary>查看浏览器工作区与任务中心截图</summary>

![浏览器工作区，首次任务开始前可以为空](images/user-guide/zh-CN/browser.jpg)

![任务中心的状态筛选与清理入口](images/user-guide/zh-CN/tasks.jpg)

</details>

### 查看完整发送原文

进入**任务中心 → 对应任务 → 时间线**，查看消息、工具结果、上下文发送与压缩决策。展开全量消息记录，可以**复制或下载**实际发送的契约和上下文，用于核对附件、插话和异常。

“记录了发送意图”不等于“网页已接收”；排查时结合回执和后续状态。下载文件可能包含项目内容，分享前先脱敏。

### 清理任务

使用任务中心的**清除所有非工作任务**，按确认提示清理。运行中、工具执行中及等待审批的任务会跳过；清理不针对项目源文件和 Codex 聊天。仍被引用的检查点或附件可能保留，数据库缩小也可能延后，见[清理 FAQ](faq.zh-CN.md#cleanup)。

<a id="settings"></a>

## 8. 设置、上下文与用量

首次使用保留默认值即可。**日常设置**调整运行偏好；**模型契约**编辑发送给网页模型的模板，普通用户无需改动。

| 日常设置 | 怎么理解、何时修改 |
| --- | --- |
| 控制台语言 | 中英切换立即保存，不翻译用户消息或模型契约 |
| 接入最多 3× 历史（实验性） | 新安装默认开启，需要连接器与六段上限；不扩大模型自身窗口，部分模式不适用 |
| 最大分段数 | 新安装默认最多六段；实际按上下文与预算决定，不是每次固定发六条 |
| 每次模型响应最长等待 | 针对一次模型响应；收到工具结果后重新计时，不是整个项目总时长 |
| 每段确认 / 压缩摘要等待 | 分别控制上下文分段和摘要的等待；不要把两者混为一项 |
| 官方模型交接摘要 | 默认关闭；开启后可为无法直接读取的官方加密历史生成可读摘要，可能使用官方额度 |
| 上传技能文件 | 默认通过正文传递技能；勾选后技能文件与近期图片共用最多 10 个上传名额 |
| 自动确认 ChatGPT 一次性工具调用 | 仅处理当前连接器的单次确认，不取消 Codex 沙箱和本机审批 |
| Pro 每周跟踪预算 | 只用于本机预计余额，不会限制发送，也不是官方剩余额度 |

设置保存后，新请求使用新值，已开始任务保留原预算和等待设置。模型目录已更新而 Codex 仍显示旧值时，完整重开 Codex。

<details>
<summary>查看设置截图</summary>

![日常设置：语言、上下文和用量跟踪](images/user-guide/zh-CN/settings.jpg)

</details>

### 分段、压缩与 Pro 的区别

**分段**把较长历史分批传入；**压缩**生成供后续继续工作的摘要。两者都会增加等待，但不等于失败。长历史不仅包含你输入的几句话，还包括指令、技能和工具结果。

Free 新会话没有已有助理／工具历史时，首次完整输入连同附件和预留预算能容纳，就单段发送；真正超限才分段，不删减指令与上下文。已有任务、压缩、已经开始的分段事务和付费模型保持原传输策略。

历史预载通常使用所选家族的非 Pro 中档，网关摘要使用非 Pro 高档；正式任务使用所选模型与强度。实际发送用途和档位以任务记录为准。3× 不是三倍记忆，摘要也不是所有旧记录的逐字副本。

**运行准备 → 账号与用量**统计本机最近七天的网页提交、Pro 使用和估算 Token，不包含其他设备或直接网页操作。“官方余额未提供”不等于余额为零。更多解释见[上下文 FAQ](faq.zh-CN.md#history)与[用量 FAQ](faq.zh-CN.md#usage)。

<a id="upgrade"></a>

## 9. 升级、配置与恢复

### 9.1 二进制原目录升级

1. 结束任务和等待中的授权，停止 RCMAKER，私下备份 `.env` 与完整 `data/`。
2. **进入原安装目录**，或先设置 `RCMAKER_INSTALL_DIR` 指向原目录，再执行相同安装命令；手动安装则替换对应发行文件。
3. 保留数据目录与端口配置，启动新版，核对运行准备。
4. 接入配置或模型目录变化后完整重启 Codex；仅在发行说明要求时刷新 ChatGPT 的工具清单。

不要同时用新旧程序打开同一份数据。备份前停止写入，不要只复制正在使用的单个 SQLite 文件。脚本更新会尝试保留 `.previous` 程序，但回退前仍需保有升级前的数据备份。

### 9.2 源码升级

先停止网关并备份数据，检查本地代码改动；获取目标版本的源码后，在项目根目录运行 `composer install`，再用 `php index.php start` 启动。不要用强制重置覆盖未保存的本地修改。

### 9.3 数据目录与可选部署参数

默认结构如下，Linux/macOS 的可执行文件没有 `.exe` 后缀：

```text
installation/
  rcmaker-codex-gateway.exe
  .env                   # 可选部署配置；源码模式位于 index.php 旁
  data/                  # 登录、凭据、任务、附件等持久数据
  runtime/               # 可重建的运行文件
```

用户偏好和凭据由控制台保存；普通使用不需要手工编辑数据库。需要调整部署时，使用 [`.env.example`](../.env.example) 所示的 **INI 分区格式**：

| `[gateway]` 配置项 | 默认值 / 作用 |
| --- | --- |
| `console_port` | `28910`，控制台端口 |
| `api_port` | `28911`，Codex 接入端口 |
| `browser_debug_port` | `28912`，私有浏览器控制端口 |
| `data_directory` | `data`，程序旁的目录名称，不是任意绝对路径 |
| `default_locale` | `en`，未保存语言偏好时的默认语言 |
| `codex_home` | 留空自动检测；多安装环境可指定配置位置 |

Codex 配置目录按以下顺序定位：`.env` 中的 `[gateway] codex_home` → `CODEX_HOME` → 当前用户的 `.codex` 目录。Windows 使用 `USERPROFILE` 或 `HOMEDRIVE` + `HOMEPATH`；Linux/macOS 使用 `HOME`，缺失时读取实际运行用户的主目录。网关从该目录读取 `config.toml`，与自身的 `data/` 目录独立。浏览器安装按系统及 CPU 选择包，并保留 Unix 执行权限和应用包内部链接。

三个端口必须互不相同。部署参数修改后需要重启 RCMAKER；涉及 Codex 接入地址时重新预览、保存接入并重启 Codex。改变 `data_directory` 会选择另一份存储，**不会自动迁移原有登录和任务**。

### 9.4 恢复原路由或官方路由

在**配置工具连接**中按目的选择：

| 操作 | 结果 |
| --- | --- |
| 恢复接入前配置 | 恢复备份的旧路由；旧路由可能是另一网关 |
| 恢复 Codex 官方路由 | 清除网关路由与 Web 默认模型，回到官方路由 |

按界面完成确认/保存，再完整重启 Codex。恢复路由不删除项目文件或聊天，但跨模型的加密历史不一定可读；需要时提供可读摘要或使用已说明的官方交接选项。

<a id="troubleshooting"></a>

## 10. 故障速查

**先看运行准备，再看受影响任务的时间线。** 先分清还在生成、等待工具/授权、交接上下文，还是已经明确失败，再决定是否重试。

| 现象 | 先做什么 | 仍未解决时 |
| --- | --- | --- |
| 控制台打不开 | 确认启动进程仍在、端口正确且无占用；使用同机地址 | [启动与网络 FAQ](faq.zh-CN.md#network) |
| Chrome 下载或组件准备失败 | 先保存正确网络配置，再查看下载错误和磁盘空间 | 保留组件名与完整错误，不重复创建插件 |
| 私有 Chrome 已登录但控制台未更新 | 返回运行准备等待检测，使用刷新状态/页面 | 确认登录的是本应用私有浏览器 |
| 连接器失败，或工具返回 502 / MCP SSE 404 | 检查隧道、插件名称/安装状态、当前 Codex 是否仍在运行 | 核对失败的连接名称与时间线；这些错误本身不能证明项目文件已坏 |
| Codex 没有 Web 模型或 Pro | 重新检测模型，预览保存，完整重启 Codex | 核对网页账号是否实际有该模式，见[模型 FAQ](faq.zh-CN.md#models) |
| 网页显示连接中断，却仍有工具调用 | 对照两侧状态与最新工具回执，不要反复重新生成 | 若网页已结束而 Codex 不结束，记录时间与两侧截图 |
| 一直压缩或压缩超过预期 | 查分段进度、摘要回执、等待授权和是否还有实际进展 | 已有摘要未确认时不要重复发起另一份；保留完整 409 文案 |
| 409 未结束回合 / 未知执行结果 | 先核对实际文件变更、回执及旧任务状态 | 确认或结束旧任务后续接；持续冲突时提交脱敏记录 |
| 加密历史、`context_json`、`SCHEMA_MISMATCH`、ID 前缀报错 | 保存准确错误，注明是否从官方模型或另一网关切换 | [历史与协议排查](faq.zh-CN.md#recovery)；不要手改历史或删库尝试恢复 |

已经可能执行的写入操作，核对结果后再重试；清空登录、删除数据库或反复重建插件不是通用修复方法。

### 提交问题时附上这些信息

```text
RCMAKER 版本：
操作系统 / CPU 架构：
安装方式：二进制 / 源码
模型与档位：
问题发生阶段：启动 / 登录 / 工具调用 / 压缩 / 结束
是否同时运行其他任务、是否切换过模型：
发生时间与完整错误：
已核对的状态、实际文件结果：
脱敏截图或任务时间线：
```

通过 [GitHub Issues](https://github.com/runchance/rcmaker-codex-gateway/issues)反馈。分享前移除 API Key、Cookie、本地令牌和私有项目内容；不要上传整份 `data/`。

[返回 README](../README.zh-CN.md) · [完整 FAQ](faq.zh-CN.md) · [English](user-guide.en.md)
