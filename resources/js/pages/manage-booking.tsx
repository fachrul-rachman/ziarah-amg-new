import { Head } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    CircleX,
    Mail,
    PencilLine,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type BookingDetail = {
    booking_reference: string;
    status: string;
    visit: {
        date: string;
        time: string;
        zone_id: number;
        zone: string;
        lot: string;
    };
    facilities: {
        tent_required: boolean;
        chair_count: number;
    };
    customer: {
        name: string;
        email: string;
        phone: string;
        deceased_name: string;
        additional_notes: string | null;
    };
    actions: {
        reschedule: boolean;
        cancel: boolean;
    };
};

type BookingOptions = {
    earliest_date: string;
    latest_date: string;
    zones: Array<{ id: number; name: string }>;
};

type Slot = {
    id: number;
    start_time: string;
    is_available: boolean;
    disabled_reason: 'date_full' | 'slot_full' | 'minimum_lead_time' | null;
};

type RescheduleData = {
    visit_date: string;
    visit_time: string;
    zone_id: number;
    lot_number: string;
    tent_required: boolean;
    chair_count: number;
    additional_notes: string;
};

export default function ManageBooking({ token }: { token: string }) {
    const [booking, setBooking] = useState<BookingDetail | null>(null);
    const [options, setOptions] = useState<BookingOptions | null>(null);
    const [slots, setSlots] = useState<Slot[]>([]);
    const [form, setForm] = useState<RescheduleData | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [loading, setLoading] = useState(true);
    const [loadingSlots, setLoadingSlots] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [mode, setMode] = useState<
        'detail' | 'reschedule' | 'rescheduled' | 'cancelled'
    >('detail');
    const [loadError, setLoadError] = useState('');
    const cancelDialog = useRef<HTMLDialogElement>(null);
    const encodedToken = encodeURIComponent(token);

    async function loadBooking() {
        setLoading(true);
        setLoadError('');

        try {
            const { detail, bookingOptions } =
                await fetchManagementData(encodedToken);
            setBooking(detail);
            setForm(formFromBooking(detail));
            setOptions(bookingOptions);
        } catch {
            setLoadError(
                'Link pengelolaan booking tidak tersedia atau sudah tidak berlaku.',
            );
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        const controller = new AbortController();

        fetchManagementData(encodedToken, controller.signal)
            .then(({ detail, bookingOptions }) => {
                setBooking(detail);
                setForm(formFromBooking(detail));
                setOptions(bookingOptions);
            })
            .catch((error: unknown) => {
                if (!(
                    error instanceof DOMException && error.name === 'AbortError'
                )) {
                    setLoadError(
                        'Link pengelolaan booking tidak tersedia atau sudah tidak berlaku.',
                    );
                }
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [encodedToken]);

    async function loadSlots(date: string) {
        setLoadingSlots(true);
        setSlots([]);
        setErrors((current) => ({
            ...current,
            visit_date: '',
            visit_time: '',
        }));

        try {
            const response = await fetch(
                `/api/manage/bookings/${encodedToken}/available-slots?date=${encodeURIComponent(date)}`,
                { headers: { Accept: 'application/json' } },
            );
            const body = (await response.json()) as {
                slots?: Slot[];
                message?: string;
            };

            if (!response.ok || !body.slots) {
                throw new Error();
            }

            setSlots(body.slots);
        } catch {
            setErrors((current) => ({
                ...current,
                visit_time:
                    'Slot waktu belum dapat dimuat. Pilih ulang tanggal.',
            }));
        } finally {
            setLoadingSlots(false);
        }
    }

    async function openReschedule() {
        if (!booking || !form) {
            return;
        }

        if (!options) {
            setErrors({
                submit: 'Pilihan jadwal belum dapat dimuat. Silakan muat ulang halaman.',
            });

            return;
        }

        setMode('reschedule');
        setErrors({});

        if (form.visit_date < options.earliest_date) {
            setForm((current) =>
                current
                    ? { ...current, visit_date: '', visit_time: '' }
                    : current,
            );
            setSlots([]);

            return;
        }

        await loadSlots(form.visit_date);
    }

    async function submitReschedule() {
        if (!form) {
            return;
        }

        setSubmitting(true);
        setErrors({});

        try {
            const response = await fetch(
                `/api/manage/bookings/${encodedToken}/reschedule`,
                {
                    method: 'PUT',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(form),
                },
            );
            const body = (await response.json()) as BookingDetail & {
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!response.ok) {
                setErrors(responseErrors(body));

                return;
            }

            setBooking(body);
            setMode('rescheduled');
        } catch {
            setErrors({
                submit: 'Koneksi bermasalah. Periksa jaringan lalu coba lagi.',
            });
        } finally {
            setSubmitting(false);
        }
    }

    async function submitCancellation() {
        setSubmitting(true);
        setErrors({});

        try {
            const response = await fetch(
                `/api/manage/bookings/${encodedToken}/cancel`,
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: '{}',
                },
            );
            const body = (await response.json()) as {
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!response.ok) {
                setErrors(responseErrors(body));
                cancelDialog.current?.close();

                return;
            }

            cancelDialog.current?.close();
            setMode('cancelled');
        } catch {
            setErrors({
                submit: 'Koneksi bermasalah. Periksa jaringan lalu coba lagi.',
            });
            cancelDialog.current?.close();
        } finally {
            setSubmitting(false);
        }
    }

    if (loading) {
        return <PageMessage title="Memuat booking..." />;
    }

    if (loadError || !booking || !form) {
        return (
            <PageMessage
                title="Booking tidak tersedia"
                description={loadError}
                action={
                    <button
                        type="button"
                        onClick={() => void loadBooking()}
                        className={primaryButton}
                    >
                        Coba lagi
                    </button>
                }
            />
        );
    }

    if (mode === 'rescheduled' || mode === 'cancelled') {
        const cancelled = mode === 'cancelled';

        return (
            <PageMessage
                icon={
                    cancelled ? (
                        <CircleX className="size-7" />
                    ) : (
                        <CheckCircle2 className="size-7" />
                    )
                }
                title={
                    cancelled
                        ? 'Booking berhasil dibatalkan'
                        : 'Jadwal berhasil diperbarui'
                }
                description={
                    cancelled
                        ? 'Konfirmasi pembatalan telah dikirim ke email Anda.'
                        : 'Link ini sudah tidak berlaku. Gunakan link pengelolaan baru yang dikirim ke email Anda.'
                }
            />
        );
    }

    return (
        <>
            <Head title="Kelola Booking" />
            <main className="min-h-screen bg-[#f6f7f9] px-4 py-6 text-[#172746] sm:py-10">
                <div className="mx-auto max-w-xl">
                    <header className="mb-5 text-center">
                        <p className="text-xs font-semibold tracking-wide text-brand-primary uppercase">
                            Al Azhar Memorial Garden
                        </p>
                        <h1 className="mt-1 text-xl font-semibold">
                            Kelola Booking Ziarah
                        </h1>
                    </header>

                    <section className="overflow-hidden rounded-lg border border-t-2 border-slate-300 border-t-[#d6a928] bg-white shadow-sm">
                        {mode === 'detail' ? (
                            <BookingOverview
                                booking={booking}
                                errors={errors}
                                onReschedule={() => void openReschedule()}
                                onCancel={() =>
                                    cancelDialog.current?.showModal()
                                }
                            />
                        ) : (
                            <RescheduleForm
                                booking={booking}
                                options={options}
                                form={form}
                                slots={slots}
                                errors={errors}
                                loadingSlots={loadingSlots}
                                submitting={submitting}
                                onChange={setForm}
                                onDate={(date) => {
                                    setForm((current) =>
                                        current
                                            ? {
                                                  ...current,
                                                  visit_date: date,
                                                  visit_time: '',
                                              }
                                            : current,
                                    );
                                    void loadSlots(date);
                                }}
                                onBack={() => {
                                    setErrors({});
                                    setMode('detail');
                                }}
                                onSubmit={() => void submitReschedule()}
                            />
                        )}
                    </section>
                </div>
            </main>

            <dialog
                ref={cancelDialog}
                aria-labelledby="cancel-title"
                className="m-auto w-[calc(100%-2rem)] max-w-md rounded-xl border border-slate-200 bg-white p-0 text-[#172746] shadow-xl backdrop:bg-slate-950/50"
            >
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 p-5">
                    <div>
                        <h2 id="cancel-title" className="font-semibold">
                            Batalkan booking?
                        </h2>
                        <p className="mt-1 text-sm text-slate-600">
                            Booking yang dibatalkan tidak dapat dipulihkan.
                        </p>
                    </div>
                    <button
                        type="button"
                        aria-label="Tutup"
                        onClick={() => cancelDialog.current?.close()}
                        className="flex size-11 shrink-0 items-center justify-center rounded-lg hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-brand-primary"
                    >
                        <X aria-hidden="true" className="size-5" />
                    </button>
                </div>
                <div className="flex justify-end gap-3 p-5">
                    <button
                        type="button"
                        onClick={() => cancelDialog.current?.close()}
                        className={secondaryButton}
                    >
                        Kembali
                    </button>
                    <button
                        type="button"
                        disabled={submitting}
                        onClick={() => void submitCancellation()}
                        className={dangerButton}
                    >
                        {submitting ? 'Memproses...' : 'Ya, batalkan'}
                    </button>
                </div>
            </dialog>
        </>
    );
}

function BookingOverview({
    booking,
    errors,
    onReschedule,
    onCancel,
}: {
    booking: BookingDetail;
    errors: Record<string, string>;
    onReschedule: () => void;
    onCancel: () => void;
}) {
    return (
        <>
            <header className="border-b border-slate-200 px-5 py-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="font-semibold">Detail Booking</h2>
                        <p className="mt-1 text-xs text-slate-500">
                            Referensi {booking.booking_reference}
                        </p>
                    </div>
                    <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                        Dikonfirmasi
                    </span>
                </div>
            </header>
            <div className="p-5">
                <DetailGroup
                    title="Kunjungan"
                    rows={[
                        ['Tanggal', formatDate(booking.visit.date)],
                        ['Jam', `${booking.visit.time} WIB`],
                        ['Zona', booking.visit.zone],
                        ['Lot', booking.visit.lot],
                    ]}
                />
                <DetailGroup
                    title="Fasilitas"
                    rows={[
                        ['Kursi', String(booking.facilities.chair_count)],
                        [
                            'Tenda',
                            booking.facilities.tent_required ? 'Ya' : 'Tidak',
                        ],
                    ]}
                />
                <DetailGroup
                    title="Data pemesan"
                    rows={[
                        ['Nama', booking.customer.name],
                        ['Email', booking.customer.email],
                        ['Telepon', booking.customer.phone],
                        ['Almarhum/ah', booking.customer.deceased_name],
                        [
                            'Catatan',
                            booking.customer.additional_notes || 'Tidak ada',
                        ],
                    ]}
                />

                <ErrorText error={errors.booking ?? errors.submit} />

                {booking.actions.reschedule || booking.actions.cancel ? (
                    <div className="mt-6 grid gap-3 sm:grid-cols-2">
                        {booking.actions.reschedule && (
                            <button
                                type="button"
                                onClick={onReschedule}
                                className={primaryButton}
                            >
                                <CalendarClock
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Jadwalkan ulang
                            </button>
                        )}
                        {booking.actions.cancel && (
                            <button
                                type="button"
                                onClick={onCancel}
                                className={dangerOutlineButton}
                            >
                                <CircleX
                                    aria-hidden="true"
                                    className="size-4"
                                />
                                Batalkan booking
                            </button>
                        )}
                    </div>
                ) : (
                    <p className="mt-6 rounded-lg bg-slate-100 p-3 text-sm text-slate-600">
                        Booking ini sudah melewati batas waktu perubahan.
                    </p>
                )}
            </div>
        </>
    );
}

function RescheduleForm({
    booking,
    options,
    form,
    slots,
    errors,
    loadingSlots,
    submitting,
    onChange,
    onDate,
    onBack,
    onSubmit,
}: {
    booking: BookingDetail;
    options: BookingOptions | null;
    form: RescheduleData;
    slots: Slot[];
    errors: Record<string, string>;
    loadingSlots: boolean;
    submitting: boolean;
    onChange: React.Dispatch<React.SetStateAction<RescheduleData | null>>;
    onDate: (date: string) => void;
    onBack: () => void;
    onSubmit: () => void;
}) {
    const currentZoneIsActive =
        options?.zones.some((zone) => zone.id === form.zone_id) ?? false;
    const chairError =
        form.chair_count > 500
            ? 'Jumlah kursi maksimal 500.'
            : errors.chair_count;

    return (
        <>
            <header className="border-b border-slate-200 px-5 py-4">
                <h2 className="font-semibold">Jadwalkan Ulang</h2>
                <p className="mt-1 text-xs text-slate-500">
                    Pilih jadwal dan detail kunjungan yang baru.
                </p>
            </header>
            <div className="space-y-5 p-5">
                <Field
                    id="reschedule-date"
                    label="Tanggal kunjungan"
                    error={errors.visit_date}
                >
                    <input
                        id="reschedule-date"
                        type="date"
                        min={options?.earliest_date}
                        max={options?.latest_date}
                        value={form.visit_date}
                        onChange={(event) => {
                            if (event.target.value) {
                                onDate(event.target.value);
                            }
                        }}
                        className={inputClass}
                        required
                    />
                </Field>

                <fieldset>
                    <legend className={labelClass}>Jam kunjungan</legend>
                    {loadingSlots ? (
                        <p role="status" className="mt-2 text-sm">
                            Memuat slot waktu...
                        </p>
                    ) : (
                        <div className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
                            {slots.map((slot) => (
                                <button
                                    key={slot.id}
                                    type="button"
                                    disabled={!slot.is_available}
                                    aria-pressed={
                                        form.visit_time === slot.start_time
                                    }
                                    onClick={() =>
                                        onChange((current) =>
                                            current
                                                ? {
                                                      ...current,
                                                      visit_time:
                                                          slot.start_time,
                                                  }
                                                : current,
                                        )
                                    }
                                    className={choiceClass(
                                        form.visit_time === slot.start_time,
                                    )}
                                >
                                    {slot.start_time}
                                </button>
                            ))}
                        </div>
                    )}
                    <ErrorText error={errors.visit_time} />
                </fieldset>

                <Field id="reschedule-zone" label="Zona" error={errors.zone_id}>
                    <select
                        id="reschedule-zone"
                        value={form.zone_id}
                        onChange={(event) =>
                            onChange((current) =>
                                current
                                    ? {
                                          ...current,
                                          zone_id: Number(event.target.value),
                                      }
                                    : current,
                            )
                        }
                        className={inputClass}
                    >
                        {!currentZoneIsActive && (
                            <option value={form.zone_id} disabled>
                                {booking.visit.zone} tidak aktif, pilih zona
                                baru
                            </option>
                        )}
                        {options?.zones.map((zone) => (
                            <option key={zone.id} value={zone.id}>
                                {zone.name}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field
                    id="reschedule-lot"
                    label="Nomor lot"
                    error={errors.lot_number}
                >
                    <input
                        id="reschedule-lot"
                        value={form.lot_number}
                        maxLength={50}
                        onChange={(event) =>
                            onChange((current) =>
                                current
                                    ? {
                                          ...current,
                                          lot_number: event.target.value
                                              .toUpperCase()
                                              .replace(/[^A-Z0-9/-]/g, ''),
                                      }
                                    : current,
                            )
                        }
                        className={inputClass}
                    />
                </Field>

                <fieldset>
                    <legend className={labelClass}>Tenda</legend>
                    <div className="mt-2 grid grid-cols-2 gap-2">
                        {[
                            ['Tidak', false],
                            ['Ya', true],
                        ].map(([label, value]) => (
                            <button
                                key={String(label)}
                                type="button"
                                aria-pressed={form.tent_required === value}
                                onClick={() =>
                                    onChange((current) =>
                                        current
                                            ? {
                                                  ...current,
                                                  tent_required:
                                                      value as boolean,
                                              }
                                            : current,
                                    )
                                }
                                className={choiceClass(
                                    form.tent_required === value,
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </fieldset>

                <Field
                    id="reschedule-chairs"
                    label="Jumlah kursi"
                    error={chairError}
                >
                    <input
                        id="reschedule-chairs"
                        type="number"
                        min="0"
                        max="500"
                        step="1"
                        inputMode="numeric"
                        value={form.chair_count}
                        onChange={(event) =>
                            onChange((current) =>
                                current
                                    ? {
                                          ...current,
                                          chair_count: Number(
                                              event.target.value,
                                          ),
                                      }
                                    : current,
                            )
                        }
                        className={inputClass}
                    />
                </Field>

                <p className="rounded-lg bg-sky-50 p-3 text-xs text-sky-900">
                    Setelah berhasil, link ini diganti. Link baru akan dikirim
                    ke {booking.customer.email}.
                </p>
                <ErrorText error={errors.booking ?? errors.submit} />
            </div>
            <footer className="flex justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button
                    type="button"
                    onClick={onBack}
                    className={secondaryButton}
                >
                    Kembali
                </button>
                <button
                    type="button"
                    disabled={submitting}
                    onClick={onSubmit}
                    className={primaryButton}
                >
                    <PencilLine aria-hidden="true" className="size-4" />
                    {submitting ? 'Menyimpan...' : 'Simpan jadwal'}
                </button>
            </footer>
        </>
    );
}

function DetailGroup({ title, rows }: { title: string; rows: string[][] }) {
    return (
        <section className="mb-4 overflow-hidden rounded-lg border border-slate-200 last:mb-0">
            <h3 className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold">
                {title}
            </h3>
            <dl className="text-sm">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="grid grid-cols-[7.5rem_1fr] gap-3 border-b border-slate-100 px-3 py-2.5 last:border-b-0"
                    >
                        <dt className="text-slate-500">{label}</dt>
                        <dd className="text-right font-medium break-words">
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label htmlFor={id} className={`mb-2 block ${labelClass}`}>
                {label}
            </label>
            {children}
            <ErrorText error={error} />
        </div>
    );
}

function ErrorText({ error }: { error?: string }) {
    return error ? (
        <p role="alert" className="mt-2 text-sm text-red-700">
            {error}
        </p>
    ) : null;
}

function PageMessage({
    title,
    description,
    icon,
    action,
}: {
    title: string;
    description?: string;
    icon?: React.ReactNode;
    action?: React.ReactNode;
}) {
    return (
        <>
            <Head title={title} />
            <main className="flex min-h-screen items-center justify-center bg-[#f6f7f9] px-4 py-10 text-[#172746]">
                <section className="w-full max-w-md rounded-lg border border-t-2 border-slate-300 border-t-[#d6a928] bg-white p-6 text-center shadow-sm">
                    {icon && (
                        <div className="mx-auto flex size-14 items-center justify-center rounded-full bg-sky-50 text-brand-primary">
                            {icon}
                        </div>
                    )}
                    <h1
                        className={`${icon ? 'mt-4' : ''} text-xl font-semibold`}
                    >
                        {title}
                    </h1>
                    {description && (
                        <p className="mt-2 text-sm text-slate-600">
                            {description}
                        </p>
                    )}
                    {action && <div className="mt-5">{action}</div>}
                    {(title.includes('diperbarui') ||
                        title.includes('dibatalkan')) && (
                        <div className="mt-5 flex items-center justify-center gap-2 text-xs text-slate-500">
                            <Mail aria-hidden="true" className="size-4" />
                            Periksa kotak masuk dan folder spam.
                        </div>
                    )}
                </section>
            </main>
        </>
    );
}

function formFromBooking(booking: BookingDetail): RescheduleData {
    return {
        visit_date: booking.visit.date,
        visit_time: booking.visit.time,
        zone_id: booking.visit.zone_id,
        lot_number: booking.visit.lot,
        tent_required: booking.facilities.tent_required,
        chair_count: booking.facilities.chair_count,
        additional_notes: booking.customer.additional_notes ?? '',
    };
}

async function fetchManagementData(encodedToken: string, signal?: AbortSignal) {
    const [bookingResponse, optionsResponse] = await Promise.all([
        fetch(`/api/manage/bookings/${encodedToken}`, {
            headers: { Accept: 'application/json' },
            signal,
        }),
        fetch('/api/public/booking-options', {
            headers: { Accept: 'application/json' },
            signal,
        }),
    ]);

    if (!bookingResponse.ok) {
        throw new Error('management-link');
    }

    return {
        detail: (await bookingResponse.json()) as BookingDetail,
        bookingOptions: optionsResponse.ok
            ? ((await optionsResponse.json()) as BookingOptions)
            : null,
    };
}

function responseErrors(body: {
    message?: string;
    errors?: Record<string, string[]>;
}) {
    if (body.errors) {
        return Object.fromEntries(
            Object.entries(body.errors).map(([key, value]) => [key, value[0]]),
        );
    }

    return {
        submit:
            body.message ??
            'Permintaan belum dapat diproses. Silakan coba lagi.',
    };
}

function formatDate(value: string) {
    return new Date(`${value}T00:00:00`).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function choiceClass(selected: boolean) {
    return `min-h-11 rounded-lg border px-3 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-300 ${
        selected
            ? 'border-brand-primary bg-sky-50 text-brand-primary-dark ring-1 ring-brand-primary'
            : 'border-slate-300 bg-white text-[#172746] hover:border-brand-primary'
    }`;
}

const labelClass =
    'text-[10px] font-semibold tracking-[0.12em] text-slate-500 uppercase';
const inputClass =
    'min-h-11 w-full rounded-lg border border-slate-300 bg-[#fafbfc] px-3 py-2 text-sm outline-none focus:border-brand-primary focus:bg-white focus:ring-3 focus:ring-sky-100';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-brand-primary px-4 py-2 text-sm font-semibold text-white hover:bg-brand-primary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary disabled:cursor-not-allowed disabled:opacity-60';
const secondaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:border-brand-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary';
const dangerButton =
    'inline-flex min-h-11 items-center justify-center rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 disabled:opacity-60';
const dangerOutlineButton =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700';
