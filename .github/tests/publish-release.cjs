'use strict';

const assert = require('node:assert/strict');
const { test } = require('node:test');
const fsp = require('node:fs/promises');
const path = require('node:path');
const { createHash } = require('node:crypto');
const { deflateRawSync } = require('node:zlib');
const { execFileSync } = require('node:child_process');
const { run, configuration, TARGETS, targetNames, checksumText } = require('../scripts/publish-release.cjs');

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

/** Generate a real small ZIP fixture with stored/deflated entries and optional data descriptors. */
function windowsZip(data, { stored = false, descriptor = false, binaryName = 'rcmaker-codex-gateway.exe' } = {}) {
    const entries = [[binaryName, data], ...['LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES', '.env.example'].map(name => [name, Buffer.from(name)])];
    const locals = [];
    const central = [];
    let offset = 0;
    for (const [name, bytes] of entries) {
        const filename = Buffer.from(name);
        const compressed = stored ? bytes : deflateRawSync(bytes);
        let crc = 0xffffffff;
        for (const value of bytes) {
            crc ^= value;
            for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
        }
        crc = (crc ^ 0xffffffff) >>> 0;
        const local = Buffer.alloc(30);
        local.writeUInt32LE(0x04034b50);
        local.writeUInt16LE(20, 4);
        local.writeUInt16LE(descriptor ? 8 : 0, 6);
        local.writeUInt16LE(stored ? 0 : 8, 8);
        if (!descriptor) {
            local.writeUInt32LE(crc, 14);
            local.writeUInt32LE(compressed.length, 18);
            local.writeUInt32LE(bytes.length, 22);
        }
        local.writeUInt16LE(filename.length, 26);
        const receipt = Buffer.alloc(descriptor ? 16 : 0);
        if (descriptor) {
            receipt.writeUInt32LE(0x08074b50);
            receipt.writeUInt32LE(crc, 4);
            receipt.writeUInt32LE(compressed.length, 8);
            receipt.writeUInt32LE(bytes.length, 12);
        }
        locals.push(local, filename, compressed, receipt);
        const record = Buffer.alloc(46);
        record.writeUInt32LE(0x02014b50);
        record.writeUInt16LE(20, 4);
        record.writeUInt16LE(20, 6);
        record.writeUInt16LE(descriptor ? 8 : 0, 8);
        record.writeUInt16LE(stored ? 0 : 8, 10);
        record.writeUInt32LE(crc, 16);
        record.writeUInt32LE(compressed.length, 20);
        record.writeUInt32LE(bytes.length, 24);
        record.writeUInt16LE(filename.length, 28);
        record.writeUInt32LE(offset, 42);
        central.push(record, filename);
        offset += local.length + filename.length + compressed.length + receipt.length;
    }
    const directory = Buffer.concat(central);
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50);
    end.writeUInt16LE(entries.length, 8);
    end.writeUInt16LE(entries.length, 10);
    end.writeUInt32LE(directory.length, 12);
    end.writeUInt32LE(offset, 16);
    return Buffer.concat([...locals, directory, end]);
}

