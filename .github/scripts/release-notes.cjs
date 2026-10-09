#!/usr/bin/env node
'use strict';

/**
 * Generate reproducible release notes from the checked-out tag, using only Git
 * and Node.js. Optional, committed .github/release-notes/<tag>.zh-CN.md and
 * <tag>.en.md files replace the generated language bodies and must form a pair.
 * Commit bodies are private by default: only paired Release-Note-ZH/EN lines
 * are published, never the rest of a commit body.
 *
 * CLI: RELEASE_TAG and GITHUB_REPOSITORY are required. RELEASE_COMMIT is an
 * optional full commit SHA assertion. RELEASE_OUTPUT_DIR is required. The
 * repository is resolved relative to this script, independent of shell cwd.
 */
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const MAX_COMMIT_ENTRIES = 100;
const GIT_MAX_BUFFER = 64 * 1024 * 1024;
const SEMVER_TAG = /^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/;
const COMMIT_SHA = /^(?:[0-9a-f]{40}|[0-9a-f]{64})$/;
const CATEGORIES = [
    ['feat', '新增功能', 'Features'],
    ['fix', '问题修复', 'Fixes'],
    ['perf', '性能改进', 'Performance'],
    ['refactor', '代码重构', 'Refactoring'],
    ['docs', '文档', 'Documentation'],
    ['build', '构建与依赖', 'Build and dependencies'],
    ['ci', '发布自动化', 'Release automation'],
    ['test', '测试', 'Tests'],
    ['chore', '维护', 'Maintenance'],
    ['revert', '回退', 'Reverts'],
    ['other', '其他变更', 'Other changes'],
];
const SUBSYSTEMS = [
    ['gateway', 'AI 网关与浏览器桥接', 'AI gateway and browser bridge'],
    ['application', '应用与接口', 'Applications and endpoints'],
    ['console', '管理界面与静态资源', 'Management UI and static assets'],
    ['runtime', '运行与进程管理', 'Runtime and process management'],
    ['configuration', '配置', 'Configuration'],
    ['dependencies', '依赖', 'Dependencies'],
    ['documentation', '文档与开发指引', 'Documentation and development guidance'],
    ['release', '构建与发布自动化', 'Build and release automation'],
    ['tests', '测试与辅助工具', 'Tests and supporting tools'],
    ['other', '其他文件', 'Other files'],
];

/**
 * Parse a v-prefixed, strict SemVer tag.
 * @param {string} tag Release ref name.
 * @returns {object|null} Parsed BigInt core/identifier arrays, or null for non-release tags.
 */
function parseVersion(tag) {
    if (typeof tag !== 'string' || tag.length > 200) return null;
    const match = SEMVER_TAG.exec(tag);
    if (!match) return null;
    const prerelease = match[4] ? match[4].split('.') : [];
    if (prerelease.some((part) => /^\d+$/.test(part) && part.length > 1 && part[0] === '0')) return null;
    return {
        tag,
        core: match.slice(1, 4).map((part) => BigInt(part)),
        prerelease,
        build: match[5] || '',
    };
}

/**
 * Compare SemVer precedence without losing precision or ordering build metadata.
 * @param {object} left Parsed version from parseVersion.
 * @param {object} right Parsed version from parseVersion.
 * @returns {number} -1, 0 or 1.
 */
function compareVersions(left, right) {
    for (let index = 0; index < 3; index += 1) {
        if (left.core[index] !== right.core[index]) return left.core[index] < right.core[index] ? -1 : 1;
    }
    if (!left.prerelease.length || !right.prerelease.length) {
        return left.prerelease.length === right.prerelease.length ? 0 : left.prerelease.length ? -1 : 1;
    }
    for (let index = 0; index < Math.max(left.prerelease.length, right.prerelease.length); index += 1) {
        const a = left.prerelease[index];
        const b = right.prerelease[index];
        if (a === undefined || b === undefined) return a === b ? 0 : a === undefined ? -1 : 1;
        if (a === b) continue;
        const aNumeric = /^\d+$/.test(a);
        const bNumeric = /^\d+$/.test(b);
        if (aNumeric && bNumeric) return BigInt(a) < BigInt(b) ? -1 : 1;
        if (aNumeric !== bNumeric) return aNumeric ? -1 : 1;
        return a < b ? -1 : 1;
    }
    return 0;
}

