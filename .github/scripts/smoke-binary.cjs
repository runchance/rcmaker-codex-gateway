'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

/** Execute only early metadata queries; this must never start the gateway or initialize a profile. */
function smokeBinary({ outputDir, tag, commit, platform, arch, execute = execFileSync }) {
  const stem = `rcmaker-codex-gateway-${tag}-${platform}-${arch}`;
  const manifest = JSON.parse(fs.readFileSync(path.join(outputDir, `${stem}.manifest.json`), 'utf8'));
  for (const [key, expected] of Object.entries({ tag, commit, platform, arch, php: '8.5', encrypted: true })) {
    if (manifest[key] !== expected) throw new Error(`Build manifest mismatch: ${key}`);
  }
  const expectedName = stem + (platform === 'windows' ? '.exe' : '');
  if (manifest.binary.name !== expectedName) throw new Error('Unexpected native binary filename');
  const binary = path.resolve(outputDir, expectedName);
  const options = { encoding: 'utf8', timeout: 30000, maxBuffer: 1024 * 1024, windowsHide: true };
  const version = execute(binary, ['--version'], options).trim();
  if (version !== `RCMAKER AI Gateway ${tag}`) throw new Error('Encrypted version query did not return the release tag');
  const info = JSON.parse(execute(binary, ['--build-info'], options));
  for (const [key, expected] of Object.entries({ tag, commit, platform, arch, php: '8.5', encrypted: true })) {
    if (info[key] !== expected) throw new Error(`Executed package identity mismatch: ${key}`);
  }
  const runtime = info.runtime || {};
  const targetOs = { windows: 'Windows', linux: 'Linux', macos: 'Darwin' }[platform];
  const runtimeArch = String(runtime.arch).toLowerCase();
  const canonicalArch = ['amd64', 'x64', 'x86_64', 'x86-64'].includes(runtimeArch) ? 'x86_64'
    : ['arm64', 'aarch64', 'armv8', 'armv8l'].includes(runtimeArch) ? 'aarch64' : runtimeArch;
  if (!/^8\.5\./.test(runtime.php) || runtime.os !== targetOs || canonicalArch !== arch || runtime.sapi !== 'micro') {
    throw new Error('Packaged PHP / OS / architecture / Micro runtime differs from the target');
  }
  const required = ['curl', 'openssl', 'pdo_sqlite', 'sqlite3', 'mbstring', 'sockets', 'zip', 'phar', 'sodium'];
  if (platform !== 'windows') required.push('pcntl', 'posix');
  for (const extension of required) {
    if (runtime.extensions?.[extension] !== true) throw new Error(`Missing packaged extension: ${extension}`);
  }
  return { tag, commit, platform, arch, passed: true, runtime };
}

module.exports = { smokeBinary };

if (require.main === module) {
  const report = smokeBinary({ outputDir: process.env.RELEASE_OUTPUT_DIR, tag: process.env.RELEASE_TAG,
    commit: process.env.RELEASE_COMMIT, platform: process.env.TARGET_PLATFORM, arch: process.env.TARGET_ARCH });
  if (process.env.RELEASE_SMOKE_REPORT) fs.writeFileSync(process.env.RELEASE_SMOKE_REPORT, JSON.stringify(report, null, 2) + '\n');
  console.log(`PASS encrypted PHP 8.5 / ${report.platform} ${report.arch} / ${report.tag}`);
}