/** Create all five signed-manifest fixtures, optionally nesting CI artifact folders. */
async function fixture(t, { tag = TAG, salt = 'first', nested = true, prerelease = tag.includes('-'), zipOptions = {} } = {}) {
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
        const archive = target.platform === 'windows' ? windowsZip(data, zipOptions)
            : Buffer.from(`Archive fixture ${tag} ${target.platform} ${target.arch} ${salt}`);
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
        this.rejectExplicitTarget = null;
        this.failCreate = null;
        this.failRecovery = null;
        this.creationAttempted = false;
        this.otherReleases = [];
    }

    /** Produce public API release metadata, intentionally omitting inline assets. */
    releaseValue(draft) {
        return { id: 1, tag_name: this.tag, draft, prerelease: this.tag.includes('-'),
            published_at: draft ? null : '2026-10-10T13:00:00Z',
            upload_url: `https://uploads.github.com/repos/example/gateway/releases/1/assets{?name,label}` };
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

    /** Return an API error fixture, including untrusted JSON/text and a request identifier. */
    failure({ status, body, requestId = 'fixture-request-id', contentType = 'application/json' }) {
        return new Response(typeof body === 'string' ? body : JSON.stringify(body), {
            status, headers: { 'content-type': contentType, 'x-github-request-id': requestId },
        });
    }

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
        if (method === 'GET' && endpoint.startsWith('/releases') && this.creationAttempted && this.failRecovery) {
            return this.failure(this.failRecovery);
        }
        if (endpoint === `/git/ref/tags/${encodeURIComponent(this.tag)}`) {
            this.tagReads++;
            if (this.tagMovesBeforePublish && this.tagReads >= 2) this.remoteCommit = 'b'.repeat(40);
            return this.response({ object: this.annotated ? { type: 'tag', sha: 'c'.repeat(40) } : { type: 'commit', sha: this.remoteCommit } });
        }
        if (endpoint === `/git/tags/${'c'.repeat(40)}`) return this.response({ object: { type: 'commit', sha: this.remoteCommit } });
        if (endpoint === '/releases/latest') {
            if (this.latestTag) return this.response(this.otherReleases.find(release => release.tag_name === this.latestTag)
                || { id: 2, tag_name: this.latestTag, draft: false, prerelease: false });
            return this.release && !this.release.draft && !this.release.prerelease
                ? this.response(this.release) : this.response({}, 404);
        }
        if (endpoint === `/releases/tags/${encodeURIComponent(this.tag)}`) return this.release && !this.release.draft ? this.response(this.release) : this.response({}, 404);
        const page = Number(url.searchParams.get('page') || 1);
        if (endpoint === '/releases' && method === 'GET') {
            return this.response([...(this.release ? [this.release] : []), ...this.otherReleases].slice((page - 1) * 100, page * 100));
        }
        if (endpoint === '/releases' && method === 'POST') {
            assert.equal(JSON.parse(options.body).draft, true, 'Release must be created as a draft');
            this.creationAttempted = true;
            if (this.rejectExplicitTarget && Object.hasOwn(JSON.parse(options.body), 'target_commitish')) {
                return this.failure({ status: this.rejectExplicitTarget, body: { message: 'Resource not accessible by integration: target modifies workflows' } });
            }
            if (this.failCreate) return this.failure(this.failCreate);
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
            assert.equal(this.assets.length, 12, 'Publishing requires all twelve English-only assets');
            const body = JSON.parse(options.body);
            assert.equal(body.draft, false);
            if (this.rejectExplicitTarget && Object.hasOwn(body, 'target_commitish')) {
                return this.failure({ status: this.rejectExplicitTarget, body: { message: 'Resource not accessible by integration: target modifies workflows' } });
            }
            if (this.failPublish) return this.response({}, 502);
            this.release = { ...this.release, ...body, published_at: '2026-10-10T13:00:00Z' };
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
    let result;
    try {
        result = await run({ env: fixture.env, fetch: server.fetch.bind(server), sleep: async milliseconds => delays.push(milliseconds), log: message => logs.push(message) });
    } finally {
        assert.ok(logs.every(message => !message.includes(fixture.env.GITHUB_TOKEN)));
    }
    return { result, logs, delays };
}

test('existing schema-1 English and bilingual releases remain verifiable without being rewritten', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    await execute(local, server);
    const windows = TARGETS.find(target => target.platform === 'windows');
    const raw = server.addAsset(targetNames(TAG, windows).binary, await fsp.readFile(local.locations.get(targetNames(TAG, windows).binary)));
    const manifestAsset = server.assets.find(asset => asset.name === 'release-manifest.json');
    const checksumAsset = server.assets.find(asset => asset.name === 'SHA256SUMS');
    const manifest = JSON.parse(server.bytes.get(manifestAsset.id));
    manifest.schema_version = 1;
    manifest.assets.push({ name: raw.name, bytes: raw.size, sha256: raw.digest.slice(7) });
    for (const bilingual of [false, true]) {
        if (bilingual) {
            const chinese = server.addAsset('release-notes.zh-CN.md', Buffer.from('# 历史说明\n'));
            manifest.assets.push({ name: chinese.name, bytes: chinese.size, sha256: chinese.digest.slice(7) });
        }
        const proof = Buffer.from(JSON.stringify(manifest) + '\n');
        server.bytes.set(manifestAsset.id, proof);
        manifestAsset.size = proof.length;
        manifestAsset.digest = `sha256:${hash(proof)}`;
        const sums = Buffer.from(checksumText([...manifest.assets,
            { name: manifestAsset.name, bytes: proof.length, sha256: hash(proof) }]));
        server.bytes.set(checksumAsset.id, sums);
        checksumAsset.size = sums.length;
        checksumAsset.digest = `sha256:${hash(sums)}`;
        const before = server.calls.length;
        assert.equal((await execute(local, server)).result.status, 'already-published');
        assert.equal(server.assets.length, bilingual ? 14 : 13);
        assert.ok(server.calls.slice(before).every(call => call.method === 'GET'));
    }
});

