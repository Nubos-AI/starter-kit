export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
  type: 'success' | 'info' | 'warning' | 'error';
  message: string;
};

export type SelectOptionAvatar = {
  name: string;
};

export type SelectOption = {
  value: string;
  label: string;
  description?: string;
  icon?: string;
  badge?: string;
  disabled?: boolean;
  disabledReason?: string;
  avatar?: SelectOptionAvatar;
};

export type ObjectTypeOption = SelectOption & {
  slug: string;
};

export type LabelledRef = {
  id: string;
  label: string;
};
