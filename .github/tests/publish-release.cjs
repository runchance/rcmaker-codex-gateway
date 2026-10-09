'use strict';

const assert = require('node:assert/strict');
const { test } = require('node:test');
const fsp = require('node:fs/promises');
const path = require('node:path');
const { createHash } = require('node:crypto');
const { run, configuration, TARGETS, targetNames } = require('../scripts/publish-release.cjs');

const TAG = 'v1.2.3';
const COMMIT = 'a'.repeat(40);
const ROOT = 'https://api.github.com/repos/example/gateway';

/** Return a deterministic SHA-256 proof for a fixture byte buffer. */
function hash(data) { return createHash('sha256').update(data).digest('hex'); }

/** Make an inert executable-format fixture; no fixture binary is ever executed. */
function binary(target, salt = 'first') {
    const data = Buffer.alloc(256);
    if (target.platform === 'linux') {
        Buffer.from([0x7f, 0x45, 0x4c, 0x46, 2, 1]).copy(data);
        data.writeUInt16LE(2, 16);
        data.writeUInt16LE(target.arch === 'x86_64' ? 62 : 183, 18);
    } else if (target.platform === 'macos') {
        Buffer.from([0xcf, 0xfa, 0xed, 0xfe]).copy(data);
        data.writeUInt32LE(target.arch === 'x86_64' ? 0x01000007 : 0x0100000c, 4);
        data.writeUInt32LE(2, 12);
    } else {
        data.write('MZ');
        data.writeUInt32LE(64, 60);
        data.write('PE\0\0', 64);
        data.writeUInt16LE(0x8664, 68);
        data.writeUInt16LE(0x20b, 88);
    }
    data.write(salt, 128);
    return data;
}

