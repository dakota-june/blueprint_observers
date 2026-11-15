<?php

declare(strict_types=1);

namespace Dakotajune\Blueclues\Support;

use PhpToken;
use RuntimeException;

/**
 * Adds / removes `#[ObservedBy(...)]` on an Eloquent model source string.
 *
 * Token based (no blind regex over the whole file) so it copes with:
 *  - final / abstract / readonly modifiers
 *  - docblocks and other attributes already on the class
 *  - an existing #[ObservedBy(...)] (single class or array) which is merged, never duplicated
 *  - existing imports, aliases and short-name collisions
 *  - being run repeatedly (idempotent)
 *
 * Pure PHP: no framework dependency, so it can be unit tested in isolation.
 */
final class ObservedByInjector
{
    public const ATTRIBUTE_FQCN = 'Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy';

    public function inject(string $source, string $modelClass, string $observerFqcn): InjectionResult
    {
        $observerFqcn = ltrim($observerFqcn, '\\');
        $tokens = PhpToken::tokenize($source);

        $classIdx = $this->findClass($tokens, $modelClass);
        if ($classIdx === null) {
            return new InjectionResult($source, InjectionResult::CLASS_NOT_FOUND);
        }

        $declStart = $this->declarationStart($tokens, $classIdx);
        $namespace = $this->namespaceOf($source);
        $imports = $this->imports($tokens, $classIdx);          // alias => fqcn
        $groups = $this->attributeGroups($tokens, $declStart);

        $edits = [];           // [offset, length, text]
        $addedImports = [];

        // --- Resolve how the observer is referenced -------------------------------------------
        $observerShort = $this->shortName($observerFqcn);
        $observerNamespace = $this->namespaceName($observerFqcn);

        if ($namespace !== null && $observerNamespace === $namespace) {
            $observerRef = $observerShort;
        } elseif (($imports[$observerShort] ?? null) === $observerFqcn) {
            $observerRef = $observerShort;
        } elseif (isset($imports[$observerShort]) || $observerShort === $modelClass) {
            $observerRef = '\\' . $observerFqcn;
        } else {
            $observerRef = $observerShort;
            $addedImports[] = $observerFqcn;
            $imports[$observerShort] = $observerFqcn;
        }

        // --- Resolve how the attribute is referenced --------------------------------------------
        $attributeAlias = array_search(self::ATTRIBUTE_FQCN, $imports, true);
        if ($attributeAlias !== false) {
            $attributeRef = (string) $attributeAlias;
        } elseif (isset($imports['ObservedBy'])) {
            $attributeRef = '\\' . self::ATTRIBUTE_FQCN;
        } else {
            $attributeRef = 'ObservedBy';
            $addedImports[] = self::ATTRIBUTE_FQCN;
        }

        // --- Class level edit ---------------------------------------------------------------------
        $existing = $this->findObservedByGroup($source, $tokens, $groups, $this->attributeNames($imports));
        $status = InjectionResult::INSERTED;

        if ($existing !== null) {
            [$start, $length, $groupText, $argsStart, $argsLength, $refs] = $existing;

            foreach ($refs as $ref) {
                if ($this->refMatches($ref, $observerFqcn, $observerShort)) {
                    return new InjectionResult($source, InjectionResult::UNCHANGED);
                }
            }

            $refs[] = $observerRef . '::class';
            $newArgs = '[' . implode(', ', $refs) . ']';
            $edits[] = [$argsStart, $argsLength, $newArgs];
            $status = InjectionResult::MERGED;
            // An existing attribute means the ObservedBy import necessarily exists in some form already.
            $addedImports = array_values(array_diff($addedImports, [self::ATTRIBUTE_FQCN]));
        } else {
            $pos = $tokens[$declStart]->pos;
            $indent = $this->lineIndent($source, $pos);
            $edits[] = [$pos, 0, "#[{$attributeRef}({$observerRef}::class)]\n{$indent}"];
        }

        // --- Import edit ----------------------------------------------------------------------------
        if ($addedImports !== []) {
            $edits[] = $this->importEdit($tokens, $classIdx, $addedImports);
        }

        return new InjectionResult($this->apply($source, $edits), $status, $addedImports);
    }

