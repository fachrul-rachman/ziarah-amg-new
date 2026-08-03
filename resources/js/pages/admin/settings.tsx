import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type SettingsData = {
    booking_window_days: number;
    booking_limit_mode: 'daily' | 'hourly';
    daily_booking_limit: number | null;
    hourly_booking_limit: number | null;
    operations_email: string;
    discord_webhook_configured: boolean;
    discord_webhook_masked: string | null;
    embed_allowed_origins: string[];
    minimum_lead_hours: number;
    report_schedules: ReportSchedule[];
    report_settings_effective_from: string | null;
};

type ReportSchedule = { day_offset: -1 | 0; time: string };

export default function Settings({
    settings,
    visit_times,
}: {
    settings: SettingsData;
    visit_times: string[];
}) {
    const form = useForm({
        booking_window_days: String(settings.booking_window_days),
        booking_limit_mode: settings.booking_limit_mode,
        daily_booking_limit:
            settings.daily_booking_limit && settings.daily_booking_limit > 0
                ? String(settings.daily_booking_limit)
                : '',
        hourly_booking_limit:
            settings.hourly_booking_limit && settings.hourly_booking_limit > 0
                ? String(settings.hourly_booking_limit)
                : '',
        operations_email: settings.operations_email,
        discord_webhook: '',
        clear_discord_webhook: false,
        embed_allowed_origins: settings.embed_allowed_origins.join('\n'),
        minimum_lead_hours: String(settings.minimum_lead_hours),
        report_schedules: settings.report_schedules,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            booking_window_days: Number(data.booking_window_days),
            daily_booking_limit: Number(data.daily_booking_limit),
            hourly_booking_limit:
                data.hourly_booking_limit === ''
                    ? null
                    : Number(data.hourly_booking_limit),
            embed_allowed_origins: data.embed_allowed_origins
                .split('\n')
                .map((origin) => origin.trim())
                .filter(Boolean),
            minimum_lead_hours: Number(data.minimum_lead_hours),
        }));
        form.put('/admin/settings', {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('discord_webhook', '');
                form.setData('clear_discord_webhook', false);
            },
        });
    }

    const originError = Object.entries(form.errors).find(([key]) =>
        key.startsWith('embed_allowed_origins'),
    )?.[1];
    const scheduleError = Object.entries(form.errors).find(([key]) =>
        key.startsWith('report_schedules'),
    )?.[1];
    const preview = reportPreview(
        Number(form.data.minimum_lead_hours),
        form.data.report_schedules,
        visit_times,
    );

    return (
        <AdminLayout>
            <Head title="Pengaturan" />
            <div className="px-4 py-6 sm:px-6 sm:py-8 lg:px-10">
                <div className="mx-auto max-w-3xl">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Pengaturan
                    </h1>
                    <p className="mt-2 text-sm text-slate-600">
                        Konfigurasi global untuk booking, laporan, dan embed.
                    </p>

                    <form
                        onSubmit={submit}
                        className="mt-8 space-y-6 rounded-xl border border-slate-200 bg-white p-5 sm:p-6"
                    >
                        <Field
                            label="Maksimal hari booking"
                            id="booking-window-days"
                            error={form.errors.booking_window_days}
                            hint="Pilih antara 1 sampai 100 hari dari hari ini."
                        >
                            <input
                                id="booking-window-days"
                                type="number"
                                min="1"
                                max="100"
                                required
                                value={form.data.booking_window_days}
                                onChange={(event) =>
                                    form.setData(
                                        'booking_window_days',
                                        integerInput(event.target.value),
                                    )
                                }
                                aria-invalid={Boolean(
                                    form.errors.booking_window_days,
                                )}
                                aria-describedby={
                                    form.errors.booking_window_days
                                        ? 'booking-window-days-error'
                                        : 'booking-window-days-hint'
                                }
                                className="min-h-11 w-full rounded-lg border border-slate-300 px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                            />
                        </Field>

                        <fieldset>
                            <legend className="mb-2 text-sm font-semibold">
                                Perhitungan batas booking
                            </legend>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {[
                                    {
                                        value: 'daily' as const,
                                        label: 'Per hari',
                                        description:
                                            'Satu batas untuk seluruh booking pada tanggal tersebut.',
                                    },
                                    {
                                        value: 'hourly' as const,
                                        label: 'Per jam',
                                        description:
                                            'Batas terpisah untuk setiap slot jam.',
                                    },
                                ].map((option) => (
                                    <label
                                        key={option.value}
                                        className="flex min-h-16 cursor-pointer gap-3 rounded-lg border border-slate-300 p-3 has-checked:border-brand-primary has-checked:bg-sky-50"
                                    >
                                        <input
                                            type="radio"
                                            name="booking-limit-mode"
                                            value={option.value}
                                            checked={
                                                form.data.booking_limit_mode ===
                                                option.value
                                            }
                                            onChange={() =>
                                                form.setData(
                                                    'booking_limit_mode',
                                                    option.value,
                                                )
                                            }
                                            className="mt-1 size-4 accent-brand-primary"
                                        />
                                        <span>
                                            <span className="block text-sm font-semibold">
                                                {option.label}
                                            </span>
                                            <span className="mt-1 block text-xs text-slate-600">
                                                {option.description}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            {form.errors.booking_limit_mode && (
                                <p
                                    role="alert"
                                    className="mt-2 text-sm text-red-700"
                                >
                                    {form.errors.booking_limit_mode}
                                </p>
                            )}
                        </fieldset>

                        {form.data.booking_limit_mode === 'daily' ? (
                            <Field
                                label="Batas booking per hari"
                                id="daily-booking-limit"
                                error={form.errors.daily_booking_limit}
                            >
                                <input
                                    id="daily-booking-limit"
                                    type="number"
                                    min="1"
                                    required
                                    value={form.data.daily_booking_limit}
                                    onChange={(event) =>
                                        form.setData(
                                            'daily_booking_limit',
                                            integerInput(event.target.value),
                                        )
                                    }
                                    aria-invalid={Boolean(
                                        form.errors.daily_booking_limit,
                                    )}
                                    aria-describedby={
                                        form.errors.daily_booking_limit
                                            ? 'daily-booking-limit-error'
                                            : undefined
                                    }
                                    className="min-h-11 w-full rounded-lg border border-slate-300 px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                                />
                            </Field>
                        ) : (
                            <Field
                                label="Batas booking per jam"
                                id="hourly-booking-limit"
                                error={form.errors.hourly_booking_limit}
                                hint="Berlaku sama untuk setiap slot jam yang aktif."
                            >
                                <input
                                    id="hourly-booking-limit"
                                    type="number"
                                    min="1"
                                    required
                                    value={form.data.hourly_booking_limit}
                                    onChange={(event) =>
                                        form.setData(
                                            'hourly_booking_limit',
                                            integerInput(event.target.value),
                                        )
                                    }
                                    aria-invalid={Boolean(
                                        form.errors.hourly_booking_limit,
                                    )}
                                    aria-describedby={
                                        form.errors.hourly_booking_limit
                                            ? 'hourly-booking-limit-error'
                                            : 'hourly-booking-limit-hint'
                                    }
                                    className="min-h-11 w-full rounded-lg border border-slate-300 px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                                />
                            </Field>
                        )}

                        <div className="border-t border-slate-200 pt-6">
                            <h2 className="text-base font-semibold">
                                Waktu minimum dan laporan operasional
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                Perubahan berlaku untuk tanggal kunjungan H+2
                                agar laporan yang sedang berjalan tidak berubah.
                            </p>
                        </div>

                        <Field
                            label="Minimal waktu booking (jam)"
                            id="minimum-lead-hours"
                            error={form.errors.minimum_lead_hours}
                        >
                            <input
                                id="minimum-lead-hours"
                                type="number"
                                min="1"
                                required
                                value={form.data.minimum_lead_hours}
                                onChange={(event) =>
                                    form.setData(
                                        'minimum_lead_hours',
                                        integerInput(event.target.value),
                                    )
                                }
                                className="min-h-11 w-full rounded-lg border border-slate-300 px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                            />
                        </Field>

                        <fieldset>
                            <legend className="text-sm font-semibold">
                                Jadwal pengiriman laporan
                            </legend>
                            <p className="mt-1 text-sm text-slate-600">
                                Minimal 1 dan maksimal 3 jadwal. Laporan dikirim
                                pada tick cron pertama setelah jeda 5 menit dari
                                waktu ini.
                            </p>
                            <div className="mt-3 space-y-3">
                                {form.data.report_schedules.map(
                                    (schedule, index) => (
                                        <div
                                            key={index}
                                            className="grid gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-[1fr_1fr_auto]"
                                        >
                                            <label className="text-sm font-medium">
                                                Hari
                                                <select
                                                    value={schedule.day_offset}
                                                    onChange={(event) => {
                                                        const schedules = [
                                                            ...form.data
                                                                .report_schedules,
                                                        ];
                                                        schedules[index] = {
                                                            ...schedule,
                                                            day_offset: Number(
                                                                event.target
                                                                    .value,
                                                            ) as -1 | 0,
                                                        };
                                                        form.setData(
                                                            'report_schedules',
                                                            schedules,
                                                        );
                                                    }}
                                                    className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"
                                                >
                                                    <option value={-1}>
                                                        H-1 kunjungan
                                                    </option>
                                                    <option value={0}>
                                                        Hari kunjungan
                                                    </option>
                                                </select>
                                            </label>
                                            <label className="text-sm font-medium">
                                                Jam
                                                <input
                                                    type="time"
                                                    required
                                                    value={schedule.time}
                                                    onChange={(event) => {
                                                        const schedules = [
                                                            ...form.data
                                                                .report_schedules,
                                                        ];
                                                        schedules[index] = {
                                                            ...schedule,
                                                            time: event.target
                                                                .value,
                                                        };
                                                        form.setData(
                                                            'report_schedules',
                                                            schedules,
                                                        );
                                                    }}
                                                    className="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3"
                                                />
                                            </label>
                                            <button
                                                type="button"
                                                aria-label={`Hapus jadwal ${index + 1}`}
                                                disabled={
                                                    form.data.report_schedules
                                                        .length === 1
                                                }
                                                onClick={() =>
                                                    form.setData(
                                                        'report_schedules',
                                                        form.data.report_schedules.filter(
                                                            (_, itemIndex) =>
                                                                itemIndex !==
                                                                index,
                                                        ),
                                                    )
                                                }
                                                className="min-h-11 self-end rounded-lg border border-slate-300 px-3 text-red-700 disabled:opacity-40"
                                            >
                                                <Trash2
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </div>
                                    ),
                                )}
                            </div>
                            {form.data.report_schedules.length < 3 && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData('report_schedules', [
                                            ...form.data.report_schedules,
                                            { day_offset: 0, time: '07:00' },
                                        ])
                                    }
                                    className="mt-3 inline-flex min-h-11 items-center gap-2 rounded-lg border border-slate-300 px-3 text-sm font-semibold"
                                >
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Tambah jadwal
                                </button>
                            )}
                            {scheduleError && (
                                <p
                                    role="alert"
                                    className="mt-2 text-sm text-red-700"
                                >
                                    {scheduleError}
                                </p>
                            )}
                        </fieldset>

                        {preview.length > 0 && (
                            <div className="rounded-lg bg-slate-50 p-4">
                                <h3 className="text-sm font-semibold">
                                    Preview pembagian jam kunjungan
                                </h3>
                                <ul className="mt-2 space-y-1 text-sm text-slate-700">
                                    {preview.map((item, index) => (
                                        <li key={`${item.label}-${index}`}>
                                            {item.label}:{' '}
                                            {item.times.length > 0
                                                ? `${item.times.join(', ')} WIB`
                                                : 'tidak mendapat jam kunjungan'}
                                        </li>
                                    ))}
                                </ul>
                                {settings.report_settings_effective_from && (
                                    <p className="mt-2 text-xs text-slate-600">
                                        Konfigurasi tersimpan saat ini berlaku
                                        mulai{' '}
                                        {
                                            settings.report_settings_effective_from
                                        }
                                        .
                                    </p>
                                )}
                            </div>
                        )}

                        <Field
                            label="Email laporan operasional"
                            id="operations-email"
                            error={form.errors.operations_email}
                        >
                            <input
                                id="operations-email"
                                type="email"
                                value={form.data.operations_email}
                                onChange={(event) =>
                                    form.setData(
                                        'operations_email',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={Boolean(
                                    form.errors.operations_email,
                                )}
                                aria-describedby={
                                    form.errors.operations_email
                                        ? 'operations-email-error'
                                        : undefined
                                }
                                className="min-h-11 w-full rounded-lg border border-slate-300 px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                            />
                        </Field>

                        <Field
                            label="Discord webhook"
                            id="discord-webhook"
                            error={form.errors.discord_webhook}
                            hint={
                                settings.discord_webhook_configured
                                    ? `Tersimpan: ${settings.discord_webhook_masked}. Kosongkan untuk mempertahankan.`
                                    : 'Opsional. Hanya URL webhook Discord HTTPS.'
                            }
                        >
                            <input
                                id="discord-webhook"
                                type="url"
                                value={form.data.discord_webhook}
                                onChange={(event) => {
                                    form.setData(
                                        'discord_webhook',
                                        event.target.value,
                                    );
                                    form.setData(
                                        'clear_discord_webhook',
                                        false,
                                    );
                                }}
                                aria-invalid={Boolean(
                                    form.errors.discord_webhook,
                                )}
                                aria-describedby={
                                    form.errors.discord_webhook
                                        ? 'discord-webhook-error'
                                        : 'discord-webhook-hint'
                                }
                                className="min-h-11 w-full rounded-lg border border-slate-300 px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                            />
                        </Field>

                        {settings.discord_webhook_configured && (
                            <label className="flex min-h-11 items-center gap-3 text-sm font-medium">
                                <input
                                    type="checkbox"
                                    checked={form.data.clear_discord_webhook}
                                    onChange={(event) =>
                                        form.setData(
                                            'clear_discord_webhook',
                                            event.target.checked,
                                        )
                                    }
                                    className="size-5 accent-brand-primary"
                                />
                                Hapus webhook tersimpan
                            </label>
                        )}

                        <Field
                            label="Origin website utama"
                            id="embed-origins"
                            error={originError}
                            hint="Satu origin per baris, misalnya https://www.example.com. Kosong berarti embed tidak diizinkan."
                        >
                            <textarea
                                id="embed-origins"
                                rows={4}
                                value={form.data.embed_allowed_origins}
                                onChange={(event) =>
                                    form.setData(
                                        'embed_allowed_origins',
                                        event.target.value,
                                    )
                                }
                                aria-invalid={Boolean(originError)}
                                aria-describedby={
                                    originError
                                        ? 'embed-origins-error'
                                        : 'embed-origins-hint'
                                }
                                className="w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                            />
                        </Field>

                        <button
                            type="submit"
                            disabled={form.processing}
                            className="min-h-11 rounded-lg bg-brand-primary px-5 font-semibold text-white hover:bg-brand-primary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary disabled:opacity-60"
                        >
                            Simpan pengaturan
                        </button>
                    </form>
                </div>
            </div>
        </AdminLayout>
    );
}

function integerInput(value: string) {
    return value.replace(/^0+(?=\d)/, '');
}

function reportPreview(
    leadHours: number,
    schedules: ReportSchedule[],
    visitTimes: string[],
) {
    if (!Number.isInteger(leadHours) || leadHours < 1) {
        return [];
    }

    const referenceDay = 2 * 24 * 60;
    const runs = schedules
        .map((schedule) => ({
            ...schedule,
            minute:
                referenceDay +
                schedule.day_offset * 1440 +
                timeMinutes(schedule.time),
            times: [] as string[],
        }))
        .sort((a, b) => a.minute - b.minute);

    for (const visitTime of visitTimes) {
        const visitMinute = referenceDay + timeMinutes(visitTime);
        const cutoff = visitMinute - leadHours * 60;
        const run = runs.find(
            (item) => item.minute >= cutoff && item.minute + 5 < visitMinute,
        );
        run?.times.push(visitTime);
    }

    return runs.map((run) => ({
        label: `${run.day_offset === -1 ? 'H-1' : 'Hari H'} ${run.time}`,
        times: run.times,
    }));
}

function timeMinutes(time: string) {
    const [hour, minute] = time.split(':').map(Number);

    return hour * 60 + minute;
}

function Field({
    label,
    id,
    error,
    hint,
    children,
}: {
    label: string;
    id: string;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    const descriptionId = error
        ? `${id}-error`
        : hint
          ? `${id}-hint`
          : undefined;

    return (
        <div>
            <label htmlFor={id} className="mb-2 block text-sm font-semibold">
                {label}
            </label>
            {children}
            {error ? (
                <p
                    id={descriptionId}
                    role="alert"
                    className="mt-2 text-sm text-red-700"
                >
                    {error}
                </p>
            ) : (
                hint && (
                    <p
                        id={descriptionId}
                        className="mt-2 text-sm text-slate-600"
                    >
                        {hint}
                    </p>
                )
            )}
        </div>
    );
}
