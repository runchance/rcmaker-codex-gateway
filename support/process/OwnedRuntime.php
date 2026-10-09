<?php
declare(strict_types=1);
namespace support\process;

use RuntimeException;

/** PHP ownership of external runtime trees, independent of HTTP requests and shell profiles. */
final class OwnedRuntime
{
    /**
     * Give a Windows runtime a project-unique image name, keeping its signed bytes and DLL directory.
     * The name also identifies detached children after a root crash. Unix uses a private process group.
     * @throws RuntimeException when a verified private copy cannot be prepared.
     */
    public static function executable(string $source, string $scope, string $role): string
    {
        $source = realpath($source) ?: throw new RuntimeException('Runtime executable missing.');
        $scope = realpath($scope) ?: throw new RuntimeException('Runtime scope missing.');
        if (!self::inside($source, $scope) || !preg_match('/\A[a-z]{3,16}\z/', $role)) {
            throw new RuntimeException('Runtime executable escaped its private scope.');
        }
        if (PHP_OS_FAMILY !== 'Windows') return $source;
        // Installed vendor directories are intentionally read-only. Keep the executable and its
        // DLL/resources in a separate private launch directory instead of changing vendor files.
        $directory = $scope . '/.owned/' . $role . '/' . substr(hash('sha256', dirname($source)), 0, 16);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Private launch directory unavailable.');
        if (!self::inside(realpath($directory) ?: '', $scope)) throw new RuntimeException('Private launch directory escaped scope.');
        $target = $directory . DIRECTORY_SEPARATOR . self::imageName($scope, $role);
        $lock = fopen($target . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Runtime copy lock unavailable.');
        try {
            $hash = hash_file('sha256', $source);
            if (str_contains($role, 'chrome')) {
                self::copyResources(dirname($source), $directory, basename($source));
            }
            if (is_file($target) && hash_equals($hash, hash_file('sha256', $target))) return $target;
            $temp = $target . '.part-' . bin2hex(random_bytes(6));
            if (!copy($source, $temp) || !hash_equals($hash, hash_file('sha256', $temp)) || !rename($temp, $target)) {
                @unlink($temp);
                throw new RuntimeException('Cannot prepare private runtime executable.');
            }
            return $target;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Copy immutable browser resources into its private launch directory; never write through a vendor hardlink. */
    private static function copyResources(string $source, string $destination, string $executable): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            if ($relative === $executable || str_starts_with(basename($relative), 'rcmaker-')) continue;
            if ($item->isLink()) throw new RuntimeException('Unexpected link in Windows browser runtime.');
            $target = $destination . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) throw new RuntimeException('Cannot prepare runtime resources.');
            } elseif (!is_file($target) || !hash_equals(hash_file('sha256', $item->getPathname()), hash_file('sha256', $target))) {
                // Copies preserve the vendor tree even if a later runtime writes beside its executable.
                if (!copy($item->getPathname(), $target)) throw new RuntimeException('Cannot copy runtime resources.');
            }
        }
    }

    /** Deterministic image identity includes the resolved installation directory, never just a vendor name. */
    public static function imageName(string $scope, string $role): string
    {
        return 'rcmaker-' . substr(hash('sha256', strtolower(str_replace('\\', '/', $scope))), 0, 24) . '-' . $role . '.exe';
    }

    /** @return array{process:resource,control:resource,pid:int,guardian_pid:int,path:string} */
    public static function launch(array $command, array $environment, string $scope, string $role, string $log): array
    {
        $command[0] = self::executable((string) $command[0], $scope, $role);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($listener === false) throw new RuntimeException('Runtime control channel unavailable.');
        $token = bin2hex(random_bytes(32));
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        // Unix installations may load POSIX/PCNTL through their configured PHP ini.
        $guardian = GatewayEntrypoint::command('--gateway-runtime-guard');
        $processOptions = ['bypass_shell' => true];
        if (PHP_OS_FAMILY === 'Windows') {
            // Keep the guardian as a plain PHP child of the owning Workerman worker.  The old
            // `conhost.exe --headless` wrapper could busy-spin on some Windows builds and consume
            // an entire logical core per runtime while otherwise idle.  A new process group keeps
            // the guardian out of the normal Ctrl+C broadcast; Workerman first runs onWorkerStop,
            // which closes the authenticated control channel and lets the guardian reclaim its
            // exact project-private Chrome/Tunnel tree before exiting.
            $processOptions['create_process_group'] = true;
        }
        // Bootstrap the authenticated loopback channel through the guardian's private
        // environment instead of relying on inherited stdin or a shell-specific pipe.
        // The component receives the original environment, never these guardian credentials.
        $guardEnvironment = array_replace($environment, [
            'RCMAKER_GUARD_ADDRESS' => stream_socket_get_name($listener, false),
            'RCMAKER_GUARD_TOKEN' => $token,
            'RCMAKER_GUARD_LOG' => $log,
        ]);
        $process = proc_open($guardian, [
            0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $log, 'a'],
        ], $pipes, $scope, $guardEnvironment, $processOptions);
        if (!is_resource($process)) { fclose($listener); throw new RuntimeException('PHP runtime guardian unavailable.'); }
        $control = null;
        try {
            $control = stream_socket_accept($listener, 8);
            if ($control === false) throw new RuntimeException('PHP runtime guardian did not connect.');
            stream_set_timeout($control, 8);
            $hello = json_decode((string) fgets($control, 8192), true);
            if (!is_array($hello) || !hash_equals($token, (string) ($hello['token'] ?? ''))) {
                throw new RuntimeException('PHP runtime guardian authentication failed.');
            }
            $payload = json_encode(['command' => $command, 'environment' => $environment,
                'scope' => $scope, 'role' => $role, 'log' => $log], JSON_THROW_ON_ERROR);
            if (strlen($payload) > 1048575) throw new RuntimeException('Runtime handoff too large.');
            self::write($control, $payload . "\n");
            $ack = json_decode((string) fgets($control, 8192), true);
            if (!is_array($ack) || !hash_equals($token, (string) ($ack['token'] ?? ''))
                || (int) ($ack['pid'] ?? 0) < 1 || (int) ($ack['guardian_pid'] ?? 0) < 1) {
                throw new RuntimeException('PHP runtime guardian did not establish ownership.');
            }
            stream_set_blocking($control, false);
            return ['process' => $process, 'control' => $control, 'pid' => (int) $ack['pid'],
                'guardian_pid' => (int) $ack['guardian_pid'], 'path' => $command[0]];
        } catch (\Throwable $error) {
            if (is_resource($control)) fclose($control);
            // EOF makes the guardian clean its child. Never terminate the guardian ahead of its child.
            self::wait($process, 10);
            throw $error;
        } finally {
            fclose($listener);
        }
    }

    /** Stop a handle owned by this PHP worker, then wait for cleanup acknowledgement via process exit. */
    public static function stop(?array &$owned): void
    {
        if ($owned === null) return;
        if (is_resource($owned['control'] ?? null)) {
            try { self::write($owned['control'], "stop\n"); } catch (\Throwable) {}
            stream_socket_shutdown($owned['control'], STREAM_SHUT_RDWR);
            fclose($owned['control']);
        }
        // Windows cleanup can use three bounded OS calls (inventory, terminate, verify).
        // Its acknowledgement must not expire before that normal cleanup budget elapses.
        if (is_resource($owned['process'] ?? null) && !self::wait($owned['process'], 25)) {
            throw new RuntimeException('Runtime cleanup has not finished.');
        }
        $owned = null;
    }

    /** Read the control channel without blocking; EOF means the runtime already exited. */
    public static function alive(?array $owned): bool
    {
        if ($owned === null || !is_resource($owned['process'] ?? null) || !is_resource($owned['control'] ?? null)) return false;
        @fread($owned['control'], 8192);
        return !feof($owned['control']) && proc_get_status($owned['process'])['running'];
    }

    /** Kill only the image name derived from this exact private directory; never chrome.exe/php.exe. */
    public static function cleanWindows(string $scope, string $role): void
    {
        if (PHP_OS_FAMILY !== 'Windows') return;
        $scope = realpath($scope) ?: throw new RuntimeException('Runtime scope missing.');
        if (!preg_match('/\A[a-z]{3,16}\z/', $role)) throw new RuntimeException('Invalid runtime role.');
        $name = self::imageName($scope, $role);
        $system = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\';
        $list = self::command([$system . 'tasklist.exe', '/FI', 'IMAGENAME eq ' . $name, '/FO', 'CSV', '/NH']);
        $found = false;
        foreach (preg_split('/\R/', $list['output']) ?: [] as $line) {
            $row = str_getcsv($line, ',', '"', '');
            if (strcasecmp((string) ($row[0] ?? ''), $name) === 0) $found = true;
        }
        if ($list['exit'] === 0 && !$found) return;
        // tasklist can time out during console shutdown. The target is still the exact
        // installation/role identity, so reclaim it and require a subsequent zero-process check.
        self::command([$system . 'taskkill.exe', '/IM', $name, '/T', '/F']);
        $deadline = microtime(true) + 4;
        do {
            $list = self::command([$system . 'tasklist.exe', '/FI', 'IMAGENAME eq ' . $name, '/FO', 'CSV', '/NH']);
            if ($list['exit'] === 0 && stripos($list['output'], '"' . $name . '"') === false) return;
            usleep(100000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Private runtime processes remain after cleanup.');
    }

    /** Standalone guardian entry; commands/secrets are handed off on an authenticated local socket. */
    public static function guard(): int
    {
        $address = (string) getenv('RCMAKER_GUARD_ADDRESS');
        $token = (string) getenv('RCMAKER_GUARD_TOKEN');
        putenv('RCMAKER_GUARD_ADDRESS'); putenv('RCMAKER_GUARD_TOKEN');
        if (!preg_match('/\A127\.0\.0\.1:\d{1,5}\z/', $address)
            || !preg_match('/\A[a-f0-9]{64}\z/', $token)) throw new RuntimeException('Invalid guardian bootstrap.');
        $control = stream_socket_client('tcp://' . $address, $errno, $error, 4);
        if ($control === false) return 1;
        stream_set_timeout($control, 8);
        self::write($control, json_encode(['token' => $token], JSON_THROW_ON_ERROR) . "\n");
        $payload = fgets($control, 1048577);
        $spec = json_decode((string) $payload, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($spec)) throw new RuntimeException('Invalid guardian handoff.');
        $command = $spec['command'];
        $scope = realpath($spec['scope']) ?: throw new RuntimeException('Private scope unavailable.');
        if (!is_array($command) || !self::inside(realpath($command[0]) ?: '', $scope)) throw new RuntimeException('Invalid runtime command.');
        if (!preg_match('/\A[a-z]{3,16}\z/', $spec['role'] ?? '')) throw new RuntimeException('Invalid runtime role.');
        $lock = fopen($scope . '/.' . $spec['role'] . '-owner.lock', 'c');
        if ($lock === false) throw new RuntimeException('Runtime ownership lock unavailable.');
        $deadline = microtime(true) + 6;
        do {
            $locked = flock($lock, LOCK_EX | LOCK_NB);
            if ($locked) break;
            usleep(50000);
        } while (microtime(true) < $deadline);
        if (!$locked) throw new RuntimeException('Runtime is already owned by another worker.');
        if (PHP_OS_FAMILY !== 'Windows') {
            if (!function_exists('posix_setsid') || !function_exists('pcntl_signal') || posix_setsid() < 0) {
                throw new RuntimeException('Runtime guardian requires POSIX and PCNTL on Unix.');
            }
            pcntl_async_signals(true);
            // Caught dispositions reset on exec; children retain the normal SIGTERM behavior.
            pcntl_signal(SIGTERM, static function (): void {});
            pcntl_signal(SIGINT, static function (): void {});
        }
        stream_set_blocking($control, false);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $child = null;
        try {
            @fread($control, 8192);
            if (feof($control)) return 1;
            self::cleanWindows($scope, $spec['role']);
            $child = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $spec['log'], 'a'],
                2 => ['file', $spec['log'], 'a']], $pipes, $scope, $spec['environment'],
                ['bypass_shell' => true]);
            if (!is_resource($child)) throw new RuntimeException('Private runtime launch failed.');
            self::write($control, json_encode(['token' => $token, 'pid' => proc_get_status($child)['pid'],
                'guardian_pid' => getmypid()], JSON_THROW_ON_ERROR) . "\n");
            while (true) {
                $read = [$control]; $write = $except = [];
                @stream_select($read, $write, $except, 0, 200000);
                $message = $read !== [] ? @fread($control, 8192) : '';
                $status = proc_get_status($child);
                if (!$status['running']) {
                    file_put_contents($spec['log'], '[runtime-guard] child exited code=' . $status['exitcode'] . PHP_EOL, FILE_APPEND);
                    break;
                }
                if ($message !== '' || feof($control)) break;
            }
        } finally {
            if (PHP_OS_FAMILY === 'Windows') {
                try {
                    self::cleanWindows($scope, $spec['role']);
                } catch (RuntimeException $error) {
                    // A failed OS inventory must not skip termination of our owned root handle.
                    if (is_resource($child) && proc_get_status($child)['running']) proc_terminate($child);
                    self::cleanWindows($scope, $spec['role']);
                } finally {
                    if (is_resource($child) && proc_get_status($child)['running']) proc_terminate($child);
                    if (is_resource($child)) self::wait($child, 3);
                }
            } else {
                // The guardian is the live session/group leader, so this group cannot belong to another application.
                posix_kill(-posix_getpid(), SIGTERM);
                if (is_resource($child)) self::wait($child, 3);
                fclose($control);
                posix_kill(-posix_getpid(), SIGKILL);
            }
            if (is_resource($control)) fclose($control);
        }
        return 0;
    }

    /** @param resource $process Bounded wait; keep ownership when the process does not exit. */
    private static function wait($process, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (!proc_get_status($process)['running']) { proc_close($process); return true; }
            usleep(30000);
        } while (microtime(true) < $deadline);
        return false;
    }

    /** Run a bounded local OS utility with array argv; no command interpreter or inherited shell profile. */
    private static function command(array $args): array
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $file = tmpfile();
        if ($file === false) throw new RuntimeException('Cannot buffer runtime utility output.');
        $process = proc_open($args, [0 => ['file', $null, 'r'], 1 => $file, 2 => $file], $pipes, null, null,
            ['bypass_shell' => true]);
        if (!is_resource($process)) { fclose($file); throw new RuntimeException('Runtime utility unavailable.'); }
        $deadline = microtime(true) + 5;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(30000);
        } while (microtime(true) < $deadline);
        if ($status['running']) proc_terminate($process);
        $exit = proc_close($process);
        rewind($file); $output = stream_get_contents($file, 1048576); fclose($file);
        return ['exit' => $status['running'] ? -1 : ($status['exitcode'] >= 0 ? $status['exitcode'] : $exit), 'output' => $output];
    }

    /** Compare canonical paths with the host filesystem's case rules. */
    private static function inside(string $path, string $scope): bool
    {
        $path = str_replace('\\', '/', $path); $scope = rtrim(str_replace('\\', '/', $scope), '/') . '/';
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $scope = strtolower($scope); }
        return $path !== '' && str_starts_with($path, $scope);
    }

    /** @param resource $stream Write a complete small local control frame. */
    private static function write($stream, string $bytes): void
    {
        while ($bytes !== '') {
            $length = fwrite($stream, $bytes);
            if ($length === false || $length === 0) throw new RuntimeException('Runtime control channel closed.');
            $bytes = substr($bytes, $length);
        }
    }
}