/**
 * Validate the owner/repository used in public GitHub links.
 * @param {string} repository GitHub owner/repository, without a URL prefix.
 * @returns {string} The validated input.
 * @throws {Error} Invalid names, URL/shell syntax or control characters.
 */
function validateRepository(repository) {
    if (typeof repository !== 'string') throw new Error('GITHUB_REPOSITORY must be owner/repository.');
    const pieces = repository.split('/');
    if (pieces.length !== 2
        || !/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/.test(pieces[0])
        || !/^[A-Za-z0-9_.-]{1,100}$/.test(pieces[1])
        || ['.', '..'].includes(pieces[1])) {
        throw new Error('GITHUB_REPOSITORY must be a valid owner/repository, without URL or control characters.');
    }
    return repository;
}

/** Execute Git with argument arrays and no shell; errors never include commit bodies. */
function git(repoRoot, args, allowedStatuses = [0]) {
    const result = spawnSync('git', ['--no-pager', '-c', 'core.quotepath=false', ...args], {
        cwd: repoRoot,
        env: { ...process.env, GIT_OPTIONAL_LOCKS: '0', GIT_TERMINAL_PROMPT: '0' },
        encoding: 'utf8',
        shell: false,
        maxBuffer: GIT_MAX_BUFFER,
        timeout: 60000,
        windowsHide: true,
    });
    if (result.error || !allowedStatuses.includes(result.status)) {
        const reason = result.error ? ` (${result.error.code || 'process error'})` : '';
        throw new Error(`Git ${args[0]} failed${reason}; verify the tag, checkout and complete Git history.`);
    }
    return { stdout: result.stdout, status: result.status };
}

/** Resolve annotated/lightweight tags; optional candidates pointing to blobs or trees are ineligible. */
function resolveTag(repoRoot, tag, required = true) {
    const result = git(repoRoot, ['rev-parse', '--verify', '--quiet', `refs/tags/${tag}^{commit}`], required ? [0] : [0, 1, 128]);
    if (result.status !== 0) return null;
    const sha = result.stdout.trim();
    if (!COMMIT_SHA.test(sha)) {
        if (!required) return null;
        throw new Error('The release tag did not resolve to a full commit SHA.');
    }
    return sha;
}

/**
 * Select the greatest lower SemVer ancestor. Stable releases ignore all
 * prereleases; prereleases may also use earlier prereleases of the same X.Y.Z.
 * Stage tags, unrelated branches and equal-precedence build variants are ignored.
 * @param {string} repoRoot Absolute Git repository root.
 * @param {object} current Parsed release version from parseVersion.
 * @param {string} commit Full SHA of the current release commit.
 * @returns {{tag: string, commit: string}|null} Eligible baseline or null for a first release.
 * @throws {Error} Git cannot read tags/history.
 */
function findPreviousRelease(repoRoot, current, commit) {
    const candidates = git(repoRoot, ['tag', '--list']).stdout.split(/\r?\n/)
        .map(parseVersion)
        .filter((version) => version && compareVersions(version, current) < 0)
        .filter((version) => !version.prerelease.length
            || (current.prerelease.length && version.core.every((part, index) => part === current.core[index])))
        .sort((a, b) => compareVersions(b, a) || (a.tag < b.tag ? -1 : a.tag > b.tag ? 1 : 0));
    for (const candidate of candidates) {
        const previousCommit = resolveTag(repoRoot, candidate.tag, false);
        if (previousCommit && git(repoRoot, ['merge-base', '--is-ancestor', previousCommit, commit], [0, 1]).status === 0) {
            return { tag: candidate.tag, commit: previousCommit };
        }
    }
    return null;
}

/**
 * Classify a Conventional Commit, preserving ordinary subjects as other changes.
 * @param {string} subject Original commit subject.
 * @param {string} [body] Commit body, used only for a breaking-change marker.
 * @returns {{category: string, breaking: boolean}} Classification without modifying the subject.
 */
function classifyCommit(subject, body = '') {
    const match = /^([a-z]+)(?:\([^\r\n)]+\))?(!)?:\s+/.exec(subject);
    const category = match && CATEGORIES.some(([key]) => key === match[1]) ? match[1] : 'other';
    return { category, breaking: Boolean(match && match[2]) || /^BREAKING[ -]CHANGE:\s*\S/m.test(body) };
}

