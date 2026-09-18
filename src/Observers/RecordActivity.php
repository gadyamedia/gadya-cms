<?php

namespace Gadya\Cms\Observers;

use Gadya\Cms\Activity\Activity;
use Illuminate\Database\Eloquent\Model;

/**
 * Notes every change to the things a client edits. Draft-only saves are
 * included on purpose: "who changed the price on Tuesday" is usually a
 * question about a draft.
 */
class RecordActivity
{
    public function __construct(private readonly Activity $activity) {}

    public function created(Model $model): void
    {
        $this->activity->recordModel('created', $model);
    }

    public function updated(Model $model): void
    {
        $this->activity->recordModel('updated', $model);
    }

    public function deleted(Model $model): void
    {
        $this->activity->recordModel(method_exists($model, 'trashed') && $model->trashed() ? 'trashed' : 'deleted', $model);
    }

    public function restored(Model $model): void
    {
        $this->activity->recordModel('restored', $model);
    }

    public function forceDeleted(Model $model): void
    {
        $this->activity->recordModel('deleted_for_good', $model);
    }
}
