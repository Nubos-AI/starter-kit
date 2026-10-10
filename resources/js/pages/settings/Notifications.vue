<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import {
    update as updatePreferences,
    updateTenantDefaults,
} from '@/actions/App/Http/Controllers/Notifications/NotificationPreferencesController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import { usePushSubscription } from '@/composables/usePushSubscription';

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

type PreferenceMap = Record<
    string,
    Record<string, StoredPreference | undefined> | undefined
>;

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

const channelState = reactive<Record<string, Record<string, boolean>>>({});
const defaultState = reactive<Record<string, Record<string, boolean>>>({});

for (const type of props.types) {
    channelState[type.key] = {};
    defaultState[type.key] = {};

    for (const channel of channels) {
        channelState[type.key][channel.value] =
            props.notificationPreferences?.[type.key]?.[channel.value]
                ?.enabled === true;
        defaultState[type.key][channel.value] =
            props.defaults?.[type.key]?.[channel.value]?.enabled === true;
    }
}

function firstStoredDeliveryMode(): string {
    for (const type of props.types) {
        for (const channel of channels) {
            const stored =
                props.notificationPreferences?.[type.key]?.[channel.value];

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
            enabled: channelState[type.key]?.[channel.value] === true,
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
                enabled: defaultState[type.key]?.[channel.value] === true,
                delivery_mode:
                    props.defaults?.[type.key]?.[channel.value]
                        ?.delivery_mode ?? 'immediate',
            })),
        ),
    };
}

const timezones = [
    'UTC',
    'Europe/Berlin',
    'Europe/London',
    'Europe/Zurich',
    'America/New_York',
];

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

const push = usePushSubscription();

const pushLabel = computed<string>(() => {
    if (!push.supported.value) {
        return t(
            'i18n.pages.settings.notifications.your_browser_does_not_support_push_notifications',
        );
    }

    if (push.subscribed.value || push.permission.value === 'granted') {
        return t(
            'i18n.pages.settings.notifications.push_notifications_are_enabled',
        );
    }

    if (push.permission.value === 'denied') {
        return t(
            'i18n.pages.settings.notifications.push_notifications_are_blocked_in_your_browser',
        );
    }

    return t(
        'i18n.pages.settings.notifications.push_notifications_are_not_enabled',
    );
});
</script>