/** Create all five signed-manifest fixtures, optionally nesting CI artifact folders. */
async function fixture(t, { tag = TAG, salt = 'first', nested = true, prerelease = tag.includes('-') } = {}) {
    const fixtureBase = path.resolve(__dirname, '../../data/generated/release-publish-tests');
    await fsp.mkdir(fixtureBase, { recursive: true });
    const root = await fsp.mkdtemp(path.join(fixtureBase, 'fixture-'));
    t.after(async () => {
        const canonical = await fsp.realpath(root);
        assert.equal(path.dirname(canonical), await fsp.realpath(fixtureBase));
        assert.ok(path.basename(canonical).startsWith('fixture-'));
        await fsp.rm(canonical, { recursive: true, force: true });
    });
    const outputDir = path.join(root, 'builds');
    const notesDir = path.join(root, 'notes');
    await fsp.mkdir(outputDir);
    await fsp.mkdir(notesDir);
    const locations = new Map();
    for (const target of TARGETS) {
        const directory = nested ? path.join(outputDir, `${target.platform}-${target.arch}`) : outputDir;
        await fsp.mkdir(directory, { recursive: true });
        const names = targetNames(tag, target);
        const data = binary(target, salt);
        const archive = Buffer.from(`Archive fixture ${tag} ${target.platform} ${target.arch} ${salt}`);
        const manifest = {
            tag, commit: COMMIT, php: '8.5', encrypted: true, platform: target.platform, arch: target.arch,
            host_php: '8.5.1', format: target.format, payload: { files: 10, php_files: 8, signature: 'SHA-256' },
            archive_entries: ['rcmaker-codex-gateway' + (target.platform === 'windows' ? '.exe' : ''), 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES', '.env.example'],
            binary: { name: names.binary, bytes: data.length, sha256: hash(data) },
            archive: { name: names.archive, bytes: archive.length, sha256: hash(archive) },
        };
        for (const [name, contents] of [[names.binary, data], [names.archive, archive], [names.manifest, JSON.stringify(manifest)]]) {
            const file = path.join(directory, name);
            await fsp.writeFile(file, contents);
            locations.set(name, file);
        }
    }
    await fsp.writeFile(path.join(notesDir, 'release-metadata.json'), JSON.stringify({ tag, commit: COMMIT, previous_tag: 'v1.2.2', prerelease }));
    await fsp.writeFile(path.join(notesDir, 'release-notes.zh-CN.md'), '# 更新\n\n修复。\n');
    await fsp.writeFile(path.join(notesDir, 'release-notes.en.md'), '# Changes\n\nFixed.\n');
    const env = { RELEASE_TAG: tag, RELEASE_COMMIT: COMMIT, GITHUB_REPOSITORY: 'example/gateway', GITHUB_TOKEN: 'fixture-token-never-log', RELEASE_OUTPUT_DIR: outputDir, RELEASE_NOTES_DIR: notesDir };
    return { root, outputDir, notesDir, locations, env };
}

/** Minimal stateful REST service for draft, published, and ambiguous-response paths. */
class GitHubFixture {
    /** State flags model failure conditions without using the real network. */
    constructor({ tag = TAG, draft = null, digest = true, annotated = false } = {}) {
        this.tag = tag;
        this.release = draft === null ? null : this.releaseValue(draft);
        this.assets = [];
        this.bytes = new Map();
        this.calls = [];
        this.sequence = 10;
        this.digest = digest;
        this.annotated = annotated;
        this.remoteCommit = COMMIT;
        this.latestTag = null;
        this.failUpload = null;
        this.ambiguousUpload = false;
        this.ambiguousCreate = false;
        this.ambiguousPublish = false;
        this.failPublish = false;
        this.tagMovesBeforePublish = false;
        this.tagReads = 0;
        this.transientReads = 0;
        this.redirectDownloads = false;
        this.evilUpload = false;
        this.corruptUploadDigest = false;
    }

    /** Produce public API release metadata, intentionally omitting inline assets. */
    releaseValue(draft) {
        return { id: 1, tag_name: this.tag, draft, prerelease: this.tag.includes('-'), upload_url: `https://uploads.github.com/repos/example/gateway/releases/1/assets{?name,label}` };
    }

    /** Store one remote asset and its server digest. */
    addAsset(name, data, overrides = {}) {
        const asset = { id: this.sequence++, name, size: data.length, state: 'uploaded', ...(this.digest ? { digest: `sha256:${hash(data)}` } : {}), ...overrides };
        this.assets.push(asset);
        this.bytes.set(asset.id, Buffer.from(data));
        return asset;
    }

    /** Count network mutations of a specific method. */
    mutations(method) { return this.calls.filter(call => call.method === method); }

    /** Read upload streams exactly as the production fetch transport consumes them. */
    async payload(body) {
        const chunks = [];
        for await (const chunk of body) chunks.push(Buffer.from(chunk));
        return Buffer.concat(chunks);
    }

    /** Return JSON without sharing mutable fixture objects with the publisher. */
    response(value, status = 200) { return new Response(JSON.stringify(value), { status, headers: { 'content-type': 'application/json' } }); }

    /** Route the exact expected API requests and enforce credential boundaries. */
    async fetch(urlString, options = {}) {
        const url = new URL(urlString);
        const method = options.method || 'GET';
        const headers = new Headers(options.headers);
        this.calls.push({ url: urlString, method, headers, body: typeof options.body === 'string' ? JSON.parse(options.body) : undefined });
        if (url.origin === 'https://release-assets.githubusercontent.com') {
            assert.equal(headers.get('authorization'), null, 'Token must not follow signed asset redirects');
            return new Response(this.bytes.get(Number(url.pathname.slice(1))));
        }
        assert.equal(headers.get('authorization'), 'Bearer fixture-token-never-log');
        assert.equal(options.redirect, 'manual');
        if (method === 'GET' && this.transientReads > 0) {
            this.transientReads--;
            return this.response({ message: 'temporary' }, this.transientReads === 1 ? 429 : 503);
        }
        if (url.origin === 'https://uploads.github.com') {
            assert.equal(method, 'POST');
            const name = url.searchParams.get('name');
            const data = await this.payload(options.body);
            assert.equal(Number(headers.get('content-length')), data.length);
            if (this.failUpload === name || this.failUpload === '*') return this.response({ message: 'failed' }, 502);
            const asset = this.addAsset(name, data, this.corruptUploadDigest ? { digest: `sha256:${'0'.repeat(64)}` } : {});
            if (this.ambiguousUpload) { this.ambiguousUpload = false; throw new Error('lost upload response'); }
            return this.response(asset, 201);
        }
        assert.ok(urlString.startsWith(ROOT + '/'), `Unexpected API endpoint ${urlString}`);
        const endpoint = url.pathname.slice(new URL(ROOT).pathname.length);
        if (endpoint === `/git/ref/tags/${encodeURIComponent(this.tag)}`) {
            this.tagReads++;
            if (this.tagMovesBeforePublish && this.tagReads >= 2) this.remoteCommit = 'b'.repeat(40);
            return this.response({ object: this.annotated ? { type: 'tag', sha: 'c'.repeat(40) } : { type: 'commit', sha: this.remoteCommit } });
        }
        if (endpoint === `/git/tags/${'c'.repeat(40)}`) return this.response({ object: { type: 'commit', sha: this.remoteCommit } });
        if (endpoint === '/releases/latest') return this.latestTag ? this.response({ id: 2, tag_name: this.latestTag, draft: false, prerelease: false }) : this.response({}, 404);
        if (endpoint === `/releases/tags/${encodeURIComponent(this.tag)}`) return this.release && !this.release.draft ? this.response(this.release) : this.response({}, 404);
        if (endpoint === '/releases' && method === 'GET') return this.response(this.release ? [this.release] : []);
        if (endpoint === '/releases' && method === 'POST') {
            assert.equal(JSON.parse(options.body).draft, true, 'Release must be created as a draft');
            this.release = this.releaseValue(true);
            if (this.evilUpload) this.release.upload_url = 'https://attacker.example/repos/example/gateway/releases/1/assets{?name,label}';
            if (this.ambiguousCreate) { this.ambiguousCreate = false; throw new Error('lost create response'); }
            return this.response(this.release, 201);
        }
        if (endpoint === '/releases/1/assets' && method === 'GET') return this.response(this.assets);
        const assetMatch = /^\/releases\/assets\/(\d+)$/.exec(endpoint);
        if (assetMatch) {
            const id = Number(assetMatch[1]);
            if (method === 'DELETE') {
                assert.equal(this.release.draft, true, 'Published assets must never be deleted');
                this.assets = this.assets.filter(asset => asset.id !== id);
                this.bytes.delete(id);
                return new Response(null, { status: 204 });
            }
            assert.equal(headers.get('accept'), 'application/octet-stream');
            if (this.redirectDownloads) return new Response(null, { status: 302, headers: { location: `https://release-assets.githubusercontent.com/${id}` } });
            return new Response(this.bytes.get(id));
        }
        if (endpoint === '/releases/1' && method === 'GET') return this.response(this.release);
        if (endpoint === '/releases/1' && method === 'PATCH') {
            assert.equal(this.assets.length, 14, 'Publishing requires all fourteen assets');
            const body = JSON.parse(options.body);
            assert.equal(body.draft, false);
            if (this.failPublish) return this.response({}, 502);
            this.release = { ...this.release, ...body };
            if (this.ambiguousPublish) { this.ambiguousPublish = false; throw new Error('lost publish response'); }
            return this.response(this.release);
        }
        throw new Error(`Unmocked ${method} ${endpoint}`);
    }
}

/** Run with a fully injected offline transport and capture non-sensitive progress. */
async function execute(fixture, server) {
    const logs = [];
    const delays = [];
    const result = await run({ env: fixture.env, fetch: server.fetch.bind(server), sleep: async milliseconds => delays.push(milliseconds), log: message => logs.push(message) });
    assert.ok(logs.every(message => !message.includes(fixture.env.GITHUB_TOKEN)));
    return { result, logs, delays };
}

test('five complete targets publish fourteen assets from a draft with SHA proofs', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture({ annotated: true });
    const { result } = await execute(local, server);
    assert.equal(result.status, 'published');
    assert.equal(server.assets.length, 14);
    assert.equal(server.mutations('POST').filter(call => call.url.startsWith(ROOT)).length, 1);
    assert.equal(server.mutations('PATCH').length, 1);
    assert.equal(server.release.make_latest, 'legacy');
    assert.equal(server.release.draft, false);
    const summaryAsset = server.assets.find(asset => asset.name === 'release-manifest.json');
    const summary = JSON.parse(server.bytes.get(summaryAsset.id));
    assert.equal(summary.targets.length, 5);
    assert.equal(summary.assets.length, 12);
    assert.ok(summary.targets.every(target => target.php === '8.5' && target.encrypted));
    const checksumAsset = server.assets.find(asset => asset.name === 'SHA256SUMS');
    assert.equal(server.bytes.get(checksumAsset.id).toString().trim().split('\n').length, 13);
    assert.ok(server.release.body.includes('# 更新') && server.release.body.includes('# Changes'));
});

