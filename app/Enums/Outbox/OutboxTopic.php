<?php

declare(strict_types=1);

namespace App\Enums\Outbox;

enum OutboxTopic: string
{
    case RecordChanged = 'record.changed';

    case RollupRecomputeRequested = 'rollup.recompute_requested';

    case RecordBackfillRequested = 'record_backfill.requested';

    case FormulaBackfillRequested = 'formula_backfill.requested';

    case FieldDefinitionChanged = 'field_definition.changed';

    case ObjectTypePurged = 'object_type.purged';

    case ReportIndexesRequested = 'report.indexes_requested';

    case ApprovalChanged = 'approval.changed';

    case PromotionApproved = 'promotion.approved';

    case UserInvitationRequested = 'user.invitation_requested';
}
