<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import {
  update as updatePreferences,
  updateTenantDefaults,
} from '@/actions/App/Http/Controllers/Notifications/NotificationPreferencesController';
import { edit as editNotifications } from '@/actions/App/Http/Controllers/Notifications/NotificationSettingsController';
import { useI18n } from '@/shared/composables/useI18n';
import { usePermissions } from '@/shared/composables/usePermissions';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import type { SelectOption } from '@/shared/types/ui';
import AlertError from '@/shared/ui/AlertError.vue';
import CardSection from '@/shared/ui/CardSection.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import FormSwitch from '@/shared/ui/FormSwitch.vue';
import Heading from '@/shared/ui/Heading.vue';
import InputError from '@/shared/ui/InputError.vue';
import { Card, CardContent } from '@/shared/ui/primitives/card';
import { Input } from '@/shared/ui/primitives/input';
import { MultiSelect } from '@/shared/ui/primitives/multi-select';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import { Separator } from '@/shared/ui/primitives/separator';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import { usePushSubscription } from '../../data/usePushSubscription';

const { t } = useI18n();

type NotificationChannelValue = 'in-app' | 'email' | 'web-push';

interface NotificationTypeOption {
  key: string;
  label: string;
}

interface StoredPreference {
  enabled: boolean;
  delivery_mode: string;
}

type PreferenceMap = Record<string, Record<string, StoredPreference | undefined> | undefined>;

const props = withDefaults(
  defineProps<{
    types?: NotificationTypeOption[];
    notificationPreferences?: PreferenceMap;
    defaults?: PreferenceMap;
  }>(),
  {
    types: () => [],
    notificationPreferences: () => ({}),
    defaults: () => ({}),
  },
);

const { isEscalated } = usePermissions();

const channels: Array<{ value: NotificationChannelValue; label: string }> = [
  {
    value: 'in-app',
    label: t('i18n.pages.settings.notifications.inbox'),
  },
  { value: 'email', label: t('i18n.pages.settings.notifications.email') },
  {
    value: 'web-push',
    label: t('i18n.pages.settings.notifications.push'),
  },
];

const deliveryModes: Array<{ value: string; label: string }> = [
  {
    value: 'immediate',
    label: t('i18n.pages.settings.notifications.immediate'),
  },
  {
    value: 'digest',
    label: t('i18n.pages.settings.notifications.digest'),
  },
];

const channelOptions: SelectOption[] = channels.map((channel) => ({
  value: channel.value,
  label: channel.label,
}));

function enabledChannels(stored: PreferenceMap, typeKey: string): string[] {
  return channels
    .filter((channel) => stored[typeKey]?.[channel.value]?.enabled === true)
    .map((channel) => channel.value);
}

const channelState = reactive<Record<string, string[]>>({});
const defaultState = reactive<Record<string, string[]>>({});

for (const type of props.types) {
  channelState[type.key] = enabledChannels(props.notificationPreferences, type.key);
  defaultState[type.key] = enabledChannels(props.defaults, type.key);
}

function preferenceError(errors: Record<string, string>, field: string): string | undefined {
  return (
    errors[field] ??
    Object.entries(errors).find(([key]) => key.startsWith('preferences.') && key.endsWith(`.${field}`))?.[1]
  );
}

function firstStoredDeliveryMode(): string {
  for (const type of props.types) {
    for (const channel of channels) {
      const stored = props.notificationPreferences?.[type.key]?.[channel.value];

      if (stored) {
        return stored.delivery_mode;
      }
    }
  }

  return 'immediate';
}

const deliveryMode = ref<string>(firstStoredDeliveryMode());
const timezone = ref<string>('UTC');
const quietHoursStart = ref<string>('');
const quietHoursEnd = ref<string>('');

function buildPreferencePayload() {
  const preferences = props.types.flatMap((type) =>
    channels.map((channel) => ({
      type: type.key,
      channel: channel.value,
      enabled: channelState[type.key]?.includes(channel.value) === true,
      delivery_mode: deliveryMode.value,
    })),
  );

  return {
    timezone: timezone.value,
    quiet_hours_start: quietHoursStart.value || null,
    quiet_hours_end: quietHoursEnd.value || null,
    preferences,
  };
}

function buildTenantDefaultPayload() {
  return {
    defaults: props.types.flatMap((type) =>
      channels.map((channel) => ({
        type: type.key,
        channel: channel.value,
        enabled: defaultState[type.key]?.includes(channel.value) === true,
        delivery_mode: props.defaults?.[type.key]?.[channel.value]?.delivery_mode ?? 'immediate',
      })),
    ),
  };
}

const timezones = ['UTC', 'Europe/Berlin', 'Europe/London', 'Europe/Zurich', 'America/New_York'];

const weekdays: Array<{ value: string; label: string }> = [
  { value: '1', label: t('i18n.pages.settings.notifications.monday') },
  { value: '2', label: t('i18n.pages.settings.notifications.tuesday') },
  { value: '3', label: t('i18n.pages.settings.notifications.wednesday') },
  { value: '4', label: t('i18n.pages.settings.notifications.thursday') },
  { value: '5', label: t('i18n.pages.settings.notifications.friday') },
  { value: '6', label: t('i18n.pages.settings.notifications.saturday') },
  { value: '7', label: t('i18n.pages.settings.notifications.sunday') },
];

