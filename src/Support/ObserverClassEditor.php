<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

use Dakotajune\BlueprintObservers\Support\Concerns\ReadsTokens;
use PhpToken;

/**
 * Edits an observer file in place under one strict rule:
 *
 *   The generator owns exactly the methods tagged `@generated blueprint_observers` and the
 *   `ShouldHandleEventsAfterCommit` entry in the class header. Nothing else is ever modified or removed.
 *
 * On update():
 *  - tagged method for a wanted event  -> rewritten in place
 *  - tagged method for an unwanted one -> deleted (the generator owns it)
 *  - missing method                    -> appended after the generated ones
 *  - untagged method with an event name -> developer's; left alone and reported as a notice
 *  - imports are only ever added; developer methods, properties, traits and comments are untouched
 *
 * Remove the tag from a method and it becomes the developer's.
 */
final class ObserverClassEditor
{
    use ReadsTokens;

    public const MARKER = '@generated blueprint_observers';

    public const INTERFACE_FQCN = 'Illuminate\\Contracts\\Events\\ShouldHandleEventsAfterCommit';

    public const MODEL_PLACEHOLDER = '{{ model }}';

    private const MARKER_PATTERN = '/@generated\s+blueprint_observers\b/';

    /**
     * @param  array<string, string>  $methods  event => method source from ObserverMethodBuilder (all wanted methods)
     * @param  array<int, string>  $imports  extra class names the method bodies need imported (add-only)
     */
    public function update(
        string $source,
        string $class,
        string $modelFqcn,
        bool $afterCommit,
        array $methods,
        array $imports = [],
    ): EditResult {
        $modelFqcn = ltrim($modelFqcn, '\\');
        $tokens = PhpToken::tokenize($source);

        $classIdx = $this->findClass($tokens, $class);
        $scan = $classIdx === null ? null : $this->scan($tokens, $source, $classIdx);

        if ($classIdx === null || $scan === null) {
            return new EditResult($source, false, ["Class [{$class}] was not found in the observer file; it was left untouched."]);
        }

        $notices = [];
        $edits = [];
        $namespace = $this->namespaceOf($source);
        $known = $this->imports($tokens, $classIdx);   // alias => fqcn
        $newImports = [];

        // -- Resolve names (add-only imports) ---------------------------------------------------
        $modelRef = $this->resolve($modelFqcn, $namespace, $known, $newImports);
        $interfaceRef = $afterCommit ? $this->resolve(self::INTERFACE_FQCN, $namespace, $known, $newImports) : null;

        foreach ($imports as $import) {
            $import = ltrim($import, '\\');
            $short = $this->shortName($import);

            if (in_array($import, $known, true) || $this->namespaceName($import) === $namespace) {
                continue;
            }

            if (isset($known[$short])) {
                $notices[] = "{$class}: a snippet needs [{$import}] but [{$short}] is already imported as [{$known[$short]}]; "
                    . 'the import was skipped, so check the generated code.';

                continue;
            }

            $known[$short] = $import;
            $newImports[] = $import;
        }

        // -- Class header: only our own interface -------------------------------------------------
        $headerEdit = $this->headerEdit($tokens, $source, $classIdx, $scan['open'], $afterCommit, $interfaceRef);
        if ($headerEdit !== null) {
            $edits[] = $headerEdit;
        }

        // -- Methods ---------------------------------------------------------------------------------
        $wanted = [];
        foreach ($methods as $event => $text) {
            $wanted[strtolower($event)] = [$event, str_replace(self::MODEL_PLACEHOLDER, $modelRef, $text)];
        }

        $handled = [];
        $deletions = [];
        $remaining = [];
        $lastGenerated = null;

        foreach ($scan['methods'] as $member) {
            $key = strtolower($member['name']);

            if ($member['generated']) {
                if (isset($wanted[$key])) {
                    $edits[] = [$member['start'], $member['end'] - $member['start'], $wanted[$key][1]];
                    $handled[$key] = true;
                    $remaining[] = $member;
                    $lastGenerated = $member;
                } else {
                    $deletions[] = $this->deletionRange($source, $member['start'], $member['end']);
                }

                continue;
            }

            if (isset($wanted[$key])) {
                $handled[$key] = true;
                $notices[] = "{$class}::{$member['name']}() already exists without the " . self::MARKER
                    . ' tag, so it belongs to you and was left untouched.';
            }

            $remaining[] = $member;
        }

        foreach ($this->mergeRanges($deletions) as [$from, $to]) {
            $edits[] = [$from, $to - $from, ''];
        }

        $missing = [];
        foreach ($wanted as $key => [$event, $text]) {
            if (! isset($handled[$key])) {
                $missing[] = $text;
            }
        }

        if ($missing !== []) {
            $edits[] = $this->insertionEdit($source, $scan, $remaining, $lastGenerated, $missing);
        }

        // -- Imports ------------------------------------------------------------------------------------
        if ($newImports !== []) {
            sort($newImports);
            $edits[] = $this->importEdit($tokens, $classIdx, array_values(array_unique($newImports)));
        }

        $result = $this->apply($source, $edits);

        return new EditResult($result, $result !== $source, $notices);
    }

