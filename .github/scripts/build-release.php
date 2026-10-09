<?php

declare(strict_types=1);

use RC\Cli\BuildBinary;
use RC\Cli\Filesystem;
use RC\Cli\ProcessRunner;
use RC\Helper\Validator;
use app\gateway\model\WebTokenizer;

/**
 * Noninteractive release adapter for the framework's native encrypted builder.
 * Run with PHP 8.5 and -d phar.readonly=0 after composer install --no-dev.
 * Required environment: RELEASE_TAG, RELEASE_COMMIT, TARGET_PLATFORM,
 * TARGET_ARCH, RELEASE_WORK_DIR and RELEASE_OUTPUT_DIR (absolute directories).
 * The framework classes are loaded without Start.php: a Composer application
 * bootstrap, including IS_SCRIPT mode, can initialize persistent application data.
 */
$releaseProjectRoot = dirname(__DIR__, 2);
foreach (['Cli/Filesystem', 'Cli/ProcessRunner', 'Cli/ArtifactRepository', 'Cli/BuildBinary', 'Helper/Validator'] as $releaseClass) {
    require_once $releaseProjectRoot . '/vendor/runchance/rcmaker-framework/src/' . $releaseClass . '.php';
}
// This stateless class defines the pinned resource identity without bootstrapping the application.
require_once $releaseProjectRoot . '/apps/gateway/model/WebTokenizer.php';