<template>
    <Head
        :title="t('i18n.pages.settings.notifications.notification_settings')"
    />

    <h1 class="sr-only">
        {{ t('i18n.pages.settings.notifications.notification_settings') }}
    </h1>

    <div class="flex flex-col gap-8">
        <Heading
            variant="small"
            :title="t('i18n.pages.settings.notifications.notifications')"
            :description="
                t(
                    'i18n.pages.settings.notifications.manage_channels_digest_schedules_and_push_notifications',
                )
            "
        />

        <Form
            :action="updatePreferences.url()"
            method="patch"
            :transform="buildPreferencePayload"
            class="flex flex-col gap-6"
            v-slot="{ processing }"
        >
            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.settings.notifications.channels_delivery')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.settings.notifications.for_each_notification_type_select_channels_delivery_mode_your',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-6">
                    <fieldset
                        v-for="type in props.types"
                        :key="type.key"
                        class="flex flex-col gap-3"
                    >
                        <legend class="text-sm font-medium">
                            {{ type.label }}
                        </legend>
                        <div class="flex flex-wrap gap-4">
                            <div
                                v-for="channel in channels"
                                :key="channel.value"
                                class="flex items-center gap-2"
                            >
                                <Checkbox
                                    :id="`channel-${type.key}-${channel.value}`"
                                    v-model="
                                        channelState[type.key][channel.value]
                                    "
                                />
                                <Label
                                    :for="`channel-${type.key}-${channel.value}`"
                                >
                                    {{ channel.label }}
                                </Label>
                            </div>
                        </div>
                    </fieldset>

                    <p
                        v-if="props.types.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            t(
                                'i18n.pages.settings.notifications.no_notification_types_are_available_yet',
                            )
                        }}
                    </p>

                    <Separator />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="delivery_mode">{{
                                t(
                                    'i18n.pages.settings.notifications.delivery_mode',
                                )
                            }}</Label>
                            <Select v-model="deliveryMode">
                                <SelectTrigger
                                    id="delivery_mode"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.pages.settings.notifications.select_delivery_mode',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="mode in deliveryModes"
                                        :key="mode.value"
                                        :value="mode.value"
                                    >
                                        {{ mode.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="timezone">{{
                                t('i18n.pages.settings.notifications.time_zone')
                            }}</Label>
                            <Select v-model="timezone">
                                <SelectTrigger id="timezone" class="w-full">
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.pages.settings.notifications.select_time_zone',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="zone in timezones"
                                        :key="zone"
                                        :value="zone"
                                    >
                                        {{ zone }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-2">
                            <Label for="quiet_hours_start">
                                {{
                                    t(
                                        'i18n.pages.settings.notifications.quiet_hours_start',
                                    )
                                }}
                            </Label>
                            <Input
                                id="quiet_hours_start"
                                v-model="quietHoursStart"
                                type="time"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="quiet_hours_end">{{
                                t(
                                    'i18n.pages.settings.notifications.quiet_hours_end',
                                )
                            }}</Label>
                            <Input
                                id="quiet_hours_end"
                                v-model="quietHoursEnd"
                                type="time"
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="processing">{{
                    t('i18n.pages.settings.notifications.save')
                }}</Button>
            </div>
        </Form>

        <Form
            :action="updatePreferences.url()"
            method="patch"
            data-test="digest-form"
            class="flex flex-col gap-6"
            v-slot="{ processing }"
        >
            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.settings.notifications.digest_schedule')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.settings.notifications.bundle_notifications_and_set_their_frequency_and_local_delivery',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="frequency">{{
                            t('i18n.pages.settings.notifications.frequency')
                        }}</Label>
                        <Select v-model="frequency" name="frequency">
                            <SelectTrigger id="frequency" class="w-full">
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.pages.settings.notifications.select_frequency',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="daily">{{
                                    t('i18n.pages.settings.notifications.daily')
                                }}</SelectItem>
                                <SelectItem value="weekly">
                                    {{
                                        t(
                                            'i18n.pages.settings.notifications.weekly',
                                        )
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div v-if="frequency === 'weekly'" class="grid gap-2">
                        <Label for="weekday">{{
                            t('i18n.pages.settings.notifications.day_of_week')
                        }}</Label>
                        <Select v-model="weekday" name="weekday">
                            <SelectTrigger id="weekday" class="w-full">
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.pages.settings.notifications.select_day_of_week',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="day in weekdays"
                                    :key="day.value"
                                    :value="day.value"
                                >
                                    {{ day.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-2">
                        <Label for="hour">{{
                            t('i18n.pages.settings.notifications.delivery_time')
                        }}</Label>
                        <Select v-model="hour" name="hour">
                            <SelectTrigger id="hour" class="w-full">
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.pages.settings.notifications.select_time',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="value in hours"
                                    :key="value"
                                    :value="String(value)"
                                >
                                    {{ String(value).padStart(2, '0') }}:00
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </CardContent>
            </Card>

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="processing">
                    {{ t('i18n.pages.settings.notifications.save_digest') }}
                </Button>
            </div>
        </Form>

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.settings.notifications.push_notifications')
                }}</CardTitle>
                <CardDescription>{{ pushLabel }}</CardDescription>
            </CardHeader>
            <CardContent class="flex items-center gap-4">
                <Button
                    v-if="!push.subscribed.value"
                    type="button"
                    :disabled="!push.supported.value || push.busy.value"
                    @click="push.subscribe()"
                >
                    {{ t('i18n.pages.settings.notifications.enable_push') }}
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    :disabled="push.busy.value"
                    @click="push.unsubscribe()"
                >
                    {{ t('i18n.pages.settings.notifications.disable_push') }}
                </Button>
                <p
                    v-if="push.error.value"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ push.error.value }}
                </p>
            </CardContent>
        </Card>

        <section
            v-if="isEscalated"
            data-test="admin-defaults"
            class="flex flex-col gap-6"
            aria-labelledby="admin-defaults-heading"
        >
            <Separator />
            <Heading
                variant="small"
                :title="t('i18n.pages.settings.notifications.tenant_defaults')"
                :description="
                    t(
                        'i18n.pages.settings.notifications.set_default_channels_for_each_notification_type_across_the',
                    )
                "
            />
            <Form
                :action="updateTenantDefaults.url()"
                method="patch"
                :transform="buildTenantDefaultPayload"
                class="flex flex-col gap-6"
                v-slot="{ processing }"
            >
                <Card>
                    <CardHeader>
                        <CardTitle id="admin-defaults-heading">
                            {{
                                t(
                                    'i18n.pages.settings.notifications.default_channels',
                                )
                            }}
                        </CardTitle>
                        <CardDescription>
                            {{
                                t(
                                    'i18n.pages.settings.notifications.these_defaults_apply_until_a_user_sets_their_own',
                                )
                            }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-6">
                        <fieldset
                            v-for="type in props.types"
                            :key="`default-${type.key}`"
                            class="flex flex-col gap-3"
                        >
                            <legend class="text-sm font-medium">
                                {{ type.label }}
                            </legend>
                            <div class="flex flex-wrap gap-4">
                                <div
                                    v-for="channel in channels"
                                    :key="channel.value"
                                    class="flex items-center gap-2"
                                >
                                    <Checkbox
                                        :id="`default-${type.key}-${channel.value}`"
                                        v-model="
                                            defaultState[type.key][
                                                channel.value
                                            ]
                                        "
                                    />
                                    <Label
                                        :for="`default-${type.key}-${channel.value}`"
                                    >
                                        {{ channel.label }}
                                    </Label>
                                </div>
                            </div>
                        </fieldset>

                        <p
                            v-if="props.types.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                t(
                                    'i18n.pages.settings.notifications.no_notification_types_are_available_yet',
                                )
                            }}
                        </p>
                    </CardContent>
                </Card>

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="processing">
                        {{
                            t('i18n.pages.settings.notifications.save_defaults')
                        }}
                    </Button>
                </div>
            </Form>
        </section>
    </div>
</template>