test('draft retry keeps correct assets and replaces stale/partial and unexpected assets', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture({ draft: true });
    const names = targetNames(TAG, TARGETS[0]);
    const kept = server.addAsset(names.binary, await fsp.readFile(local.locations.get(names.binary)));
    const replaced = server.addAsset(names.archive, Buffer.from('old bytes'));
    const extra = server.addAsset('unfinished.tmp', Buffer.from('partial'), { state: 'starter' });
    await execute(local, server);
    assert.equal(server.assets.find(asset => asset.name === names.binary).id, kept.id);
    assert.ok(server.assets.find(asset => asset.name === names.archive).id !== replaced.id);
    assert.deepEqual(server.mutations('DELETE').map(call => Number(new URL(call.url).pathname.split('/').at(-1))).sort((a, b) => a - b), [replaced.id, extra.id]);
    assert.equal(server.mutations('POST').filter(call => call.url.startsWith(ROOT)).length, 0);
});

test('missing fifth manifest fails before any network mutation', async t => {
    const local = await fixture(t);
    await fsp.unlink(local.locations.get(targetNames(TAG, TARGETS[4]).manifest));
    const server = new GitHubFixture();
    await assert.rejects(execute(local, server), /Missing target manifest/);
    assert.equal(server.calls.length, 0);
});

