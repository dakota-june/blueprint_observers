<?php

declare(strict_types=1);

return [
    /*
    | Events generated when an observer lists none (or `events: true`).
    */
    'default_events' => ['creating', 'updating', 'deleted'],

    /*
    | Make generated observers implement ShouldHandleEventsAfterCommit unless the draft says otherwise.
    */
    'after_commit' => true,

    /*
    | Add #[ObservedBy(...)] to the observed model. Existing attributes are merged, never duplicated.
    */
    'attach_attribute' => true,

    /*
    | Observers nobody had to list. Off by default.
    |
    |   enabled: build an observer for every model in the draft that has behaviour to enforce and that no
    |            entry under `observers:` covers. Observers you list yourself are never changed.
    |   unique:  models with a `unique` column or a unique index get an observer that checks the value is
    |            not already taken (built-in `unique` snippet, on creating and updating).
    |   validation: also create observers (for models in the draft) whose only job is to validate; needs
    |            `validation.enabled`. Observers you list validate anyway when that is on.
    |   guards:  models with hasMany / hasOne relationships get a guard per relationship on deleting.
    |   guard_default: what an auto guard does when the child's foreign key has no `ondelete:` (which, when
    |            present, wins: cascade, null and restrict map to cascade, nullify and restrict):
    |            'restrict' (block the delete), 'cascade' or 'nullify'.
    |   Each constraint / relationship gets its own generated method. `unique` and `guards` need `enabled`.
    */
    'auto' => [
        'enabled' => false,
        'unique' => true,
        'guards' => true,
        'guard_default' => 'restrict',
        'validation' => true,
    ],

    /*
    | Validation: observers validate their model with Laravel's validator before it is saved, using rules
    | derived from the column definitions under `models:` (type, length, nullable, default, enum ...).
    | A failure throws Illuminate\Validation\ValidationException, which Nova shows on the form fields.
    |
    |   enabled: every regular observer whose model is in the draft validates, unless it says `validation: false`.
    |            Off: only observers with a `validation:` key validate. Nova observers (`nova_observers:`) never
    |            inherit this switch (the regular observer already validates Nova requests too); they validate
    |            only with their own `validation:` key.
    */
    'validation' => [
        'enabled' => false,
    ],

    /*
    | Guards (the `guards:` key of an observer, and auto guards).
    |
    |   trashed: 'block'  soft-deleted related rows count: they block a restrict, and cascade / nullify include them.
    |            'ignore' they do not. Can be set per guard: `payments: { action: restrict, trashed: ignore }`.
    */
    'guards' => [
        'trashed' => 'block',
    ],

    /*
    | Namespace (relative to blueprint.namespace) and file name used for generated observers.
    */
    'namespace' => 'Observers',

    /*
    | Namespace (relative to blueprint.namespace) of observers generated from the `nova_observers:` block.
    | These only run for Nova requests: they are registered with Laravel\Nova\Observable, not #[ObservedBy].
    */
    'nova_namespace' => 'Nova\Observers',

    /*
    | The Nova service provider (relative to blueprint.namespace) that gets a generated
    | `blueprintObservers()` method, called from its boot(). Nothing else in it is ever touched.
    */
    'nova_provider' => 'Providers\NovaServiceProvider',

    /*
    | Records which model files were modified so `blueprint:erase` can undo it.
    | Relative to the project root. Commit it or ignore it, same as `.blueprint`.
    */
    'manifest' => '.blueprint_observers',
];
