'use strict';

const fs = require('node:fs');
const fsp = require('node:fs/promises');
const path = require('node:path');
const { createHash } = require('node:crypto');

const PRODUCT = 'rcmaker-codex-gateway';
const TARGETS = Object.freeze([
    { platform: 'linux', arch: 'x86_64', format: 'ELF' },
    { platform: 'linux', arch: 'aarch64', format: 'ELF' },
    { platform: 'macos', arch: 'x86_64', format: 'Mach-O' },
    { platform: 'macos', arch: 'aarch64', format: 'Mach-O' },
    { platform: 'windows', arch: 'x86_64', format: 'PE' },
]);
const NOTE_NAMES = ['release-notes.zh-CN.md', 'release-notes.en.md'];
const SUMMARY_NAME = 'release-manifest.json';
const CHECKSUM_NAME = 'SHA256SUMS';
const SHA256 = /^[a-f0-9]{64}$/;
const COMMIT = /^[a-f0-9]{40}$/;

/** Fail closed on a violated local or remote release contract. */
function requireThat(condition, message) {
    if (!condition) throw new Error(message);
}

/** Parse SemVer tags without numeric rounding; return null for other release tags. */
function version(tag) {
    const match = /^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/.exec(tag);
    if (!match || (match[4] && match[4].split('.').some(value => /^\d+$/.test(value) && /^0\d/.test(value)))) return null;
    return { numbers: match.slice(1, 4).map(BigInt), prerelease: Boolean(match[4]) };
}

/** Validate CI input and HTTPS API roots before any token-bearing request. */
function configuration(env) {
    for (const key of ['RELEASE_TAG', 'RELEASE_COMMIT', 'GITHUB_REPOSITORY', 'GITHUB_TOKEN', 'RELEASE_OUTPUT_DIR', 'RELEASE_NOTES_DIR']) {
        requireThat(typeof env[key] === 'string' && env[key].length > 0 && !/[\x00-\x1f]/.test(env[key]), `Missing or invalid ${key}.`);
    }
    const parsedVersion = version(env.RELEASE_TAG);
    requireThat(parsedVersion !== null && env.RELEASE_TAG.length <= 180, 'RELEASE_TAG must be a vMAJOR.MINOR.PATCH SemVer tag.');
    requireThat(COMMIT.test(env.RELEASE_COMMIT), 'RELEASE_COMMIT must be a full lowercase Git SHA-1.');
    requireThat(/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(env.GITHUB_REPOSITORY)
        && !env.GITHUB_REPOSITORY.split('/').some(value => value === '.' || value === '..'), 'Invalid GITHUB_REPOSITORY.');
    for (const key of ['RELEASE_OUTPUT_DIR', 'RELEASE_NOTES_DIR']) {
        requireThat(path.isAbsolute(env[key]), `${key} must be an absolute directory.`);
    }
    const api = new URL(env.GITHUB_API_URL || 'https://api.github.com');
    const server = new URL(env.GITHUB_SERVER_URL || 'https://github.com');
    for (const value of [api, server]) {
        requireThat(value.protocol === 'https:' && !value.username && !value.password && !value.search && !value.hash, 'GitHub URLs must be HTTPS roots without credentials or query strings.');
    }
    const publicGitHub = api.origin === 'https://api.github.com' && api.pathname.replace(/\/$/, '') === '';
    if (publicGitHub) {
        requireThat(server.origin === 'https://github.com' && server.pathname.replace(/\/$/, '') === '', 'GitHub API/server roots do not match.');
    } else {
        requireThat(Boolean(env.GITHUB_API_URL) && Boolean(env.GITHUB_SERVER_URL)
            && api.origin === server.origin && api.pathname.replace(/\/$/, '') === '/api/v3'
            && server.pathname.replace(/\/$/, '') === '', 'Enterprise GitHub requires matching server origin and /api/v3 API root.');
    }
    const repositoryPath = env.GITHUB_REPOSITORY.split('/').map(encodeURIComponent).join('/');
    return {
        tag: env.RELEASE_TAG, commit: env.RELEASE_COMMIT, token: env.GITHUB_TOKEN,
        repository: env.GITHUB_REPOSITORY, outputDir: env.RELEASE_OUTPUT_DIR, notesDir: env.RELEASE_NOTES_DIR,
        apiRoot: api.href.replace(/\/$/, ''), apiOrigin: api.origin, apiPrefix: api.pathname.replace(/\/$/, ''),
        publicGitHub, repositoryPath, prerelease: parsedVersion.prerelease, version: parsedVersion,
    };
}

