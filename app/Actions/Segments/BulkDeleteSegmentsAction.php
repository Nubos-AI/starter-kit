<?php

declare(strict_types=1);

namespace App\Actions\Segments;

use App\Actions\Abstracts\BulkDeleteAction;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BulkDeleteSegmentsAction extends BulkDeleteAction
{
    public function __construct(private readonly DeleteSegmentAction $deleteSegment) {}

    /**
     * @return Builder<Segment>
     */
    protected function query(User $actor, ?Model $scope): Builder
    {
        return Segment::query()->where('tenant_id', $actor->tenant_id);
    }

    /**
     * @param  Segment  $model
     */
    protected function deleteOne(User $actor, Model $model): void
    {
        $this->deleteSegment->execute($actor, $model);
    }
}
