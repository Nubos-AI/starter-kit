import { RuleTester } from 'eslint';
import { describe, it } from 'vitest';
import featureBoundaries from './featureBoundaries.js';

RuleTester.describe = describe;
RuleTester.it = it;
RuleTester.itOnly = it.only;

const tester = new RuleTester({
  languageOptions: { ecmaVersion: 2022, sourceType: 'module' },
});

const root = '/var/www/html';
const core = (path: string): string => `${root}/resources/js/${path}`;
const charge = (path: string): string => `${root}/vendor/nubos/charge/resources/js/${path}`;

tester.run('feature-boundaries', featureBoundaries, {
  valid: [
    {
      code: "import { RecordPicker } from '@/domains/business-data/ui-records';",
      filename: core('domains/business-data/feature-records/pages/records/Index.vue'),
    },
    {
      code: "import { useRecordLookup } from '@/domains/business-data/data';",
      filename: core('domains/business-data/ui-records/ui/RecordPicker.vue'),
    },
    {
      code: "import { formatFieldValue } from '@/domains/business-data/util-record-format';",
      filename: core('domains/business-data/data/types/grid.ts'),
    },
    {
      code: "import { formatFieldValue } from '@/domains/business-data/util-record-format';",
      filename: core('domains/business-data/ui-record-field/ui/DynamicField.vue'),
    },
    {
      code: "import ActivityForm from './ActivityForm.vue';",
      filename: core('domains/business-data/ui-record-collaboration/ui/RecordActivitiesPanel.vue'),
    },
    {
      code: "import { Button } from '@/shared/ui/primitives/button';",
      filename: core('domains/business-data/util-record-format/utils/formatFieldValue.ts'),
    },
    {
      code: "import FilterBuilder from '@/shared/filters/ui/FilterBuilder.vue';",
      filename: core('domains/analytics/feature-dashboards/ui/DashboardGrid.vue'),
    },
    {
      code: "import { cn } from '@/shared/lib/utils';",
      filename: core('shared/ui/ConfirmDialog.vue'),
    },
    {
      code: "import { MaintenanceLockBanner } from '@/platform/feature-maintenance';",
      filename: core('app-shell/ui/AppShell.vue'),
    },
    {
      code: "import { AppLayout, AuthLayout } from '@/app-shell';",
      filename: core('app.ts'),
    },
    {
      code: "import { ObjectTypeLayout } from '@/domains/business-data/ui-object-types';",
      filename: core('app.ts'),
    },
    {
      code: "import SegmentBar from '@/domains/business-data/feature-record-views/ui/SegmentBar.vue';",
      filename: core('domains/analytics/feature-reports/pages/reports/Index.spec.ts'),
    },
    {
      code: "import { RecordPayload } from '@/domains/business-data/data';",
      filename: core('shared/ui/RecordCreateButton.spec.ts'),
    },
    {
      code: "import { serveJson } from '@/tests/msw';",
      filename: core('tests/designTokens.spec.ts'),
    },
    {
      code: "import { RecordPicker } from '@/domains/business-data/ui-records';",
      filename: charge('domains/billing/feature-invoices/ui/InvoiceForm.vue'),
    },
    {
      code: "import { QuoteStatusBadge } from '../../ui-quotes';",
      filename: charge('domains/billing/feature-invoices/ui/InvoiceForm.vue'),
    },
    {
      code: "import { AppLayout } from '@/app-shell';",
      filename: charge('domains/billing/feature-invoices/pages/charge/invoices/Index.vue'),
    },
    {
      code: "import ChargeList from '../../../../shared/ui/ChargeList.vue';",
      filename: charge('domains/billing/feature-invoices/ui/InvoiceList.vue'),
    },
    {
      code: "import { CustomerSelect } from '../../../domains/customers/ui-customers';",
      filename: charge('extensions/record-panels/ui/ProductRecordSection.vue'),
    },
    {
      code: "import { RecordPayload } from '@/domains/business-data/data';",
      filename: charge('shared/types/chargeRecords.ts'),
    },
    {
      code: "import { RecordPicker } from '@/domains/business-data/ui-records';",
      filename: charge('domains/customers/ui-customers/ui/CustomerAccountFields.vue'),
    },
  ],
  invalid: [
    {
      code: "import { ReportChart } from '@/domains/analytics/feature-reports';",
      filename: core('domains/analytics/feature-dashboards/ui/DashboardGrid.vue'),
      errors: [{ messageId: 'typeViolation' }],
    },
    {
      code: "import { DynamicForm } from '@/domains/business-data/ui-record-field';",
      filename: core('domains/business-data/ui-records/ui/RecordPicker.vue'),
      errors: [{ messageId: 'typeViolation' }],
    },
    {
      code: "import { RecordPicker } from '@/domains/business-data/ui-records';",
      filename: core('domains/business-data/data/types/records.ts'),
      errors: [{ messageId: 'typeViolation' }],
    },
    {
      code: "import { useRecordLookup } from '@/domains/business-data/data';",
      filename: core('domains/business-data/util-record-format/utils/formatFieldValue.ts'),
      errors: [{ messageId: 'typeViolation' }],
    },
    {
      code: "import RecordPicker from '@/domains/business-data/ui-records/ui/RecordPicker.vue';",
      filename: core('domains/business-data/feature-records/pages/records/Index.vue'),
      errors: [{ messageId: 'deepImport' }],
    },
    {
      code: "import { RecordPicker } from '@/domains/business-data/ui-records';",
      filename: core('domains/business-data/ui-records/ui/RecordPicker.vue'),
      errors: [{ messageId: 'ownBarrel' }],
    },
    {
      code: "import { ReportChart } from '@/domains/analytics/ui-charts';",
      filename: core('domains/workflow/feature-notification-rules/pages/rules/Index.vue'),
      errors: [{ messageId: 'domainViolation' }],
    },
    {
      code: "import { RecordPicker } from '@/domains/business-data/ui-records';",
      filename: core('domains/analytics/feature-dashboards/ui/DashboardGrid.vue'),
      errors: [{ messageId: 'domainViolation' }],
    },
    {
      code: "import { MaintenanceLockBanner } from '@/platform/feature-maintenance';",
      filename: core('domains/workspace-access/feature-authentication/pages/auth/Login.vue'),
      errors: [{ messageId: 'typeViolation' }],
    },
    {
      code: "import { RecordPicker } from '@/domains/business-data/ui-records';",
      filename: core('shared/ui/RecordField.vue'),
      errors: [{ messageId: 'sharedImportsDomain' }],
    },
    {
      code: "import { AppLayout } from '@/app-shell';",
      filename: core('domains/business-data/feature-records/pages/records/Index.vue'),
      errors: [{ messageId: 'appShellImport' }],
    },
    {
      code: "import { cn } from '@/shared/lib/utils';",
      filename: core('domains/business-data/records/ui/RecordGrid.vue'),
      errors: [{ messageId: 'unknownModuleType' }],
    },
    {
      code: "import { cn } from '@/shared/lib/utils';",
      filename: core('domains/business-data/data-records/types/records.ts'),
      errors: [{ messageId: 'unknownModuleType' }],
    },
    {
      code: "import { RecordPicker } from '@/features/records';",
      filename: core('domains/business-data/feature-record-views/pages/segments/Index.vue'),
      errors: [{ messageId: 'legacyRoot' }],
    },
    {
      code: "import { usePage } from '@/composables/usePage';",
      filename: core('shared/ui/Heading.vue'),
      errors: [{ messageId: 'legacyRoot' }],
    },
    {
      code: "import { RecordGrid } from '@/domains/business-data/feature-records';",
      filename: charge('domains/billing/feature-invoices/ui/InvoiceForm.vue'),
      errors: [{ messageId: 'typeViolation' }],
    },
    {
      code: "import { PaymentMethodBadge } from '../../../payments/ui-payments';",
      filename: charge('domains/billing/feature-invoices/ui/InvoiceForm.vue'),
      errors: [{ messageId: 'domainViolation' }],
    },
    {
      code: "import { ProductRecordSection } from '../../../../extensions/record-panels';",
      filename: charge('domains/billing/feature-invoices/ui/InvoiceForm.vue'),
      errors: [{ messageId: 'extensionImport' }],
    },
    {
      code: "import { InvoiceForm } from '../../domains/billing/feature-invoices';",
      filename: charge('shared/ui/ChargeList.vue'),
      errors: [{ messageId: 'sharedImportsDomain' }],
    },
    {
      code: "import { serveJson } from '@/tests/msw';",
      filename: core('domains/business-data/ui-records/ui/RecordPicker.spec.ts'),
      errors: [{ messageId: 'testsImport' }],
    },
  ],
});
