#!/usr/bin/env node
'use strict';

/**
 * Isolated regression fixtures for the release-note generator.
 * Run: node .github/tests/release-notes.cjs
 * All Git mutations and generated notes are confined to a fresh OS temp tree;
 * the real repository is used only to load/copy the production script.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const scriptPath = path.resolve(__dirname, '../scripts/release-notes.cjs');
const notes = require(scriptPath);
const testRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'rcmaker-release-notes-'));
const repository = 'different-owner/new-repository';
let fixtureCount = 0;
let passed = 0;

/** Return an isolated Git environment without inherited repository redirection. */
function fixtureEnv(extra = {}) {
    const env = { ...process.env, GIT_CONFIG_NOSYSTEM: '1', GIT_CONFIG_GLOBAL: path.join(testRoot, 'no-global-config'),
        GIT_AUTHOR_DATE: '2026-01-01T00:00:00+00:00', GIT_COMMITTER_DATE: '2026-01-01T00:00:00+00:00', ...extra };
    for (const key of ['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_COMMON_DIR']) delete env[key];
    return env;
}

/** Run a fixture-only Git command, with no shell or interactive signing/hooks. */
function git(repoRoot, args) {
    assert.ok(path.relative(testRoot, repoRoot) && !path.relative(testRoot, repoRoot).startsWith('..'), 'Git mutations must remain inside the temp fixture root.');
    const result = spawnSync('git', ['-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false',
        '-c', `core.hooksPath=${path.join(testRoot, 'no-hooks')}`, ...args], {
        cwd: repoRoot, env: fixtureEnv(), encoding: 'utf8', shell: false, windowsHide: true,
    });
    assert.equal(result.status, 0, `Fixture git ${args[0]} failed: ${result.error ? result.error.code : result.stderr}`);
    return result.stdout.trim();
}

/** Create a completely new repository on an arbitrary branch, without real-repo state. */
function fixture() {
    fixtureCount += 1;
    const repoRoot = path.join(testRoot, `repository-${fixtureCount}`);
    fs.mkdirSync(repoRoot);
    git(repoRoot, ['init']);
    git(repoRoot, ['checkout', '-b', 'fresh-unrelated-branch']);
    git(repoRoot, ['config', 'user.name', 'Release Fixture']);
    git(repoRoot, ['config', 'user.email', 'release-fixture@example.invalid']);
    return repoRoot;
}

/** Commit fixture files or an empty change; return the fixture's full HEAD SHA. */
function commit(repoRoot, subject, files = {}, body = '') {
    for (const [name, content] of Object.entries(files)) {
        const destination = path.join(repoRoot, name);
        fs.mkdirSync(path.dirname(destination), { recursive: true });
        fs.writeFileSync(destination, content, 'utf8');
    }
    git(repoRoot, ['add', '--all']);
    git(repoRoot, ['commit', '--allow-empty', '-m', subject, ...(body ? ['-m', body] : [])]);
    return git(repoRoot, ['rev-parse', 'HEAD']);
}

/** Generate in-memory notes from a fixture and an independently chosen GitHub repo. */
function generate(repoRoot, tag, options = {}) {
    return notes.generateReleaseNotes({ repoRoot, tag, repository, ...options });
}

/** Run one assertion group with a readable progress line. */
function test(name, callback) {
    callback();
    passed += 1;
    process.stdout.write(`ok ${passed} - ${name}\n`);
}