const hours = Array.from({ length: 24 }, (_value, index) => index);

const frequency = ref<'daily' | 'weekly'>('daily');
const weekday = ref<string>('1');
const hour = ref<string>('9');

const settingsHref = editNotifications.url();

const preferenceChanges = useUnsavedChanges({
  values: () => ({
    channels: channelState,
    deliveryMode: deliveryMode.value,
    timezone: timezone.value,
    quietHoursStart: quietHoursStart.value,
    quietHoursEnd: quietHoursEnd.value,
  }),
  backHref: settingsHref,
});

const digestChanges = useUnsavedChanges({
  values: () => ({
    frequency: frequency.value,
    weekday: weekday.value,
    hour: hour.value,
  }),
  backHref: settingsHref,
});

const defaultChanges = useUnsavedChanges({
  values: () => ({ defaults: defaultState }),
  backHref: settingsHref,
});

const unsavedChanges = [preferenceChanges, digestChanges, defaultChanges];

const push = usePushSubscription();

const isPushEnabled = computed<boolean>({
  get: () => push.subscribed.value,
  set: (enabled) => {
    if (enabled) {
      void push.subscribe();

      return;
    }

    void push.unsubscribe();
  },
});

const tenantDefaultsDescription = t(
  'i18n.pages.settings.notifications.set_default_channels_for_each_notification_type_across_the',
);

const pushLabel = computed<string>(() => {
  if (!push.supported.value) {
    return t('i18n.pages.settings.notifications.your_browser_does_not_support_push_notifications');
  }

  if (push.subscribed.value || push.permission.value === 'granted') {
    return t('i18n.pages.settings.notifications.push_notifications_are_enabled');
  }

  if (push.permission.value === 'denied') {
    return t('i18n.pages.settings.notifications.push_notifications_are_blocked_in_your_browser');
  }

  return t('i18n.pages.settings.notifications.push_notifications_are_not_enabled');
});
</script>

