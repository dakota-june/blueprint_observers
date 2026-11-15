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
    | Records which model files were modified so `blueprint:erase` can undo it.
    | Relative to the project root. Commit it or ignore it, same as `.blueprint`.
    */
    'manifest' => '.blueprint_observers',
];