    /**
     * Reverse of inject(): drops the observer from #[ObservedBy], deletes the attribute if it becomes
     * empty, and removes the imports that inject() added when nothing else in the file uses them.
     *
     * @param  array<int, string>  $addedImports
     */
    public function remove(string $source, string $modelClass, string $observerFqcn, array $addedImports = []): string
    {
        $observerFqcn = ltrim($observerFqcn, '\\');
        $observerShort = $this->shortName($observerFqcn);
        $tokens = PhpToken::tokenize($source);

        $classIdx = $this->findClass($tokens, $modelClass);
        if ($classIdx === null) {
            return $source;
        }

        $declStart = $this->declarationStart($tokens, $classIdx);
        $groups = $this->attributeGroups($tokens, $declStart);
        $names = $this->attributeNames($this->imports($tokens, $classIdx));
        $existing = $this->findObservedByGroup($source, $tokens, $groups, $names);

        if ($existing === null) {
            return $source;
        }

        [$start, $length, $groupText, $argsStart, $argsLength, $refs] = $existing;

        $remaining = array_values(array_filter(
            $refs,
            fn (string $ref): bool => ! $this->refMatches($ref, $observerFqcn, $observerShort),
        ));

        if (count($remaining) === count($refs)) {
            return $source;
        }

        if ($remaining === []) {
            if (! $this->isPureObservedByGroup($groupText, $names)) {
                return $source; // shares a group with other attributes; leave for a human
            }

            // Remove the group and the whitespace that follows it.
            $end = $start + $length;
            while (isset($source[$end]) && ctype_space($source[$end])) {
                $end++;
                if ($source[$end - 1] === "\n") {
                    break;
                }
            }
            $source = substr_replace($source, '', $start, $end - $start);
        } else {
            $newArgs = count($remaining) === 1 ? $remaining[0] : '[' . implode(', ', $remaining) . ']';
            $source = substr_replace($source, $newArgs, $argsStart, $argsLength);
        }

        foreach ($addedImports as $import) {
            $source = $this->removeImportIfUnused($source, ltrim($import, '\\'));
        }

        return $source;
    }

    // =====================================================================================================
    // Token helpers
    // =====================================================================================================

