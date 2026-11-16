<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Support;

use Dakotajune\BlueprintObservers\Support\Concerns\ReadsTokens;
use PhpToken;

/**
 * Edits the Nova service provider in place under one strict rule:
 *
 *   The generator owns exactly one method, `blueprintObservers()`, while it carries the
 *   `@generated blueprint_observers` tag, plus one statement in boot(): `$this->blueprintObservers();`.
 *   Everything else in the provider is the developer's and is never modified or removed.
 *
 * On update():
 *  - tagged blueprintObservers()      -> rewritten in place with the observers of this build
 *  - missing blueprintObservers()     -> added right after boot()
 *  - untagged blueprintObservers()    -> developer's; left alone and reported as a notice
 *  - boot() without the call          -> `$this->blueprintObservers();` appended as its last statement
 *  - no boot()                        -> one is added (calling parent::boot() when the class extends something)
 *  - imports are only ever added
 *
 * strip() is the reverse, used when the observers leave the draft and on `blueprint:erase`: it deletes the
 * tagged method and the call to it, and the imports this editor added when nothing else uses them.
 */
final class NovaProviderEditor
{
    use ReadsTokens;

    public const METHOD = 'blueprintObservers';

    public const OBSERVABLE_FQCN = 'Laravel\\Nova\\Observable';

    private const MARKER_PATTERN = '/@generated\s+blueprint_observers\b/';

    /**
     * @param  array<int, array{model:string, observer:string}>  $registrations  fully qualified class names
     */
    public function update(string $source, string $class, array $registrations): ProviderEdit
    {
        $tokens = PhpToken::tokenize($source);
        $classIdx = $this->findClass($tokens, $class);
        $layout = $classIdx === null ? null : $this->layout($tokens, $source, $classIdx);

        if ($classIdx === null || $layout === null) {
            return new ProviderEdit($source, false, ["Class [{$class}] was not found in the Nova provider; no observers were registered."]);
        }

        $notices = [];
        $edits = [];
        $texts = [];
        $namespace = $this->namespaceOf($source);
        $known = $this->imports($tokens, $classIdx);   // alias => fqcn
        $newImports = [];

        $existing = $this->methodNamed($layout['methods'], self::METHOD);
        $boot = $this->methodNamed($layout['methods'], 'boot');

        // -- blueprintObservers() ------------------------------------------------------------------
        if ($existing !== null && ! $existing['generated']) {
            $notices[] = "{$class}::" . self::METHOD . '() already exists without the ' . ObserverClassEditor::MARKER
                . ' tag, so it belongs to you and was left untouched.';
        } else {
            $text = $this->methodText($registrations, $namespace, $known, $newImports);

            if ($existing !== null) {
                $edits[] = [$existing['start'], $existing['end'] - $existing['start'], $text];
            } else {
                $texts[] = $text;
            }
        }

        // -- the call in boot() ------------------------------------------------------------------------
        if ($boot === null) {
            array_unshift($texts, $this->bootText($tokens, $classIdx, $layout['open']));
        } elseif ($boot['bodyOpen'] !== null && $boot['bodyClose'] !== null
            && $this->callRanges($tokens, $source, $boot) === []) {
            $edits[] = $this->callEdit($tokens, $source, $boot);
        }

        if ($texts !== []) {
            $anchor = $boot ?? ($layout['methods'] === [] ? null : end($layout['methods']));
            $edits[] = $this->insertionEdit($tokens, $source, $layout, $anchor, implode("\n\n", $texts));
        }

        // -- imports ------------------------------------------------------------------------------------
        $added = [];
        if ($newImports !== []) {
            $added = array_values(array_unique($newImports));
            sort($added);
            $edits[] = $this->importEdit($tokens, $classIdx, $added);
        }

        $result = $this->apply($source, $edits);

        return new ProviderEdit($result, $result !== $source, $notices, $added);
    }

