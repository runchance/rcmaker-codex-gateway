<?php

declare(strict_types=1);

/** Isolated release-policy, archive and executable validation; never starts application code. */
require dirname(__DIR__, 2) . '/.github/scripts/build-release.php';

use RC\Cli\Filesystem;
use RC\Cli\ProcessRunner;

$releaseFixture = dirname(__DIR__, 2) . '/data/generated/release-contract-' . bin2hex(random_bytes(8));
Filesystem::mkdir($releaseFixture);
$releaseChecks = 0;

/** Assert one observable release contract or stop the isolated test. */
function releaseCheck(string $name, bool $passed): void
{
    global $releaseChecks;
    ++$releaseChecks;
    if (!$passed) {
        throw new RuntimeException('FAIL ' . $name);
    }
    echo 'PASS ', $name, PHP_EOL;
}

/** Confirm a malformed release input or payload is rejected. */
function releaseRejects(string $name, callable $action): void
{
    try {
        $action();
    } catch (Throwable) {
        releaseCheck($name, true);
        return;
    }
    releaseCheck($name, false);
}

/** Write a fixture file under the private directory. */
function releaseFixtureFile(string $root, string $name, string $contents): void
{
    Filesystem::mkdir(dirname($root . '/' . $name));
    file_put_contents($root . '/' . $name, $contents);
}

/** Construct a minimal CPU-specific executable header for independent format tests. */
function releaseHeader(string $platform, string $arch): string
{
    if ($platform === 'windows') {
        return 'MZ' . str_repeat("\0", 58) . pack('V', 64) . "PE\0\0" . pack('v', 0x8664)
            . str_repeat("\0", 18) . pack('v', 0x20b) . str_repeat("\0", 64);
    }
    if ($platform === 'linux') {
        return "\x7fELF\x02\x01\x01" . str_repeat("\0", 9) . pack('v', 2)
            . pack('v', $arch === 'x86_64' ? 62 : 183) . str_repeat("\0", 44);
    }
    return "\xcf\xfa\xed\xfe" . pack('V', $arch === 'x86_64' ? 0x01000007 : 0x0100000c)
        . str_repeat("\0", 4) . pack('V', 2) . str_repeat("\0", 48);
}

/** Build a fake protected payload to exercise structural checks without running PHP. */
function releaseFixturePayload(string $root, string $source, ?callable $mutate = null): string
{
    $pharPath = $root . '/fixture-' . bin2hex(random_bytes(4)) . '.phar';
    $phar = new Phar($pharPath);
    $phar->startBuffering();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $name = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
        if (!GatewayReleaseBuilder::excluded($name)) {
            $content = file_get_contents($entry->getPathname());
            $phar[$name] = str_ends_with($name, '.php') ? "RCMB1\0\0\0" . hash('sha256', $content) : $content;
        }
    }
    if ($mutate !== null) {
        $mutate($phar);
    }
    $phar->setSignatureAlgorithm(Phar::SHA256);
    $phar->setStub("#!/usr/bin/env php\n<?php\ndefine('IN_PHAR', true);\nPhar::mapPhar('rcmaker');\nrequire 'phar://rcmaker/index.php';\n__HALT_COMPILER();\n");
    $phar->stopBuffering();
    unset($phar);
    $binary = $pharPath . '.bin';
    file_put_contents($binary, releaseHeader('linux', 'x86_64') . file_get_contents($pharPath));
    return $binary;
}

