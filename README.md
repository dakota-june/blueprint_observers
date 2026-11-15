# dakotajune/blueprint_observers

First-class Eloquent **Observer** generation for [Laravel Blueprint](https://github.com/laravel-shift/blueprint),
with opt-in, build-time **snippets** that add code to the generated event methods.

```bash
composer require dakotajune/blueprint_observers --dev
```

## Draft syntax

Observers live in their own top-level `observers:` block. Blueprint's built-in lexers ignore that
section, so nothing is decorated or reordered and a column called `observer` can never be created.

```yaml
observers:
  Invoice: ~                    # model=Invoice, events from config, no snippets
  PaymentObserver:
    model: Payment              # optional; defaults to the name minus "Observer" (Admin\User is allowed)
    afterCommit: true           # implements ShouldHandleEventsAfterCommit
    events:
      creating: [audit]         # opt in to the "audit" snippet for this event only
      updated:                  # event with no snippets
      deleted: false            # do not generate this event
```

* **Snippets are always written inside `[...]`.** A bare value (`creating: audit`) is rejected with a hint.
  This also sidesteps Blueprint stripping leading `- ` list dashes from the draft before YAML is parsed.
* `event:` or `event: true` generates the event with no snippets; `event: false` switches it off (a previously
  generated, still-tagged method for it is removed on the next build).
* `events` may also be a flow list (`[creating, updated]`) or `creating, updated`.

Unknown events, unknown or unsupported snippets, and duplicates fail the build with a clear message.

## Snippets

A snippet is a named piece of code added to one observer method **at build time**. Snippets are always
opt-in, per event, from the draft. There is no runtime behaviour: what you see in the file is what runs.

```php
use Dakotajune\BlueprintObservers\Snippets\{Snippet, SnippetContext, SnippetOutput};

final class AuditSnippet implements Snippet
{
    public function name(): string { return 'audit'; }

    public function supports(string $event): bool { return in_array($event, ['creating', 'updated'], true); }

    public function render(SnippetContext $context): SnippetOutput
    {
        return new SnippetOutput(
            "Log::info('{$context->event}', ['id' => {$context->variableName()}->getKey()]);",
            imports: ['Illuminate\\Support\\Facades\\Log'],
        );
    }
}
```

`SnippetContext` exposes the observer, model, event, the method's variable name, and (when the model is in
the draft) `hasColumn()` / `columns()`, so snippets can adapt to the model.

### The registry

The registry is a single explicit list. Nothing is scanned or discovered; a snippet exists only if a
service provider registered it:

```php
public function boot(): void
{
    $this->app->make(\Dakotajune\BlueprintObservers\Snippets\SnippetRegistry::class)->register(new AuditSnippet());
}
```

Duplicate names fail. Each snippet's output is syntax-checked on its own, so a broken snippet fails the
build *by name* before anything is written. Snippets contribute method bodies and imports only (no
constructor dependencies in v1).

## Ownership: what blueprint_observers will and will not touch

Every method blueprint_observers writes carries a docblock tag:

```php
/**
 * Handle the Invoice "creating" event.
 *
 * @generated blueprint_observers Rewritten on every build. Remove this tag to take ownership of the method.
 */
```

* **Tagged methods are the generator's.** They are rewritten in place on every build, and deleted when
  their event leaves the draft.
* **Everything else is yours and is never modified or removed:** untagged methods, properties, constants,
  traits, comments, and your imports. Imports are only ever added.
* If you already have an **untagged** method named after an event, it is left alone and you get a notice.
* **Remove the tag** to take a method over; it is never touched again.
* The class header is managed only for `ShouldHandleEventsAfterCommit` (follows `afterCommit`). Other
  interfaces, parents and traits are left alone.

Rebuilds are therefore surgical edits of the existing file, not Blueprint's usual overwrite.

## `blueprint:build`

* Writes `app/Observers/{Name}.php` (stub: `--tag=blueprint-observers-stubs`, only `{{ namespace }}` and `{{ class }}`).
* Adds `#[ObservedBy(...)]` to the model when its file exists (`attach_attribute`). Existing attributes,
  docblocks, `final`/`abstract`/`readonly`, aliased imports and short-name clashes are handled; an existing
  `#[ObservedBy]` is merged, never duplicated; reruns change nothing.
* Reports observers it created under `created` and everything else it edited under `updated`; honours
  `--only=observers` / `--skip=observers`.
* Prints notices (left-alone methods, skipped imports) after the command.

## `blueprint:erase`

Erase deletes everything under `created` in `.blueprint`. To keep that from destroying your code,
blueprint_observers acts just before erase starts:

1. strips the `#[ObservedBy]` attributes and imports it added to models;
2. strips the `@generated blueprint_observers` methods from each observer it touched;
3. if an observer **still holds your code** (or was not created by blueprint_observers), removes it from `.blueprint`'s
   `created` list so erase skips it. `.blueprint` is **backed up first** to `.blueprint.bak-YYYYmmddHHMMSS`
   and is only edited if the backup succeeds.

Observers with nothing of yours left stay on the list and are deleted by erase as normal. blueprint_observers
records its edits in `.blueprint_observers` (latest build only, like `.blueprint`) and removes it on erase.

## Config (`--tag=blueprint-observers-config`)

| key | default |
| --- | --- |
| `default_events` | `['creating', 'updating', 'deleted']` |
| `after_commit` | `true` |
| `attach_attribute` | `true` |
| `namespace` | `Observers` (relative to `blueprint.namespace`) |
| `manifest` | `.blueprint_observers` |

## Known limits

* Source edits assume LF line endings.
* Grouped imports (`use A\{B, C};`) are not tracked when checking for name clashes.
* Changing a snippet's output only reaches methods that still carry the `@generated` tag.

## Tests

```bash
composer install && vendor/bin/pest
```