    /** Removes every generated method; developer members, header and imports stay exactly as they are. */
    public function stripGenerated(string $source, string $class): string
    {
        $tokens = PhpToken::tokenize($source);
        $classIdx = $this->findClass($tokens, $class);
        $scan = $classIdx === null ? null : $this->scan($tokens, $source, $classIdx);

        if ($scan === null) {
            return $source;
        }

        $ranges = [];
        foreach ($scan['methods'] as $member) {
            if ($member['generated']) {
                $ranges[] = $this->deletionRange($source, $member['start'], $member['end']);
            }
        }

        $edits = array_map(
            static fn (array $r): array => [$r[0], $r[1] - $r[0], ''],
            $this->mergeRanges($ranges),
        );

        return $this->apply($source, $edits);
    }

    /** True when the class body holds anything other than generated methods. */
    public function hasDeveloperContent(string $source, string $class): bool
    {
        $stripped = $this->stripGenerated($source, $class);
        $tokens = PhpToken::tokenize($stripped);
        $classIdx = $this->findClass($tokens, $class);
        $scan = $classIdx === null ? null : $this->scan($tokens, $stripped, $classIdx);

        if ($scan === null) {
            return false;
        }

        $from = $tokens[$scan['open']]->pos + 1;
        $to = $tokens[$scan['close']]->pos;

        return trim(substr($stripped, $from, $to - $from)) !== '';
    }

