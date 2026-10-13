<?php
declare(strict_types=1);
namespace app\gateway\model;

/** Lossless TOML statement editor for Codex routing, without rewriting unrelated settings. */
final class CodexConfigDocument
{
    /** @var list<array{start:int,length:int,text:string,kind:string,path:array,value:string}> */
    private array $statements = [];

    /**
     * Index complete statements, keeping strings, arrays and comments opaque.
     * @param string $text Existing configuration (maximum 2 MiB).
     * @throws GatewayError On an incomplete or unsupported TOML structure.
     */
    public function __construct(private string $text)
    {
        if (strlen($text) > 2097152) $this->invalid();
        $scope = [];
        $start = 0;
        $quote = '';
        $depth = 0;
        $comment = false;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($comment) {
                if ($char !== "\n") continue;
                $comment = false;
            } elseif ($quote !== '') {
                if ($quote[0] === '"' && $char === '\\') { $i++; continue; }
                if (substr($text, $i, strlen($quote)) === $quote) {
                    $i += strlen($quote) - 1;
                    $quote = '';
                } elseif ($char === "\n" && strlen($quote) === 1) $this->invalid();
                continue;
            } elseif ($char === '#') {
                $comment = true;
                continue;
            } elseif ($char === '"' || $char === "'") {
                $quote = substr($text, $i, 3) === str_repeat($char, 3) ? str_repeat($char, 3) : $char;
                $i += strlen($quote) - 1;
                continue;
            } elseif ($char === '[' || $char === '{') $depth++;
            elseif ($char === ']' || $char === '}') {
                if (--$depth < 0) $this->invalid();
            }
            if ($char === "\n" && $depth === 0) {
                $this->index($start, $i + 1, $scope);
                $start = $i + 1;
            }
        }
        if ($quote !== '' || $depth !== 0) $this->invalid();
        if ($start < $length) $this->index($start, $length, $scope);
    }

    /** Return matching assignments, including duplicates so callers cannot mistake them for valid routing. */
    public function assignments(array $path): array
    {
        return array_values(array_filter($this->statements, static fn(array $s) => $s['kind'] === 'assignment' && $s['path'] === $path));
    }

    /** Return table existence without matching text embedded in strings or comments. */
    public function hasTable(array $path): bool
    {
        foreach ($this->statements as $s) if ($s['kind'] === 'table' && $s['path'] === $path) return true;
        return false;
    }

    /** Detect an actual route marker; leftover compression markers do not mean the route is installed. */
    public function hasMarker(string $id): bool
    {
        foreach ($this->statements as $s) {
            if ($s['kind'] === 'comment' && preg_match('/^# RCMAKER (?:BEGIN|END) ' . preg_quote($id, '/') . '$/', trim($s['text']))) return true;
        }
        return false;
    }

    /** Detect both real route comments, accepting legacy, absent or differing identifier suffixes. */
    public function hasIntegrationMarkers(): bool
    {
        $markers = [];
        foreach ($this->statements as $statement) {
            if ($statement['kind'] === 'comment'
                && preg_match('/^# RCMAKER (BEGIN|END)(?:[ \t]+.*)?$/', trim($statement['text']), $match)) {
                $markers[$match[1]] = true;
            }
        }
        return isset($markers['BEGIN'], $markers['END']);
    }

    /**
     * Remove only specified assignments and managed marker comments, including legacy suffixes.
     * @param list<list<string>> $paths Absolute TOML key paths.
     * @return string All other bytes preserved.
     */
    public function without(array $paths, bool $markers = true): string
    {
        $result = '';
        foreach ($this->statements as $s) {
            if ($s['kind'] === 'assignment' && in_array($s['path'], $paths, true)) continue;
            if ($markers && $s['kind'] === 'comment' && preg_match('/^# RCMAKER (?:COMPRESSION )?(?:BEGIN|END)(?:[ \t]+.*)?$/', trim($s['text']))) continue;
            $result .= $s['text'];
        }
        return $result;
    }

    /** Insert already reviewed statements at the start of their root/table scope. */
    public function insert(string $text, array $scope = []): string
    {
        if ($text === '') return $this->text;
        if ($scope === []) return $text . $this->text;
        foreach ($this->statements as $s) {
            if ($s['kind'] !== 'table' || $s['path'] !== $scope) continue;
            $offset = $s['start'] + $s['length'];
            $separator = str_ends_with($s['text'], "\n") ? '' : "\n";
            return substr($this->text, 0, $offset) . $separator . $text . substr($this->text, $offset);
        }
        return rtrim($this->text, "\r\n") . "\n[" . implode('.', $scope) . "]\n" . $text;
    }

    /** Index a complete logical statement and track its TOML table scope. */
    private function index(int $start, int $end, array &$scope): void
    {
        $raw = substr($this->text, $start, $end - $start);
        $line = trim($raw);
        $kind = 'blank'; $path = []; $value = '';
        if (str_starts_with($line, '#')) $kind = 'comment';
        elseif ($line !== '') {
            $keyPart = '(?:[A-Za-z0-9_-]+|"(?:[^"\\\\\r\n]|\\\\.)*"|\'[^\'\r\n]*\')';
            $key = $keyPart . '(?:[ \t]*\.[ \t]*' . $keyPart . ')*';
            if (preg_match('/^\[[ \t]*(' . $key . ')[ \t]*\][ \t]*(?:#[^\r\n]*)?$/s', $line, $match)) {
                $kind = 'table'; $path = $scope = $this->keyPath($match[1]);
            } elseif (preg_match('/^\[\[[ \t]*(' . $key . ')[ \t]*\]\][ \t]*(?:#[^\r\n]*)?$/s', $line, $match)) {
                $kind = 'table-array'; $scope = array_merge($this->keyPath($match[1]), ['[]']);
            } elseif (preg_match('/^(' . $key . ')[ \t]*=[ \t]*(.*)$/s', $line, $match)) {
                $kind = 'assignment'; $path = array_merge($scope, $this->keyPath($match[1])); $value = trim($match[2]);
            } else $this->invalid();
        }
        $this->statements[] = ['start' => $start, 'length' => $end - $start, 'text' => $raw,
            'kind' => $kind, 'path' => $path, 'value' => $value, 'scope' => $scope];
    }

    /** Decode bare/quoted key components; a quoted dot remains part of that key. */
    private function keyPath(string $key): array
    {
        preg_match_all('/[A-Za-z0-9_-]+|"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'/', $key, $matches);
        return array_map(function (string $part): string {
            if ($part[0] === "'") return substr($part, 1, -1);
            if ($part[0] !== '"') return $part;
            $value = json_decode($part, true);
            if (!is_string($value)) $this->invalid();
            return $value;
        }, $matches[0]);
    }

    /** Stop before any file write if the document cannot be edited unambiguously. */
    private function invalid(): never
    {
        throw new GatewayError('codex_config_complex', 'Codex 配置格式无法识别，请先修正未闭合的字符串或配置节，再重新预览。', 422);
    }
}
