<?php

namespace Aerni\AdvancedSeo\Actions;

use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Aerni\AdvancedSeo\Data\DefaultsData;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Taxonomies\Taxonomy;
use Statamic\Events\TermBlueprintFound;

class EvaluateModelHandle
{
    public static function handle(mixed $model): ?string
    {
        return match (true) {
            ($model instanceof Collection) => $model->handle(),
            ($model instanceof Entry) => $model->collection()->handle(),
            ($model instanceof EntryBlueprintFound) => Str::after($model->blueprint->namespace(), '.'),
            ($model instanceof Taxonomy) => $model->handle(),
            ($model instanceof Term) => $model->taxonomy()->handle(),
            ($model instanceof TermBlueprintFound) => Str::after($model->blueprint->namespace(), '.'),
            ($model instanceof DefaultsData) => $model->handle,
            default => null
        };
    }
}