    /** @param  array<int, PhpToken>  $tokens */
    private function findClass(array $tokens, string $name): ?int
    {
        foreach ($tokens as $i => $token) {
            if (! $token->is(T_CLASS)) {
                continue;
            }

            $prev = $this->prevSignificant($tokens, $i);
            if ($prev !== null && $tokens[$prev]->is([T_DOUBLE_COLON, T_NEW])) {
                continue; // Foo::class or `new class`
            }

            $next = $this->nextSignificant($tokens, $i);
            if ($next !== null && $tokens[$next]->is(T_STRING) && $tokens[$next]->text === $name) {
                return $i;
            }
        }

        return null;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private function declarationStart(array $tokens, int $classIdx): int
    {
        $start = $classIdx;
        while (($prev = $this->prevSignificant($tokens, $start)) !== null
            && $tokens[$prev]->is([T_FINAL, T_ABSTRACT, T_READONLY])) {
            $start = $prev;
        }

        return $start;
    }

    /**
     * Contiguous `#[...]` groups directly above the declaration, in source order.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, array{0:int,1:int}>  [openIdx, closeIdx]
     */
    private function attributeGroups(array $tokens, int $declStart): array
    {
        $groups = [];
        $i = $this->prevSignificant($tokens, $declStart);

        while ($i !== null && $tokens[$i]->text === ']') {
            $depth = 0;
            $k = $i;
            for (; $k >= 0; $k--) {
                $t = $tokens[$k];
                if ($t->text === ']') {
                    $depth++;
                } elseif ($t->is(T_ATTRIBUTE) || $t->text === '[') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }

            if ($k < 0 || ! $tokens[$k]->is(T_ATTRIBUTE)) {
                break;
            }

            array_unshift($groups, [$k, $i]);
            $i = $this->prevSignificant($tokens, $k);
        }

        return $groups;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @param  array<int, array{0:int,1:int}>  $groups
     * @param  array<int, string>  $names  Names the attribute may be written as (ObservedBy, import aliases).
     * @return array{0:int,1:int,2:string,3:int,4:int,5:array<int,string>}|null
     *         [groupStart, groupLength, groupText, argsStart, argsLength, refs]
     */
    private function findObservedByGroup(string $source, array $tokens, array $groups, array $names): ?array
    {
        foreach ($groups as [$open, $close]) {
            $start = $tokens[$open]->pos;
            $length = $tokens[$close]->pos + 1 - $start;
            $text = substr($source, $start, $length);

            if (! preg_match('/(?<![\w\\\\])(?:\\\\?(?:[A-Za-z_]\w*\\\\)*)?(?:' . $this->namePattern($names) . ')\s*\(/', $text, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $parenOpen = $m[0][1] + strlen($m[0][0]) - 1;
            $parenClose = $this->matchingParen($text, $parenOpen);
            if ($parenClose === null) {
                continue;
            }

            $args = substr($text, $parenOpen + 1, $parenClose - $parenOpen - 1);
            $trimmed = trim($args);
            $leading = strlen($args) - strlen(ltrim($args));

            $argsStart = $start + $parenOpen + 1 + $leading;
            $argsLength = strlen($trimmed);

            $inner = $trimmed;
            if (str_starts_with($inner, '[') && str_ends_with($inner, ']')) {
                $inner = substr($inner, 1, -1);
            }

            $refs = array_values(array_filter(
                array_map('trim', explode(',', $inner)),
                static fn (string $r): bool => $r !== '',
            ));

            return [$start, $length, $text, $argsStart, $argsLength, $refs];
        }

        return null;
    }

    /** @param  array<int, string>  $names */
    private function isPureObservedByGroup(string $groupText, array $names): bool
    {
        return (bool) preg_match(
            '/^#\[\s*(?:\\\\?(?:[A-Za-z_]\w*\\\\)*)?(?:' . $this->namePattern($names) . ')\s*\(.*\)\s*\]$/s',
            $groupText,
        );
    }

    /**
     * @param  array<string, string>  $imports  alias => fqcn
     * @return array<int, string>
     */
    private function attributeNames(array $imports): array
    {
        $names = ['ObservedBy'];
        foreach ($imports as $alias => $fqcn) {
            if ($fqcn === self::ATTRIBUTE_FQCN) {
                $names[] = (string) $alias;
            }
        }

        return array_values(array_unique($names));
    }

    /** @param  array<int, string>  $names */
    private function namePattern(array $names): string
    {
        return implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $names));
    }

    private function refMatches(string $ref, string $fqcn, string $short): bool
    {
        if (! preg_match('/^(\\\\?[\w\\\\]+)::class$/', $ref, $m)) {
            return false;
        }

        $name = ltrim($m[1], '\\');

        return $name === $fqcn || $name === $short;
    }

    private function matchingParen(string $text, int $open): ?int
    {
        $depth = 0;
        for ($i = $open, $n = strlen($text); $i < $n; $i++) {
            if ($text[$i] === '(') {
                $depth++;
            } elseif ($text[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    // =====================================================================================================
    // Imports
    // =====================================================================================================

    /**
     * Top-level `use Foo\Bar [as Baz];` statements before the class.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array<string, string>  alias => fqcn
     */
    private function imports(array $tokens, int $classIdx): array
    {
        $map = [];

        foreach ($this->importStatements($tokens, $classIdx) as [$from, $to]) {
            $text = '';
            for ($i = $from + 1; $i < $to; $i++) {
                $text .= $tokens[$i]->text;
            }
            $text = trim($text);

            if (preg_match('/^(function|const)\s/i', $text) || str_contains($text, '{')) {
                continue; // function/const/group imports are not tracked
            }

            foreach (explode(',', $text) as $part) {
                if (preg_match('/^\s*\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*$/i', $part, $m)) {
                    $fqcn = $m[1];
                    $alias = $m[2] ?? $this->shortName($fqcn);
                    $map[$alias] = $fqcn;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array<int, array{0:int,1:int}>  [useIdx, semicolonIdx]
     */
    private function importStatements(array $tokens, int $classIdx): array
    {
        $statements = [];

        for ($i = 0; $i < $classIdx; $i++) {
            if (! $tokens[$i]->is(T_USE)) {
                continue;
            }
            for ($j = $i + 1; $j < $classIdx; $j++) {
                if ($tokens[$j]->text === ';') {
                    $statements[] = [$i, $j];
                    $i = $j;
                    break;
                }
            }
        }

        return $statements;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @param  array<int, string>  $imports
     * @return array{0:int,1:int,2:string}
     */
    private function importEdit(array $tokens, int $classIdx, array $imports): array
    {
        $lines = array_map(static fn (string $fqcn): string => "use {$fqcn};", $imports);
        $statements = $this->importStatements($tokens, $classIdx);

        if ($statements !== []) {
            $last = end($statements);
            $offset = $tokens[$last[1]]->pos + 1;

            return [$offset, 0, "\n" . implode("\n", $lines)];
        }

        for ($i = 0; $i < $classIdx; $i++) {
            if ($tokens[$i]->is(T_NAMESPACE)) {
                for ($j = $i + 1; $j < $classIdx; $j++) {
                    if ($tokens[$j]->text === ';') {
                        return [$tokens[$j]->pos + 1, 0, "\n\n" . implode("\n", $lines)];
                    }
                }
            }
        }

        throw new RuntimeException('Cannot add imports: no namespace statement found in the model file.');
    }

    private function removeImportIfUnused(string $source, string $fqcn): string
    {
        $tokens = PhpToken::tokenize($source);
        $classIdx = null;
        foreach ($tokens as $i => $t) {
            if ($t->is(T_CLASS) && ($p = $this->prevSignificant($tokens, $i)) !== null
                && ! $tokens[$p]->is([T_DOUBLE_COLON, T_NEW])) {
                $classIdx = $i;
                break;
            }
        }
        if ($classIdx === null) {
            return $source;
        }

        $short = $this->shortName($fqcn);
        $target = null;
        $importRanges = [];

        foreach ($this->importStatements($tokens, $classIdx) as [$from, $to]) {
            $importRanges[] = [$from, $to];
            $text = '';
            for ($i = $from + 1; $i < $to; $i++) {
                $text .= $tokens[$i]->text;
            }
            if (preg_match('/^\s*\\\\?' . preg_quote($fqcn, '/') . '\s*$/', $text)) {
                $target = [$from, $to];
            }
        }

        if ($target === null) {
            return $source;
        }

        // Still referenced anywhere outside the import block?
        foreach ($tokens as $i => $t) {
            foreach ($importRanges as [$from, $to]) {
                if ($i >= $from && $i <= $to) {
                    continue 2;
                }
            }
            if ($t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $t->text === $short) {
                return $source;
            }
        }

        $start = $tokens[$target[0]]->pos;
        $end = $tokens[$target[1]]->pos + 1;
        if (isset($source[$end]) && $source[$end] === "\n") {
            $end++;
        }

        $result = substr_replace($source, '', $start, $end - $start);

        // Don't leave a double blank line behind.
        if ($start >= 2 && substr($result, $start - 2, 2) === "\n\n" && ($result[$start] ?? '') === "\n") {
            $result = substr_replace($result, '', $start, 1);
        }

        return $result;
    }

    // =====================================================================================================
    // Small utilities
    // =====================================================================================================

    /** @param  array<int, PhpToken>  $tokens */
    private function prevSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (! $tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from + 1, $n = count($tokens); $i < $n; $i++) {
            if (! $tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    private function namespaceOf(string $source): ?string
    {
        return preg_match('/^\s*namespace\s+([^;{\s]+)/m', $source, $m) ? $m[1] : null;
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private function namespaceName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? '' : substr($fqcn, 0, $pos);
    }

    private function lineIndent(string $source, int $pos): string
    {
        $lineStart = strrpos(substr($source, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $prefix = substr($source, $lineStart, $pos - $lineStart);

        return trim($prefix) === '' ? $prefix : '';
    }

    /** @param  array<int, array{0:int,1:int,2:string}>  $edits */
    private function apply(string $source, array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($edits as [$offset, $length, $text]) {
            $source = substr_replace($source, $text, $offset, $length);
        }

        return $source;
    }
}