<template>
  <div class="flex flex-col gap-6">
    <Head :title="t('i18n.pages.settings.notifications.notification_settings')" />

    <Heading
      variant="small"
      :title="t('i18n.pages.settings.notifications.notifications')"
      :description="t('i18n.pages.settings.notifications.manage_channels_digest_schedules_and_push_notifications')"
    />

    <Card data-notification-settings>
      <CardContent class="flex flex-col gap-6">
        <CardSection
          :title="t('i18n.pages.settings.notifications.channels_delivery')"
          :description="
            t('i18n.pages.settings.notifications.for_each_notification_type_select_channels_delivery_mode_your')
          "
          data-notification-section="channels"
        >
          <Form
            :action="updatePreferences.url()"
            method="patch"
            :transform="buildPreferencePayload"
            :on-success="preferenceChanges.markSaved"
            class="flex flex-col gap-6"
            v-slot="{ errors, processing }"
          >
            <div v-if="props.types.length > 0" class="grid gap-4 sm:grid-cols-2" data-test="channel-preferences">
              <FormField v-for="type in props.types" :key="type.key" :for="`channel-${type.key}`" :label="type.label">
                <MultiSelect
                  :id="`channel-${type.key}`"
                  v-model="channelState[type.key]"
                  :options="channelOptions"
                  :placeholder="t('i18n.pages.settings.notifications.select_channels')"
                  :data-channel-type="type.key"
                />
              </FormField>
              <InputError class="sm:col-span-2" :message="preferenceError(errors, 'channel')" />
            </div>

            <EmptyState
              v-if="props.types.length === 0"
              variant="inline"
              :description="t('i18n.pages.settings.notifications.no_notification_types_are_available_yet')"
            />

            <Separator />

            <div class="grid gap-4 sm:grid-cols-2">
              <FormField
                for="delivery_mode"
                :label="t('i18n.pages.settings.notifications.delivery_mode')"
                :error="preferenceError(errors, 'delivery_mode')"
              >
                <Select v-model="deliveryMode">
                  <SelectTrigger id="delivery_mode" class="w-full">
                    <SelectValue :placeholder="t('i18n.pages.settings.notifications.select_delivery_mode')" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="mode in deliveryModes" :key="mode.value" :value="mode.value">
                      {{ mode.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>

              <FormField
                for="timezone"
                :label="t('i18n.pages.settings.notifications.time_zone')"
                :error="errors.timezone"
              >
                <Select v-model="timezone">
                  <SelectTrigger id="timezone" class="w-full">
                    <SelectValue :placeholder="t('i18n.pages.settings.notifications.select_time_zone')" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="zone in timezones" :key="zone" :value="zone">
                      {{ zone }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>

              <FormField
                for="quiet_hours_start"
                :label="t('i18n.pages.settings.notifications.quiet_hours_start')"
                :error="errors.quiet_hours_start"
              >
                <Input id="quiet_hours_start" v-model="quietHoursStart" type="time" />
              </FormField>

              <FormField
                for="quiet_hours_end"
                :label="t('i18n.pages.settings.notifications.quiet_hours_end')"
                :error="errors.quiet_hours_end"
              >
                <Input id="quiet_hours_end" v-model="quietHoursEnd" type="time" />
              </FormField>
            </div>

            <FormActions
              :dirty="preferenceChanges.isDirty.value"
              :processing="processing"
              @cancel="preferenceChanges.requestLeave"
            />
          </Form>
        </CardSection>

        <CardSection
          :title="t('i18n.pages.settings.notifications.digest_schedule')"
          :description="
            t('i18n.pages.settings.notifications.bundle_notifications_and_set_their_frequency_and_local_delivery')
          "
          data-notification-section="digest"
        >
          <Form
            :action="updatePreferences.url()"
            method="patch"
            data-test="digest-form"
            :on-success="digestChanges.markSaved"
            class="flex flex-col gap-4"
            v-slot="{ errors, processing }"
          >
            <div class="grid gap-4 sm:grid-cols-3">
              <FormField
                for="frequency"
                :label="t('i18n.pages.settings.notifications.frequency')"
                :error="errors.frequency"
              >
                <Select v-model="frequency" name="frequency">
                  <SelectTrigger id="frequency" class="w-full">
                    <SelectValue :placeholder="t('i18n.pages.settings.notifications.select_frequency')" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="daily">{{ t('i18n.pages.settings.notifications.daily') }}</SelectItem>
                    <SelectItem value="weekly">
                      {{ t('i18n.pages.settings.notifications.weekly') }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>

              <FormField
                v-if="frequency === 'weekly'"
                for="weekday"
                :label="t('i18n.pages.settings.notifications.day_of_week')"
                :error="errors.weekday"
              >
                <Select v-model="weekday" name="weekday">
                  <SelectTrigger id="weekday" class="w-full">
                    <SelectValue :placeholder="t('i18n.pages.settings.notifications.select_day_of_week')" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="day in weekdays" :key="day.value" :value="day.value">
                      {{ day.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>

              <FormField for="hour" :label="t('i18n.pages.settings.notifications.delivery_time')" :error="errors.hour">
                <Select v-model="hour" name="hour">
                  <SelectTrigger id="hour" class="w-full">
                    <SelectValue :placeholder="t('i18n.pages.settings.notifications.select_time')" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="value in hours" :key="value" :value="String(value)">
                      {{ String(value).padStart(2, '0') }}:00
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>
            </div>

            <FormActions
              :dirty="digestChanges.isDirty.value"
              :processing="processing"
              @cancel="digestChanges.requestLeave"
            />
          </Form>
        </CardSection>

        <CardSection
          :title="t('i18n.pages.settings.notifications.push_notifications')"
          :description="pushLabel"
          data-notification-section="push"
        >
          <FormSwitch
            id="push_notifications"
            v-model="isPushEnabled"
            :label="t('i18n.pages.settings.notifications.receive_push_notifications')"
            :disabled="!push.supported.value || push.busy.value"
            data-test="push-switch"
          />
          <AlertError v-if="push.error.value" data-test="push-error" :message="push.error.value" />
        </CardSection>

        <CardSection
          v-if="isEscalated"
          :title="t('i18n.pages.settings.notifications.tenant_defaults')"
          :description="tenantDefaultsDescription"
          data-notification-section="tenant-defaults"
        >
          <Form
            :action="updateTenantDefaults.url()"
            method="patch"
            :transform="buildTenantDefaultPayload"
            :on-success="defaultChanges.markSaved"
            class="flex flex-col gap-6"
            data-test="admin-defaults"
            v-slot="{ errors, processing }"
          >
            <div v-if="props.types.length > 0" class="grid gap-4 sm:grid-cols-2">
              <FormField
                v-for="type in props.types"
                :key="`default-${type.key}`"
                :for="`default-${type.key}`"
                :label="type.label"
              >
                <MultiSelect
                  :id="`default-${type.key}`"
                  v-model="defaultState[type.key]"
                  :options="channelOptions"
                  :placeholder="t('i18n.pages.settings.notifications.select_channels')"
                  :data-default-type="type.key"
                />
              </FormField>
              <InputError class="sm:col-span-2" :message="errors.defaults" />
            </div>

            <EmptyState
              v-if="props.types.length === 0"
              variant="inline"
              :description="t('i18n.pages.settings.notifications.no_notification_types_are_available_yet')"
            />

            <FormActions
              :dirty="defaultChanges.isDirty.value"
              :processing="processing"
              @cancel="defaultChanges.requestLeave"
            />
          </Form>
        </CardSection>
      </CardContent>
    </Card>

    <UnsavedChangesDialog
      v-for="(changes, index) in unsavedChanges"
      :key="index"
      :open="changes.promptOpen.value"
      @confirm="changes.confirmLeave"
      @cancel="changes.cancelLeave"
    />
  </div>
</template>