    /**
     * Removes the generated method, the call to it, and the given imports when nothing else uses them.
     * A provider whose blueprintObservers() is untagged (the developer's) is returned unchanged.
     *
     * @param  array<int, string>  $addedImports
     */
    public function strip(string $source, string $class, array $addedImports = []): string
    {
        $tokens = PhpToken::tokenize($source);
        $classIdx = $this->findClass($tokens, $class);
        $layout = $classIdx === null ? null : $this->layout($tokens, $source, $classIdx);

        if ($layout === null) {
            return $source;
        }

        $method = $this->methodNamed($layout['methods'], self::METHOD);

        if ($method === null || ! $method['generated']) {
            return $source;
        }

        [$from, $to] = $this->deletionRange($source, $method['start'], $method['end']);
        $edits = [[$from, $to - $from, '']];

        $boot = $this->methodNamed($layout['methods'], 'boot');

        if ($boot !== null && $boot['bodyOpen'] !== null && $boot['bodyClose'] !== null) {
            foreach ($this->callRanges($tokens, $source, $boot) as [$start, $end]) {
                $edits[] = [$start, $end - $start, ''];
            }
        }

        $result = $this->apply($source, $edits);

        foreach ($addedImports as $import) {
            $result = $this->removeImportIfUnused($result, ltrim($import, '\\'));
        }

        return $result;
    }

    // =====================================================================================================
    // Layout
    // =====================================================================================================

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{open:int, close:int, methods: array<int, array<string, mixed>>}|null
     */
    private function layout(array $tokens, string $source, int $classIdx): ?array
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
     * @return array<string, mixed>|null
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

