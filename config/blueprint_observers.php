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