test('new publication ignores leftover Chinese files and rejects Chinese in English notes', async t => {
    const local = await fixture(t);
    await fsp.writeFile(path.join(local.notesDir, 'release-notes.zh-CN.md'), '# 旧文件\n');
    const server = new GitHubFixture();
    await execute(local, server);
    assert.equal(server.assets.length, 12);
    assert.ok(server.assets.every(asset => !asset.name.includes('zh-CN')));
    await fsp.writeFile(path.join(local.notesDir, 'release-notes.en.md'), '# 中文\n');
    const unused = new GitHubFixture();
    await assert.rejects(execute(local, unused), /without Chinese text/);
    assert.equal(unused.calls.length, 0);
});

test('workflow preserves releases and artifacts, excludes standalone EXEs after native smoke tests', async () => {
    const workflow = await fsp.readFile(path.join(__dirname, '../workflows/release.yml'), 'utf8');
    assert.doesNotMatch(workflow, /^  cleanup:|actions: write|cleanup-release-storage|retention-days:/m);
    assert.match(workflow, /!\$\{\{ runner\.temp \}\}\/rcmaker-release-assets\/\*\.exe/);
    assert.ok(workflow.indexOf('run: node .github/scripts/smoke-binary.cjs') < workflow.indexOf('!\$\{\{ runner.temp'));
    assert.doesNotMatch(workflow, /bilingual|Chinese \/ English/);
});

test('five complete targets publish twelve English-only assets from a draft with SHA proofs', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture({ annotated: true });
    const { result } = await execute(local, server);
    assert.equal(result.status, 'published');
    assert.equal(server.assets.length, 12);
    assert.equal(server.mutations('POST').filter(call => call.url.startsWith(ROOT)).length, 1);
    assert.equal(server.mutations('PATCH').length, 1);
    assert.equal(server.release.make_latest, 'legacy');
    assert.equal(server.release.draft, false);
    const summaryAsset = server.assets.find(asset => asset.name === 'release-manifest.json');
    const summary = JSON.parse(server.bytes.get(summaryAsset.id));
    assert.equal(summary.targets.length, 5);
    assert.equal(summary.assets.length, 10);
    assert.equal(summary.schema_version, 2);
    assert.ok(!server.assets.some(asset => asset.name.endsWith('.exe')));
    assert.ok(summary.targets.every(target => target.php === '8.5' && target.encrypted));
    const checksumAsset = server.assets.find(asset => asset.name === 'SHA256SUMS');
    assert.equal(server.bytes.get(checksumAsset.id).toString().trim().split('\n').length, 12);
    assert.ok(server.release.body.includes('# Changes'));
    assert.doesNotMatch(server.release.body, /\p{Script=Han}/u);
    assert.ok(!server.assets.some(asset => asset.name.includes('zh-CN')));
});