final class GatewayReleaseBuilder
{
    private const PRODUCT = 'rcmaker-codex-gateway';
    private const ROOT_RUNTIME_DIRECTORIES = ['apps', 'config', 'public', 'support', 'vendor', 'view'];
    private const ROOT_RUNTIME_FILES = ['index.php', 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES'];
    private const COMPANION_FILES = ['LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES', '.env.example'];
    private const STUB = "#!/usr/bin/env php\n<?php\ndefine('IN_PHAR', true);\nPhar::mapPhar('rcmaker');\nrequire 'phar://rcmaker/index.php';\n__HALT_COMPILER();";

    /**
     * Validate CI input with the framework Validator and release-specific contracts.
     * @param array<string, mixed> $environment Required release environment values.
     * @return array<string, string> Validated exact tag, commit, target and paths.
     * @throws RuntimeException If an input is missing or violates its contract.
     */
    public static function configuration(array $environment): array
    {
        $rules = [];
        foreach (['RELEASE_TAG', 'RELEASE_COMMIT', 'TARGET_PLATFORM', 'TARGET_ARCH', 'RELEASE_WORK_DIR', 'RELEASE_OUTPUT_DIR'] as $name) {
            $rules[$name] = ['rule' => 'string', 'len' => [1, 4096], 'name' => $name];
        }
        $values = (new Validator())->input($environment, $rules, static function ($field): never {
            throw new RuntimeException('Missing or invalid release input: ' . $field);
        });
        if (!preg_match('/\Av(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/', $values['RELEASE_TAG'], $tagParts)) {
            throw new RuntimeException('RELEASE_TAG must be a v-prefixed SemVer tag.');
        }
        foreach (explode('.', $tagParts[1] ?? '') as $identifier) {
            if (strlen($identifier) > 1 && ctype_digit($identifier) && $identifier[0] === '0') {
                throw new RuntimeException('Numeric SemVer prerelease identifiers cannot have leading zeroes.');
            }
        }
        if (!preg_match('/\A[a-f0-9]{40}\z/', $values['RELEASE_COMMIT'])) {
            throw new RuntimeException('RELEASE_COMMIT must be a full lowercase Git SHA-1.');
        }
        if (!in_array($values['TARGET_PLATFORM'] . '/' . $values['TARGET_ARCH'], [
            'windows/x86_64', 'linux/x86_64', 'linux/aarch64', 'macos/x86_64', 'macos/aarch64',
        ], true)) {
            throw new RuntimeException('Unsupported release platform/architecture.');
        }
        foreach (['RELEASE_WORK_DIR', 'RELEASE_OUTPUT_DIR'] as $name) {
            if (!Filesystem::isAbsolute($values[$name]) || preg_match('/[\x00-\x1f]/', $values[$name])) {
                throw new RuntimeException($name . ' must be an absolute local directory path.');
            }
        }
        return $values;
    }

    /**
     * Apply the native builder's exact current policy and additional release exclusions.
     * Only the established runtime roots are distributable; arbitrary root helpers are
     * never implicit runtime input. Runtime JSON, YAML, certificates and assets survive.
     * @param string $relativePath Path relative to the application root.
     * @return bool Whether the entry must be omitted from the protected payload.
     * @throws RuntimeException If the installed native exclusion contract changed.
     */
    public static function excluded(string $relativePath): bool
    {
        static $nativePolicy;
        if ($nativePolicy === null) {
            try {
                $nativePolicy = new ReflectionMethod(BuildBinary::class, 'shouldExcludeByDefault');
            } catch (ReflectionException $error) {
                throw new RuntimeException('Installed framework default exclusion policy is unavailable.', 0, $error);
            }
            if (!$nativePolicy->isStatic()) {
                throw new RuntimeException('Installed framework default exclusion policy changed.');
            }
        }
        $path = Filesystem::normalizeRelative($relativePath);
        if ($nativePolicy->invoke(null, $path)) {
            return true;
        }
        $parts = explode('/', ltrim($path, '/'));
        foreach ($parts as $part) {
            $lower = strtolower($part);
            if ($lower === '.env' || str_starts_with($lower, '.env.') || $lower === '.data') {
                return true;
            }
        }
        if (in_array($parts[0], self::ROOT_RUNTIME_DIRECTORIES, true)) {
            return false;
        }
        return count($parts) !== 1 || !in_array($parts[0], self::ROOT_RUNTIME_FILES, true);
    }

    /**
     * Copy an isolated runtime tree without reading excluded directories or symlinks.
     * @param string $source Existing source checkout with installed dependencies.
     * @param string $target New, empty build source directory in a private work area.
     * @return void
     * @throws RuntimeException If an included symlink or unreadable file is encountered.
     */
    public static function stage(string $source, string $target): void
    {
        Filesystem::mkdir($target);
        Filesystem::copyTree($source, $target, static function (string $path, SplFileInfo $entry): bool {
            if (self::excluded($path)) {
                return true;
            }
            if ($entry->isLink()) {
                throw new RuntimeException('Release runtime input cannot contain symlinks: ' . $path);
            }
            return false;
        });
        foreach (['index.php', 'vendor/autoload.php'] as $required) {
            if (!is_file($target . '/' . $required)) {
                throw new RuntimeException('Release runtime input is missing: ' . $required);
            }
        }
        self::verifyTokenizerVocabulary($target);
    }

    /**
     * Refuse an unusable tokenizer before protecting and publishing the staged runtime.
     * @param string $sourceRoot Isolated runtime tree; this resource belongs inside the PHAR, not external data.
     * @return void The staged bytes must exactly match upstream, independently of checkout settings.
     * @throws RuntimeException If the vocabulary is missing, unreadable or altered (including CRLF conversion).
     */
    private static function verifyTokenizerVocabulary(string $sourceRoot): void
    {
        $relative = 'apps/gateway/resource/o200k_base.tiktoken';
        $path = $sourceRoot . '/' . $relative;
        $hash = is_file($path) && is_readable($path) ? @hash_file('sha256', $path) : false;
        if (!is_string($hash) || !hash_equals(WebTokenizer::VOCABULARY_SHA256, $hash)) {
            throw new RuntimeException('Release tokenizer vocabulary is missing, unreadable or changed: ' . $relative
                . '. Preserve the pinned file bytes; check Git line-ending conversion before rebuilding.');
        }
    }

    /**
     * Check executable format and the actual 64-bit CPU field without executing it.
     * @param string $binary Built binary path.
     * @param string $platform Exact windows, linux or macos target.
     * @param string $arch Exact x86_64 or aarch64 target.
     * @return string Validated format name (PE, ELF or Mach-O).
     * @throws RuntimeException If magic, architecture, bitness or file type mismatches.
     */
    public static function inspectBinary(string $binary, string $platform, string $arch): string
    {
        $stream = fopen($binary, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read binary: ' . $binary);
        }
        try {
            $header = fread($stream, 64);
            if ($header === false || strlen($header) < 64) {
                throw new RuntimeException('Binary header is truncated.');
            }
            if ($platform === 'windows' && substr($header, 0, 2) === 'MZ') {
                $offset = unpack('V', substr($header, 60, 4))[1];
                if ($offset < 64 || $offset > filesize($binary) - 26 || fseek($stream, $offset) !== 0) {
                    throw new RuntimeException('Invalid PE header offset.');
                }
                $pe = fread($stream, 26);
                if ($pe !== false && strlen($pe) === 26 && substr($pe, 0, 4) === "PE\0\0"
                    && unpack('v', substr($pe, 4, 2))[1] === 0x8664
                    && unpack('v', substr($pe, 24, 2))[1] === 0x20b && $arch === 'x86_64') {
                    return 'PE';
                }
            }
            if ($platform === 'linux' && substr($header, 0, 4) === "\x7fELF"
                && ord($header[4]) === 2 && ord($header[5]) === 1) {
                $type = unpack('v', substr($header, 16, 2))[1];
                $machine = unpack('v', substr($header, 18, 2))[1];
                if (in_array($type, [2, 3], true) && $machine === ($arch === 'x86_64' ? 62 : 183)) {
                    return 'ELF';
                }
            }
            if ($platform === 'macos' && substr($header, 0, 4) === "\xcf\xfa\xed\xfe") {
                $cpu = unpack('V', substr($header, 4, 4))[1];
                $type = unpack('V', substr($header, 12, 4))[1];
                if ($type === 2 && $cpu === ($arch === 'x86_64' ? 0x01000007 : 0x0100000c)) {
                    return 'Mach-O';
                }
            }
            throw new RuntimeException('Binary format or CPU does not match ' . $platform . '/' . $arch . '.');
        } finally {
            fclose($stream);
        }
    }

    /**
     * Extract only the appended PHAR for read-only structural and protection checks.
     * No packaged PHP is included or evaluated. The temporary PHAR stays private.
     * @param string $binary Built binary path.
     * @param string $payloadPath New .phar path inside the private work directory.
     * @param string $sourceRoot Filtered pre-encryption source, for exact entry matching.
     * @return array{files:int, php_files:int, signature:string} Verified payload facts.
     * @throws RuntimeException If signature, exclusions, contents or encryption fail.
     */
    public static function inspectPayload(string $binary, string $payloadPath, string $sourceRoot): array
    {
        $input = fopen($binary, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot open binary payload.');
        }
        $offsets = [];
        $carry = '';
        $position = 0;
        while (!feof($input)) {
            $chunk = fread($input, 1048576);
            if ($chunk === false) {
                fclose($input);
                throw new RuntimeException('Cannot scan binary payload.');
            }
            $buffer = $carry . $chunk;
            $search = 0;
            while (($found = strpos($buffer, self::STUB, $search)) !== false) {
                $offsets[] = $position - strlen($carry) + $found;
                $search = $found + strlen(self::STUB);
            }
            $position += strlen($chunk);
            $carry = substr($buffer, -(strlen(self::STUB) - 1));
        }
        if (count($offsets) !== 1 || fseek($input, $offsets[0]) !== 0) {
            fclose($input);
            throw new RuntimeException('Expected exactly one framework PHAR stub.');
        }
        $output = fopen($payloadPath, 'xb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Cannot create private payload inspection file.');
        }
        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException('Cannot extract private payload inspection file.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        $phar = new Phar($payloadPath);
        $signature = $phar->getSignature();
        if (!is_array($signature) || $signature['hash_type'] !== 'SHA-256') {
            throw new RuntimeException('Release PHAR must have a verified SHA-256 signature.');
        }
        $expected = self::sourceFiles($sourceRoot);
        $actual = [];
        $phpFiles = 0;
        foreach (new RecursiveIteratorIterator($phar) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $name = substr(str_replace('\\', '/', $entry->getPathname()), strlen('phar://' . str_replace('\\', '/', $payloadPath) . '/'));
            if (self::excluded($name) || !isset($expected[$name])) {
                throw new RuntimeException('Forbidden or unexpected release payload entry: ' . $name);
            }
            $contents = $entry->getContent();
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'php') {
                ++$phpFiles;
                // The native tool emits RCMB1 protected files. Ciphertext can contain
                // a random PHP opening-tag byte sequence, so scan its actual header.
                if (!str_starts_with($contents, "RCMB1\0\0\0")
                    || hash_equals($expected[$name], hash('sha256', $contents))) {
                    throw new RuntimeException('PHP entry was not protected: ' . $name);
                }
            } elseif (!hash_equals($expected[$name], hash('sha256', $contents))) {
                throw new RuntimeException('Runtime resource changed during protection: ' . $name);
            }
            $actual[$name] = true;
        }
        if ($phpFiles === 0 || array_diff_key($expected, $actual) !== [] || !isset($actual['index.php'], $actual['config/release.php'])) {
            throw new RuntimeException('Release PHAR entry inventory is incomplete.');
        }
        return ['files' => count($actual), 'php_files' => $phpFiles, 'signature' => $signature['hash_type']];
    }