    // =====================================================================================================
    // Scanning
    // =====================================================================================================

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{open:int, close:int, methods: array<int, array{name:string,start:int,end:int,generated:bool}>}|null
     */
    private function scan(array $tokens, string $source, int $classIdx): ?array
    {
        $open = null;
        for ($i = $classIdx, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]->text === '{') {
                $open = $i;
                break;
            }
        }

        $close = $open === null ? null : $this->matchBrace($tokens, $open);
        if ($open === null || $close === null) {
            return null;
        }

        $methods = [];
        $depth = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '{' || $text === '${') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            } elseif ($depth === 0 && $tokens[$i]->is(T_FUNCTION)) {
                $member = $this->member($tokens, $source, $i, $close);
                if ($member !== null) {
                    $methods[] = $member;
                    $i = $member['endIdx'];
                }
            }
        }

        return ['open' => $open, 'close' => $close, 'methods' => $methods];
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{name:string,start:int,end:int,generated:bool,endIdx:int}|null
     */
    private function member(array $tokens, string $source, int $functionIdx, int $classClose): ?array
    {
        $nameIdx = $this->nextSignificant($tokens, $functionIdx);
        if ($nameIdx !== null && $tokens[$nameIdx]->text === '&') {
            $nameIdx = $this->nextSignificant($tokens, $nameIdx);
        }

        $parenOpen = $nameIdx === null ? null : $this->nextSignificant($tokens, $nameIdx);
        if ($nameIdx === null || $parenOpen === null || $tokens[$parenOpen]->text !== '(') {
            return null;                                   // closure / arrow fn, not a method
        }

        $parenClose = $this->matchParenTokens($tokens, $parenOpen);
        if ($parenClose === null) {
            return null;
        }

        $endIdx = null;
        for ($k = $parenClose + 1; $k < $classClose; $k++) {
            if ($tokens[$k]->text === '{') {
                $endIdx = $this->matchBrace($tokens, $k);
                break;
            }
            if ($tokens[$k]->text === ';') {
                $endIdx = $k;
                break;
            }
        }
        if ($endIdx === null) {
            return null;
        }

        // Walk back over modifiers, attributes and docblocks to find where the member really starts.
        $start = $functionIdx;
        while (($p = $this->prevSignificant($tokens, $start)) !== null
            && $tokens[$p]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT])) {
            $start = $p;
        }

        $docs = '';
        while (($p = $this->prevNonWhitespace($tokens, $start)) !== null) {
            if ($tokens[$p]->is(T_DOC_COMMENT)) {
                $docs .= $tokens[$p]->text;
                $start = $p;
            } elseif ($tokens[$p]->text === ']' && ($open = $this->attributeOpenFor($tokens, $p)) !== null) {
                $start = $open;
            } else {
                break;
            }
        }

        $pos = $tokens[$start]->pos;
        $lineStart = strrpos(substr($source, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        if (trim(substr($source, $lineStart, $pos - $lineStart)) === '') {
            $pos = $lineStart;
        }

        return [
            'name' => $tokens[$nameIdx]->text,
            'start' => $pos,
            'end' => $tokens[$endIdx]->pos + strlen($tokens[$endIdx]->text),
            'generated' => (bool) preg_match(self::MARKER_PATTERN, $docs),
            'endIdx' => $endIdx,
        ];
    }

    // =====================================================================================================
    // Edit planning
    // =====================================================================================================

    /**
     * @param  array<string, string>  $known  alias => fqcn (updated in place when an import is added)
     * @param  array<int, string>  $newImports
     */
    private function resolve(string $fqcn, ?string $namespace, array &$known, array &$newImports): string
    {
        $short = $this->shortName($fqcn);

        if ($this->namespaceName($fqcn) === $namespace) {
            return $short;
        }

        if (($known[$short] ?? null) === $fqcn) {
            return $short;
        }

        $alias = array_search($fqcn, $known, true);
        if ($alias !== false) {
            return (string) $alias;
        }

        if (isset($known[$short])) {
            return '\\' . $fqcn;                          // short name taken by a different class
        }

        $known[$short] = $fqcn;
        $newImports[] = $fqcn;

        return $short;
    }

    /**
     * Adds / removes only `ShouldHandleEventsAfterCommit` in the header.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array{0:int,1:int,2:string}|null
     */
    private function headerEdit(array $tokens, string $source, int $classIdx, int $openIdx, bool $afterCommit, ?string $interfaceRef): ?array
    {
        $nameIdx = $this->nextSignificant($tokens, $classIdx);
        if ($nameIdx === null) {
            return null;
        }

        $has = false;
        $hasImplements = false;
        $lastSignificant = $nameIdx;

        for ($i = $nameIdx + 1; $i < $openIdx; $i++) {
            if ($tokens[$i]->isIgnorable()) {
                continue;
            }
            $lastSignificant = $i;
            if ($tokens[$i]->is(T_IMPLEMENTS)) {
                $hasImplements = true;
            }
            if ($tokens[$i]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
                && $this->shortName($tokens[$i]->text) === 'ShouldHandleEventsAfterCommit') {
                $has = true;
            }
        }

        if ($afterCommit && ! $has) {
            $at = $tokens[$lastSignificant]->pos + strlen($tokens[$lastSignificant]->text);

            return [$at, 0, ($hasImplements ? ', ' : ' implements ') . $interfaceRef];
        }

        if (! $afterCommit && $has) {
            $from = $tokens[$nameIdx]->pos + strlen($tokens[$nameIdx]->text);
            $to = $tokens[$openIdx]->pos;
            $header = substr($source, $from, $to - $from);
            $name = '(?:\\\\?(?:[A-Za-z_]\w*\\\\)*)?ShouldHandleEventsAfterCommit';

            $new = preg_replace('/\s+implements\s+' . $name . '(?=\s*\z)/', '', $header, 1, $count);
            if (! $count) {
                $new = preg_replace('/\s*,\s*' . $name . '/', '', $header, 1, $count);
            }
            if (! $count) {
                $new = preg_replace('/(implements\s+)' . $name . '\s*,\s*/', '$1', $header, 1);
            }

            return $new === null || $new === $header ? null : [$from, $to - $from, $new];
        }

        return null;
    }

    /**
     * @param  array{open:int, close:int, methods: array}  $scan
     * @param  array<int, array{start:int,end:int}>  $remaining
     * @param  array<int, string>  $texts
     * @return array{0:int,1:int,2:string}
     */
    private function insertionEdit(string $source, array $scan, array $remaining, ?array $lastGenerated, array $texts): array
    {
        $tokens = PhpToken::tokenize($source);
        $joined = implode("\n\n", $texts);
        $anchor = $lastGenerated ?? ($remaining === [] ? null : end($remaining));

        if ($anchor !== null) {
            return [$anchor['end'], 0, "\n\n" . $joined];
        }

        $open = $tokens[$scan['open']]->pos;
        $close = $tokens[$scan['close']]->pos;
        $body = substr($source, $open + 1, $close - $open - 1);

        if (trim($body) === '') {
            return [$open + 1, strlen($body), "\n" . $joined . "\n"];
        }

        // Developer properties / constants only: append after the last of them.
        return [$open + 1 + strlen(rtrim($body)), 0, "\n\n" . $joined];
    }

    /**
     * Whole lines of a member plus the surrounding blank line, so no double blank lines are left behind.
     *
     * @return array{0:int,1:int}
     */
    private function deletionRange(string $source, int $start, int $end): array
    {
        if (($source[$end] ?? '') === "\n") {
            $end++;
        }

        if ($start >= 2 && substr($source, $start - 2, 2) === "\n\n") {
            $start--;
        } elseif ($start >= 2 && substr($source, $start - 2, 2) === "{\n" && ($source[$end] ?? '') === "\n") {
            $end++;
        }

        return [$start, $end];
    }

    /**
     * @param  array<int, array{0:int,1:int}>  $ranges
     * @return array<int, array{0:int,1:int}>
     */
    private function mergeRanges(array $ranges): array
    {
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($ranges as $range) {
            $last = count($merged) - 1;
            if ($last >= 0 && $range[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }
}