/** Produce exact public names; manifest file names themselves are CI-only inputs. */
function targetNames(tag, target) {
    const stem = `${PRODUCT}-${tag}-${target.platform}-${target.arch}`;
    return { manifest: `${stem}.manifest.json`, binary: stem + (target.platform === 'windows' ? '.exe' : ''), archive: stem + (target.platform === 'windows' ? '.zip' : '.tar.gz') };
}

/** Read a small regular JSON file; symlinks cannot escape an artifact directory. */
async function jsonFile(file) {
    const stat = await fsp.lstat(file);
    requireThat(stat.isFile() && !stat.isSymbolicLink() && stat.size <= 5 * 1024 * 1024, `Invalid JSON input: ${path.basename(file)}.`);
    return JSON.parse(await fsp.readFile(file, 'utf8'));
}

/** Enumerate artifact files, rejecting duplicate names and all symlinks. */
async function inventory(directory) {
    const files = new Map();
    async function visit(current) {
        for (const entry of await fsp.readdir(current, { withFileTypes: true })) {
            requireThat(!entry.isSymbolicLink(), 'Release inputs cannot contain symlinks.');
            const file = path.join(current, entry.name);
            if (entry.isDirectory()) await visit(file);
            else if (entry.isFile()) {
                requireThat(!files.has(entry.name), `Duplicate artifact: ${entry.name}.`);
                files.set(entry.name, file);
            } else throw new Error('Release inputs must be regular files.');
        }
    }
    const stat = await fsp.lstat(directory);
    requireThat(stat.isDirectory() && !stat.isSymbolicLink(), 'Release input root must be a regular directory.');
    await visit(directory);
    return files;
}

/** Hash binary inputs in bounded memory and report exact bytes. */
async function fileRecord(file, name = path.basename(file)) {
    const stat = await fsp.lstat(file);
    requireThat(stat.isFile() && !stat.isSymbolicLink() && stat.size > 0 && Number.isSafeInteger(stat.size), `Invalid or empty artifact: ${name}.`);
    const hash = createHash('sha256');
    let bytes = 0;
    for await (const chunk of fs.createReadStream(file)) { hash.update(chunk); bytes += chunk.length; }
    requireThat(bytes === stat.size, `Artifact changed while reading: ${name}.`);
    return { name, sha256: hash.digest('hex'), bytes, path: file };
}

/** Verify 64-bit CPU, executable magic and type without running downloaded code. */
async function inspectBinary(file, target) {
    const handle = await fsp.open(file, 'r');
    try {
        const header = Buffer.alloc(64);
        requireThat((await handle.read(header, 0, 64, 0)).bytesRead === 64, 'Binary header is truncated.');
        let valid = false;
        if (target.platform === 'linux') {
            valid = header.subarray(0, 4).equals(Buffer.from([0x7f, 0x45, 0x4c, 0x46]))
                && header[4] === 2 && header[5] === 1 && [2, 3].includes(header.readUInt16LE(16))
                && header.readUInt16LE(18) === (target.arch === 'x86_64' ? 62 : 183);
        } else if (target.platform === 'macos') {
            valid = header.subarray(0, 4).equals(Buffer.from([0xcf, 0xfa, 0xed, 0xfe]))
                && header.readUInt32LE(4) === (target.arch === 'x86_64' ? 0x01000007 : 0x0100000c)
                && header.readUInt32LE(12) === 2;
        } else {
            const offset = header.readUInt32LE(60);
            const size = (await handle.stat()).size;
            if (header.subarray(0, 2).toString() === 'MZ' && offset >= 64 && offset <= size - 26) {
                const pe = Buffer.alloc(26);
                valid = (await handle.read(pe, 0, 26, offset)).bytesRead === 26
                    && pe.subarray(0, 4).equals(Buffer.from('PE\0\0')) && pe.readUInt16LE(4) === 0x8664
                    && pe.readUInt16LE(24) === 0x20b && target.arch === 'x86_64';
            }
        }
        requireThat(valid, `Binary format or CPU mismatch: ${target.platform}/${target.arch}.`);
    } finally { await handle.close(); }
}

