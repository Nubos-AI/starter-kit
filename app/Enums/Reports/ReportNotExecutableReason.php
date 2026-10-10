<?php

declare(strict_types=1);

namespace App\Enums\Reports;

enum ReportNotExecutableReason: string
{
    case MalformedDefinition = 'malformed_definition';

    case UnknownField = 'unknown_field';

    case FieldNotReadable = 'field_not_readable';

    case EncryptedField = 'encrypted_field';

    case FieldNotFilterable = 'field_not_filterable';

    case UnsupportedAggregation = 'unsupported_aggregation';

    case UnsupportedGrouping = 'unsupported_grouping';

    case InvalidFilterTree = 'invalid_filter_tree';

    case ReportMissing = 'report_missing';

    case SourceNotVisible = 'source_not_visible';

    case GoalMissing = 'goal_missing';

    case GoalNotVisible = 'goal_not_visible';

    public function message(): string
    {
        return match ($this) {
            self::MalformedDefinition => __('i18n.backend.enums.reports.report_not_executable_reason.this_report_s_configuration_is_incomplete_or_inconsistent_please'),
            self::UnknownField => __('i18n.backend.enums.reports.report_not_executable_reason.this_report_references_something_that_no_longer_exists_please'),
            self::FieldNotReadable => __('i18n.backend.enums.reports.report_not_executable_reason.you_do_not_have_permission_to_view_one_of'),
            self::EncryptedField => __('i18n.backend.enums.reports.report_not_executable_reason.encrypted_values_cannot_be_counted_summed_or_grouped'),
            self::FieldNotFilterable => __('i18n.backend.enums.reports.report_not_executable_reason.one_of_the_values_this_report_uses_cannot_be'),
            self::UnsupportedAggregation => __('i18n.backend.enums.reports.report_not_executable_reason.this_calculation_cannot_be_applied_to_the_selected_value'),
            self::UnsupportedGrouping => __('i18n.backend.enums.reports.report_not_executable_reason.grouping_by_period_requires_a_date_or_timestamp'),
            self::InvalidFilterTree => __('i18n.backend.enums.reports.report_not_executable_reason.this_report_s_filter_could_not_be_applied_please'),
            self::ReportMissing => __('i18n.backend.enums.reports.report_not_executable_reason.the_report_this_tile_uses_no_longer_exists'),
            self::SourceNotVisible => __('i18n.backend.enums.reports.report_not_executable_reason.you_do_not_have_permission_to_view_this_data'),
            self::GoalMissing => __('i18n.backend.enums.reports.report_not_executable_reason.the_goal_this_tile_uses_no_longer_exists'),
            self::GoalNotVisible => __('i18n.backend.enums.reports.report_not_executable_reason.you_do_not_have_permission_to_view_this_goal'),
        };
    }
}
