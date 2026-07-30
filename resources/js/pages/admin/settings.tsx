import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type SettingsData = {
    daily_booking_limit: number | null;
    operations_email: string;
    discord_webhook_configured: boolean;
    discord_webhook_masked: string | null;
    embed_allowed_origins: string[];
};

export default function Settings({ settings }: { settings: SettingsData }) {
    const form = useForm({
        daily_booking_limit:
            settings.daily_booking_limit && settings.daily_booking_limit > 0
                ? String(settings.daily_booking_limit)
                : '',
        operations_email: settings.operations_email,
        discord_webhook: '',
        clear_discord_webhook: false,
        embed_allowed_origins: settings.embed_allowed_origins.join('\n'),
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            daily_booking_limit: Number(data.daily_booking_limit),
            embed_allowed_origins: data.embed_allowed_origins
                .split('\n')
                .map((origin) => origin.trim())
                .filter(Boolean),
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
                            label="Batas booking harian"
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
                                        event.target.value.replace(
                                            /^0+(?=\d)/,
                                            '',
                                        ),
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