        $bodyOpen = null;
        $endIdx = null;
        for ($k = $parenClose + 1; $k < $classClose; $k++) {
            if ($tokens[$k]->text === '{') {
                $bodyOpen = $k;
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
            'bodyOpen' => $bodyOpen,
            'bodyClose' => $bodyOpen === null ? null : $endIdx,
            'endIdx' => $endIdx,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $methods
     * @return array<string, mixed>|null
     */
    private function methodNamed(array $methods, string $name): ?array
    {
        foreach ($methods as $method) {
            if (strcasecmp($method['name'], $name) === 0) {
                return $method;
            }
        }

        return null;
    }

    // =====================================================================================================
    // Calls to blueprintObservers() inside boot()
    // =====================================================================================================

    /**
     * Every `$this->blueprintObservers();` statement in the method body, as source ranges to delete. Whole
     * lines (and the blank line above a closing brace) when the statement stands alone. Comments are separate
     * tokens, so a commented-out call is not a call.
     *
     * @param  array<int, PhpToken>  $tokens
     * @param  array<string, mixed>  $boot
     * @return array<int, array{0:int,1:int}>
     */
    private function callRanges(array $tokens, string $source, array $boot): array
    {
        $ranges = [];

        for ($i = $boot['bodyOpen'] + 1; $i < $boot['bodyClose']; $i++) {
            if (! $tokens[$i]->is(T_VARIABLE) || $tokens[$i]->text !== '$this') {
                continue;
            }

            $arrow = $this->nextSignificant($tokens, $i);
            $name = $arrow === null ? null : $this->nextSignificant($tokens, $arrow);
            $open = $name === null ? null : $this->nextSignificant($tokens, $name);
            $close = $open === null ? null : $this->nextSignificant($tokens, $open);
            $semicolon = $close === null ? null : $this->nextSignificant($tokens, $close);

            if ($semicolon === null
                || ! $tokens[$arrow]->is(T_OBJECT_OPERATOR)
                || ! $tokens[$name]->is(T_STRING) || strcasecmp($tokens[$name]->text, self::METHOD) !== 0
                || $tokens[$open]->text !== '('
                || $tokens[$close]->text !== ')'
                || $tokens[$semicolon]->text !== ';') {
                continue;
            }

            $start = $tokens[$i]->pos;
            $end = $tokens[$semicolon]->pos + 1;

            $lineStart = strrpos(substr($source, 0, $start), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $newline = strpos($source, "\n", $end);
            $tail = $newline === false ? substr($source, $end) : substr($source, $end, $newline - $end);

            if (trim(substr($source, $lineStart, $start - $lineStart)) === '' && trim($tail) === '') {
                $from = $lineStart;
                $to = $newline === false ? strlen($source) : $newline + 1;

                if ($from >= 2 && substr($source, $from - 2, 2) === "\n\n"
                    && preg_match('/\G[ \t]*\}/', $source, $m, 0, $to)) {
                    $from--;                               // the blank line we added above the call
                }

                $ranges[] = [$from, $to];
            } else {
                $ranges[] = [$start, $end];
            }

            $i = $semicolon;
        }

        return $ranges;
    }

    /**
     * Appends `$this->blueprintObservers();` as the last statement of boot().
     *
     * @param  array<int, PhpToken>  $tokens
     * @param  array<string, mixed>  $boot
     * @return array{0:int,1:int,2:string}
     */
    private function callEdit(array $tokens, string $source, array $boot): array
    {
        $statement = '$this->' . self::METHOD . '();';
        $closePos = $tokens[$boot['bodyClose']]->pos;

        $lineStart = strrpos(substr($source, 0, $closePos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $prefix = substr($source, $lineStart, $closePos - $lineStart);

        if (trim($prefix) !== '') {                        // `{ parent::boot(); }` on one line
            return [$closePos, 0, ' ' . $statement . ' '];
        }

        $empty = true;
        for ($i = $boot['bodyOpen'] + 1; $i < $boot['bodyClose']; $i++) {
            if (! $tokens[$i]->isIgnorable()) {
                $empty = false;
                break;
            }
        }

        return [$lineStart, 0, ($empty ? '' : "\n") . $prefix . '    ' . $statement . "\n"];
    }

    // =====================================================================================================
    // Text
    // =====================================================================================================

    /**
     * @param  array<int, array{model:string, observer:string}>  $registrations
     * @param  array<string, string>  $known
     * @param  array<int, string>  $newImports
     */
    private function methodText(array $registrations, ?string $namespace, array &$known, array &$newImports): string
    {
        $observable = $this->resolve(self::OBSERVABLE_FQCN, $namespace, $known, $newImports);

        $lines = [
            '    /**',
            '     * Register the Nova-only observers generated by blueprint_observers.',
            '     *',
            '     * ' . ObserverClassEditor::MARKER . ' Rewritten on every build. Remove this tag to take ownership of the method.',
            '     */',
            '    protected function ' . self::METHOD . '(): void',
            '    {',
        ];

        foreach ($registrations as $registration) {
            $model = $this->resolve($registration['model'], $namespace, $known, $newImports);
            $observer = $this->resolve($registration['observer'], $namespace, $known, $newImports);

            $lines[] = "        {$observable}::make({$model}::class, {$observer}::class);";
        }

        $lines[] = '    }';

        return implode("\n", $lines);
    }

    /** @param  array<int, PhpToken>  $tokens */
    private function bootText(array $tokens, int $classIdx, int $openIdx): string
    {
        $extends = false;
        for ($i = $classIdx; $i < $openIdx; $i++) {
            if ($tokens[$i]->is(T_EXTENDS)) {
                $extends = true;
                break;
            }
        }

        $lines = [
            '    /**',
            '     * Bootstrap any application services.',
            '     */',
            '    public function boot(): void',
            '    {',
        ];

        if ($extends) {
            $lines[] = '        parent::boot();';
            $lines[] = '';
        }

        $lines[] = '        $this->' . self::METHOD . '();';
        $lines[] = '    }';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @param  array{open:int, close:int, methods: array<int, array<string, mixed>>}  $layout
     * @param  array<string, mixed>|null  $anchor
     * @return array{0:int,1:int,2:string}
     */
    private function insertionEdit(array $tokens, string $source, array $layout, ?array $anchor, string $text): array
    {
        if ($anchor !== null) {
            return [$anchor['end'], 0, "\n\n" . $text];
        }

        $open = $tokens[$layout['open']]->pos;
        $close = $tokens[$layout['close']]->pos;
        $body = substr($source, $open + 1, $close - $open - 1);

        if (trim($body) === '') {
            return [$open + 1, strlen($body), "\n" . $text . "\n"];
        }

        return [$open + 1 + strlen(rtrim($body)), 0, "\n\n" . $text];
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

    // =====================================================================================================
    // Names and imports
    // =====================================================================================================

    /**
     * How to write $fqcn inside this file. Adds an import when the short name is free, otherwise falls back
     * to the fully qualified name (a Nova resource called Post may well be imported already).
     *
     * @param  array<string, string>  $known  alias => fqcn (updated in place when an import is added)
     * @param  array<int, string>  $newImports
     */
    private function resolve(string $fqcn, ?string $namespace, array &$known, array &$newImports): string
    {
        $fqcn = ltrim($fqcn, '\\');
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
}