test('Windows publishes from ZIP alone and retains the extracted EXE checksum used by installers', async t => {
    for (const stored of [false, true]) {
        for (const descriptor of [false, true]) {
            const local = await fixture(t, { zipOptions: { stored, descriptor } });
            const windows = TARGETS.find(target => target.platform === 'windows');
            const names = targetNames(TAG, windows);
            const data = await fsp.readFile(local.locations.get(names.binary));
            await fsp.unlink(local.locations.get(names.binary));
            const server = new GitHubFixture();
            await execute(local, server);
            assert.ok(server.assets.some(asset => asset.name === names.archive));
            assert.ok(!server.assets.some(asset => asset.name === names.binary));
            const sums = server.bytes.get(server.assets.find(asset => asset.name === 'SHA256SUMS').id).toString();
            assert.ok(sums.includes(`${hash(data)}  ${names.binary}\n`));
            const summary = JSON.parse(server.bytes.get(server.assets.find(asset => asset.name === 'release-manifest.json').id));
            assert.equal(summary.targets.find(target => target.platform === 'windows').binary.sha256, hash(data));
            assert.ok(!(await fsp.readdir(local.outputDir)).some(name => name.startsWith('.verify-windows-')));
        }
    }
});

test('the real framework-generated Windows ZIP passes publication without a separate EXE', async t => {
    const local = await fixture(t);
    const windows = TARGETS.find(target => target.platform === 'windows');
    const names = targetNames(TAG, windows);
    const archiveFile = local.locations.get(names.archive);
    await fsp.unlink(archiveFile);
    // Use the unchanged PHP builder rather than a hand-made ZIP for this integration check.
    execFileSync(process.env.RELEASE_TEST_PHP || 'php', ['-r',
        "require $argv[1]; GatewayReleaseBuilder::archive($argv[2], $argv[3], $argv[4], 'windows');",
        path.resolve(__dirname, '../scripts/build-release.php'), local.locations.get(names.binary),
        path.resolve(__dirname, '../..'), archiveFile], { timeout: 30000 });
    const manifest = JSON.parse(await fsp.readFile(local.locations.get(names.manifest)));
    const bytes = await fsp.readFile(archiveFile);
    manifest.archive = { name: names.archive, bytes: bytes.length, sha256: hash(bytes) };
    await fsp.writeFile(local.locations.get(names.manifest), JSON.stringify(manifest));
    await fsp.unlink(local.locations.get(names.binary));
    const server = new GitHubFixture();
    await execute(local, server);
    assert.equal(server.assets.length, 12);
    assert.ok(server.assets.every(asset => !asset.name.endsWith('.exe')));
});

test('Windows ZIP-contained hash, CPU, duplicate-entry and truncation failures prevent publication', async t => {
    for (const problem of ['hash', 'cpu', 'duplicate', 'truncated', 'local-name', 'overflow']) {
        const local = await fixture(t);
        const windows = TARGETS.find(target => target.platform === 'windows');
        const names = targetNames(TAG, windows);
        const manifest = JSON.parse(await fsp.readFile(local.locations.get(names.manifest)));
        let data = binary(windows);
        if (problem === 'cpu') {
            data.writeUInt16LE(0xaa64, 68);
            manifest.binary.sha256 = hash(data);
        }
        let archive = windowsZip(data, { binaryName: problem === 'duplicate' ? 'LICENSE' : undefined });
        if (problem === 'hash') manifest.binary.sha256 = '0'.repeat(64);
        if (problem === 'truncated') archive = archive.subarray(0, archive.length - 8);
        if (problem === 'local-name') archive[30] ^= 1;
        if (problem === 'overflow') {
            manifest.binary.bytes--;
            // Match the central directory to the false size; stream verification must still fail.
            const directory = archive.readUInt32LE(archive.length - 6);
            archive.writeUInt32LE(manifest.binary.bytes, directory + 24);
        }
        manifest.archive = { name: names.archive, bytes: archive.length, sha256: hash(archive) };
        await fsp.writeFile(local.locations.get(names.archive), archive);
        await fsp.writeFile(local.locations.get(names.manifest), JSON.stringify(manifest));
        const server = new GitHubFixture();
        await assert.rejects(execute(local, server), /Windows ZIP|format or CPU mismatch/);
        assert.equal(server.calls.length, 0);
        assert.ok(!(await fsp.readdir(local.outputDir)).some(name => name.startsWith('.verify-windows-')));
    }
});

