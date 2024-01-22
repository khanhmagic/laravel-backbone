<?php

namespace KhanhArtisan\LaravelBackbone\RelationCascade\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use KhanhArtisan\LaravelBackbone\RelationCascade\ShouldCascade;
use KhanhArtisan\LaravelBackbone\RelationCascade\CascadeDetails;
use KhanhArtisan\LaravelBackbone\RelationCascade\RelationCascadeManager;
use KhanhArtisan\LaravelBackbone\ModelListener\ModelListenerManager;

class CascadeDelete extends Cascade implements ShouldQueue
{
    /**
     * Build the model query
     *
     * @param Builder $query
     * @return void
     */
    protected function buildModelQuery(Builder $query): void
    {
        $instance = $query->getModel();

        $query->where($instance->qualifyColumn($instance->getCascadeStatusColumn()), false)
                ->whereNotNull($instance->getDeletedAtColumn())
                ->orderBy($instance->getDeletedAtColumn())
                ->limit($this->chunk)
                ->withTrashed();
    }

    /**
     * Handle the cascade-deletable model
     *
     * @param ShouldCascade $model
     * @return void
     */
    protected function handleCascadeDeletableModel(ShouldCascade $model): void
    {
        // Get the cascade delete details
        $cascadeDeleteDetails = $model->getCascadeDeleteDetails();
        $cascadeDeleteDetails = is_array($cascadeDeleteDetails) ? $cascadeDeleteDetails : [$cascadeDeleteDetails];

        // Loop through all cascade delete details
        $allRelationsAreDeleted = true;
        foreach ($cascadeDeleteDetails as $details) {

            // Skip if $details isn't an instance of CascadeDetails
            if (!$details instanceof CascadeDetails) {
                continue;
            }

            $limit = min($this->chunk, $this->recordsLimit);
            $deletedCount = 0;

            if ($details->shouldUseTransaction()) {
                DB::transaction(function () use ($details, $limit, &$recordsLimit, &$deletedCount) {
                    $deletedCount = $this->deleteRelations($details, $limit);
                });
            } else {
                $deletedCount = $this->deleteRelations($details, $limit);
            }

            $this->recordsLimit -= $deletedCount;

            // Return if the limit is reached
            if ($this->recordsLimit <= 0) {
                return;
            }

            // Detect if not all relations are deleted
            if ($deletedCount >= $limit) {
                $allRelationsAreDeleted = false;
            }
        }

        // If all relations are deleted
        if ($allRelationsAreDeleted) {

            // Force delete and finish
            if ($details->shouldForceDelete()) {
                $model->forceDelete();
                return;
            }

            // Update the cascade_status to true
            $model->setAttribute($model->getCascadeStatusColumn(), true);
            $model->save();
        }
    }

    /**
     * Delete the relations
     *
     * @param CascadeDetails $cascadeDeleteDetails
     * @param int $limit
     * @return int
     */
    protected function deleteRelations(CascadeDetails $cascadeDeleteDetails, int $limit): int
    {
        // Batch delete
        if (!$cascadeDeleteDetails->shouldDeletePerItem()) {
            return $cascadeDeleteDetails->getRelation()->take($limit)->delete();
        }

        // Per item delete
        $deleted = 0;
        $cascadeDeleteDetails
            ->getRelation()
            ->take($limit)
            ->get()
            ->each(function (Model $model) use (&$deleted, $cascadeDeleteDetails) {
                if ($model->delete()) {
                    $deleted++;
                }
            });

        return $deleted;
    }
}