/**
 * Locate an affected subsystem from a Git path; do not publish individual paths.
 * @param {string} filePath Repository-relative, slash-separated Git path.
 * @returns {string} Key from SUBSYSTEMS.
 */
function classifyPath(filePath) {
    if (/^(?:apps\/gateway\/|support\/browser\/)/.test(filePath)) return 'gateway';
    if (/^(?:apps\/|support\/middleware\/)/.test(filePath)) return 'application';
    if (/^(?:public\/|view\/)/.test(filePath)) return 'console';
    if (/^(?:index\.php$|windows\.(?:bat|php)$|support\/(?:process|queue|exception|service)\/)/.test(filePath)) return 'runtime';
    if (/^(?:config\/|\.env(?:\.example)?$)/.test(filePath)) return 'configuration';
    if (/^(?:composer\.(?:json|lock)$|vendor\/)/.test(filePath)) return 'dependencies';
    if (/^(?:\.github\/|scripts\/|build\/)/.test(filePath)) return 'release';
    if (/^(?:data\/(?:tests|scripts)\/|tests?\/|tools\/)/.test(filePath)) return 'tests';
    if (/^(?:\.agents\/|README(?:\.[^/]+)?$|AGENTS\.md$|CLAUDE\.md$|GEMINI\.md$)/.test(filePath)
        || /\.(?:md|markdown|rst)$/i.test(filePath)) return 'documentation';
    return 'other';
}

/**
 * Escape untrusted subject/footer text while retaining its readable original wording.
 * @param {string} value Commit title or explicitly public release-note field.
 * @returns {string} One safe, readable Markdown text line.
 */
