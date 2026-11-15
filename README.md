# dakotajune/blueclues

First-class Eloquent **Observer** generation for [Laravel Blueprint](https://github.com/laravel-shift/blueprint).

```bash
composer require dakotajune/blueclues --dev
```

## Draft syntax

Observers live in their own top-level `observers:` block. Blueprint's built-in lexers ignore that
section, so nothing is decorated or reordered and a column called `observer` can never be created.

```yaml
observers:
  Invoice: ~                    # defaults: model=Invoice, events from config, afterCommit from config
  PaymentObserver:
    model: Payment              # optional; defaults to the name minus "Observer" (Admin\User is allowed)
    afterCommit: true           # implements ShouldHandleEventsAfterCommit
    events: [creating, updated, deleted]   # list, or "creating, updated"
```

Unknown event names fail the build with the list of valid events instead of being silently dropped.

## What `blueprint:build` does

* Writes `app/Observers/{Name}.php` from `stubs/observer.stub` (publish with
  `--tag=blueclues-stubs`; a copy in `stubs/blueprint/observer.stub` wins).
* Adds `#[ObservedBy(...)]` to the model when its file exists (`attach_attribute` config). Existing
  attributes, docblocks, `final`/`abstract`/`readonly`, aliased imports and short-name clashes are
  handled; an existing `#[ObservedBy]` is merged into an array, never duplicated; reruns change nothing.
* Reports observers under `created` and edited models under `updated`, and respects
  `--only=observers` / `--skip=observers`.
* Paths and namespaces come from `Blueprint::appPath()`, `Blueprint::relativeNamespace()` and
  `Tree::fqcnForContext()`.

## `blueprint:erase`

Erase deletes everything under `created` in `.blueprint`, which removes the generated observers. It cannot
revert edits to existing models, so blueclues records its edits in `.blueclues` and, just before
`blueprint:erase` starts, strips the attribute and the imports it added (imports still used elsewhere in
the file are left alone). Like `.blueprint`, `.blueclues` describes the latest build only.

## Config (`--tag=blueclues-config`)

| key | default |
| --- | --- |
| `default_events` | `['creating', 'updating', 'deleted']` |
| `after_commit` | `true` |
| `attach_attribute` | `true` |
| `namespace` | `Observers` (relative to `blueprint.namespace`) |
| `manifest` | `.blueclues` |

## Tests

```bash
composer install && vendor/bin/pest
```