    /**
     * Archive a binary under the stable product entry plus present legal/example files.
     * @param string $binary Validated encrypted binary to archive.
     * @param string $sourceRoot Original checkout supplying public companion files.
     * @param string $archive New .zip (Windows) or .tar.gz (Unix) file path.
     * @param string $platform Exact target platform.
     * @return array<string> Exact flat archive entry names.
     * @throws RuntimeException If archive creation or companion copying fails.
     */
    public static function archive(string $binary, string $sourceRoot, string $archive, string $platform): array
    {
        $files = [self::PRODUCT . ($platform === 'windows' ? '.exe' : '') => $binary];
        foreach (self::COMPANION_FILES as $name) {
            if (is_file($sourceRoot . '/' . $name) && !is_link($sourceRoot . '/' . $name)) {
                $files[$name] = $sourceRoot . '/' . $name;
            }
        }
        if (file_exists($archive)) {
            throw new RuntimeException('Archive already exists: ' . $archive);
        }
        if ($platform === 'windows') {
            $zip = new ZipArchive();
            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('Cannot create release ZIP.');
            }
            try {
                foreach ($files as $name => $path) {
                    if (!$zip->addFile($path, $name)) {
                        throw new RuntimeException('Cannot add ZIP entry: ' . $name);
                    }
                }
            } finally {
                if (!$zip->close()) {
                    throw new RuntimeException('Cannot finalize release ZIP.');
                }
            }
        } else {
            $tarPath = substr($archive, 0, -3);
            $tar = new PharData($tarPath);
            foreach ($files as $name => $path) {
                $tar->addFile($path, $name);
                $tar[$name]->chmod(str_starts_with($name, self::PRODUCT) ? 0755 : 0644);
            }
            $tar->compress(Phar::GZ);
            unset($tar);
            Phar::unlinkArchive($tarPath);
        }
        return array_keys($files);
    }

    /**
     * Build, validate and publish one immutable target without touching live build/.
     * @param string $root Existing Git checkout root with Composer dependencies.
     * @param array<string, mixed> $environment CI release environment.
     * @return array<string, mixed> Published manifest, including artifact checksums.
     * @throws RuntimeException On any failed validation, build or artifact write.
     */
    public static function run(string $root, array $environment): array
    {
        if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5 || filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Use PHP 8.5 with -d phar.readonly=0 for release builds.');
        }
        $config = self::configuration($environment);
        $root = realpath($root) ?: throw new RuntimeException('Checkout root does not exist.');
        ProcessRunner::run(['git', 'diff', '--quiet', 'HEAD', '--'], $root);
        ProcessRunner::run(['git', 'diff', '--cached', '--quiet', 'HEAD', '--'], $root);
        // Git's argument-array invocation prevents tag/SHA values becoming shell code.
        $head = trim(self::gitOutput($root, ['rev-parse', '--verify', 'HEAD']));
        if (!hash_equals($config['RELEASE_COMMIT'], $head)) {
            throw new RuntimeException('RELEASE_COMMIT does not match the checked-out HEAD.');
        }
        $tagCommit = trim(self::gitOutput($root, ['rev-parse', '--verify', 'refs/tags/' . $config['RELEASE_TAG'] . '^{commit}']));
        if (!hash_equals($head, $tagCommit)) {
            throw new RuntimeException('RELEASE_TAG does not point to the checked-out commit.');
        }
        $untracked = self::gitOutput($root, ['ls-files', '--others', '-z', '--',
            'apps', 'config', 'public', 'support', 'view', 'index.php', 'LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES']);
        foreach (array_filter(explode("\0", $untracked)) as $path) {
            if (!self::excluded($path)) {
                throw new RuntimeException('Untracked runtime input cannot be attributed to RELEASE_COMMIT: ' . $path);
            }
        }
        $work = self::privateDirectory($config['RELEASE_WORK_DIR'], $root);
        $output = self::privateDirectory($config['RELEASE_OUTPUT_DIR'], $root);
        if (self::within($work, $output) || self::within($output, $work)) {
            throw new RuntimeException('Release work and output directories must be separate.');
        }
        $sandbox = $work . '/release-' . bin2hex(random_bytes(12));
        if (!mkdir($sandbox, 0700)) {
            throw new RuntimeException('Cannot create an exclusive release work directory.');
        }
        $sandbox = realpath($sandbox) ?: throw new RuntimeException('Cannot resolve release work directory.');
        $published = [];
        try {
            $staged = $sandbox . '/source';
            self::stage($root, $staged);
            $metadata = [
                'tag' => $config['RELEASE_TAG'], 'commit' => $config['RELEASE_COMMIT'], 'php' => '8.5',
                'encrypted' => true, 'platform' => $config['TARGET_PLATFORM'], 'arch' => $config['TARGET_ARCH'],
            ];
            self::write($staged . '/config/release.php', "<?php\n\ndeclare(strict_types=1);\n\n// Immutable metadata injected only into the isolated release source.\nreturn " . var_export($metadata, true) . ";\n");
            $built = BuildBinary::execute($staged, [
                'with-php' => '8.5', 'platform' => $config['TARGET_PLATFORM'], 'arch' => $config['TARGET_ARCH'],
                'encrypt' => true, 'exclude-files' => '.env,.data,data,ssl',
            ], static fn(string $line) => print($line . PHP_EOL));
            if (($built['php'] ?? null) !== '8.5' || ($built['encrypted'] ?? null) !== true
                || ($built['platform'] ?? null) !== $config['TARGET_PLATFORM'] || ($built['arch'] ?? null) !== $config['TARGET_ARCH']) {
                throw new RuntimeException('Native builder returned inconsistent release metadata.');
            }
            $format = self::inspectBinary($built['path'], $config['TARGET_PLATFORM'], $config['TARGET_ARCH']);
            $payload = self::inspectPayload($built['path'], $sandbox . '/inspection.phar', $staged);
            $stem = self::PRODUCT . '-' . $config['RELEASE_TAG'] . '-' . $config['TARGET_PLATFORM'] . '-' . $config['TARGET_ARCH'];
            $binaryName = $stem . ($config['TARGET_PLATFORM'] === 'windows' ? '.exe' : '');
            $archiveName = $stem . ($config['TARGET_PLATFORM'] === 'windows' ? '.zip' : '.tar.gz');
            $archivePath = $sandbox . '/' . $archiveName;
            $entries = self::archive($built['path'], $root, $archivePath, $config['TARGET_PLATFORM']);
            $manifest = $metadata + [
                'host_php' => PHP_VERSION, 'format' => $format, 'payload' => $payload, 'archive_entries' => $entries,
                'binary' => ['name' => $binaryName, 'sha256' => hash_file('sha256', $built['path']), 'bytes' => filesize($built['path'])],
                'archive' => ['name' => $archiveName, 'sha256' => hash_file('sha256', $archivePath), 'bytes' => filesize($archivePath)],
            ];
            $manifestName = $stem . '.manifest.json';
            $manifestPath = $sandbox . '/' . $manifestName;
            self::write($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            foreach ([$binaryName => $built['path'], $archiveName => $archivePath, $manifestName => $manifestPath] as $name => $path) {
                $destination = $output . '/' . $name;
                self::publishFile($path, $destination);
                $published[] = $destination;
            }
            if ($config['TARGET_PLATFORM'] !== 'windows') {
                chmod($output . '/' . $binaryName, 0755);
            }
            return $manifest;
        } catch (Throwable $error) {
            foreach ($published as $path) {
                Filesystem::removeFile($path);
            }
            throw $error;
        } finally {
            // Only this freshly created random child is ever recursively removed.
            if (!self::within($sandbox, $work) || !preg_match('/\Arelease-[a-f0-9]{24}\z/', basename($sandbox))) {
                throw new RuntimeException('Refusing cleanup outside this release work directory.');
            }
            Filesystem::removePath($sandbox);
        }
    }

    /** Collect distributable source-file digests for exact PHAR inventory checks. */
    private static function sourceFiles(string $root): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $name = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            if (!self::excluded($name)) {
                $files[$name] = hash_file('sha256', $entry->getPathname());
            }
        }
        return $files;
    }

    /** Resolve and restrict work/output roots before creating any build files. */
    private static function privateDirectory(string $path, string $root): string
    {
        $path = Filesystem::normalizedAbsolute($path);
        $existing = $path;
        $suffix = [];
        while (!file_exists($existing)) {
            array_unshift($suffix, basename($existing));
            $parent = dirname($existing);
            if ($parent === $existing) {
                throw new RuntimeException('Release directory has no existing ancestor.');
            }
            $existing = $parent;
        }
        $existing = realpath($existing) ?: throw new RuntimeException('Cannot resolve release directory ancestor.');
        $path = $existing . ($suffix === [] ? '' : '/' . implode('/', $suffix));
        $generated = $root . '/data/generated';
        if (self::within($root, $path) || (self::within($path, $root) && !self::within($path, $generated))
            || self::samePath($path, $generated)) {
            throw new RuntimeException('Release directories must be outside the checkout or below data/generated.');
        }
        Filesystem::mkdir($path);
        return realpath($path) ?: throw new RuntimeException('Cannot resolve private release directory.');
    }

    /** Compare canonical path boundaries without Windows case or separator ambiguity. */
    private static function within(string $path, string $parent): bool
    {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));
        $parent = str_replace('\\', '/', rtrim($parent, '/\\'));
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path, $parent . '/');
    }

    /** Test exact canonical path equality using the same platform-aware comparison. */
    private static function samePath(string $left, string $right): bool
    {
        return self::within($left, $right) && self::within($right, $left);
    }

    /** Capture read-only Git inspection output with shell-free argument arrays. */
    private static function gitOutput(string $root, array $arguments): string
    {
        $process = proc_open(['git', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot inspect release Git checkout.');
        }
        fclose($pipes[0]);
        $head = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $head === false) {
            throw new RuntimeException('Cannot resolve release Git checkout provenance.');
        }
        return $head;
    }

    /** Write an owned private file and fail if the complete content was not stored. */
    private static function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Cannot write release file: ' . $path);
        }
    }

    /** Publish one new file exclusively; an existing artifact is never overwritten. */
    private static function publishFile(string $source, string $destination): void
    {
        $stream = @fopen($destination, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Release artifact exists or cannot be created: ' . $destination);
        }
        try {
            Filesystem::copyFileToStream($source, $stream);
        } catch (Throwable $error) {
            fclose($stream);
            Filesystem::removeFile($destination);
            throw $error;
        }
        fclose($stream);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $releaseManifest = GatewayReleaseBuilder::run($releaseProjectRoot, getenv());
        echo json_encode($releaseManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    } catch (Throwable $releaseError) {
        fwrite(STDERR, 'Release build failed: ' . $releaseError->getMessage() . PHP_EOL);
        exit(1);
    }
}