test('binary and archive local hash/byte mismatches fail before draft creation', async t => {
    for (const kind of ['binary', 'archive']) {
        const local = await fixture(t);
        await fsp.appendFile(local.locations.get(targetNames(TAG, TARGETS[0])[kind]), 'corruption');
        const server = new GitHubFixture();
        await assert.rejects(execute(local, server), /Hash or bytes mismatch/);
        assert.equal(server.calls.length, 0);
    }
});

test('actual CPU mismatch fails even with internally consistent local hashes', async t => {
    const local = await fixture(t);
    const names = targetNames(TAG, TARGETS[0]);
    const data = binary(TARGETS[1]);
    const manifest = JSON.parse(await fsp.readFile(local.locations.get(names.manifest)));
    manifest.binary.sha256 = hash(data);
    await fsp.writeFile(local.locations.get(names.binary), data);
    await fsp.writeFile(local.locations.get(names.manifest), JSON.stringify(manifest));
    const server = new GitHubFixture();
    await assert.rejects(execute(local, server), /format or CPU mismatch/);
    assert.equal(server.calls.length, 0);
});

test('upload failure never publishes a partial draft', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    server.failUpload = targetNames(TAG, TARGETS[1]).archive;
    await assert.rejects(execute(local, server), /Upload failed without a complete asset/);
    assert.equal(server.release.draft, true);
    assert.equal(server.mutations('PATCH').length, 0);
    assert.ok(server.assets.length < 14);
    server.failUpload = null;
    await execute(local, server);
    assert.equal(server.release.draft, false);
    assert.equal(server.mutations('POST').filter(call => call.url.startsWith(ROOT)).length, 1);
});

test('uploaded asset with incorrect server digest never allows publication', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    server.corruptUploadDigest = true;
    await assert.rejects(execute(local, server), /Release asset hash mismatch/);
    assert.equal(server.release.draft, true);
    assert.equal(server.mutations('PATCH').length, 0);
});

test('manifest missing required checksum evidence fails before any API request', async t => {
    const local = await fixture(t);
    const file = local.locations.get(targetNames(TAG, TARGETS[0]).manifest);
    const manifest = JSON.parse(await fsp.readFile(file));
    delete manifest.archive.sha256;
    await fsp.writeFile(file, JSON.stringify(manifest));
    const server = new GitHubFixture();
    await assert.rejects(execute(local, server), /Invalid archive record/);
    assert.equal(server.calls.length, 0);
});

test('published rerun verifies old random-encryption proofs without overwriting new bytes', async t => {
    const first = await fixture(t);
    const server = new GitHubFixture();
    await execute(first, server);
    const before = server.calls.length;
    const remoteBinary = server.assets.find(asset => asset.name === targetNames(TAG, TARGETS[0]).binary);
    const second = await fixture(t, { salt: 'different random encryption result' });
    assert.notEqual(remoteBinary.digest, `sha256:${hash(await fsp.readFile(second.locations.get(remoteBinary.name)))}`);
    const { result } = await execute(second, server);
    assert.equal(result.status, 'already-published');
    assert.ok(server.calls.slice(before).every(call => call.method === 'GET'));
    assert.equal(server.assets.length, 14);
});

test('published incomplete release is preserved and reported without any mutation', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    await execute(local, server);
    server.assets = server.assets.filter(asset => asset.name !== targetNames(TAG, TARGETS[4]).archive);
    const before = server.calls.length;
    await assert.rejects(execute(local, server), /inventory is incomplete/);
    assert.ok(server.calls.slice(before).every(call => call.method === 'GET'));
    assert.equal(server.release.draft, false);
});

