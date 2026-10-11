# Automated releases / 自动发布

## English

This directory is self-contained after creating a new Git repository. It does not depend on the old remote, branch name, tags, local PHP path, or development `data/` directory. Keep `.github/`, `composer.json`, `composer.lock`, `config/release.php`, and the application source in the new repository.

Push a version tag after committing the release source:

```sh
git tag -a v1.0.0 -m "RCMAKER AI Gateway v1.0.0"
git push origin v1.0.0
```

`release.yml` validates the tag, generates release notes, then builds these five targets in parallel:

| Platform | Architecture | Native GitHub runner | Archive |
| --- | --- | --- | --- |
| Windows | x86_64 | windows-2025 | ZIP |
| Linux | x86_64 | ubuntu-24.04 | tar.gz |
| Linux | ARM64 / aarch64 | ubuntu-24.04-arm | tar.gz |
| macOS | Intel / x86_64 | macos-15-intel | tar.gz |
| macOS | Apple Silicon / aarch64 | macos-15 | tar.gz |

All builds use **PHP 8.5**, **source encryption**, locked production Composer dependencies, and the existing validated framework repair. The native `RC\Cli\BuildBinary` compiler owns encryption and packaging. A private staging directory contains the generated version metadata; the source `config/release.php` stays at `dev`.

Every build checks the actual executable header and CPU, PHAR signature, protected PHP payload, exclusion rules, archive contents, and SHA-256. Its native runner executes `--version` and `--build-info` without bootstrapping the gateway. Only after all five targets pass does the publisher create or resume a draft, verify every uploaded asset, and publish it. A failed build cannot expose a partial public Release.

No personal access token, translation API key, or custom signing secret is required. The publishing job uses GitHub's built-in `GITHUB_TOKEN` with `contents: write`; preparation and build jobs have read access. There is no automatic cleanup job or Actions deletion permission. GitHub Actions must be enabled in the destination repository. The hosted runtime/protection tools are obtained through the framework's official artifact repository. These packages are not Apple-notarized or Authenticode-signed.

The Release includes twelve assets: four Unix executables, five archives, English notes, `SHA256SUMS`, and `release-manifest.json`. **Windows is ZIP only**: its standalone EXE is excluded from Actions uploads and new Release assets. It is still compiled, encrypted and smoke-tested before upload; the publisher verifies the executable inside the ZIP against its SHA-256, byte count and PE/CPU header. The schema-2 manifest retains this executable's metadata, and `SHA256SUMS` retains its extracted-file hash for one-click installer compatibility. The Release page and newly uploaded notes are English only. Each archive contains the executable, licensing notices, and the sample `.env.example`; it contains no real `.env`, `data/`, browser profile, credential, log, test, CI, AI, or development directory. First startup creates external `data/`. Keep existing `data/` and your external `.env` when upgrading.

Extract the archive for your system. Run `rcmaker-codex-gateway.exe` on Windows or `./rcmaker-codex-gateway` on Linux/macOS. A directly downloaded Unix executable needs `chmod +x`. The startup banner and `--version` show the exact release tag.

### Notes and reruns

- The first version gets initial-release notes. Later versions compare with the highest lower ancestral SemVer tag; unrelated development tags are ignored. Stable releases ignore prerelease baselines. `v1.1.0-beta.1` is automatically marked as a prerelease.
- Default notes contain English subsystem summaries and change categories. English commit titles are retained. Chinese titles use an English category and a commit link instead of publishing Chinese or inventing a translation.
- For a precise English description, optionally add this field to a commit body; no Chinese counterpart is required:

  ```text
  Release-Note-EN: Preserve tool receipts when a stream is interrupted.
  ```

- To replace the generated prose for a particular version, commit `.github/release-notes/v1.0.0.en.md` **before** creating its tag. An empty override or Chinese text in this English file is rejected. Legacy `.zh-CN.md` overrides are ignored.
- In Actions, choose **Release encrypted binaries → Run workflow**, enter an existing tag, and leave **publish** disabled for a full build rehearsal. Enable it to retry a failed publication. An incomplete draft can resume. A published Release is verified against its existing manifest and never overwritten with newly encrypted bytes, including schema-1 releases with a standalone Windows EXE and older bilingual releases.
- `release-checks.yml` runs isolated CI contract tests for changes to the automation or version entry points. Dependabot opens monthly PRs for pinned Action updates; it does not auto-merge them.

### Storage and retention

**Historical Releases, their download assets and Git tags are retained.** Release assets have no total release-size or bandwidth limit; each release allows up to 1,000 assets, each smaller than 2 GiB. They are download attachments, not binaries committed into Git or stored in Git LFS. See [About releases](https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases).

This public repository uses standard GitHub-hosted runners, covered by GitHub's [free public Actions usage](https://docs.github.com/en/billing/concepts/product-billing/github-actions). No post-publication job deletes intermediate build artifacts. They follow the repository's configured Actions retention (90 days by default; public repositories support 1–90 days), so failed publications and build rehearsals remain available for normal retries. Changing Actions retention does not delete Release downloads. See [Actions retention settings](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/enabling-features-for-your-repository/managing-github-actions-settings-for-a-repository).