function escapeMarkdown(value) {
    return String(value).replace(/[\u0000-\u001f\u007f]/g, ' ').replace(/\s+/g, ' ').trim()
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/[\\`*_[\]{}()#+.!|~=-]/g, '\\$&');
}

/**
 * Only publish explicit paired, nonempty bilingual note lines from a commit body.
 * @param {string} body Original commit body, never included wholesale in output.
 * @returns {{zh: string, en: string}|null} Author-provided pair, or null when absent/ambiguous.
 */
function bilingualNote(body) {
    const notes = { zh: [], en: [] };
    for (const line of body.split(/\r?\n/)) {
        const match = /^Release-Note-(ZH|EN):\s*(.*)$/i.exec(line);
        if (match && match[2].trim()) notes[match[1].toLowerCase()].push(match[2].trim());
    }
    if (notes.zh.length === 1 && notes.en.length === 1) return { zh: notes.zh[0], en: notes.en[0] };
    return null;
}

/** Decode NUL-separated Git records; valid commit messages cannot contain NUL bytes. */
function parseCommitLog(log, width) {
    const parts = log.split('\0');
    if (parts.at(-1) === '') parts.pop();
    if (parts.length % width !== 0) throw new Error('Unexpected Git commit log format.');
    const records = [];
    for (let index = 0; index < parts.length; index += width) records.push(parts.slice(index, index + width));
    return records;
}

/** Read paired manual overrides from the tagged tree, never from uncommitted files. */
function readOverrides(repoRoot, tag, commit) {
    const files = [`${commit}:.github/release-notes/${tag}.zh-CN.md`, `${commit}:.github/release-notes/${tag}.en.md`];
    const present = files.map((file) => git(repoRoot, ['cat-file', '-e', file], [0, 1, 128]).status === 0);
    if (present[0] !== present[1]) throw new Error('Manual release notes must include both .zh-CN.md and .en.md files.');
    if (!present[0]) return null;
    const contents = files.map((file) => git(repoRoot, ['show', file]).stdout.replace(/^\uFEFF/, '').replace(/\r\n/g, '\n').trim());
    if (contents.some((content) => !content || content.includes('\0'))) throw new Error('Both manual release note files must contain nonempty Markdown.');
    return { zh: contents[0], en: contents[1] };
}

/** Render one language using factual path/count summaries and original commit titles. */
function renderLanguage(language, model) {
    const zh = language === 'zh';
    const { tag, previous, commit, commitCount, commits, categoryCounts, subsystemCounts, changedFileCount, breakingCount, baseUrl, changelogUrl } = model;
    const releaseUrl = `${baseUrl}/releases/tag/${encodeURIComponent(tag)}`;
    const lines = [`# RCMAKER ${tag}`, ''];
    if (previous) {
        lines.push(zh ? `本次发布基于上一个可用版本 ${previous.tag}，包含 ${commitCount} 条提交，涉及 ${changedFileCount} 个变更文件。`
            : `This release follows ${previous.tag}, with ${commitCount} commits affecting ${changedFileCount} changed files.`);
    } else {
        lines.push(zh ? '这是当前仓库历史的首个适用版本发布；没有找到更低版本且属于当前提交祖先的有效版本标签。'
            : 'This is the first applicable release in this repository history. No lower eligible version tag is an ancestor of this commit.');
        lines.push('', zh ? `本次包含 ${commitCount} 条提交，当前版本共有 ${changedFileCount} 个受版本控制的文件。`
            : `This release contains ${commitCount} commits and ${changedFileCount} tracked files.`);
    }
    if (parseVersion(tag).prerelease.length) {
        lines.push('', zh ? '**预发布版本：** 建议先在测试环境验证后再用于正式环境。'
            : '**Prerelease:** validate this version in a test environment before production use.');
    }
    lines.push('', zh ? '### 下载与升级' : '### Downloads and upgrades', '',
        zh ? '本次发布提供内置 PHP 8.5、启用源码加密的独立可执行文件及归档包，覆盖以下五个目标：'
            : 'This release provides standalone executables and archives with bundled PHP 8.5 and encrypted application PHP source for these five targets:', '',
        '- Linux x86_64', '- Linux AArch64', '- macOS x86_64', '- macOS AArch64', '- Windows x86_64', '',
        zh ? `请从[本版本附件](${releaseUrl})下载与操作系统、CPU 架构匹配的文件，并使用附件中的 SHA256SUMS 校验下载完整性；release-manifest.json 记录本次发布的版本与构建信息。`
            : `Download the file matching your operating system and CPU architecture from the [release assets](${releaseUrl}). Verify its integrity against SHA256SUMS; release-manifest.json records the release version and build information.`, '',
        zh ? '升级前先停止运行中的程序并备份；替换程序文件时保留程序旁的外置 `data/` 目录和 `.env` 配置，随后重新启动。持久数据不包含在可执行文件中，不能用新版本附件覆盖或清空。'
            : 'Stop the running application and make a backup before upgrading. Replace the application files while preserving the external `data/` directory and `.env` configuration beside the executable, then restart. Persistent data is external to the executable and must survive the upgrade.', '',
        zh ? '### 变更摘要' : '### Change summary', '');
    const categories = CATEGORIES.filter(([key]) => categoryCounts[key] > 0);
    lines.push(zh ? `按提交类型统计：${categories.map(([key, label]) => `${label} ${categoryCounts[key]} 条`).join('；') || '无新增提交'}。`
        : `Commit categories: ${categories.map(([key, , label]) => `${label}: ${categoryCounts[key]}`).join('; ') || 'no additional commits'}.`);
    const subsystems = SUBSYSTEMS.filter(([key]) => subsystemCounts[key] > 0);
    lines.push('', zh ? `按变更路径统计：${subsystems.map(([key, label]) => `${label} ${subsystemCounts[key]} 个文件`).join('；') || '无文件变更'}。`
        : `Changed paths by subsystem: ${subsystems.map(([key, , label]) => `${label}: ${subsystemCounts[key]} files`).join('; ') || 'no file changes'}.`);
    if (breakingCount) {
        lines.push('', zh ? `其中 ${breakingCount} 条提交明确标记了不兼容变更；升级前请查看对应提交。`
            : `${breakingCount} commits explicitly mark breaking changes; review the linked commits before upgrading.`);
    }
    lines.push('', zh ? '### 提交详情' : '### Commit details', '',
        zh ? '以下条目保留作者的原始提交标题，不视为自动翻译。有成对的 Release-Note-ZH / Release-Note-EN 字段时，显示作者提供的中英说明。'
            : 'Entries retain the original commit titles and are not presented as translations. Paired Release-Note-ZH / Release-Note-EN fields provide author-written Chinese and English descriptions.', '');
    for (const [category, zhLabel, enLabel] of CATEGORIES) {
        const entries = commits.filter((entry) => entry.category === category);
        if (!entries.length) continue;
        lines.push(`#### ${zh ? zhLabel : enLabel}`, '');
        for (const entry of entries) {
            const link = `[${entry.sha.slice(0, 7)}](${baseUrl}/commit/${entry.sha})`;
            if (entry.note) {
                lines.push(`- ${escapeMarkdown(entry.note[language])} (${link})`,
                    `  ${zh ? '原始提交：' : 'Original commit: '}${escapeMarkdown(entry.subject)}`);
            } else {
                lines.push(`- ${zh ? '原始提交：' : 'Original commit: '}${escapeMarkdown(entry.subject)} (${link})`);
            }
        }
        lines.push('');
    }
    if (!commits.length) lines.push(zh ? '此版本与上一个适用版本之间没有新增提交。' : 'There are no additional commits since the previous eligible release.', '');
    if (commitCount > MAX_COMMIT_ENTRIES) {
        lines.push(zh ? `此处列出最新 ${commits.length} 条提交，共有 ${commitCount} 条；完整列表请查看下方变更记录。`
            : `The latest ${commits.length} of ${commitCount} commits are listed here. Use the complete changelog below for all commits.`, '');
    }
    lines.push(zh ? `[完整变更记录](${changelogUrl}) · [发布提交](${baseUrl}/commit/${commit})`
        : `[Complete changelog](${changelogUrl}) · [Release commit](${baseUrl}/commit/${commit})`);
    return `${lines.join('\n').trim()}\n`;
}

/**
 * Generate notes for an exact, fully fetched tag checkout.
 * @param {{repoRoot: string, tag: string, repository: string, commit?: string, outputDir?: string}} options
 * @returns {{metadata: object, files: Object<string, string>}} Deterministic UTF-8 contents; writes only when outputDir is supplied.
 * @throws {Error} Invalid input, mismatched HEAD/tag/SHA, shallow history or incomplete overrides.
 */
function generateReleaseNotes(options) {
    const { tag, repository, outputDir } = options;
    const current = parseVersion(tag);
    if (!current) throw new Error('RELEASE_TAG must be a strict v-prefixed SemVer tag, such as v1.2.3 or v1.2.3-beta.1.');
    validateRepository(repository);
    if (options.commit !== undefined && !COMMIT_SHA.test(options.commit)) throw new Error('RELEASE_COMMIT must be a full lowercase hexadecimal commit SHA.');
    const repoRoot = fs.realpathSync(path.resolve(options.repoRoot));
    const topLevel = fs.realpathSync(git(repoRoot, ['rev-parse', '--show-toplevel']).stdout.trim());
    const comparablePath = (value) => process.platform === 'win32' ? value.toLowerCase() : value;
    if (comparablePath(repoRoot) !== comparablePath(topLevel)) throw new Error('repoRoot must be the Git repository root.');
    if (git(repoRoot, ['rev-parse', '--is-shallow-repository']).stdout.trim() !== 'false') {
        throw new Error('Complete Git history and tags are required; use a non-shallow checkout.');
    }
    const commit = resolveTag(repoRoot, tag);
    const head = git(repoRoot, ['rev-parse', '--verify', 'HEAD^{commit}']).stdout.trim();
    if (head !== commit || (options.commit !== undefined && options.commit !== commit)) {
        throw new Error('HEAD, release tag and RELEASE_COMMIT must identify the same commit.');
    }
    const previous = findPreviousRelease(repoRoot, current, commit);
    const range = previous ? `${previous.commit}..${commit}` : commit;
    const categoryCounts = Object.fromEntries(CATEGORIES.map(([key]) => [key, 0]));
    const summaries = parseCommitLog(git(repoRoot, ['log', '-z', '--format=%s%x00%b', range, '--']).stdout, 2);
    let breakingCount = 0;
    for (const [subject, body] of summaries) {
        const classification = classifyCommit(subject, body);
        categoryCounts[classification.category] += 1;
        if (classification.breaking) breakingCount += 1;
    }
    const commits = parseCommitLog(git(repoRoot, ['log', '--topo-order', `--max-count=${MAX_COMMIT_ENTRIES}`, '-z', '--format=%H%x00%s%x00%b', range, '--']).stdout, 3)
        .map(([sha, subject, body]) => ({ sha, subject, ...classifyCommit(subject, body), note: bilingualNote(body) }));
    const pathArgs = previous ? ['diff', '--no-renames', '--name-only', '-z', previous.commit, commit, '--']
        : ['ls-tree', '-r', '--name-only', '-z', commit];
    const changedPaths = [...new Set(git(repoRoot, pathArgs).stdout.split('\0').filter(Boolean))];
    const subsystemCounts = Object.fromEntries(SUBSYSTEMS.map(([key]) => [key, 0]));
    for (const filePath of changedPaths) subsystemCounts[classifyPath(filePath)] += 1;
    const baseUrl = `https://github.com/${repository}`;
    const changelogUrl = previous ? `${baseUrl}/compare/${encodeURIComponent(previous.tag)}...${encodeURIComponent(tag)}` : `${baseUrl}/commits/${commit}`;
    const overrides = readOverrides(repoRoot, tag, commit);
    const model = { tag, previous, commit, commitCount: summaries.length, commits, categoryCounts, subsystemCounts,
        changedFileCount: changedPaths.length, breakingCount, baseUrl, changelogUrl };
    const zh = overrides ? `${overrides.zh}\n` : renderLanguage('zh', model);
    const en = overrides ? `${overrides.en}\n` : renderLanguage('en', model);
    const metadata = {
        tag,
        commit,
        sha: commit,
        previous_tag: previous ? previous.tag : null,
        prerelease: current.prerelease.length > 0,
        commit_count: summaries.length,
        listed_commit_count: commits.length,
        changed_file_count: changedPaths.length,
        breaking_change_count: breakingCount,
        category_counts: categoryCounts,
        subsystem_counts: subsystemCounts,
        notes_source: overrides ? 'committed-bilingual-overrides' : 'git-summary-and-original-commit-titles',
        changelog_url: changelogUrl,
    };
    const files = {
        'release-notes.md': `## 简体中文\n\n${zh}\n---\n\n## English\n\n${en}`,
        'release-notes.zh-CN.md': zh,
        'release-notes.en.md': en,
        'release-metadata.json': `${JSON.stringify(metadata, null, 2)}\n`,
    };
    if (outputDir !== undefined) {
        if (typeof outputDir !== 'string' || !outputDir.trim()) throw new Error('RELEASE_OUTPUT_DIR must name an output directory.');
        const resolvedOutput = path.resolve(outputDir);
        fs.mkdirSync(resolvedOutput, { recursive: true });
        for (const [name, content] of Object.entries(files)) fs.writeFileSync(path.join(resolvedOutput, name), content, 'utf8');
    }
    return { metadata, files };
}

/**
 * Execute the CI entry point and append only validated, single-line GitHub outputs.
 * @param {object} [env] Environment with RELEASE_TAG/GITHUB_REPOSITORY/RELEASE_OUTPUT_DIR.
 * @returns {object} Generated metadata and file contents.
 * @throws {Error} Invalid checkout/input or unreadable/unwritable files.
 */
function main(env = process.env) {
    if (!env.RELEASE_OUTPUT_DIR || !env.RELEASE_OUTPUT_DIR.trim()) throw new Error('RELEASE_OUTPUT_DIR is required.');
    const result = generateReleaseNotes({
        repoRoot: path.resolve(__dirname, '../..'),
        tag: env.RELEASE_TAG,
        repository: env.GITHUB_REPOSITORY,
        ...(env.RELEASE_COMMIT !== undefined ? { commit: env.RELEASE_COMMIT } : {}),
        outputDir: env.RELEASE_OUTPUT_DIR,
    });
    if (env.GITHUB_OUTPUT) {
        fs.appendFileSync(env.GITHUB_OUTPUT, `tag=${result.metadata.tag}\ncommit=${result.metadata.commit}\nprerelease=${result.metadata.prerelease}\n`, 'utf8');
    }
    process.stdout.write(`Release notes generated for ${result.metadata.tag} (${result.metadata.commit}).\n`);
    return result;
}

module.exports = { MAX_COMMIT_ENTRIES, parseVersion, compareVersions, validateRepository, classifyCommit, classifyPath,
    escapeMarkdown, bilingualNote, findPreviousRelease, generateReleaseNotes, main };

if (require.main === module) {
    try {
        main();
    } catch (error) {
        process.stderr.write(`Release notes failed: ${error.message}\n`);
        process.exitCode = 1;
    }
}
