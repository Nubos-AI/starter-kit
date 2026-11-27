<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum MergeBlockReason: string
{
    case SameRecord = 'same_record';

    case DifferentObjectType = 'different_object_type';

    case DifferentTenant = 'different_tenant';

    case Trashed = 'trashed';

    case AlreadyMerged = 'already_merged';

    case SystemObjectType = 'system_object_type';

    case HierarchyCycle = 'hierarchy_cycle';

    case CardinalityConflict = 'cardinality_conflict';

    case StaleVersion = 'stale_version';

    case RuleForbids = 'rule_forbids';

    case DedupMismatch = 'dedup_mismatch';

    case ReasonRequired = 'reason_required';

    case DecisionMissing = 'decision_missing';

    case UniqueFieldConflict = 'unique_field_conflict';
}