test('published manifest with a different commit is rejected without modifying assets', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    await execute(local, server);
    const asset = server.assets.find(value => value.name === 'release-manifest.json');
    const summary = JSON.parse(server.bytes.get(asset.id));
    summary.commit = 'b'.repeat(40);
    const changed = Buffer.from(JSON.stringify(summary));
    server.bytes.set(asset.id, changed);
    asset.size = changed.length;
    asset.digest = `sha256:${hash(changed)}`;
    const before = server.calls.length;
    await assert.rejects(execute(local, server), /manifest identity is inconsistent/);
    assert.ok(server.calls.slice(before).every(call => call.method === 'GET'));
    assert.equal(server.release.draft, false);
});

test('remote tag mismatch never creates or publishes a release', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture({ annotated: true });
    server.remoteCommit = 'b'.repeat(40);
    await assert.rejects(execute(local, server), /Remote tag commit differs/);
    assert.equal(server.mutations('POST').length, 0);
    assert.equal(server.mutations('PATCH').length, 0);
});

test('tag moved after upload leaves the complete release as a draft', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    server.tagMovesBeforePublish = true;
    await assert.rejects(execute(local, server), /Remote tag commit differs/);
    assert.equal(server.assets.length, 14);
    assert.equal(server.release.draft, true);
    assert.equal(server.mutations('PATCH').length, 0);
});

test('missing API digests fall back to downloads and do not forward token to storage', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture({ digest: false });
    server.redirectDownloads = true;
    await execute(local, server);
    assert.equal(server.release.draft, false);
    assert.ok(server.calls.some(call => call.url.startsWith('https://release-assets.githubusercontent.com')));
});

test('safe read retries recover rate limits and upstream failures', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    server.transientReads = 2;
    const { delays } = await execute(local, server);
    assert.equal(delays.length, 2);
    assert.ok(delays.every(delay => delay >= 1000 && delay <= 10000));
});

test('ambiguous create, upload and publication responses are reconciled without repeat POST', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    server.ambiguousCreate = true;
    server.ambiguousUpload = true;
    server.ambiguousPublish = true;
    await execute(local, server);
    assert.equal(server.mutations('POST').filter(call => call.url.startsWith(ROOT)).length, 1);
    assert.equal(server.mutations('POST').filter(call => call.url.startsWith('https://uploads.github.com')).length, 14);
    assert.equal(server.mutations('PATCH').length, 1);
    assert.equal(server.release.draft, false);
});

test('failed publication stays draft and does not blindly repeat update', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    server.failPublish = true;
    await assert.rejects(execute(local, server), /verified assets remain in a draft/);
    assert.equal(server.mutations('PATCH').length, 1);
    assert.equal(server.release.draft, true);
});

test('old stable version never takes latest from a newer version', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    server.latestTag = 'v10.0.0';
    await execute(local, server);
    assert.equal(server.release.make_latest, 'false');
});

test('prerelease truth comes from validated notes metadata and cannot become latest', async t => {
    const tag = 'v2.0.0-rc.1';
    const local = await fixture(t, { tag });
    const server = new GitHubFixture({ tag });
    await execute(local, server);
    assert.equal(server.release.prerelease, true);
    assert.equal(server.release.make_latest, 'false');
    assert.ok(!server.calls.some(call => call.url.endsWith('/releases/latest')));
    const invalid = await fixture(t, { tag, prerelease: false });
    const unusedServer = new GitHubFixture({ tag });
    await assert.rejects(execute(invalid, unusedServer), /notes metadata/);
    assert.equal(unusedServer.calls.length, 0);
});

test('untrusted upload endpoints never receive credentials or any POST', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    server.evilUpload = true;
    await assert.rejects(execute(local, server), /Upload failed without a complete asset/);
    assert.ok(!server.calls.some(call => call.url.includes('attacker.example')));
    assert.equal(server.mutations('PATCH').length, 0);
});

test('configuration rejects unsafe GitHub origins and shell-shaped tag/repository inputs', async t => {
    const local = await fixture(t);
    for (const changed of [
        { GITHUB_API_URL: 'http://api.github.com' },
        { GITHUB_API_URL: 'https://attacker.example', GITHUB_SERVER_URL: 'https://github.com' },
        { GITHUB_API_URL: 'https://secret@api.github.com' },
        { GITHUB_REPOSITORY: '../gateway' },
        { GITHUB_REPOSITORY: 'example/gateway; command' },
        { RELEASE_TAG: 'v1.2.3$(command)' },
        { RELEASE_TAG: 'v1.2.3-01' },
    ]) assert.throws(() => configuration({ ...local.env, ...changed }));
    const enterprise = configuration({ ...local.env, GITHUB_API_URL: 'https://github.example/api/v3', GITHUB_SERVER_URL: 'https://github.example' });
    assert.equal(enterprise.apiRoot, 'https://github.example/api/v3');
});