/** Validate the builder's per-target identity and protected-payload assertions. */
function validateTarget(manifest, config, target) {
    const names = targetNames(config.tag, target);
    requireThat(manifest && manifest.tag === config.tag && manifest.commit === config.commit
        && manifest.php === '8.5' && manifest.encrypted === true
        && manifest.platform === target.platform && manifest.arch === target.arch && manifest.format === target.format,
    `Invalid target metadata: ${target.platform}/${target.arch}.`);
    requireThat(typeof manifest.host_php === 'string' && /^8\.5\./.test(manifest.host_php)
        && manifest.payload && Number.isSafeInteger(manifest.payload.files) && manifest.payload.files > 0
        && Number.isSafeInteger(manifest.payload.php_files) && manifest.payload.php_files > 0
        && manifest.payload.php_files <= manifest.payload.files && manifest.payload.signature === 'SHA-256', 'Missing protected PHP 8.5 payload evidence.');
    const binaryEntry = PRODUCT + (target.platform === 'windows' ? '.exe' : '');
    const allowedEntries = [binaryEntry, 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES', '.env.example'];
    requireThat(Array.isArray(manifest.archive_entries) && new Set(manifest.archive_entries).size === manifest.archive_entries.length
        && [binaryEntry, 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES'].every(name => manifest.archive_entries.includes(name))
        && manifest.archive_entries.every(name => allowedEntries.includes(name)), 'Invalid flat archive entry inventory.');
    for (const kind of ['binary', 'archive']) {
        const record = manifest[kind];
        requireThat(record && record.name === names[kind] && SHA256.test(record.sha256)
            && Number.isSafeInteger(record.bytes) && record.bytes > 0, `Invalid ${kind} record for ${target.platform}/${target.arch}.`);
    }
}

/** Strip local paths from public immutable file records. */
function publicRecord(record) {
    return { name: record.name, sha256: record.sha256, bytes: record.bytes };
}

/** Canonical SHA256SUMS covers the twelve payloads and the summary, avoiding self-reference. */
function checksumText(records) {
    return [...records].sort((left, right) => left.name.localeCompare(right.name, 'en')).map(record => `${record.sha256}  ${record.name}\n`).join('');
}

/** Validate all five builds before any remote mutation; write the two combined proofs. */
async function localRelease(config) {
    const notes = await inventory(config.notesDir);
    requireThat(notes.has('release-metadata.json'), 'Missing release-metadata.json.');
    const metadata = await jsonFile(notes.get('release-metadata.json'));
    requireThat(metadata.tag === config.tag && metadata.commit === config.commit && metadata.prerelease === config.prerelease
        && (metadata.previous_tag === null || (typeof metadata.previous_tag === 'string' && version(metadata.previous_tag))), 'Release notes metadata does not match the planned tag/commit/prerelease.');
    const files = await inventory(config.outputDir);
    const records = [];
    const targets = [];
    const expectedManifests = new Set(TARGETS.map(target => targetNames(config.tag, target).manifest));
    requireThat([...files.keys()].filter(name => name.endsWith('.manifest.json')).every(name => expectedManifests.has(name)), 'Unexpected target manifest.');
    for (const target of TARGETS) {
        const names = targetNames(config.tag, target);
        requireThat(files.has(names.manifest), `Missing target manifest: ${names.manifest}.`);
        const manifest = await jsonFile(files.get(names.manifest));
        validateTarget(manifest, config, target);
        for (const kind of ['binary', 'archive']) {
            requireThat(files.has(names[kind]), `Missing ${kind}: ${names[kind]}.`);
            const record = await fileRecord(files.get(names[kind]));
            requireThat(record.sha256 === manifest[kind].sha256 && record.bytes === manifest[kind].bytes, `Hash or bytes mismatch: ${record.name}.`);
            records.push(record);
        }
        await inspectBinary(files.get(names.binary), target);
        targets.push(manifest);
    }
    for (const name of NOTE_NAMES) {
        requireThat(notes.has(name), `Missing ${name}.`);
        records.push(await fileRecord(notes.get(name)));
    }
    const summary = {
        schema_version: 1, tag: config.tag, commit: config.commit, previous_tag: metadata.previous_tag,
        prerelease: config.prerelease, php: '8.5', encrypted: true, targets,
        assets: records.map(publicRecord).sort((left, right) => left.name.localeCompare(right.name, 'en')),
    };
    await fsp.writeFile(path.join(config.outputDir, SUMMARY_NAME), JSON.stringify(summary, null, 2) + '\n');
    records.push(await fileRecord(path.join(config.outputDir, SUMMARY_NAME)));
    await fsp.writeFile(path.join(config.outputDir, CHECKSUM_NAME), checksumText(records));
    records.push(await fileRecord(path.join(config.outputDir, CHECKSUM_NAME)));
    const body = (await fsp.readFile(notes.get(NOTE_NAMES[0]), 'utf8')) + '\n\n---\n\n' + (await fsp.readFile(notes.get(NOTE_NAMES[1]), 'utf8'));
    requireThat(Buffer.byteLength(body) <= 125000, 'Combined release notes exceed the GitHub release body limit.');
    return { metadata, records, body };
}

/** Repository-scoped REST client: tokens never follow redirects or untrusted response URLs. */
class GitHub {
    /** Accept injectable transport/delay only for offline tests. */
    constructor(config, fetchImpl, sleep) {
        this.config = config;
        this.fetch = fetchImpl;
        this.sleep = sleep;
        this.base = `${config.apiRoot}/repos/${config.repositoryPath}`;
    }

    /** Retry safe reads/deletes on bounded rate limits or transient upstream failures. */
    async request(endpoint, { method = 'GET', body, accept = 'application/vnd.github+json', allow404 = false, upload = false, contentType, contentLength } = {}) {
        const url = upload ? new URL(endpoint) : new URL(this.base + endpoint);
        const config = this.config;
        if (upload) {
            const publicPath = `/repos/${config.repositoryPath}/releases/`;
            const enterprisePaths = [`${config.apiPrefix}${publicPath}`, `/api/uploads${publicPath}`];
            requireThat(url.protocol === 'https:' && !url.username && !url.password && !url.hash
                && (config.publicGitHub ? url.origin === 'https://uploads.github.com' && url.pathname.startsWith(publicPath)
                    : url.origin === config.apiOrigin && enterprisePaths.some(prefix => url.pathname.startsWith(prefix)))
                && /\/releases\/\d+\/assets$/.test(url.pathname), 'Refusing an untrusted release upload URL.');
        } else {
            requireThat(url.origin === config.apiOrigin && url.pathname.startsWith(new URL(this.base).pathname + '/'), 'Refusing a request outside this repository API.');
        }
        const retrySafe = method === 'GET' || method === 'DELETE';
        for (let attempt = 0; ; attempt++) {
            let response;
            try {
                const headers = { Accept: accept, Authorization: `Bearer ${config.token}`, 'X-GitHub-Api-Version': '2022-11-28' };
                if (body !== undefined) headers['Content-Type'] = contentType || 'application/json';
                if (contentLength !== undefined) headers['Content-Length'] = String(contentLength);
                const payload = typeof body === 'function' ? body() : body === undefined ? undefined : JSON.stringify(body);
                response = await this.fetch(url.href, { method, headers, body: payload, redirect: 'manual', signal: AbortSignal.timeout(120000), ...(upload ? { duplex: 'half' } : {}) });
            } catch {
                if (retrySafe && attempt < 3) { await this.sleep(Math.min(1000 * 2 ** attempt, 10000)); continue; }
                throw new Error(`GitHub ${method} request failed; remote outcome may be unknown.`);
            }
            if (retrySafe && (response.status === 429 || response.status >= 500 || (response.status === 403 && response.headers.get('x-ratelimit-remaining') === '0')) && attempt < 3) {
                const retryAfter = Number(response.headers.get('retry-after'));
                await this.sleep(Math.min(Math.max(Number.isFinite(retryAfter) ? retryAfter * 1000 : 0, 1000 * 2 ** attempt), 10000));
                continue;
            }
            if (allow404 && response.status === 404) return null;
            if (accept === 'application/octet-stream' && [301, 302, 303, 307, 308].includes(response.status)) return response;
            requireThat(response.ok, `GitHub ${method} failed with HTTP ${response.status}.`);
            return response;
        }
    }

    /** Request small JSON API metadata under the known repository. */
    async json(endpoint, options) {
        const response = await this.request(endpoint, options);
        return response === null ? null : response.json();
    }

    /** Paginate lists rather than trusting the release response's truncated assets. */
    async list(endpoint) {
        const values = [];
        for (let page = 1; page <= 100; page++) {
            const chunk = await this.json(`${endpoint}?per_page=100&page=${page}`);
            requireThat(Array.isArray(chunk), 'Invalid GitHub list response.');
            values.push(...chunk);
            if (chunk.length < 100) return values;
        }
        throw new Error('GitHub pagination exceeded its safety bound.');
    }

    /** Get published tags directly, then find drafts through authenticated release listing. */
    async findRelease() {
        const found = await this.json(`/releases/tags/${encodeURIComponent(this.config.tag)}`, { allow404: true });
        if (found) return found;
        const matches = (await this.list('/releases')).filter(release => release.tag_name === this.config.tag);
        requireThat(matches.length <= 1, 'Multiple releases exist for the same tag.');
        return matches[0] || null;
    }

    /** Dereference annotated tags until the exact commit is known; detect loops/type changes. */
    async verifyTag() {
        const reference = await this.json(`/git/ref/tags/${encodeURIComponent(this.config.tag)}`);
        let object = reference.object;
        const seen = new Set();
        for (let depth = 0; depth < 16; depth++) {
            requireThat(object && COMMIT.test(object.sha), 'Invalid remote tag object.');
            if (object.type === 'commit') {
                requireThat(object.sha === this.config.commit, 'Remote tag commit differs from the planned release commit.');
                return;
            }
            requireThat(object.type === 'tag' && !seen.has(object.sha), 'Remote tag cannot be dereferenced to a commit.');
            seen.add(object.sha);
            object = (await this.json(`/git/tags/${object.sha}`)).object;
        }
        throw new Error('Annotated tag nesting exceeded its safety bound.');
    }

    /** Validate API-supplied object IDs before constructing an authenticated asset route. */
    id(value) {
        requireThat(Number.isSafeInteger(value) && value > 0, 'Invalid GitHub object ID.');
        return value;
    }

    /** Hash an asset, using a digest when available or an authenticated API download otherwise. */
    async assetHash(asset) {
        if (typeof asset.digest === 'string' && /^sha256:[a-f0-9]{64}$/.test(asset.digest)) return asset.digest.slice(7);
        return (await this.download(asset, false)).sha256;
    }

    /** Stream asset bytes; signed storage redirects are HTTPS and never receive the token. */
    async download(asset, retain = true) {
        let response = await this.request(`/releases/assets/${this.id(asset.id)}`, { accept: 'application/octet-stream' });
        for (let redirects = 0; [301, 302, 303, 307, 308].includes(response.status); redirects++) {
            requireThat(redirects < 5, 'Too many asset download redirects.');
            const location = response.headers.get('location');
            requireThat(location, 'Asset redirect has no location.');
            const url = new URL(location);
            requireThat(url.protocol === 'https:' && !url.username && !url.password, 'Refusing an unsafe asset download redirect.');
            response = await this.fetch(url.href, { headers: { Accept: 'application/octet-stream' }, redirect: 'manual', signal: AbortSignal.timeout(120000) });
        }
        requireThat(response.ok && response.body, 'Asset download failed.');
        const hash = createHash('sha256');
        const chunks = [];
        let bytes = 0;
        for await (const chunk of response.body) {
            bytes += chunk.length;
            requireThat(!retain || bytes <= 5 * 1024 * 1024, 'Release proof exceeds its size limit.');
            hash.update(chunk);
            if (retain) chunks.push(Buffer.from(chunk));
        }
        requireThat(bytes === asset.size, `Downloaded asset bytes mismatch: ${asset.name}.`);
        return { bytes, sha256: hash.digest('hex'), data: retain ? Buffer.concat(chunks) : undefined };
    }

    /** Refuse mutations once a release has become published, including during concurrent reruns. */
    async draft(releaseId) {
        const release = await this.json(`/releases/${this.id(releaseId)}`);
        requireThat(release.tag_name === this.config.tag && release.draft === true, 'Release is already published; refusing to change its assets.');
        return release;
    }

    /** Require uploaded state, size and digest/download proof for every public asset. */
    async verifyAssets(assets, records) {
        requireThat(assets.length === records.length && new Set(assets.map(asset => asset.name)).size === records.length, 'Release asset inventory is incomplete or duplicated.');
        const byName = new Map(assets.map(asset => [asset.name, asset]));
        for (const record of records) {
            const asset = byName.get(record.name);
            requireThat(asset && asset.state === 'uploaded' && asset.size === record.bytes, `Release asset missing or has wrong size: ${record.name}.`);
            requireThat(await this.assetHash(asset) === record.sha256, `Release asset hash mismatch: ${record.name}.`);
        }
    }

    /** Verify existing public artifacts against their own proof, never a new random encryption. */
    async verifyPublished(release) {
        requireThat(release.tag_name === this.config.tag && release.draft === false && release.prerelease === this.config.prerelease, 'Published release identity does not match.');
        await this.verifyTag();
        const assets = await this.list(`/releases/${this.id(release.id)}/assets`);
        const summaries = assets.filter(asset => asset.name === SUMMARY_NAME);
        const checksums = assets.filter(asset => asset.name === CHECKSUM_NAME);
        requireThat(summaries.length === 1 && checksums.length === 1, 'Published release lacks its immutable release proofs.');
        const proof = await this.download(summaries[0]);
        const summary = JSON.parse(proof.data.toString('utf8'));
        requireThat(summary.schema_version === 1 && summary.tag === this.config.tag && summary.commit === this.config.commit
            && summary.prerelease === this.config.prerelease && summary.php === '8.5' && summary.encrypted === true
            && Array.isArray(summary.targets) && summary.targets.length === TARGETS.length, 'Published release manifest identity is inconsistent.');
        for (const target of TARGETS) {
            const matches = summary.targets.filter(value => value.platform === target.platform && value.arch === target.arch);
            requireThat(matches.length === 1, 'Published release target inventory is incomplete.');
            validateTarget(matches[0], this.config, target);
        }
        const expected = new Set([...TARGETS.flatMap(target => { const names = targetNames(this.config.tag, target); return [names.binary, names.archive]; }), ...NOTE_NAMES]);
        requireThat(Array.isArray(summary.assets) && summary.assets.length === expected.size
            && new Set(summary.assets.map(record => record.name)).size === expected.size, 'Published release manifest asset inventory is incomplete.');
        for (const record of summary.assets) {
            requireThat(expected.has(record.name) && SHA256.test(record.sha256) && Number.isSafeInteger(record.bytes) && record.bytes > 0, 'Invalid published asset proof.');
        }
        for (const target of summary.targets) {
            for (const kind of ['binary', 'archive']) {
                const record = summary.assets.find(value => value.name === target[kind].name);
                requireThat(record && record.bytes === target[kind].bytes && record.sha256 === target[kind].sha256, 'Published target and asset proofs disagree.');
            }
        }
        const records = [...summary.assets, { name: SUMMARY_NAME, bytes: proof.bytes, sha256: proof.sha256 }];
        const checksumProof = await this.download(checksums[0]);
        requireThat(checksumProof.data.toString('utf8') === checksumText(records), 'Published checksum inventory differs from its manifest.');
        records.push({ name: CHECKSUM_NAME, bytes: checksumProof.bytes, sha256: checksumProof.sha256 });
        await this.verifyAssets(assets, records);
    }

    /** Preserve a newer stable release's latest designation; prereleases never become latest. */
    async latestPolicy() {
        if (this.config.prerelease) return 'false';
        const latest = await this.json('/releases/latest', { allow404: true });
        if (!latest || latest.tag_name === this.config.tag) return 'legacy';
        const previous = version(latest.tag_name);
        if (!previous || previous.prerelease) return 'false';
        for (let index = 0; index < 3; index++) {
            if (this.config.version.numbers[index] < previous.numbers[index]) return 'false';
            if (this.config.version.numbers[index] > previous.numbers[index]) return 'legacy';
        }
        return 'false';
    }

    /** Upload once; reconcile ambiguous POST outcomes by listing and validating the asset. */
    async upload(release, record) {
        const current = await this.draft(release.id);
        requireThat(typeof current.upload_url === 'string', 'Draft has no upload endpoint.');
        const endpoint = new URL(current.upload_url.replace(/\{.*\}$/, ''));
        requireThat(endpoint.pathname.endsWith(`/releases/${this.id(release.id)}/assets`), 'Upload endpoint does not match this release ID.');
        endpoint.searchParams.set('name', record.name);
        let failure;
        try {
            await this.request(endpoint.href, { method: 'POST', upload: true, contentType: 'application/octet-stream', contentLength: record.bytes, body: () => fs.createReadStream(record.path) });
        } catch (error) { failure = error; }
        const matches = (await this.list(`/releases/${this.id(release.id)}/assets`)).filter(asset => asset.name === record.name);
        requireThat(matches.length === 1, failure ? `Upload failed without a complete asset: ${record.name}.` : `Upload did not produce a unique asset: ${record.name}.`);
        await this.verifyAssets(matches, [record]);
    }
}

/**
 * Publish one complete five-target release. Options inject an offline fetch/delay/logger.
 * Throws on incomplete input or remote proof failure; every failed upload stays a draft.
 * Returns {status, tag, releaseId}; a public rerun only verifies existing immutable bytes.
 */
async function run({ env = process.env, fetch: fetchImpl = globalThis.fetch, sleep = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds)), log = console.log } = {}) {
    const config = configuration(env);
    const github = new GitHub(config, fetchImpl, sleep);
    // Validate every matrix artifact even on reruns: a broken workflow must fail closed.
    const local = await localRelease(config);
    await github.verifyTag();
    let release = await github.findRelease();
    if (release && release.draft === false) {
        await github.verifyPublished(release);
        log(`Release ${config.tag} already published; all 14 existing assets verified.`);
        return { status: 'already-published', tag: config.tag, releaseId: release.id };
    }
    if (!release) {
        try {
            release = await github.json('/releases', { method: 'POST', body: { tag_name: config.tag, target_commitish: config.commit, name: config.tag, body: local.body, draft: true, prerelease: config.prerelease } });
        } catch {
            // Creation POST is never blindly repeated: an interrupted response can still create a draft.
            release = await github.findRelease();
            requireThat(release, 'Release creation failed and no recoverable draft exists.');
        }
        if (release.draft === false) {
            await github.verifyPublished(release);
            return { status: 'already-published', tag: config.tag, releaseId: release.id };
        }
    }
    await github.draft(release.id);
    let assets = await github.list(`/releases/${github.id(release.id)}/assets`);
    const expected = new Map(local.records.map(record => [record.name, record]));
    const nameCounts = new Map();
    for (const asset of assets) nameCounts.set(asset.name, (nameCounts.get(asset.name) || 0) + 1);
    for (const asset of assets) {
        const record = expected.get(asset.name);
        let matches = false;
        if (record && nameCounts.get(asset.name) === 1) {
            try { await github.verifyAssets([asset], [record]); matches = true; } catch { /* A draft's stale/partial file can be safely replaced. */ }
        }
        if (!matches) {
            await github.draft(release.id);
            await github.request(`/releases/assets/${github.id(asset.id)}`, { method: 'DELETE' });
        }
    }
    assets = await github.list(`/releases/${github.id(release.id)}/assets`);
    const present = new Set(assets.map(asset => asset.name));
    for (const record of local.records) if (!present.has(record.name)) await github.upload(release, record);
    await github.verifyAssets(await github.list(`/releases/${github.id(release.id)}/assets`), local.records);
    const makeLatest = await github.latestPolicy();
    await github.draft(release.id);
    await github.verifyTag();
    const releaseId = github.id(release.id);
    try {
        release = await github.json(`/releases/${releaseId}`, { method: 'PATCH', body: { tag_name: config.tag, target_commitish: config.commit, name: config.tag, body: local.body, draft: false, prerelease: config.prerelease, make_latest: makeLatest } });
        requireThat(release.draft === false, 'GitHub did not publish the completed draft.');
    } catch {
        release = await github.json(`/releases/${releaseId}`);
        requireThat(release.draft === false, 'Publication failed; the verified assets remain in a draft.');
    }
    await github.verifyPublished(release);
    log(`Release ${config.tag} published with all 14 verified assets.`);
    return { status: 'published', tag: config.tag, releaseId: release.id };
}

module.exports = { run, configuration, targetNames, TARGETS, NOTE_NAMES, checksumText };

if (require.main === module) {
    run().catch(error => { console.error(`Release publication failed: ${error.message}`); process.exitCode = 1; });
}
