<?php
declare(strict_types=1);
/** Composer install/update entry: apply only the validated Windows supervisor repair to its exact installed package. */
$projectRoot = dirname(__DIR__, 2);
$targetRoot = $projectRoot;
if (isset($argv[1])) {
    $fixture = realpath($argv[1]);
    $prefix = strtolower(str_replace('\\', '/', $projectRoot) . '/data/');
    if (getenv('PHP_GATEWAY_TEST_MODE') !== '1' || $fixture === false
        || !str_starts_with(strtolower(str_replace('\\', '/', $fixture)) . '/', $prefix)) {
        fwrite(STDERR, "Only an explicit disposable fixture may override the package root.\n"); exit(1);
    }
    $targetRoot = $fixture;
}
$target = $targetRoot . '/vendor/runchance/rcmaker-framework/src/Cli/WindowsRuntime.php';
$manifest = json_decode((string)file_get_contents(__DIR__ . '/windows-runtime-isolation.json'), true, 16, JSON_THROW_ON_ERROR);
$original = (string)file_get_contents($target);
$text = str_replace("\r\n", "\n", $original);
$hash = hash('sha256', $text);
if (hash_equals($manifest['patched_normalized_sha256'], $hash)) {
    echo "Windows supervisor isolation patch already applied.\n"; exit(0);
}
if (!hash_equals($manifest['original_normalized_sha256'], $hash)) {
    fwrite(STDERR, "The installed runchance/rcmaker-framework revision differs from the validated repair. No file was changed; review the dependency before upgrading.\n"); exit(1);
}
$source = explode("\n", $text);
$patch = explode("\n", str_replace("\r\n", "\n", (string)file_get_contents(__DIR__ . '/windows-runtime-isolation.patch')));
$output = []; $cursor = 0; $inHunk = false;
foreach ($patch as $line) {
    if (preg_match('/\A@@ -(\d+)(?:,\d+)? \+\d+(?:,\d+)? @@/', $line, $match)) {
        $start = max(0,(int)$match[1]-1);
        while ($cursor < $start) $output[] = $source[$cursor++];
        $inHunk = true; continue;
    }
    if (!$inHunk || $line === '' || str_starts_with($line,'\\ No newline')) continue;
    $kind = $line[0]; $content = substr($line,1);
    if ($kind === ' ' || $kind === '-') {
        if (($source[$cursor] ?? null) !== $content) { fwrite(STDERR,"Validated patch context did not match. No file was changed.\n");exit(1); }
        ++$cursor;
    }
    if ($kind === ' ' || $kind === '+') $output[] = $content;
}
while ($cursor < count($source)) $output[] = $source[$cursor++];
$patched = implode("\n", $output);
if (!hash_equals($manifest['patched_normalized_sha256'], hash('sha256',$patched))) {
    fwrite(STDERR,"Validated patch output checksum did not match. No file was changed.\n");exit(1);
}
if (str_contains($original,"\r\n")) $patched = str_replace("\n","\r\n",$patched);
$temporary = dirname($target) . '/.WindowsRuntime.patch-' . bin2hex(random_bytes(6)) . '.tmp';
if (file_put_contents($temporary,$patched,LOCK_EX) !== strlen($patched)) {
    @unlink($temporary); fwrite(STDERR,"Could not stage the framework repair; original file preserved.\n");exit(1);
}
@chmod($temporary, (int)fileperms($target) & 0777);
if (!@rename($temporary,$target)) { @unlink($temporary);fwrite(STDERR,"Could not publish the framework repair; original file preserved.\n");exit(1); }
echo "Applied the validated Windows supervisor isolation repair.\n";
