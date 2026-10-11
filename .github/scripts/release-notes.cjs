#!/usr/bin/env node
'use strict';

/**
 * Generate reproducible release notes from the checked-out tag, using only Git
 * and Node.js. An optional committed .github/release-notes/<tag>.en.md file
 * replaces the generated English body. Chinese notes are not published.
 * Commit bodies are private by default: only explicit Release-Note-EN lines
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
    ['feat', 'Features'],
    ['fix', 'Fixes'],
    ['perf', 'Performance'],
    ['refactor', 'Refactoring'],
    ['docs', 'Documentation'],
    ['build', 'Build and dependencies'],
    ['ci', 'Release automation'],
    ['test', 'Tests'],
    ['chore', 'Maintenance'],
    ['revert', 'Reverts'],
    ['other', 'Other changes'],
];
const SUBSYSTEMS = [
    ['gateway', 'AI gateway and browser bridge'],
    ['application', 'Applications and endpoints'],
    ['console', 'Management UI and static assets'],
    ['runtime', 'Runtime and process management'],
    ['configuration', 'Configuration'],
    ['dependencies', 'Dependencies'],
    ['documentation', 'Documentation and development guidance'],
    ['release', 'Build and release automation'],
    ['tests', 'Tests and supporting tools'],
    ['other', 'Other files'],
];
const HAN_TEXT = /\p{Script=Han}/u;

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
 * Read a single explicit English note; ignore Chinese-only or ambiguous fields.
 * @param {string} body Original commit body, never included wholesale in output.
 * @returns {string|null} Author-provided English text, or null when absent/ambiguous.
 */
function englishNote(body) {
    const notes = [];
    for (const line of body.split(/\r?\n/)) {
        const match = /^Release-Note-EN:\s*(.*)$/i.exec(line);
        if (match && match[1].trim()) notes.push(match[1].trim());
    }
    return notes.length === 1 && !HAN_TEXT.test(notes[0]) ? notes[0] : null;
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

/** Read the English override from the tagged tree, never from uncommitted files. */
function readOverrides(repoRoot, tag, commit) {
    const file = `${commit}:.github/release-notes/${tag}.en.md`;
    if (git(repoRoot, ['cat-file', '-e', file], [0, 1, 128]).status !== 0) return null;
    const content = git(repoRoot, ['show', file]).stdout.replace(/^\uFEFF/, '').replace(/\r\n/g, '\n').trim();
    if (!content || content.includes('\0')) throw new Error('English release notes must contain nonempty Markdown.');
    if (HAN_TEXT.test(content)) throw new Error('English release notes must not contain Chinese text.');
    return content;
}

/** Render English summaries without inventing translations of Chinese commit titles. */
function renderEnglish(model) {
    const { tag, previous, commit, commitCount, commits, categoryCounts, subsystemCounts, changedFileCount, breakingCount, baseUrl, changelogUrl } = model;
    const releaseUrl = `${baseUrl}/releases/tag/${encodeURIComponent(tag)}`;
    const lines = [`# RCMAKER ${tag}`, ''];
    if (previous) {
        lines.push(`This release follows ${previous.tag}, with ${commitCount} commits affecting ${changedFileCount} changed files.`);
    } else {
        lines.push('This is the first applicable release in this repository history. No lower eligible version tag is an ancestor of this commit.');
        lines.push('', `This release contains ${commitCount} commits and ${changedFileCount} tracked files.`);
    }
    if (parseVersion(tag).prerelease.length) {
        lines.push('', '**Prerelease:** validate this version in a test environment before production use.');
    }
    lines.push('', '### Downloads and upgrades', '',
        'This release provides standalone executables and archives with bundled PHP 8.5 and encrypted application PHP source for these five targets:', '',
        '- Linux x86_64', '- Linux AArch64', '- macOS x86_64', '- macOS AArch64', '- Windows x86_64 (ZIP only)', '',
        `Download the file matching your operating system and CPU architecture from the [release assets](${releaseUrl}). Verify its integrity against SHA256SUMS; release-manifest.json records the release version and build information.`, '',
        'On Windows, download and extract the ZIP; no standalone EXE is published.', '',
        'Stop the running application and make a backup before upgrading. Replace the application files while preserving the external `data/` directory and `.env` configuration beside the executable, then restart. Persistent data is external to the executable and must survive the upgrade.', '',
        '### Change summary', '');
    const categories = CATEGORIES.filter(([key]) => categoryCounts[key] > 0);
    lines.push(`Commit categories: ${categories.map(([key, label]) => `${label}: ${categoryCounts[key]}`).join('; ') || 'no additional commits'}.`);
    const subsystems = SUBSYSTEMS.filter(([key]) => subsystemCounts[key] > 0);
    lines.push('', `Changed paths by subsystem: ${subsystems.map(([key, label]) => `${label}: ${subsystemCounts[key]} files`).join('; ') || 'no file changes'}.`);
    if (breakingCount) {
        lines.push('', `${breakingCount} commits explicitly mark breaking changes; review the linked commits before upgrading.`);
    }
    lines.push('', '### Commit details', '',
        'Author-written Release-Note-EN fields take priority. Chinese commit titles are represented by their change category and a commit link, without an invented translation.', '');
    for (const [category, label] of CATEGORIES) {
        const entries = commits.filter((entry) => entry.category === category);
        if (!entries.length) continue;
        lines.push(`#### ${label}`, '');
        for (const entry of entries) {
            const link = `[${entry.sha.slice(0, 7)}](${baseUrl}/commit/${entry.sha})`;
            if (entry.note) {
                lines.push(`- ${escapeMarkdown(entry.note)} (${link})`);
            } else if (HAN_TEXT.test(entry.subject)) {
                lines.push(`- ${label}: see commit for details (${link})`);
            } else {
                lines.push(`- Original commit: ${escapeMarkdown(entry.subject)} (${link})`);
            }
        }
        lines.push('');
    }
    if (!commits.length) lines.push('There are no additional commits since the previous eligible release.', '');
    if (commitCount > MAX_COMMIT_ENTRIES) {
        lines.push(`The latest ${commits.length} of ${commitCount} commits are listed here. Use the complete changelog below for all commits.`, '');
    }
    lines.push(`[Complete changelog](${changelogUrl}) · [Release commit](${baseUrl}/commit/${commit})`);
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
        .map(([sha, subject, body]) => ({ sha, subject, ...classifyCommit(subject, body), note: englishNote(body) }));
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
    const en = overrides ? `${overrides}\n` : renderEnglish(model);
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
        notes_source: overrides ? 'committed-english-override' : 'git-summary-and-english-commit-notes',
        changelog_url: changelogUrl,
    };
    const files = {
        'release-notes.md': en,
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
    escapeMarkdown, englishNote, findPreviousRelease, generateReleaseNotes, main };

if (require.main === module) {
    try {
        main();
    } catch (error) {
        process.stderr.write(`Release notes failed: ${error.message}\n`);
        process.exitCode = 1;
    }
}