try {
    test('strict SemVer validation and numeric precedence', () => {
        for (const tag of ['v0.0.0', 'v1.2.3', 'v1.2.3-beta.1', 'v1.2.3-rc.12+build.001']) assert.ok(notes.parseVersion(tag));
        for (const tag of ['1.2.3', 'v01.2.3', 'v1.2.3-beta.01', 'v1.2.3-', 'v1.2.3.', 'stage', 'v1.2.3\n', 'v1.2.3;touch marker']) assert.equal(notes.parseVersion(tag), null);
        const order = ['v1.0.0-alpha', 'v1.0.0-alpha.1', 'v1.0.0-alpha.beta', 'v1.0.0-beta', 'v1.0.0-beta.2', 'v1.0.0-beta.11', 'v1.0.0-rc.1', 'v1.0.0'];
        for (let index = 1; index < order.length; index += 1) assert.ok(notes.compareVersions(notes.parseVersion(order[index - 1]), notes.parseVersion(order[index])) < 0);
        assert.equal(notes.compareVersions(notes.parseVersion('v1.2.3+first'), notes.parseVersion('v1.2.3+second')), 0);
        assert.ok(notes.compareVersions(notes.parseVersion('v9007199254740992.0.0'), notes.parseVersion('v9007199254740993.0.0')) < 0);
    });

    test('fresh history produces English-only notes even with Chinese commit titles', () => {
        const repoRoot = fixture();
        const sha = commit(repoRoot, '初始提交：新的仓库', { 'apps/gateway/bridge.php': '<?php', 'config/app.php': '<?php' },
            'Release-Note-EN: Initialize the gateway and deployment configuration.');
        git(repoRoot, ['tag', '-a', 'v1.0.0', '-m', 'first annotated release']);
        const result = generate(repoRoot, 'v1.0.0', { commit: sha });
        assert.equal(result.metadata.previous_tag, null);
        assert.equal(result.metadata.commit, sha);
        assert.equal(result.metadata.sha, sha);
        assert.equal(result.metadata.prerelease, false);
        assert.equal(result.metadata.commit_count, 1);
        assert.equal(result.metadata.subsystem_counts.gateway, 1);
        assert.match(result.files['release-notes.en.md'], /first applicable release/);
        for (const name of ['release-notes.md', 'release-notes.en.md']) {
            const text = result.files[name];
            for (const expected of ['PHP 8.5', 'Linux x86_64', 'Linux AArch64', 'macOS x86_64', 'macOS AArch64', 'Windows x86_64 (ZIP only)', 'no standalone EXE is published', 'SHA256SUMS', 'release-manifest.json', '`data/`', '`.env`']) assert.ok(text.includes(expected));
            assert.ok(text.includes(`https://github.com/${repository}/releases/tag/v1.0.0`));
            assert.ok(text.includes(`https://github.com/${repository}/commits/${sha}`));
            assert.doesNotMatch(text, /\p{Script=Han}/u);
            assert.ok(text.includes('Initialize the gateway and deployment configuration\\.'));
            assert.ok(!text.includes('see commit for details'));
            assert.ok(text.includes(`/commit/${sha}`));
        }
        assert.equal(result.files['release-notes.zh-CN.md'], undefined);
        assert.equal(result.files['release-notes.md'], result.files['release-notes.en.md']);
    });

    test('later release classifies only its range and escapes untrusted original titles', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'initial source', { 'index.php': '<?php' });
        git(repoRoot, ['tag', 'v1.0.0']);
        commit(repoRoot, 'feat(gateway): English [link](https://evil.invalid) <img src=x> `code` $(); touch marker',
            { 'apps/gateway/bridge.php': '<?php changed' }, 'Private-Prompt: DO_NOT_LEAK_THE_PRIVATE_PROMPT');
        commit(repoRoot, 'fix(runtime)!: preserve upgrade data', { 'support/process/worker.php': '<?php worker' });
        commit(repoRoot, '普通中文提交', { 'public/app.js': 'console.log(1)' },
            'Release-Note-EN: Update the management interface.');
        git(repoRoot, ['tag', 'stage-gateway']);
        git(repoRoot, ['tag', 'v1.1.0']);
        const result = generate(repoRoot, 'v1.1.0');
        assert.equal(result.metadata.previous_tag, 'v1.0.0');
        assert.equal(result.metadata.commit_count, 3);
        assert.equal(result.metadata.category_counts.feat, 1);
        assert.equal(result.metadata.category_counts.fix, 1);
        assert.equal(result.metadata.category_counts.other, 1);
        assert.equal(result.metadata.breaking_change_count, 1);
        assert.equal(result.metadata.subsystem_counts.gateway, 1);
        assert.equal(result.metadata.subsystem_counts.runtime, 1);
        assert.equal(result.metadata.subsystem_counts.console, 1);
        assert.match(result.files['release-notes.en.md'], /Features: 1/);
        assert.ok(result.files['release-notes.en.md'].includes('\\[link\\]\\(https://evil\\.invalid\\)'));
        assert.ok(result.files['release-notes.en.md'].includes('&lt;img src\\=x&gt;'));
        assert.ok(result.files['release-notes.en.md'].includes('\\`code\\`'));
        assert.doesNotMatch(result.files['release-notes.en.md'], /\p{Script=Han}/u);
        assert.ok(!JSON.stringify(result.files).includes('DO_NOT_LEAK_THE_PRIVATE_PROMPT'));
        assert.ok(!fs.existsSync(path.join(repoRoot, 'marker')));
        assert.ok(result.metadata.changelog_url.endsWith('/compare/v1.0.0...v1.1.0'));
    });

    test('English commit notes do not need a Chinese pair and never leak private bodies', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'seed');
        git(repoRoot, ['tag', 'v2.0.0']);
        commit(repoRoot, 'fix: 中文原始标题', { 'config/app.php': '<?php' },
            'Secret: PRIVATE_BODY_MUST_NOT_APPEAR\nRelease-Note-ZH: 修复升级时的配置保留。\nRelease-Note-EN: Preserve configuration during upgrades.\nBREAKING CHANGE: review configuration');
        commit(repoRoot, 'docs: partial bilingual field', {}, 'Release-Note-ZH: UNPAIRED_MUST_NOT_APPEAR');
        commit(repoRoot, 'feat: 中文功能', {}, 'Release-Note-EN: Add English release notes.');
        git(repoRoot, ['tag', 'v2.0.1']);
        const result = generate(repoRoot, 'v2.0.1');
        assert.ok(result.files['release-notes.en.md'].includes('Preserve configuration during upgrades\\.'));
        assert.ok(result.files['release-notes.en.md'].includes('Add English release notes\\.'));
        for (const name of ['release-notes.md', 'release-notes.en.md']) {
            assert.doesNotMatch(result.files[name], /\p{Script=Han}/u);
            assert.ok(result.files[name].includes('docs: partial bilingual field'));
            assert.ok(!result.files[name].includes('PRIVATE_BODY_MUST_NOT_APPEAR'));
            assert.ok(!result.files[name].includes('UNPAIRED_MUST_NOT_APPEAR'));
        }
        assert.equal(result.metadata.breaking_change_count, 1);
        assert.throws(() => notes.englishNotes('Release-Note-EN: 中文'), /nonempty English change details/);
        assert.throws(() => notes.englishNotes('Release-Note-EN: '), /nonempty English change details/);
        assert.deepEqual(notes.englishNotes('Release-Note-EN: first\nRelease-Note-EN: second'), ['first', 'second']);
    });

    test('all English changes from a single commit appear directly and in order', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'seed');
        git(repoRoot, ['tag', 'v2.1.0']);
        const sha = commit(repoRoot, 'fix(console): 完整更新说明', { 'public/app.js': 'updated' },
            'Private-Prompt: KEEP_PRIVATE\nRelease-Note-EN: Restore the setup wizard.\n'
            + 'Release-Note-EN: Preserve tab state and unsaved input.\n'
            + 'Release-Note-EN: Keep copying and scrolling stable during polling.');
        git(repoRoot, ['tag', 'v2.1.1']);
        const text = generate(repoRoot, 'v2.1.1').files['release-notes.md'];
        assert.match(text, /#### Fixes/);
        const changes = ['Restore the setup wizard\\.', 'Preserve tab state and unsaved input\\.',
            'Keep copying and scrolling stable during polling\\.'];
        let previousIndex = -1;
        for (const change of changes) {
            const index = text.indexOf(`- ${change} ([${sha.slice(0, 7)}]`);
            assert.ok(index > previousIndex, `Visible change missing or reordered: ${change}`);
            previousIndex = index;
        }
        assert.ok(!text.includes('KEEP_PRIVATE'));
        assert.ok(!text.includes('see commit'));
        assert.doesNotMatch(text, /\p{Script=Han}/u);
    });

    test('missing English details cannot silently publish an empty category placeholder', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'seed');
        git(repoRoot, ['tag', 'v2.2.0']);
        const sha = commit(repoRoot, 'fix: 只有中文说明', {}, 'Private-Prompt: KEEP_PRIVATE');
        git(repoRoot, ['tag', 'v2.2.1']);
        const outputDir = path.join(repoRoot, 'not-created');
        assert.throws(() => generate(repoRoot, 'v2.2.1', { outputDir }),
            (error) => error.message.includes(sha.slice(0, 7)) && error.message.includes('Release-Note-EN')
                && !error.message.includes('KEEP_PRIVATE'));
        assert.ok(!fs.existsSync(outputDir));
        commit(repoRoot, 'docs: complete release notes', {
            '.github/release-notes/v2.2.2.en.md': '# Changes\n\n- Restore task continuity.\n',
        });
        git(repoRoot, ['tag', 'v2.2.2']);
        assert.match(generate(repoRoot, 'v2.2.2').files['release-notes.md'], /Restore task continuity/);
    });

    test('committed per-commit supplements repair old histories without rewriting commits', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'seed'); git(repoRoot, ['tag', 'v1.0.0']);
        const sha = commit(repoRoot, 'feat: 中文改动', {}, 'Private-Prompt: KEEP_PRIVATE');
        git(repoRoot, ['tag', 'v1.1.0']);
        assert.throws(() => notes.checkReleaseNotes(repoRoot), /no English change details/);
        const supplement = `.github/release-notes/commits/${sha}.en.json`;
        fs.mkdirSync(path.dirname(path.join(repoRoot, supplement)), { recursive: true });
        fs.writeFileSync(path.join(repoRoot, supplement), JSON.stringify(['Uncommitted text must not be used.']));
        assert.throws(() => generate(repoRoot, 'v1.1.0'), /no English change details/);
        // Replace only the fixture's failed tag so the same version includes the repair tree.
        commit(repoRoot, 'docs: supply reviewed historical release notes', {
            [supplement]: JSON.stringify(['Split the API processes.', 'Preserve task progress and exact tool receipts.']),
        });
        git(repoRoot, ['tag', '-f', 'v1.1.0']);
        const result = generate(repoRoot, 'v1.1.0');
        assert.match(result.files['release-notes.md'], /Split the API processes/);
        assert.match(result.files['release-notes.md'], /Preserve task progress/);
        assert.ok(!result.files['release-notes.md'].includes('KEEP_PRIVATE'));
        assert.equal(notes.checkReleaseNotes(repoRoot).commit_count, 2);
    });

    test('preflight aggregates missing English details and validates committed supplements', () => {
        const repoRoot = fixture(); commit(repoRoot, 'seed');git(repoRoot, ['tag', 'v1.0.0']);
        const a = commit(repoRoot, 'fix: 第一处'); const b = commit(repoRoot, 'fix: 第二处');
        assert.throws(() => notes.checkReleaseNotes(repoRoot), (e) => e.message.includes(a.slice(0,7)) && e.message.includes(b.slice(0,7)));
        commit(repoRoot, 'docs: invalid supplemental details', { [`.github/release-notes/commits/${a}.en.json`]: '[]' });
        git(repoRoot, ['tag', 'v1.1.0']);
        assert.throws(() => generate(repoRoot, 'v1.1.0'), /nonempty single-line English/);
        commit(repoRoot, 'docs: fix supplemental details', {
            [`.github/release-notes/commits/${a}.en.json`]: JSON.stringify(['Fix first behavior.']),
            [`.github/release-notes/commits/${b}.en.json`]: JSON.stringify(['Fix second behavior.']),
        });
        git(repoRoot, ['tag', '-f', 'v1.1.0']);
        assert.equal(notes.checkReleaseNotes(repoRoot).commit_count, 4);
        assert.match(generate(repoRoot, 'v1.1.0').files['release-notes.md'], /Fix second behavior/);
    });

    test('prereleases use earlier same-version prereleases and ignore another line', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'seed');
        git(repoRoot, ['tag', 'v1.0.0']);
        commit(repoRoot, 'feat: beta one');
        git(repoRoot, ['tag', 'v1.1.0-beta.1']);
        commit(repoRoot, 'feat: other version candidate');
        git(repoRoot, ['tag', 'v1.0.1-beta.99']);
        commit(repoRoot, 'fix: beta two');
        git(repoRoot, ['tag', 'v1.1.0-beta.2']);
        const result = generate(repoRoot, 'v1.1.0-beta.2');
        assert.equal(result.metadata.previous_tag, 'v1.1.0-beta.1');
        assert.equal(result.metadata.prerelease, true);
        assert.equal(result.metadata.commit_count, 2);
        assert.match(result.files['release-notes.en.md'], /Prerelease/);
    });

    test('stable releases exclude prerelease baselines even on their own line', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'seed');
        git(repoRoot, ['tag', 'v1.0.0']);
        commit(repoRoot, 'feat: beta');
        git(repoRoot, ['tag', 'v1.1.0-rc.1']);
        commit(repoRoot, 'fix: stable');
        git(repoRoot, ['tag', 'v1.1.0']);
        const result = generate(repoRoot, 'v1.1.0');
        assert.equal(result.metadata.previous_tag, 'v1.0.0');
        assert.equal(result.metadata.commit_count, 2);
    });

    test('other-branch, future and non-semver tags cannot become the baseline', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'seed');
        git(repoRoot, ['tag', 'v1.0.0']);
        git(repoRoot, ['checkout', '-b', 'unrelated-release-branch']);
        commit(repoRoot, 'feat: branch-only change');
        git(repoRoot, ['tag', 'v1.9.0']);
        git(repoRoot, ['checkout', 'fresh-unrelated-branch']);
        commit(repoRoot, 'fix: mainline change');
        git(repoRoot, ['tag', 'v2.0.0']);
        git(repoRoot, ['tag', 'v999.0.0']);
        git(repoRoot, ['tag', 'stage-999']);
        assert.equal(generate(repoRoot, 'v2.0.0').metadata.previous_tag, 'v1.0.0');
    });

    test('equal-precedence builds are ignored and baseline ties are deterministic', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'seed');
        git(repoRoot, ['tag', 'v1.0.0+z']);
        git(repoRoot, ['tag', 'v1.0.0+a']);
        commit(repoRoot, 'fix: next');
        git(repoRoot, ['tag', 'v1.1.0+z']);
        git(repoRoot, ['tag', 'v1.1.0+a']);
        const result = generate(repoRoot, 'v1.1.0+z');
        assert.equal(result.metadata.previous_tag, 'v1.0.0+a');
        assert.ok(result.metadata.changelog_url.includes('v1.0.0%2Ba...v1.1.0%2Bz'));
    });

    test('SemVer tags pointing to non-commit objects are ineligible baselines', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'seed', { 'index.php': '<?php' });
        git(repoRoot, ['tag', 'v1.0.0']);
        const blob = git(repoRoot, ['rev-parse', 'HEAD:index.php']);
        git(repoRoot, ['tag', 'v1.9.0', blob]);
        commit(repoRoot, 'fix: valid release');
        git(repoRoot, ['tag', 'v2.0.0']);
        assert.equal(generate(repoRoot, 'v2.0.0').metadata.previous_tag, 'v1.0.0');
        assert.throws(() => generate(repoRoot, 'v1.9.0'), /Git rev-parse failed/);
    });

    test('same-commit lower tags produce nonempty zero-change notes', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'seed');
        git(repoRoot, ['tag', 'v3.0.0']);
        git(repoRoot, ['tag', 'v3.0.1']);
        const result = generate(repoRoot, 'v3.0.1');
        assert.equal(result.metadata.commit_count, 0);
        assert.equal(result.metadata.changed_file_count, 0);
        assert.match(result.files['release-notes.en.md'], /no additional commits/i);
    });

    test('committed English override wins and a legacy Chinese override is ignored', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'docs: authored release notes', {
            '.github/release-notes/v4.0.0.zh-CN.md': '# 人工中文说明\r\n\r\n本次更新说明。\r\n',
            '.github/release-notes/v4.0.0.en.md': '# Authored English notes\n\nRelease details.\n',
        });
        git(repoRoot, ['tag', 'v4.0.0']);
        fs.writeFileSync(path.join(repoRoot, '.github/release-notes/v4.0.0.en.md'), 'UNCOMMITTED_MUST_NOT_APPEAR');
        const result = generate(repoRoot, 'v4.0.0');
        assert.equal(result.metadata.notes_source, 'committed-english-override');
        assert.equal(result.files['release-notes.zh-CN.md'], undefined);
        assert.equal(result.files['release-notes.en.md'], '# Authored English notes\n\nRelease details.\n');
        assert.ok(!result.files['release-notes.md'].includes('UNCOMMITTED_MUST_NOT_APPEAR'));
    });

    test('English-only overrides work; legacy Chinese-only files do not block releases', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'docs: incomplete overrides', { '.github/release-notes/v4.1.0.zh-CN.md': '只有中文' });
        git(repoRoot, ['tag', 'v4.1.0']);
        assert.match(generate(repoRoot, 'v4.1.0').files['release-notes.en.md'], /first applicable release/);
        commit(repoRoot, 'docs: English-only notes', { '.github/release-notes/v4.1.1.en.md': '# English only\n' });
        git(repoRoot, ['tag', 'v4.1.1']);
        assert.equal(generate(repoRoot, 'v4.1.1').files['release-notes.md'], '# English only\n');
    });

    test('empty or Chinese English overrides fail clearly', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'docs: empty English override', {
            '.github/release-notes/v4.1.1.zh-CN.md': '只有中文', '.github/release-notes/v4.1.1.en.md': ' \n\n',
        });
        git(repoRoot, ['tag', 'v4.1.1']);
        assert.throws(() => generate(repoRoot, 'v4.1.1'), /nonempty Markdown/);
        commit(repoRoot, 'docs: wrong-language English override', { '.github/release-notes/v4.1.2.en.md': '# 中文\n' });
        git(repoRoot, ['tag', 'v4.1.2']);
        assert.throws(() => generate(repoRoot, 'v4.1.2'), /must not contain Chinese/);
    });

    test('more than 100 commits are all visible without truncating changes', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'seed');
        git(repoRoot, ['tag', 'v5.0.0']);
        for (let index = 1; index <= 105; index += 1) commit(repoRoot, `fix: change ${index}`);
        git(repoRoot, ['tag', 'v5.0.1']);
        const result = generate(repoRoot, 'v5.0.1');
        assert.equal(result.metadata.commit_count, 105);
        assert.equal(result.metadata.listed_commit_count, 105);
        assert.equal(result.metadata.category_counts.fix, 105);
        assert.equal((result.files['release-notes.en.md'].match(/^- fix: change /gm) || []).length, 105);
        assert.ok(result.files['release-notes.en.md'].includes('- fix: change 1 ('));
        assert.ok(result.files['release-notes.en.md'].includes('- fix: change 105 ('));
        assert.ok(!result.files['release-notes.en.md'].includes('latest 100'));
        assert.ok(result.files['release-notes.en.md'].includes('/compare/v5.0.0...v5.0.1'));
    });

    test('input, tag SHA and checkout validation fails before writing files', () => {
        const repoRoot = fixture();
        const sha = commit(repoRoot, 'seed');
        git(repoRoot, ['tag', 'v6.0.0']);
        const outputDir = path.join(repoRoot, 'not-created');
        for (const invalid of ['https://github.com/owner/repo', 'owner/repo\n', 'owner/../repo', 'owner/repo;touch marker', '/repo', 'owner/..']) {
            assert.throws(() => generate(repoRoot, 'v6.0.0', { repository: invalid, outputDir }), /GITHUB_REPOSITORY/);
        }
        assert.throws(() => generate(repoRoot, 'v6.0.0;touch marker', { outputDir }), /RELEASE_TAG/);
        assert.throws(() => generate(repoRoot, 'v6.0.0', { commit: 'HEAD', outputDir }), /RELEASE_COMMIT/);
        assert.throws(() => generate(repoRoot, 'v6.0.0', { commit: 'a'.repeat(40), outputDir }), /same commit/);
        commit(repoRoot, 'fix: mismatch checkout');
        assert.throws(() => generate(repoRoot, 'v6.0.0', { commit: sha, outputDir }), /same commit/);
        assert.throws(() => generate(repoRoot, 'v6.0.1', { outputDir }), /Git rev-parse failed/);
        assert.ok(!fs.existsSync(outputDir));
        assert.ok(!fs.existsSync(path.join(repoRoot, 'marker')));
    });

    test('preflight honors a released version override without hiding missing notes in newer commits', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'feat: 旧版说明', {'.github/release-notes/v6.0.0.en.md': 'Complete reviewed English release details.'});
        git(repoRoot, ['tag', 'v6.0.0']);
        commit(repoRoot, 'fix: an English follow-up');
        assert.equal(notes.checkReleaseNotes(repoRoot).commit_count, 2);
        const missing = commit(repoRoot, 'fix: 新改动缺少说明');
        assert.throws(() => notes.checkReleaseNotes(repoRoot), new RegExp(missing.slice(0, 7)));
    });

    test('shallow history cannot silently produce an incorrect first release', () => {
        const repoRoot = fixture();
        const sha = commit(repoRoot, 'shallow fixture seed');
        git(repoRoot, ['tag', 'v6.1.0']);
        fs.writeFileSync(path.join(repoRoot, '.git/shallow'), `${sha}\n`, 'utf8');
        assert.throws(() => generate(repoRoot, 'v6.1.0'), /Complete Git history and tags are required/);
    });

    test('repeated generation produces byte-identical output files', () => {
        const repoRoot = fixture();
        commit(repoRoot, 'feat: deterministic release', { 'public/app.js': 'release' });
        git(repoRoot, ['tag', 'v7.0.0']);
        const firstDir = path.join(repoRoot, 'first-output');
        const secondDir = path.join(repoRoot, 'second-output');
        const first = generate(repoRoot, 'v7.0.0', { outputDir: firstDir });
        const second = generate(repoRoot, 'v7.0.0', { outputDir: secondDir });
        assert.deepEqual(first, second);
        for (const name of Object.keys(first.files)) {
            assert.deepEqual(fs.readFileSync(path.join(firstDir, name)), fs.readFileSync(path.join(secondDir, name)));
        }
    });

    test('CLI finds its own repository root and emits validated GitHub outputs', () => {
        const repoRoot = fixture();
        const sha = commit(repoRoot, 'feat: portable CLI');
        git(repoRoot, ['tag', 'v8.0.0-beta.1']);
        const copiedScript = path.join(repoRoot, '.github/scripts/release-notes.cjs');
        fs.mkdirSync(path.dirname(copiedScript), { recursive: true });
        fs.copyFileSync(scriptPath, copiedScript);
        const outputDir = path.join(testRoot, 'cli-output');
        const githubOutput = path.join(testRoot, 'github-output');
        const result = spawnSync(process.execPath, [copiedScript], {
            cwd: testRoot, shell: false, encoding: 'utf8', windowsHide: true,
            env: fixtureEnv({ RELEASE_TAG: 'v8.0.0-beta.1', GITHUB_REPOSITORY: repository,
                RELEASE_COMMIT: sha, RELEASE_OUTPUT_DIR: outputDir, GITHUB_OUTPUT: githubOutput }),
        });
        assert.equal(result.status, 0, result.stderr);
        assert.equal(fs.readFileSync(githubOutput, 'utf8'), `tag=v8.0.0-beta.1\ncommit=${sha}\nprerelease=true\n`);
        const metadata = JSON.parse(fs.readFileSync(path.join(outputDir, 'release-metadata.json'), 'utf8'));
        assert.equal(metadata.commit, sha);
        assert.equal(metadata.previous_tag, null);
        assert.match(result.stdout, /Release notes generated/);
    });

    process.stdout.write(`Passed ${passed} release-note fixture tests. No real Git repository was modified.\n`);
} finally {
    const resolved = path.resolve(testRoot);
    assert.equal(path.dirname(resolved), path.resolve(os.tmpdir()));
    assert.ok(path.basename(resolved).startsWith('rcmaker-release-notes-'));
    fs.rmSync(resolved, { recursive: true, force: true });
}