Larger hosted runners, private-repository storage and Git LFS have separate billing rules; this workflow does not use larger runners or LFS for its release files. Existing published assets are never deleted or rewritten on reruns. Only stale or unexpected assets in the current unpublished draft can be replaced. To rebuild after Actions artifacts have expired, rerun the entire workflow or start a new manual run.

The remote GitHub runners and public upload can only be verified after pushing this automation to the destination repository. Local acceptance verifies the scripts, workflow syntax, isolated failure paths, and a real encrypted Windows package; it does not claim an executed Linux/macOS production gateway.

References: [GitHub-hosted runners](https://docs.github.com/en/actions/reference/runners/github-hosted-runners), [Release API](https://docs.github.com/en/rest/releases/releases), [immutable releases](https://docs.github.com/en/code-security/concepts/supply-chain-security/immutable-releases).

## 中文

删除旧 `.git`、重新初始化后，这套自动化仍可直接使用。它不依赖旧远程地址、分支名、历史标签、本机 PHP 路径或开发数据。请保留 `.github/`、`composer.json`、`composer.lock`、`config/release.php` 和应用源码。

先提交待发布源码，再创建并推送版本标签：

```sh
git tag -a v1.0.0 -m "RCMAKER AI Gateway v1.0.0"
git push origin v1.0.0
```

自动生成英文发布说明，随后并行构建 Windows x64、Linux x64/ARM64、macOS Intel/Apple Silicon 五份包。全部使用 **PHP 8.5 + 源码加密**，沿用框架原生构建器、锁定的生产依赖和已经验证的框架补丁。构建 tag 写入隔离副本，不修改源码开发版的 `dev`。

每份包检查真实可执行格式与 CPU、PHAR 签名、PHP 加密、排除规则、归档内容及 SHA-256；对应原生 runner 再执行不启动服务的版本查询。五份全部通过后，才创建或接回 Release 草稿，上传并核对全部附件，最后公开发布。失败不会发布残缺版本。

无需额外配置个人 Token、翻译 API Key 或签名密钥；使用 GitHub 自带 Token，发布任务有 `contents: write`，准备和编译任务为只读。没有自动清理任务，也不申请 Actions 删除权限。新仓库需要开启 Actions。PHP 与保护工具由框架官方资源库提供；本方案未包含 Apple 公证或 Windows Authenticode 签名。

Release 共 12 个附件：四份 Unix 可执行文件、五份压缩包、一份英文说明、`SHA256SUMS` 和总构建清单。**Windows 只上传 ZIP，不上传独立 EXE**，包括 Actions 构建产物和新 Release 附件。EXE 仍先完成加密编译及版本冒烟测试，发布器从 ZIP 中读取并核对 EXE 的哈希、大小、PE 格式及 CPU；总清单使用 schema 2，仍保留 EXE 的元数据和解压后哈希，一键安装脚本继续正常校验。发布页面及新说明附件只使用英文。Windows 下载 ZIP，Linux/macOS 下载 tar.gz。包内只保留运行程序、许可证说明和 `.env.example` 示例，不包含真实凭据、数据、浏览器登录资料、日志或开发辅助目录。首次启动自动创建外部 `data/`；升级时保留已有 `data/` 与外置 `.env`。

启动 Banner 和 `--version` 显示完整 tag。Windows 解压后运行 `rcmaker-codex-gateway.exe`；Linux/macOS 运行 `./rcmaker-codex-gateway`。直接下载 Unix 可执行文件时先执行 `chmod +x`。

首版自动生成首发说明；后续按上一合法祖先版本计算变更，忽略开发标签。预发布标签如 `v1.1.0-beta.1` 自动标记预发布。默认生成英文模块摘要和分类；中文提交标题改为英文分类加提交链接，不伪造翻译。需要具体英文说明时，可在提交正文使用 `Release-Note-EN`，或在打 tag 前提供 `.github/release-notes/<tag>.en.md`，无需中文文件。

Actions 中可手动运行 **Release encrypted binaries**：输入已有 tag，关闭 **publish** 可先做完整构建演练；开启则进行发布或失败重试。草稿可以续传，已经公开的版本只验证、不覆盖。工作流检查自动运行，依赖 Action 固定 SHA，Dependabot 每月提交更新 PR。

历史 Release、下载附件和 Git tags 均保留，不再发布一个版本就删除旧版本。GitHub Release 没有总容量或下载带宽限制，每个 Release 最多 1,000 个附件，每个附件小于 2 GiB；这些附件不写入 Git 仓库，也不使用 Git LFS。

公开仓库使用标准 GitHub runner 属于免费 Actions 使用范围。本工作流不主动删除中间构建产物，改为遵循仓库配置的保留期（默认 90 天，公开仓库可设 1–90 天），便于失败重试。Actions 产物到期不影响 Release 下载。大规格 runner、私有仓库存储和 Git LFS 另有计费规则，本工作流没有使用大规格 runner 或 LFS 保存发布文件。重跑时兼容旧版含独立 EXE 的 schema-1 清单及历史中英说明，不修改已经公开的附件；仅替换当前未发布草稿中的错误或多余附件。产物到期后需要重新编译时，重跑全部任务或新建手动运行。

GitHub 的五平台运行与公开上传需要在推送到新仓库后完成首轮验证。本地已验证脚本、工作流语法、隔离失败路径和真实 Windows 加密包，没有把本地检查当作 Linux/macOS 网关的实机验收。
