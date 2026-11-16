<?php

declare(strict_types=1);

namespace Dakotajune\BlueprintObservers\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/** A model that never touches a database: the observer only reads its attributes. */
final class ValidatedInvoice extends Model
{
    protected $guarded = [];
}