test('publishing retains historical releases and removes a standalone Windows EXE only from its own draft', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture({ draft: true });
    server.otherReleases = [{ id: 2, tag_name: 'v1.0.0', draft: false }, { id: 3, tag_name: 'v1.1.0', draft: false }];
    const windowsName = targetNames(TAG, TARGETS.find(target => target.platform === 'windows')).binary;
    const oldExe = server.addAsset(windowsName, await fsp.readFile(local.locations.get(windowsName)));
    await execute(local, server);
    assert.equal(server.otherReleases.length, 2);
    assert.deepEqual(server.mutations('DELETE').map(call => call.url), [`${ROOT}/releases/assets/${oldExe.id}`]);
    assert.ok(server.calls.every(call => !call.url.includes('/actions/')));
    assert.ok(!server.assets.some(asset => asset.name.endsWith('.exe')));
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
    assert.ok(server.assets.length < 12);
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
    assert.equal(server.assets.length, 12);
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
    assert.equal(server.assets.length, 12);
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
    assert.equal(server.mutations('POST').filter(call => call.url.startsWith('https://uploads.github.com')).length, 12);
    assert.equal(server.mutations('PATCH').length, 1);
    assert.equal(server.release.draft, false);
});

test('existing verified tags publish without asking the token to target an older workflow commit', async t => {
    for (const status of [403, 404]) {
        const local = await fixture(t);
        const server = new GitHubFixture({ annotated: true });
        server.rejectExplicitTarget = status;
        const { result } = await execute(local, server);
        assert.equal(result.status, 'published');
        const creation = server.mutations('POST').filter(call => call.url === ROOT + '/releases');
        const publication = server.mutations('PATCH');
        assert.equal(creation.length, 1);
        assert.equal(publication.length, 1);
        for (const call of [...creation, ...publication]) assert.equal(Object.hasOwn(call.body, 'target_commitish'), false);
        const createIndex = server.calls.indexOf(creation[0]);
        const publishIndex = server.calls.indexOf(publication[0]);
        const tagChecks = server.calls.map((call, index) => ({ call, index }))
            .filter(({ call }) => call.url === ROOT + `/git/ref/tags/${encodeURIComponent(TAG)}`);
        assert.ok(tagChecks.some(({ index }) => index < createIndex), 'The real tag commit must be verified before draft creation');
        assert.ok(tagChecks.some(({ index }) => index > createIndex && index < publishIndex), 'The real tag commit must be verified again before publication');
    }
});

test('creation rejection preserves API status, cause and request ID without retrying POST', async t => {
    for (const [status, message] of [[403, 'Resource not accessible by integration'], [404, 'Not Found'], [422, 'Validation Failed']]) {
        const local = await fixture(t);
        const server = new GitHubFixture();
        server.failCreate = {
            status, requestId: `create-request-${status}`,
            body: { message: `${message}\n${local.env.GITHUB_TOKEN}`, errors: [{ resource: 'Release', field: 'tag_name', code: 'custom', message: 'release creation reason' }] },
        };
        await assert.rejects(execute(local, server), error => {
            assert.match(error.message, /Release creation failed and no recoverable draft exists/);
            assert.match(error.message, /GitHub POST \/releases/);
            assert.ok(error.message.includes(`HTTP ${status}`));
            assert.ok(error.message.includes(message));
            assert.ok(error.message.includes('release creation reason'));
            assert.ok(error.message.includes(`create-request-${status}`));
            assert.ok(!error.message.includes(local.env.GITHUB_TOKEN));
            assert.doesNotMatch(error.message, /[\x00-\x1f\x7f]/);
            return true;
        });
        const createCalls = server.mutations('POST').filter(call => call.url === ROOT + '/releases');
        assert.equal(createCalls.length, 1);
        assert.equal(server.mutations('PATCH').length, 0);
        const afterCreate = server.calls.slice(server.calls.indexOf(createCalls[0]) + 1);
        assert.ok(afterCreate.length > 0 && afterCreate.every(call => call.method === 'GET'));
        assert.equal(server.release, null);
    }
});

