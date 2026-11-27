import { sharedModuleOptions } from '@/shared/lib/modules';
import type { CoreMergeTransferCategory, MergeTransferCategory } from '../types/merge';
import { MERGE_TRANSFER_CATEGORIES, MERGE_TRANSFER_CATEGORY_LABELS } from '../types/merge';

type Translate = (key: string) => string;

function isCoreCategory(category: MergeTransferCategory): category is CoreMergeTransferCategory {
  return (MERGE_TRANSFER_CATEGORIES as readonly string[]).includes(category);
}

export function mergeTransferCategoryLabel(category: MergeTransferCategory, t: Translate): string {
  if (isCoreCategory(category)) {
    return t(MERGE_TRANSFER_CATEGORY_LABELS[category]);
  }

  return (
    sharedModuleOptions('merge.transfer-categories').find((option) => option.value === category)?.label ?? category
  );
}
