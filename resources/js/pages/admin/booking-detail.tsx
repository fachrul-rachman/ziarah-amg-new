import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Save, XCircle } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type Booking = {
    id: number;
    public_reference: string;
    status: 'confirmed' | 'cancelled' | 'completed';
    visit_date: string;
    visit_time: string;
    zone: string;
    zone_id: number;
    lot_number: string;
    tent_required: boolean;
    chair_count: number;
    customer_name: string;
    customer_email: string;
    customer_phone: string;
    deceased_name: string;
    additional_notes: string | null;
    created_at: string | null;
    can_edit: boolean;
    can_cancel: boolean;
};

type Props = {
    booking: Booking;
    zones: { id: number; name: string }[];
    time_slots: string[];
};

export default function BookingDetail({ booking, zones, time_slots }: Props) {
    const { flash } = usePage().props as {
        flash?: { success?: string | null };
    };
    const form = useForm({
        visit_date: booking.visit_date,
        visit_time: booking.visit_time,
        zone_id: booking.zone_id,
        lot_number: booking.lot_number,
        tent_required: booking.tent_required,
        chair_count: booking.chair_count,
        customer_name: booking.customer_name,
        customer_email: booking.customer_email,
        customer_phone: booking.customer_phone,
        deceased_name: booking.deceased_name,
        additional_notes: booking.additional_notes ?? '',
    });
    const bookingError = (form.errors as Record<string, string>).booking;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.put(`/admin/bookings/${booking.id}`, { preserveScroll: true });
    }

    function cancelBooking() {
        if (
            window.confirm(
                'Batalkan booking ini? Booking tetap tersimpan sebagai riwayat.',
            )
        ) {
            router.post(
                `/admin/bookings/${booking.id}/cancel`,
                {},
                { preserveScroll: true },
            );
        }
    }

    const disabled = !booking.can_edit || form.processing;

    return (
        <AdminLayout>
            <Head title={`Booking ${booking.public_reference}`} />
            <div className="px-4 py-6 sm:px-6 sm:py-8 lg:px-10">
                <div className="mx-auto max-w-4xl">
                    <Link
                        href="/admin"
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg text-sm font-semibold text-brand-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary"
                    >
                        <ArrowLeft aria-hidden="true" className="size-5" />
                        Kembali ke dashboard
                    </Link>

                    <div className="mt-4">
                        <p className="text-sm text-slate-600">Kode booking</p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight break-all">
                            {booking.public_reference}
                        </h1>
                    </div>

                    {flash?.success && (
                        <p
                            role="status"
                            className="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800"
                        >
                            {flash.success}
                        </p>
                    )}
                    {bookingError && (
                        <p
                            role="alert"
                            className="mt-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800"
                        >
                            {bookingError}
                        </p>
                    )}
                    {!booking.can_edit && (
                        <p className="mt-5 rounded-lg border border-slate-200 bg-slate-100 p-4 text-sm text-slate-700">
                            Booking ini hanya dapat dilihat karena sudah
                            dibatalkan, selesai, atau waktu kunjungannya telah
                            lewat.
                        </p>
                    )}

                    <form
                        onSubmit={submit}
                        className="mt-6 space-y-6 rounded-xl border border-slate-200 bg-white p-5 sm:p-6"
                    >
                        <section aria-labelledby="visit-heading">
                            <h2 id="visit-heading" className="font-semibold">
                                Jadwal dan lokasi
                            </h2>
                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="visit-date"
                                    label="Tanggal kunjungan"
                                    error={form.errors.visit_date}
                                >
                                    <input
                                        id="visit-date"
                                        type="date"
                                        value={form.data.visit_date}
                                        disabled={disabled}
                                        onChange={(event) =>
                                            form.setData(
                                                'visit_date',
                                                event.target.value,
                                            )
                                        }
                                        className={inputClass}
                                    />
                                </Field>
                                <Field
                                    id="visit-time"
                                    label="Jam kunjungan"
                                    error={form.errors.visit_time}
                                >
                                    <select
                                        id="visit-time"
                                        value={form.data.visit_time}
                                        disabled={disabled}
                                        onChange={(event) =>
                                            form.setData(
                                                'visit_time',
                                                event.target.value,
                                            )
                                        }
                                        className={inputClass}
                                    >
                                        {time_slots.map((time) => (
                                            <option key={time} value={time}>
                                                {time}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    id="zone"
                                    label="Zona"
                                    error={form.errors.zone_id}
                                >
                                    <select
                                        id="zone"
                                        value={form.data.zone_id}
                                        disabled={disabled}
                                        onChange={(event) =>
                                            form.setData(
                                                'zone_id',
                                                Number(event.target.value),
                                            )
                                        }
                                        className={inputClass}
                                    >
                                        {zones.map((zone) => (
                                            <option
                                                key={zone.id}
                                                value={zone.id}
                                            >
                                                {zone.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    id="lot-number"
                                    label="Nomor lot"
                                    error={form.errors.lot_number}
                                >
                                    <input
                                        id="lot-number"
                                        value={form.data.lot_number}
                                        disabled={disabled}
                                        onChange={(event) =>
                                            form.setData(
                                                'lot_number',
                                                event.target.value.toUpperCase(),
                                            )
                                        }
                                        className={inputClass}
                                    />
                                </Field>
                                <Field
                                    id="chair-count"
                                    label="Jumlah kursi"
                                    error={form.errors.chair_count}
                                >
                                    <input
                                        id="chair-count"
                                        type="number"
                                        min="2"
                                        max="6"
                                        value={form.data.chair_count}
                                        disabled={disabled}
                                        onChange={(event) =>
                                            form.setData(
                                                'chair_count',
                                                Number(event.target.value),
                                            )
                                        }
                                        className={inputClass}
                                    />
                                </Field>
                                <label className="flex min-h-11 items-center gap-3 self-end text-sm font-semibold">
                                    <input
                                        type="checkbox"
                                        checked={form.data.tent_required}
                                        disabled={disabled}
                                        onChange={(event) =>
                                            form.setData(
                                                'tent_required',
                                                event.target.checked,
                                            )
                                        }
                                        className="size-5 accent-brand-primary"
                                    />
                                    Memerlukan tenda
                                </label>
                            </div>
                        </section>

                        <section
                            aria-labelledby="contact-heading"
                            className="border-t border-slate-200 pt-6"
                        >
                            <h2 id="contact-heading" className="font-semibold">
                                Data pemesan
                            </h2>
                            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="customer-name"
                                    label="Nama lengkap"
                                    error={form.errors.customer_name}
                                >
                                    <input
                                        id="customer-name"
                                        value={form.data.customer_name}
                                        disabled={disabled}
                                        onChange={(event) =>
                                            form.setData(
                                                'customer_name',
                                                event.target.value,
                                            )
                                        }
                                        className={inputClass}
                                    />
                                </Field>
                                <Field
                                    id="customer-email"
                                    label="Email"
                                    error={form.errors.customer_email}
                                >
                                    <input
                                        id="customer-email"
                                        type="email"
                                        required
                                        maxLength={255}
                                        value={form.data.customer_email}
                                        disabled={disabled}
                                        aria-invalid={Boolean(
                                            form.errors.customer_email,
                                        )}
                                        aria-describedby={
                                            form.errors.customer_email
                                                ? 'customer-email-error'
                                                : undefined
                                        }
                                        onChange={(event) =>
                                            form.setData(
                                                'customer_email',
                                                event.target.value,
                                            )
                                        }
                                        className={inputClass}
                                    />
                                </Field>
                                <Field
                                    id="customer-phone"
                                    label="Nomor telepon"
                                    error={form.errors.customer_phone}
                                >
                                    <input
                                        id="customer-phone"
                                        type="tel"
                                        inputMode="numeric"
                                        required
                                        minLength={10}
                                        maxLength={15}
                                        pattern="(?:08|62)[0-9]{8,13}"
                                        value={form.data.customer_phone}
                                        disabled={disabled}
                                        aria-invalid={Boolean(
                                            form.errors.customer_phone,
                                        )}
                                        aria-describedby={
                                            form.errors.customer_phone
                                                ? 'customer-phone-error'
                                                : undefined
                                        }
                                        onChange={(event) =>
                                            form.setData(
                                                'customer_phone',
                                                event.target.value
                                                    .replace(/\D/g, '')
                                                    .slice(0, 15),
                                            )
                                        }
                                        className={inputClass}
                                    />
                                </Field>
                                <Field
                                    id="deceased-name"
                                    label="Nama almarhum/almarhumah"
                                    error={form.errors.deceased_name}
                                >
                                    <input
                                        id="deceased-name"
                                        value={form.data.deceased_name}
                                        disabled={disabled}
                                        onChange={(event) =>
                                            form.setData(
                                                'deceased_name',
                                                event.target.value,
                                            )
                                        }
                                        className={inputClass}
                                    />
                                </Field>
                            </div>
                            <Field
                                id="additional-notes"
                                label="Catatan tambahan"
                                error={form.errors.additional_notes}
                                className="mt-4"
                            >
                                <textarea
                                    id="additional-notes"
                                    rows={4}
                                    value={form.data.additional_notes}
                                    disabled={disabled}
                                    onChange={(event) =>
                                        form.setData(
                                            'additional_notes',
                                            event.target.value,
                                        )
                                    }
                                    className={inputClass}
                                />
                            </Field>
                        </section>

                        {booking.can_edit && (
                            <div className="flex flex-col gap-3 border-t border-slate-200 pt-6 sm:flex-row sm:justify-between">
                                <button
                                    type="button"
                                    onClick={cancelBooking}
                                    disabled={
                                        !booking.can_cancel || form.processing
                                    }
                                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-red-300 px-4 text-sm font-semibold text-red-700 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 disabled:opacity-60"
                                >
                                    <XCircle
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                    Batalkan booking
                                </button>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-brand-primary px-5 text-sm font-semibold text-white hover:bg-brand-primary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary disabled:opacity-60"
                                >
                                    <Save
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                    Simpan perubahan
                                </button>
                            </div>
                        )}
                    </form>
                </div>
            </div>
        </AdminLayout>
    );
}

const inputClass =
    'min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-600';

function Field({
    id,
    label,
    error,
    className = '',
    children,
}: {
    id: string;
    label: string;
    error?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={className}>
            <label htmlFor={id} className="mb-2 block text-sm font-semibold">
                {label}
            </label>
            {children}
            {error && (
                <p
                    id={`${id}-error`}
                    role="alert"
                    className="mt-2 text-sm text-red-700"
                >
                    {error}
                </p>
            )}
        </div>
    );
}