try {
    $environment = [
        'RELEASE_TAG' => 'v1.2.3', 'RELEASE_COMMIT' => str_repeat('a', 40), 'TARGET_PLATFORM' => 'windows',
        'TARGET_ARCH' => 'x86_64', 'RELEASE_WORK_DIR' => $releaseFixture . '/work', 'RELEASE_OUTPUT_DIR' => $releaseFixture . '/out',
    ];
    releaseCheck('required-environment-accepted', GatewayReleaseBuilder::configuration($environment) === $environment);
    foreach (['v1.2.3-beta.1', 'v1.2.3-beta.1+build.4', 'v0.0.0+001'] as $tag) {
        releaseCheck('semver-' . $tag, GatewayReleaseBuilder::configuration(['RELEASE_TAG' => $tag] + $environment)['RELEASE_TAG'] === $tag);
    }
    foreach (['1.2.3', 'v01.2.3', 'v1.2.3-beta.01', 'v1.2.3/../escape', 'v1.2.3;echo secret'] as $tag) {
        releaseRejects('reject-tag-' . $tag, static fn() => GatewayReleaseBuilder::configuration(['RELEASE_TAG' => $tag] + $environment));
    }
    releaseRejects('reject-windows-arm', static fn() => GatewayReleaseBuilder::configuration(['TARGET_ARCH' => 'aarch64'] + $environment));
    releaseRejects('reject-short-commit', static fn() => GatewayReleaseBuilder::configuration(['RELEASE_COMMIT' => 'abc'] + $environment));
    releaseRejects('reject-relative-work', static fn() => GatewayReleaseBuilder::configuration(['RELEASE_WORK_DIR' => './build'] + $environment));
    releaseRejects('reject-missing-output', static fn() => GatewayReleaseBuilder::configuration(array_diff_key($environment, ['RELEASE_OUTPUT_DIR' => true])));

    foreach (['data/private.txt', '.data/private.txt', '.env', '.env.local', '.env.example', 'ssl/key.pem', 'README.md',
        'AGENTS.md', 'CLAUDE.md', 'root-helper.php', 'root-helper.json', 'tools/run.php', 'scripts/buildBin.php',
        '.agents/token.txt', '.github/workflows/release.yml', 'build/live.exe', 'runtime/logs/live.log',
        'vendor/pkg/tests/test.php', 'vendor/pkg/docs/readme.txt', 'vendor/pkg/.git/objects/private', 'apps/gateway/.env.secret',
        'public/.env.secret', 'vendor/pkg/.env', 'public/asset.js.map', 'config/README.md'] as $path) {
        releaseCheck('exclude-' . $path, GatewayReleaseBuilder::excluded($path));
    }
    $resources = [
        'index.php' => '<?php echo "fixture";', 'vendor/autoload.php' => '<?php // fixture autoload',
        'config/release.php' => '<?php return [];', 'apps/gateway/controller/home.php' => '<?php class Home {}',
        'public/assets/locale.json' => '{"en":"ok"}', 'public/console/css/main.css' => '.ok { color: green; }',
        'public/assets/logo.svg' => '<svg/>', 'public/assets/route.yaml' => 'route: runtime',
        'vendor/pkg/ca-bundle.pem' => 'PUBLIC CA CERTIFICATE', 'vendor/pkg/config.xml' => '<config/>',
        'view/default.html' => '<h1>fixture</h1>', 'apps/gateway/schema.json' => '{}',
        'apps/gateway/resource/o200k_base.tiktoken' => file_get_contents(dirname(__DIR__, 2) . '/apps/gateway/resource/o200k_base.tiktoken'),
        'LICENSE' => 'license fixture', 'NOTICE' => 'notice fixture', 'THIRD_PARTY_NOTICES' => 'third party fixture',
    ];
    $source = $releaseFixture . '/source';
    foreach ($resources as $name => $contents) {
        releaseFixtureFile($source, $name, $contents);
        releaseCheck('preserve-' . $name, !GatewayReleaseBuilder::excluded($name));
    }
    foreach (['.env', '.env.local', 'data/database/private.sqlite', '.data/private.txt', 'ssl/private.pem', 'root-helper.php', 'public/.env.secret'] as $name) {
        releaseFixtureFile($source, $name, 'SECRET-CANARY-DO-NOT-RELEASE');
    }
    releaseFixtureFile($source, '.env.example', 'PUBLIC_CONFIGURATION_EXAMPLE=1');
    $staged = $releaseFixture . '/staged';
    GatewayReleaseBuilder::stage($source, $staged);
    $actual = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staged, FilesystemIterator::SKIP_DOTS)) as $entry) {
        if ($entry->isFile()) {
            $actual[str_replace('\\', '/', substr($entry->getPathname(), strlen($staged) + 1))] = file_get_contents($entry->getPathname());
        }
    }
    ksort($actual);
    ksort($resources);
    releaseCheck('staging-keeps-exact-runtime-resources-without-secrets', $actual === $resources);
    $vocabularyName = 'apps/gateway/resource/o200k_base.tiktoken';
    $vocabulary = $resources[$vocabularyName];
    releaseFixtureFile($source, $vocabularyName, str_replace("\n", "\r\n", $vocabulary));
    releaseRejects('reject-checkout-crlf-vocabulary-before-build', static fn() => GatewayReleaseBuilder::stage($source, $releaseFixture . '/crlf-staged'));
    releaseFixtureFile($source, $vocabularyName, $vocabulary . "invalid-vocabulary-record\n");
    releaseRejects('reject-corrupt-vocabulary-before-build', static fn() => GatewayReleaseBuilder::stage($source, $releaseFixture . '/corrupt-staged'));
    unlink($source . '/' . $vocabularyName);
    releaseRejects('reject-missing-vocabulary-before-build', static fn() => GatewayReleaseBuilder::stage($source, $releaseFixture . '/missing-staged'));
    releaseFixtureFile($source, $vocabularyName, $vocabulary);

    foreach ([['windows', 'x86_64', 'PE'], ['linux', 'x86_64', 'ELF'], ['linux', 'aarch64', 'ELF'],
        ['macos', 'x86_64', 'Mach-O'], ['macos', 'aarch64', 'Mach-O']] as [$platform, $arch, $format]) {
        $binary = $releaseFixture . '/' . $platform . '-' . $arch . '.bin';
        file_put_contents($binary, releaseHeader($platform, $arch));
        releaseCheck('format-' . $platform . '-' . $arch, GatewayReleaseBuilder::inspectBinary($binary, $platform, $arch) === $format);
        releaseRejects('wrong-cpu-' . $platform . '-' . $arch, static fn() => GatewayReleaseBuilder::inspectBinary($binary, $platform, $arch === 'x86_64' ? 'aarch64' : 'x86_64'));
        $archive = $releaseFixture . '/' . $platform . '-' . $arch . ($platform === 'windows' ? '.zip' : '.tar.gz');
        $entries = GatewayReleaseBuilder::archive($binary, $source, $archive, $platform);
        $expectedEntries = ['rcmaker-codex-gateway' . ($platform === 'windows' ? '.exe' : ''), 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES', '.env.example'];
        releaseCheck('archive-entries-' . $platform . '-' . $arch, $entries === $expectedEntries);
        if ($platform === 'windows') {
            $zip = new ZipArchive();
            $zip->open($archive);
            releaseCheck('zip-flat-exact-entry-count', $zip->numFiles === count($entries));
            foreach ($entries as $name) {
                $expectedContent = str_starts_with($name, 'rcmaker-codex-gateway') ? file_get_contents($binary) : file_get_contents($source . '/' . $name);
                releaseCheck('zip-content-' . $name, $zip->getFromName($name) === $expectedContent);
            }
            $zip->close();
        } else {
            $tar = new PharData($archive);
            releaseCheck('tar-flat-exact-entry-count-' . $platform . '-' . $arch, count($tar) === count($entries));
            foreach ($entries as $name) {
                $expectedContent = str_starts_with($name, 'rcmaker-codex-gateway') ? file_get_contents($binary) : file_get_contents($source . '/' . $name);
                releaseCheck('tar-content-' . $platform . '-' . $arch . '-' . $name, $tar[$name]->getContent() === $expectedContent);
            }
            releaseCheck('tar-executable-permissions-' . $platform . '-' . $arch, ($tar['rcmaker-codex-gateway']->getPerms() & 0777) === 0755);
            unset($tar);
        }
        releaseRejects('archive-refuses-overwrite-' . $platform . '-' . $arch, static fn() => GatewayReleaseBuilder::archive($binary, $source, $archive, $platform));
    }
    $binary = releaseFixturePayload($releaseFixture, $staged);
    $payload = GatewayReleaseBuilder::inspectPayload($binary, $releaseFixture . '/inspected.phar', $staged);
    releaseCheck('payload-exact-inventory-and-signature', $payload === ['files' => count($resources), 'php_files' => 4, 'signature' => 'SHA-256']);
    $collisionBinary = releaseFixturePayload($releaseFixture, $staged, static fn(Phar $archive) => $archive['index.php'] = "RCMB1\0\0\0random-ciphertext-<?=bytes<?php");
    releaseCheck('protected-ciphertext-tag-collision-is-valid', GatewayReleaseBuilder::inspectPayload($collisionBinary, $releaseFixture . '/ciphertext-collision.phar', $staged)['php_files'] === 4);
    foreach ([
        'plaintext' => static fn(Phar $archive) => $archive['index.php'] = '<?php echo "source";',
        'unchanged-source' => static fn(Phar $archive) => $archive['index.php'] = $resources['index.php'],
        'secret-entry' => static fn(Phar $archive) => $archive['.env'] = 'SECRET-CANARY',
        'resource-corruption' => static fn(Phar $archive) => $archive['public/assets/locale.json'] = '{}',
        'tokenizer-crlf' => static fn(Phar $archive) => $archive['apps/gateway/resource/o200k_base.tiktoken'] = str_replace("\n", "\r\n", $resources['apps/gateway/resource/o200k_base.tiktoken']),
        'missing-tokenizer' => static fn(Phar $archive) => $archive->delete('apps/gateway/resource/o200k_base.tiktoken'),
        'missing-runtime-file' => static fn(Phar $archive) => $archive->delete('view/default.html'),
    ] as $name => $mutation) {
        $invalid = releaseFixturePayload($releaseFixture, $staged, $mutation);
        releaseRejects('reject-payload-' . $name, static fn() => GatewayReleaseBuilder::inspectPayload($invalid, $releaseFixture . '/reject-' . $name . '.phar', $staged));
    }
    ProcessRunner::run(['git', 'init', '--quiet'], $source);
    ProcessRunner::run(['git', 'add', '--all'], $source);
    ProcessRunner::run(['git', '-c', 'user.name=Release Test', '-c', 'user.email=test@example.invalid', 'commit', '--quiet', '-m', 'Private release policy fixture'], $source);
    ProcessRunner::run(['git', 'tag', 'v1.2.3'], $source);
    $commitProcess = proc_open(['git', 'rev-parse', 'HEAD'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $commitPipes, $source);
    if (!is_resource($commitProcess)) {
        throw new RuntimeException('Cannot inspect private fixture commit.');
    }
    fclose($commitPipes[0]);
    $commit = trim((string)stream_get_contents($commitPipes[1]));
    fclose($commitPipes[1]);
    releaseCheck('fixture-git-commit-readable', proc_close($commitProcess) === 0);
    $buildEnvironment = ['RELEASE_COMMIT' => $commit] + $environment;
    releaseRejects('reject-wrong-checkout-commit', static fn() => GatewayReleaseBuilder::run($source, $environment));
    releaseRejects('reject-missing-tag', static fn() => GatewayReleaseBuilder::run($source, ['RELEASE_TAG' => 'v9.9.9'] + $buildEnvironment));
    foreach ([$source, $source . '/build', $source . '/data', $releaseFixture] as $workPath) {
        releaseRejects('reject-unsafe-work-' . basename($workPath), static fn() => GatewayReleaseBuilder::run($source, ['RELEASE_WORK_DIR' => $workPath] + $buildEnvironment));
    }
    releaseRejects('reject-overlapping-work-and-output', static fn() => GatewayReleaseBuilder::run($source, [
        'RELEASE_WORK_DIR' => $releaseFixture . '/private', 'RELEASE_OUTPUT_DIR' => $releaseFixture . '/private/out',
    ] + $buildEnvironment));
    releaseFixtureFile($source, 'public/untracked-runtime.json', '{}');
    releaseRejects('reject-untracked-runtime-provenance', static fn() => GatewayReleaseBuilder::run($source, $buildEnvironment));
    unlink($source . '/public/untracked-runtime.json');
    file_put_contents($source . '/index.php', '<?php echo "changed";');
    releaseRejects('reject-dirty-runtime-provenance', static fn() => GatewayReleaseBuilder::run($source, $buildEnvironment));
    echo 'TOTAL ', $releaseChecks, ' PASSED', PHP_EOL;
} finally {
    // Windows Git marks loose objects read-only; this fixture owns every file here.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($releaseFixture, FilesystemIterator::SKIP_DOTS)) as $entry) {
        if ($entry->isFile()) {
            chmod($entry->getPathname(), 0666);
        }
    }
    Filesystem::removePath($releaseFixture);
}