test('failed creation and failed recovery retain both independent API errors', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    server.failCreate = { status: 403, requestId: 'create-denied-id', body: { message: 'Creation denied' } };
    server.failRecovery = { status: 503, requestId: 'recovery-unavailable-id', body: { message: 'Recovery lookup unavailable' } };
    await assert.rejects(execute(local, server), error => {
        for (const expected of ['HTTP 403', 'Creation denied', 'create-denied-id', 'HTTP 503', 'Recovery lookup unavailable', 'recovery-unavailable-id']) {
            assert.ok(error.message.includes(expected), `Missing independent failure context: ${expected}`);
        }
        assert.ok(!error.message.includes(local.env.GITHUB_TOKEN));
        return true;
    });
    assert.equal(server.mutations('POST').filter(call => call.url === ROOT + '/releases').length, 1);
    assert.equal(server.mutations('PATCH').length, 0);
});

test('untrusted error text and request identifiers cannot expose credentials or control characters', async t => {
    const local = await fixture(t);
    const server = new GitHubFixture();
    server.failCreate = {
        status: 422, requestId: `diagnostic-${local.env.GITHUB_TOKEN}`,
        body: { message: `Validation\x00 failed\n${local.env.GITHUB_TOKEN}`, errors: [{ message: `bad\tfield ${local.env.GITHUB_TOKEN}` }] },
    };
    await assert.rejects(execute(local, server), error => {
        assert.match(error.message, /HTTP 422/);
        assert.ok(error.message.includes('Validation') && error.message.includes('failed'));
        assert.ok(error.message.includes('diagnostic-'));
        assert.ok(!error.message.includes(local.env.GITHUB_TOKEN));
        assert.doesNotMatch(error.message, /[\x00-\x1f\x7f]/);
        return true;
    });
});

test('oversized JSON and non-JSON API failures produce bounded safe diagnostics', async t => {
    for (const contentType of ['application/json', 'text/html']) {
        const local = await fixture(t);
        const server = new GitHubFixture();
        const untrusted = `${local.env.GITHUB_TOKEN}\x00\r\n` + 'oversized untrusted payload '.repeat(10000);
        server.failCreate = { status: 422, requestId: 'oversized-response-id', contentType,
            body: contentType === 'application/json' ? { message: untrusted, errors: [{ message: untrusted }] } : `<html>${untrusted}</html>` };
        await assert.rejects(execute(local, server), error => {
            assert.match(error.message, /HTTP 422/);
            assert.ok(error.message.includes('oversized-response-id'));
            assert.ok(error.message.length < 4000, 'An API failure must not print an unbounded response body');
            assert.ok(!error.message.includes(local.env.GITHUB_TOKEN));
            assert.doesNotMatch(error.message, /[\x00-\x1f\x7f]/);
            return true;
        });
        assert.equal(server.mutations('POST').filter(call => call.url === ROOT + '/releases').length, 1);
    }
});

test('old-tag retries load the publisher from the workflow commit while builds stay pinned to the tag', async () => {
    const workflow = await fsp.readFile(path.join(__dirname, '../workflows/release.yml'), 'utf8');
    const build = workflow.split(/^  build:\s*$/m)[1].split(/^  publish:\s*$/m)[0];
    const publish = workflow.split(/^  publish:\s*$/m)[1];
    assert.match(build, /name: Check out[^\r\n]*[\s\S]*?ref: \$\{\{ needs\.prepare\.outputs\.commit \}\}/);
    assert.match(publish, /name: Check out[^\r\n]*[\s\S]*?ref: \$\{\{ github\.workflow_sha \}\}/);
    assert.match(publish, /RELEASE_COMMIT: \$\{\{ needs\.prepare\.outputs\.commit \}\}/);
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
