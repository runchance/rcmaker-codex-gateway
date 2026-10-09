'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { smokeBinary } = require('../scripts/smoke-binary.cjs');

const generated = path.resolve(__dirname, '../../data/generated');
fs.mkdirSync(generated, { recursive: true });
const fixture = fs.mkdtempSync(path.join(generated, 'release-smoke-'));
const tag = 'v1.0.0-beta.1';
const commit = 'a'.repeat(40);
let checks = 0;

/** Reproduce native metadata without starting a process or an application. */
function scenario(platform, arch, mutate = () => {}, version = `RCMAKER AI Gateway ${tag}`) {
  const stem = `rcmaker-codex-gateway-${tag}-${platform}-${arch}`;
  const binary = stem + (platform === 'windows' ? '.exe' : '');
  fs.writeFileSync(path.join(fixture, `${stem}.manifest.json`), JSON.stringify({
    tag, commit, platform, arch, php: '8.5', encrypted: true, binary: { name: binary },
  }));
  const info = { tag, commit, platform, arch, php: '8.5', encrypted: true,
    runtime: { php: '8.5.9', sapi: 'micro', os: { windows: 'Windows', linux: 'Linux', macos: 'Darwin' }[platform],
      arch: arch === 'x86_64' ? 'AMD64' : 'arm64',
      extensions: Object.fromEntries(['curl', 'openssl', 'pdo_sqlite', 'sqlite3', 'mbstring', 'sockets',
        'zip', 'phar', 'sodium', 'pcntl', 'posix'].map(key => [key, key === 'pcntl' || key === 'posix' ? platform !== 'windows' : true])) } };
  mutate(info);
  const calls = [];
  const result = smokeBinary({ outputDir: fixture, tag, commit, platform, arch,
    execute(filename, args, options) {
      assert.equal(filename, path.resolve(fixture, binary));
      assert.equal(options.windowsHide, true);
      assert.equal(options.timeout, 30000);
      assert.equal(args.length, 1);
      calls.push(args[0]);
      if (args[0] === '--version') return `${version}\n`;
      assert.equal(args[0], '--build-info');
      return JSON.stringify(info);
    } });
  assert.deepEqual(calls, ['--version', '--build-info']);
  return result;
}

for (const [platform, arch] of [['windows', 'x86_64'], ['linux', 'x86_64'], ['linux', 'aarch64'], ['macos', 'x86_64'], ['macos', 'aarch64']]) {
  assert.equal(scenario(platform, arch).passed, true);
  checks++;
}
for (const mutate of [
  info => { info.tag = 'dev'; }, info => { info.commit = 'b'.repeat(40); },
  info => { info.encrypted = false; }, info => { info.php = '8.4'; },
  info => { info.platform = 'macos'; }, info => { info.arch = 'aarch64'; },
  info => { info.runtime.php = '8.4.9'; }, info => { info.runtime.sapi = 'cli'; },
  info => { info.runtime.os = 'Darwin'; }, info => { info.runtime.arch = 'aarch64'; },
]) {
  assert.throws(() => scenario('linux', 'x86_64', mutate));
  checks++;
}
for (const extension of ['curl', 'openssl', 'pdo_sqlite', 'sqlite3', 'mbstring', 'sockets', 'zip', 'phar', 'sodium', 'pcntl', 'posix']) {
  assert.throws(() => scenario('linux', 'x86_64', info => { info.runtime.extensions[extension] = false; }));
  checks++;
}
assert.throws(() => scenario('windows', 'x86_64', () => {}, 'RCMAKER AI Gateway dev'));
checks++;
console.log(`PASS ${checks} native metadata smoke checks`);
