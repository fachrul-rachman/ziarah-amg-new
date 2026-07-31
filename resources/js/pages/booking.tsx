import { Head } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    ChevronLeft,
    ChevronRight,
    Search,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

type DateAvailability = {
    date: string;
    is_full: boolean;
    is_available: boolean;
};

type Zone = {
    id: number;
    name: string;
};

type TimeSlot = {
    id: number;
    start_time: string;
    is_available?: boolean;
    disabled_reason?: 'date_full' | 'slot_full' | 'minimum_lead_time' | null;
};

type BookingOptions = {
    earliest_date: string;
    latest_date: string;
    booking_window_days: number;
    dates: DateAvailability[];
    zones: Zone[];
    time_slots: TimeSlot[];
    form_token: string;
};

type BookingData = {
    visit_date: string;
    visit_time: string;
    zone_id: number | null;
    lot_number: string;
    tent_required: boolean | null;
    chair_count: number;
    customer_name: string;
    customer_email: string;
    customer_phone: string;
    deceased_name: string;
    additional_notes: string;
    ethics_confirmed: boolean;
};

type BookingResult = {
    booking_reference: string;
    status: string;
    visit: {
        date: string;
        time: string;
        zone: string;
        lot: string;
    };
};

type TurnstileApi = {
    render: (
        element: HTMLElement,
        options: {
            sitekey: string;
            callback: (token: string) => void;
            'expired-callback': () => void;
            'error-callback': () => void;
        },
    ) => string;
    remove: (widgetId: string) => void;
};

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}

const initialData: BookingData = {
    visit_date: '',
    visit_time: '',
    zone_id: null,
    lot_number: '',
    tent_required: null,
    chair_count: 0,
    customer_name: '',
    customer_email: '',
    customer_phone: '',
    deceased_name: '',
    additional_notes: '',
    ethics_confirmed: false,
};

const steps = ['Kunjungan', 'Fasilitas', 'Data Diri', 'Tinjau'];

export default function Booking({
    turnstileSiteKey,
}: {
    turnstileSiteKey: string | null;
}) {
    const [options, setOptions] = useState<BookingOptions | null>(null);
    const [slots, setSlots] = useState<TimeSlot[]>([]);
    const [data, setData] = useState<BookingData>(initialData);
    const [step, setStep] = useState(0);
    const [zoneSearch, setZoneSearch] = useState('');
    const [turnstileToken, setTurnstileToken] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [loading, setLoading] = useState(true);
    const [loadingSlots, setLoadingSlots] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [loadError, setLoadError] = useState('');
    const [result, setResult] = useState<BookingResult | null>(null);

    async function loadOptions() {
        setLoading(true);
        setLoadError('');

        try {
            const response = await fetch('/api/public/booking-options', {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error();
            }

            setOptions((await response.json()) as BookingOptions);
        } catch {
            setLoadError(
                'Pilihan booking belum dapat dimuat. Silakan coba lagi.',
            );
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        const controller = new AbortController();

        fetch('/api/public/booking-options', {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error();
                }

                return response.json() as Promise<BookingOptions>;
            })
            .then(setOptions)
            .catch((error: unknown) => {
                if (!(
                    error instanceof DOMException && error.name === 'AbortError'
                )) {
                    setLoadError(
                        'Pilihan booking belum dapat dimuat. Silakan coba lagi.',
                    );
                }
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, []);

    async function selectDate(date: string) {
        setData((current) => ({
            ...current,
            visit_date: date,
            visit_time: '',
        }));
        setLoadingSlots(true);
        setErrors((current) => ({
            ...current,
            visit_date: '',
            visit_time: '',
        }));

        try {
            const response = await fetch(
                `/api/public/available-slots?date=${encodeURIComponent(date)}`,
                { headers: { Accept: 'application/json' } },
            );
            const body = (await response.json()) as {
                slots?: TimeSlot[];
                message?: string;
            };

            if (!response.ok || !body.slots) {
                throw new Error(body.message);
            }

            setSlots(body.slots);
        } catch {
            setSlots([]);
            setErrors((current) => ({
                ...current,
                visit_time:
                    'Slot waktu belum dapat dimuat. Pilih ulang tanggal.',
            }));
        } finally {
            setLoadingSlots(false);
        }
    }

    const filteredZones = useMemo(() => {
        const search = zoneSearch.trim().toLocaleLowerCase('id');

        return (
            options?.zones.filter((zone) =>
                zone.name.toLocaleLowerCase('id').includes(search),
            ) ?? []
        );
    }, [options, zoneSearch]);

    const selectedZone = options?.zones.find(
        (zone) => zone.id === data.zone_id,
    );
    const selectedDate = options?.dates.find(
        (date) => date.date === data.visit_date,
    );

    function validateStep(currentStep: number): boolean {
        const nextErrors: Record<string, string> = {};

        if (currentStep === 0) {
            if (!data.visit_date) {
                nextErrors.visit_date = 'Pilih tanggal kunjungan.';
            } else if (selectedDate?.is_full) {
                nextErrors.visit_date = 'Tanggal ini sudah penuh.';
            }

            if (!data.visit_time) {
                nextErrors.visit_time = 'Pilih jam kunjungan.';
            }

            if (!data.zone_id) {
                nextErrors.zone_id = 'Pilih zona.';
            }

            if (!/^[A-Z0-9]+([/-][A-Z0-9]+)*$/.test(data.lot_number)) {
                nextErrors.lot_number =
                    'Lot hanya boleh berisi huruf, angka, tanda - atau /.';
            }
        }

        if (currentStep === 1) {
            if (data.chair_count < 0) {
                nextErrors.chair_count =
                    'Jumlah kursi tidak boleh kurang dari 0.';
            } else if (data.chair_count > 500) {
                nextErrors.chair_count = 'Jumlah kursi maksimal 500.';
            }

            if (data.tent_required === null) {
                nextErrors.tent_required = 'Pilih kebutuhan tenda.';
            }
        }

        if (currentStep === 2) {
            if (!data.customer_name.trim()) {
                nextErrors.customer_name = 'Nama lengkap wajib diisi.';
            }

            if (!data.customer_email.trim()) {
                nextErrors.customer_email = 'Email wajib diisi.';
            } else if (!isValidEmail(data.customer_email)) {
                nextErrors.customer_email = 'Format email tidak valid.';
            }

            if (!data.customer_phone.trim()) {
                nextErrors.customer_phone = 'Nomor telepon wajib diisi.';
            } else if (!isValidPhone(data.customer_phone)) {
                nextErrors.customer_phone =
                    'Nomor telepon harus terdiri dari 10-15 digit dan diawali 08 atau 62.';
            }

            if (!data.deceased_name.trim()) {
                nextErrors.deceased_name =
                    'Nama almarhum atau almarhumah wajib diisi.';
            }
        }

        setErrors(nextErrors);

        return Object.keys(nextErrors).length === 0;
    }

    function nextStep() {
        if (validateStep(step)) {
            setStep((current) => Math.min(current + 1, 3));
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    async function submit() {
        if (!data.ethics_confirmed) {
            setErrors({
                ethics_confirmed: 'Konfirmasi etika wajib disetujui.',
            });

            return;
        }

        if (!turnstileToken) {
            setErrors({
                turnstile_token:
                    'Selesaikan verifikasi keamanan terlebih dahulu.',
            });

            return;
        }

        if (!options) {
            return;
        }

        setSubmitting(true);
        setErrors({});

        try {
            const response = await fetch('/api/public/bookings', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ...data,
                    turnstile_token: turnstileToken,
                    form_token: options.form_token,
                    website: '',
                }),
            });
            const body = (await response.json()) as BookingResult & {
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (!response.ok) {
                if (body.errors) {
                    setErrors(
                        Object.fromEntries(
                            Object.entries(body.errors).map(([key, value]) => [
                                key,
                                value[0],
                            ]),
                        ),
                    );
                } else {
                    setErrors({
                        submit:
                            body.message ??
                            'Booking belum dapat dikirim. Silakan coba lagi.',
                    });
                }

                setTurnstileToken('');

                return;
            }

            setResult(body);
        } catch {
            setErrors({
                submit: 'Koneksi bermasalah. Periksa jaringan lalu coba lagi.',
            });
            setTurnstileToken('');
        } finally {
            setSubmitting(false);
        }
    }

    if (result) {
        return <Success result={result} />;
    }

    return (
        <>
            <Head title="Booking Ziarah" />
            <main className="min-h-screen bg-[#f6f7f9] text-[#14213d]">
                <header className="border-b border-slate-200 bg-white">
                    <div className="mx-auto max-w-lg px-4 py-4 text-center sm:px-6">
                        <p className="text-xs font-semibold tracking-wide text-brand-primary uppercase">
                            Al Azhar Memorial Garden
                        </p>
                        <h1 className="mt-1 text-xl font-semibold">
                            Booking Kunjungan Ziarah
                        </h1>
                    </div>
                </header>

                <div className="mx-auto max-w-lg px-4 py-5 sm:px-6 sm:py-7">
                    <ol
                        aria-label="Tahapan booking"
                        className="flex items-start"
                    >
                        {steps.map((label, index) => (
                            <li
                                key={label}
                                aria-current={
                                    index === step ? 'step' : undefined
                                }
                                className="relative flex flex-1 flex-col items-center text-center"
                            >
                                {index > 0 && (
                                    <span
                                        aria-hidden="true"
                                        className={`absolute top-3 right-1/2 h-px w-full ${
                                            index <= step
                                                ? 'bg-[#172746]'
                                                : 'bg-slate-300'
                                        }`}
                                    />
                                )}
                                <span
                                    className={`relative z-10 flex size-7 items-center justify-center rounded-full border text-xs font-semibold ${
                                        index < step
                                            ? 'border-[#172746] bg-[#172746] text-white'
                                            : index === step
                                              ? 'border-[#172746] bg-white text-[#172746] ring-2 ring-white'
                                              : 'border-slate-300 bg-white text-slate-600'
                                    }`}
                                >
                                    {index < step ? (
                                        <Check
                                            aria-hidden="true"
                                            className="size-3.5"
                                            strokeWidth={2.5}
                                        />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                <span
                                    className={`mt-1.5 block text-[10px] leading-tight ${
                                        index <= step
                                            ? 'font-medium text-[#172746]'
                                            : 'text-slate-400'
                                    }`}
                                >
                                    {label}
                                </span>
                            </li>
                        ))}
                    </ol>

                    <section className="mt-4 overflow-hidden rounded-lg border border-t-2 border-slate-300 border-t-[#d6a928] bg-white shadow-sm">
                        {loading ? (
                            <p role="status" className="p-5 text-sm">
                                Memuat pilihan booking...
                            </p>
                        ) : loadError ? (
                            <div role="alert" className="p-5">
                                <p className="text-red-700">{loadError}</p>
                                <button
                                    type="button"
                                    onClick={() => void loadOptions()}
                                    className={secondaryButton}
                                >
                                    Coba lagi
                                </button>
                            </div>
                        ) : (
                            <>
                                {step === 0 && (
                                    <VisitStep
                                        options={options!}
                                        data={data}
                                        errors={errors}
                                        slots={slots}
                                        loadingSlots={loadingSlots}
                                        filteredZones={filteredZones}
                                        zoneSearch={zoneSearch}
                                        onZoneSearch={setZoneSearch}
                                        onDate={(date) => void selectDate(date)}
                                        onChange={setData}
                                    />
                                )}
                                {step === 1 && (
                                    <FacilitiesStep
                                        data={data}
                                        errors={errors}
                                        onChange={setData}
                                    />
                                )}
                                {step === 2 && (
                                    <PersonalStep
                                        data={data}
                                        zoneName={selectedZone?.name ?? ''}
                                        errors={errors}
                                        onChange={setData}
                                    />
                                )}
                                {step === 3 && (
                                    <ReviewStep
                                        data={data}
                                        zoneName={selectedZone?.name ?? ''}
                                        errors={errors}
                                        turnstileSiteKey={turnstileSiteKey}
                                        onChange={setData}
                                        onTurnstile={setTurnstileToken}
                                        onEdit={setStep}
                                    />
                                )}

                                <div className="flex items-center justify-between gap-3 border-t border-slate-200 bg-[#fafbfc] px-4 py-4">
                                    {step > 0 ? (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setStep(
                                                    (current) => current - 1,
                                                )
                                            }
                                            className={secondaryButton}
                                        >
                                            <ArrowLeft
                                                aria-hidden="true"
                                                className="size-4"
                                            />
                                            Kembali
                                        </button>
                                    ) : (
                                        <span />
                                    )}
                                    {step < 3 ? (
                                        <button
                                            type="button"
                                            onClick={nextStep}
                                            className={primaryButton}
                                        >
                                            Lanjut
                                            <ArrowRight
                                                aria-hidden="true"
                                                className="size-4"
                                            />
                                        </button>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => void submit()}
                                            disabled={submitting}
                                            className={primaryButton}
                                        >
                                            {submitting
                                                ? 'Mengirim...'
                                                : 'Konfirmasi dan Kirim'}
                                            {!submitting && (
                                                <ArrowRight
                                                    aria-hidden="true"
                                                    className="size-4"
                                                />
                                            )}
                                        </button>
                                    )}
                                </div>
                            </>
                        )}
                    </section>
                </div>
            </main>
        </>
    );
}

function VisitStep({
    options,
    data,
    errors,
    slots,
    loadingSlots,
    filteredZones,
    zoneSearch,
    onZoneSearch,
    onDate,
    onChange,
}: {
    options: BookingOptions;
    data: BookingData;
    errors: Record<string, string>;
    slots: TimeSlot[];
    loadingSlots: boolean;
    filteredZones: Zone[];
    zoneSearch: string;
    onZoneSearch: (value: string) => void;
    onDate: (value: string) => void;
    onChange: React.Dispatch<React.SetStateAction<BookingData>>;
}) {
    const chosenDate = options.dates.find(
        (item) => item.date === data.visit_date,
    );
    const prerequisitesComplete = Boolean(
        data.visit_date && data.visit_time && data.zone_id,
    );

    return (
        <div className="px-4 py-5">
            <StepHeader
                title="Tanggal, Jam, Zona & Lot"
                description="Tentukan tanggal, jam, zona, dan lot yang tersedia"
                note="Pastikan zona dan lot diisi dengan benar."
            />

            <fieldset>
                <legend className={fieldLabelClass}>Tanggal kunjungan</legend>
                <BookingCalendar
                    options={options}
                    value={data.visit_date}
                    onChange={onDate}
                />
                <p className="mt-2 text-xs text-slate-600">
                    Minimal pemesanan H+1 dan maksimal H+
                    {options.booking_window_days} dari hari ini.
                </p>
                {chosenDate?.is_full && (
                    <p className="mt-2 text-sm font-medium text-red-700">
                        Tanggal ini sudah penuh.
                    </p>
                )}
                <ErrorText id="visit-date-error" error={errors.visit_date} />
            </fieldset>

            <fieldset className="mt-6">
                <legend className={fieldLabelClass}>Jam kunjungan</legend>
                {loadingSlots ? (
                    <p className="mt-2 text-sm" role="status">
                        Memuat slot waktu...
                    </p>
                ) : data.visit_date ? (
                    <div className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
                        {slots.map((slot) => (
                            <button
                                key={slot.id}
                                type="button"
                                disabled={!slot.is_available}
                                aria-pressed={
                                    data.visit_time === slot.start_time
                                }
                                title={
                                    slot.disabled_reason === 'minimum_lead_time'
                                        ? 'Kurang dari 18 jam'
                                        : slot.disabled_reason === 'date_full'
                                          ? 'Tanggal penuh'
                                          : slot.disabled_reason === 'slot_full'
                                            ? 'Jam penuh'
                                            : undefined
                                }
                                onClick={() =>
                                    onChange((current) => ({
                                        ...current,
                                        visit_time: slot.start_time,
                                    }))
                                }
                                className={choiceClass(
                                    data.visit_time === slot.start_time,
                                )}
                            >
                                {slot.start_time.slice(0, 5)}
                            </button>
                        ))}
                    </div>
                ) : (
                    <p className="mt-2 text-sm text-slate-600">
                        Pilih tanggal terlebih dahulu.
                    </p>
                )}
                <ErrorText id="visit-time-error" error={errors.visit_time} />
            </fieldset>

            <fieldset className="mt-6">
                <div className="flex items-center justify-between gap-3">
                    <legend className={fieldLabelClass}>Zona</legend>
                    <span className="rounded-full border border-[#e6d49b] bg-[#fff9e8] px-2 py-0.5 text-[10px] text-[#735b13]">
                        {options.zones.length} zona
                    </span>
                </div>
                <label htmlFor="zone-search" className="sr-only">
                    Cari zona
                </label>
                <div className="relative mt-2">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        id="zone-search"
                        type="search"
                        value={zoneSearch}
                        onChange={(event) => onZoneSearch(event.target.value)}
                        className={`${inputClass} pl-9`}
                        placeholder="Cari zona..."
                    />
                </div>
                <div className="mt-2 max-h-48 overflow-y-auto rounded-lg border border-slate-300 p-2">
                    <div className="grid grid-cols-2 gap-2">
                        {filteredZones.map((zone) => (
                            <button
                                key={zone.id}
                                type="button"
                                aria-pressed={data.zone_id === zone.id}
                                onClick={() =>
                                    onChange((current) => ({
                                        ...current,
                                        zone_id: zone.id,
                                    }))
                                }
                                className={choiceClass(
                                    data.zone_id === zone.id,
                                )}
                            >
                                {zone.name}
                            </button>
                        ))}
                    </div>
                    {filteredZones.length === 0 && (
                        <p className="p-3 text-center text-sm text-slate-600">
                            Zona tidak ditemukan.
                        </p>
                    )}
                </div>
                <ErrorText id="zone-error" error={errors.zone_id} />
            </fieldset>

            <Field
                id="lot-number"
                label="Nomor lot"
                error={errors.lot_number}
                hint="Contoh: DSD810"
            >
                <input
                    id="lot-number"
                    disabled={!prerequisitesComplete}
                    value={data.lot_number}
                    maxLength={50}
                    onChange={(event) =>
                        onChange((current) => ({
                            ...current,
                            lot_number: event.target.value
                                .toUpperCase()
                                .replace(/[^A-Z0-9/-]/g, ''),
                        }))
                    }
                    className={inputClass}
                    autoComplete="off"
                    placeholder={
                        prerequisitesComplete
                            ? 'Masukkan nomor lot'
                            : 'Pilih tanggal, jam, dan zona terlebih dahulu'
                    }
                />
            </Field>
        </div>
    );
}

function BookingCalendar({
    options,
    value,
    onChange,
}: {
    options: BookingOptions;
    value: string;
    onChange: (value: string) => void;
}) {
    const firstAllowedMonth = startOfMonth(options.earliest_date);
    const lastAllowedMonth = startOfMonth(options.latest_date);
    const [visibleMonth, setVisibleMonth] = useState(
        startOfMonth(value || options.earliest_date),
    );
    const availability = useMemo(
        () => new Map(options.dates.map((item) => [item.date, item])),
        [options.dates],
    );
    const monthDate = parseLocalDate(visibleMonth);
    const daysInMonth = new Date(
        monthDate.getFullYear(),
        monthDate.getMonth() + 1,
        0,
    ).getDate();
    const mondayOffset = (monthDate.getDay() + 6) % 7;
    const cells = [
        ...Array.from<null>({ length: mondayOffset }).fill(null),
        ...Array.from({ length: daysInMonth }, (_, index) => index + 1),
    ];

    function moveMonth(offset: number) {
        const next = new Date(
            monthDate.getFullYear(),
            monthDate.getMonth() + offset,
            1,
        );

        setVisibleMonth(formatDate(next));
    }

    return (
        <div className="mt-2 overflow-hidden rounded-lg border border-slate-300">
            <div className="flex items-center justify-between bg-[#172746] px-3 py-2 text-white">
                <button
                    type="button"
                    aria-label="Bulan sebelumnya"
                    disabled={visibleMonth <= firstAllowedMonth}
                    onClick={() => moveMonth(-1)}
                    className="flex size-9 items-center justify-center rounded-md border border-white/25 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:opacity-35"
                >
                    <ChevronLeft aria-hidden="true" className="size-4" />
                </button>
                <p className="text-sm font-semibold capitalize">
                    {monthDate.toLocaleDateString('id-ID', {
                        month: 'long',
                        year: 'numeric',
                    })}
                </p>
                <button
                    type="button"
                    aria-label="Bulan berikutnya"
                    disabled={visibleMonth >= lastAllowedMonth}
                    onClick={() => moveMonth(1)}
                    className="flex size-9 items-center justify-center rounded-md border border-white/25 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:opacity-35"
                >
                    <ChevronRight aria-hidden="true" className="size-4" />
                </button>
            </div>
            <div className="grid grid-cols-7 border-b border-slate-200 bg-slate-50 px-2 py-2 text-center text-[10px] font-medium tracking-wide text-slate-500 uppercase">
                {['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'].map(
                    (day) => (
                        <span key={day}>{day}</span>
                    ),
                )}
            </div>
            <div className="grid grid-cols-7 gap-y-1 p-2">
                {cells.map((day, index) => {
                    if (!day) {
                        return <span key={`empty-${index}`} />;
                    }

                    const date = formatDate(
                        new Date(
                            monthDate.getFullYear(),
                            monthDate.getMonth(),
                            day,
                        ),
                    );
                    const availableDate = availability.get(date);
                    const disabled =
                        !availableDate ||
                        !availableDate.is_available ||
                        availableDate.is_full;
                    const selected = value === date;

                    return (
                        <button
                            key={date}
                            type="button"
                            disabled={disabled}
                            aria-pressed={selected}
                            aria-label={`${day} ${monthDate.toLocaleDateString(
                                'id-ID',
                                {
                                    month: 'long',
                                    year: 'numeric',
                                },
                            )}${availableDate?.is_full ? ', penuh' : ''}`}
                            onClick={() => onChange(date)}
                            className={`mx-auto flex size-9 items-center justify-center rounded-md text-xs focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-primary ${
                                selected
                                    ? 'bg-[#172746] font-semibold text-white'
                                    : disabled
                                      ? 'cursor-not-allowed text-slate-300 line-through'
                                      : 'text-[#172746] hover:bg-slate-100'
                            }`}
                        >
                            {day}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

function parseLocalDate(value: string) {
    return new Date(`${value}T00:00:00`);
}

function startOfMonth(value: string) {
    const date = parseLocalDate(value);

    return formatDate(new Date(date.getFullYear(), date.getMonth(), 1));
}

function formatDate(date: Date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function FacilitiesStep({
    data,
    errors,
    onChange,
}: {
    data: BookingData;
    errors: Record<string, string>;
    onChange: React.Dispatch<React.SetStateAction<BookingData>>;
}) {
    const chairError =
        data.chair_count > 500
            ? 'Jumlah kursi maksimal 500.'
            : errors.chair_count;

    return (
        <div className="px-4 py-5">
            <StepHeader
                title="Fasilitas"
                description="Pilih fasilitas yang Anda butuhkan"
            />
            <fieldset>
                <legend className={fieldLabelClass}>Jumlah item</legend>
                <input
                    id="chair-count"
                    type="number"
                    min="0"
                    max="500"
                    step="1"
                    inputMode="numeric"
                    value={data.chair_count}
                    aria-label="Jumlah kursi"
                    aria-invalid={Boolean(chairError)}
                    aria-describedby={
                        chairError ? 'chair-count-error' : undefined
                    }
                    onChange={(event) =>
                        onChange((current) => ({
                            ...current,
                            chair_count: Number(event.target.value),
                        }))
                    }
                    className={`${inputClass} mt-2`}
                />
                <ErrorText id="chair-count-error" error={chairError} />
            </fieldset>

            <fieldset className="mt-5">
                <legend className={fieldLabelClass}>
                    Perlengkapan tambahan
                </legend>
                <div className="mt-2 rounded-lg border border-slate-300">
                    <div className="flex min-h-16 items-center justify-between gap-4 px-3 py-2">
                        <div>
                            <p className="text-sm font-medium">Tenda</p>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Pilih sesuai kebutuhan kunjungan
                            </p>
                        </div>
                        <div
                            className="flex rounded-lg bg-slate-100 p-1"
                            role="group"
                            aria-label="Kebutuhan tenda"
                        >
                            {[
                                ['Tidak', false],
                                ['Ya', true],
                            ].map(([label, value]) => (
                                <button
                                    key={String(label)}
                                    type="button"
                                    aria-pressed={data.tent_required === value}
                                    onClick={() =>
                                        onChange((current) => ({
                                            ...current,
                                            tent_required: value as boolean,
                                        }))
                                    }
                                    className={`min-h-9 rounded-md px-3 text-xs font-semibold focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-primary ${
                                        data.tent_required === value
                                            ? 'bg-white text-[#172746] shadow-sm'
                                            : 'text-slate-500'
                                    }`}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
                <ErrorText id="tent-error" error={errors.tent_required} />
            </fieldset>
        </div>
    );
}

function PersonalStep({
    data,
    zoneName,
    errors,
    onChange,
}: {
    data: BookingData;
    zoneName: string;
    errors: Record<string, string>;
    onChange: React.Dispatch<React.SetStateAction<BookingData>>;
}) {
    const fields: Array<{
        key: keyof BookingData;
        label: string;
        type?: string;
        autoComplete?: string;
        inputMode?: 'email' | 'numeric' | 'text';
    }> = [
        {
            key: 'customer_name',
            label: 'Nama lengkap',
            autoComplete: 'name',
        },
        {
            key: 'customer_email',
            label: 'Email',
            type: 'email',
            autoComplete: 'email',
            inputMode: 'email',
        },
        {
            key: 'customer_phone',
            label: 'Nomor telepon',
            type: 'tel',
            autoComplete: 'tel',
            inputMode: 'numeric',
        },
        {
            key: 'deceased_name',
            label: 'Nama almarhum atau almarhumah',
        },
    ];

    return (
        <div className="px-4 py-5">
            <StepHeader
                title="Data Diri"
                description="Isi informasi kontak untuk konfirmasi booking"
            />
            {fields.map((field) => (
                <Field
                    key={field.key}
                    id={field.key}
                    label={field.label}
                    error={errors[field.key]}
                >
                    <input
                        id={field.key}
                        type={field.type ?? 'text'}
                        inputMode={field.inputMode}
                        autoComplete={field.autoComplete}
                        value={String(data[field.key])}
                        placeholder={fieldPlaceholder(field.key)}
                        required
                        minLength={
                            field.key === 'customer_phone' ? 10 : undefined
                        }
                        maxLength={
                            field.key === 'customer_phone'
                                ? 15
                                : field.key === 'customer_email'
                                  ? 255
                                  : undefined
                        }
                        pattern={
                            field.key === 'customer_phone'
                                ? '(?:08|62)[0-9]{8,13}'
                                : undefined
                        }
                        aria-invalid={Boolean(errors[field.key])}
                        aria-describedby={
                            errors[field.key] ? `${field.key}-error` : undefined
                        }
                        onChange={(event) => {
                            const value =
                                field.key === 'customer_phone'
                                    ? event.target.value
                                          .replace(/\D/g, '')
                                          .slice(0, 15)
                                    : event.target.value;

                            onChange((current) => ({
                                ...current,
                                [field.key]: value,
                            }));
                        }}
                        className={inputClass}
                    />
                </Field>
            ))}
            <section className="mt-5 overflow-hidden rounded-lg border border-slate-300">
                <h3 className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-[#172746]">
                    Ringkasan booking
                </h3>
                <dl className="text-xs">
                    {[
                        ['Zona', zoneName],
                        ['Lot', data.lot_number],
                        ['Tanggal', formatDisplayDate(data.visit_date)],
                        ['Jam', data.visit_time.slice(0, 5)],
                        [
                            'Fasilitas',
                            `Kursi ${data.chair_count} · Tenda ${
                                data.tent_required ? 'Ya' : 'Tidak'
                            }`,
                        ],
                    ].map(([label, value]) => (
                        <div
                            key={label}
                            className="grid grid-cols-[5.5rem_1fr] gap-3 border-b border-slate-100 px-3 py-2.5 last:border-b-0"
                        >
                            <dt className="text-slate-500">{label}</dt>
                            <dd className="text-right font-medium break-words">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
            </section>
        </div>
    );
}

function fieldPlaceholder(key: keyof BookingData) {
    const placeholders: Partial<Record<keyof BookingData, string>> = {
        customer_name: 'Contoh: Budi Santoso',
        customer_email: 'Contoh: budi@email.com',
        customer_phone: 'Contoh: 081234567890',
        deceased_name: 'Nama almarhum atau almarhumah',
    };

    return placeholders[key];
}

function isValidEmail(value: string) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
}

function isValidPhone(value: string) {
    return /^(?:08|62)[0-9]{8,13}$/.test(value.trim());
}

function formatDisplayDate(value: string) {
    if (!value) {
        return '';
    }

    return parseLocalDate(value).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function ReviewStep({
    data,
    zoneName,
    errors,
    turnstileSiteKey,
    onChange,
    onTurnstile,
    onEdit,
}: {
    data: BookingData;
    zoneName: string;
    errors: Record<string, string>;
    turnstileSiteKey: string | null;
    onChange: React.Dispatch<React.SetStateAction<BookingData>>;
    onTurnstile: (token: string) => void;
    onEdit: (step: number) => void;
}) {
    return (
        <div className="px-4 py-5">
            <StepHeader
                title="Tinjau Booking"
                description="Pastikan seluruh informasi sudah benar"
            />
            <ReviewGroup
                title="Kunjungan"
                onEdit={() => onEdit(0)}
                rows={[
                    ['Tanggal', data.visit_date],
                    ['Jam', data.visit_time],
                    ['Zona', zoneName],
                    ['Lot', data.lot_number],
                ]}
            />
            <ReviewGroup
                title="Fasilitas"
                onEdit={() => onEdit(1)}
                rows={[
                    ['Tenda', data.tent_required ? 'Ya' : 'Tidak'],
                    ['Kursi', String(data.chair_count)],
                ]}
            />
            <ReviewGroup
                title="Data diri"
                onEdit={() => onEdit(2)}
                rows={[
                    ['Nama', data.customer_name],
                    ['Email', data.customer_email],
                    ['Telepon', data.customer_phone],
                    ['Almarhum/ah', data.deceased_name],
                ]}
            />

            <label className="mt-6 flex min-h-11 items-start gap-3 rounded-lg border border-slate-200 p-3">
                <input
                    type="checkbox"
                    checked={data.ethics_confirmed}
                    onChange={(event) =>
                        onChange((current) => ({
                            ...current,
                            ethics_confirmed: event.target.checked,
                        }))
                    }
                    className="mt-0.5 size-5 accent-brand-primary"
                />
                <span className="text-sm">
                    Saya memastikan informasi sudah benar dan akan mengikuti
                    Etika Ziarah Al Azhar Memorial Garden.
                </span>
            </label>
            <ErrorText id="ethics-error" error={errors.ethics_confirmed} />

            {turnstileSiteKey ? (
                <Turnstile siteKey={turnstileSiteKey} onToken={onTurnstile} />
            ) : (
                <p role="alert" className="mt-4 text-sm text-red-700">
                    Verifikasi keamanan belum dikonfigurasi.
                </p>
            )}
            <ErrorText id="turnstile-error" error={errors.turnstile_token} />
            <ErrorText id="submit-error" error={errors.submit} />
        </div>
    );
}

function Turnstile({
    siteKey,
    onToken,
}: {
    siteKey: string;
    onToken: (token: string) => void;
}) {
    const container = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let widgetId: string | undefined;
        const render = () => {
            if (container.current && window.turnstile && !widgetId) {
                widgetId = window.turnstile.render(container.current, {
                    sitekey: siteKey,
                    callback: onToken,
                    'expired-callback': () => onToken(''),
                    'error-callback': () => onToken(''),
                });
            }
        };

        const existing = document.querySelector<HTMLScriptElement>(
            'script[data-turnstile]',
        );

        if (existing) {
            if (window.turnstile) {
                render();
            } else {
                existing.addEventListener('load', render, { once: true });
            }
        } else {
            const script = document.createElement('script');
            script.src =
                'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.defer = true;
            script.dataset.turnstile = 'true';
            script.addEventListener('load', render, { once: true });
            document.head.appendChild(script);
        }

        return () => {
            if (widgetId && window.turnstile) {
                window.turnstile.remove(widgetId);
            }

            onToken('');
        };
    }, [onToken, siteKey]);

    return <div ref={container} className="mt-5 min-h-[65px]" />;
}

function Success({ result }: { result: BookingResult }) {
    return (
        <>
            <Head title="Booking Berhasil" />
            <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10 text-brand-ink">
                <section className="w-full max-w-lg rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <p className="text-sm font-semibold text-emerald-700">
                        Booking berhasil
                    </p>
                    <h1 className="mt-2 text-2xl font-semibold">
                        Kunjungan Anda telah dikonfirmasi
                    </h1>
                    <dl className="mt-6 grid grid-cols-[auto_1fr] gap-x-4 gap-y-3 text-sm">
                        <dt className="font-semibold">Referensi</dt>
                        <dd className="break-all">
                            {result.booking_reference}
                        </dd>
                        <dt className="font-semibold">Tanggal</dt>
                        <dd>{result.visit.date}</dd>
                        <dt className="font-semibold">Jam</dt>
                        <dd>{result.visit.time}</dd>
                        <dt className="font-semibold">Zona dan lot</dt>
                        <dd>
                            {result.visit.zone}, {result.visit.lot}
                        </dd>
                    </dl>
                    <p className="mt-6 text-sm text-slate-600">
                        Periksa email Anda untuk link pengelolaan booking.
                        Simpan referensi ini sebagai bukti konfirmasi.
                    </p>
                </section>
            </main>
        </>
    );
}

function ReviewGroup({
    title,
    rows,
    onEdit,
}: {
    title: string;
    rows: string[][];
    onEdit: () => void;
}) {
    return (
        <section className="mt-5 rounded-lg border border-slate-200 p-4">
            <div className="flex items-center justify-between gap-4">
                <h3 className="font-semibold">{title}</h3>
                <button
                    type="button"
                    onClick={onEdit}
                    className="min-h-11 px-2 text-sm font-semibold text-brand-primary underline underline-offset-4"
                >
                    Ubah
                </button>
            </div>
            <dl className="mt-2 space-y-2 text-sm">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="grid grid-cols-[8rem_1fr] gap-3"
                    >
                        <dt className="text-slate-600">{label}</dt>
                        <dd className="font-medium break-words">{value}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

function StepHeader({
    title,
    description,
    note,
}: {
    title: string;
    description: string;
    note?: string;
}) {
    return (
        <header className="-mx-4 -mt-5 mb-5 border-b border-slate-200 px-4 py-4">
            <h2 className="text-base font-semibold text-[#172746]">{title}</h2>
            <p className="mt-1 text-xs text-slate-500">{description}</p>
            {note && (
                <p className="mt-3 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2.5 text-xs text-slate-600">
                    {note}
                </p>
            )}
        </header>
    );
}

function Field({
    id,
    label,
    error,
    hint,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="mt-5">
            <label htmlFor={id} className={`mb-2 block ${fieldLabelClass}`}>
                {label}
            </label>
            {children}
            {hint && !error && (
                <p className="mt-2 text-sm text-slate-600">{hint}</p>
            )}
            <ErrorText id={`${id}-error`} error={error} />
        </div>
    );
}

function ErrorText({ id, error }: { id: string; error?: string }) {
    return error ? (
        <p id={id} role="alert" className="mt-2 text-sm text-red-700">
            {error}
        </p>
    ) : null;
}

function choiceClass(selected: boolean) {
    return `min-h-10 rounded-md border px-3 py-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-50 disabled:text-slate-300 ${
        selected
            ? 'border-[#172746] bg-[#172746] text-white'
            : 'border-slate-300 bg-[#fafbfc] text-[#172746] hover:border-brand-primary'
    }`;
}

const inputClass =
    'min-h-11 w-full rounded-lg border border-slate-300 bg-[#fafbfc] px-3 py-2 text-sm outline-none placeholder:text-slate-400 focus:border-brand-primary focus:bg-white focus:ring-3 focus:ring-sky-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400';
const fieldLabelClass =
    'text-[10px] font-semibold tracking-[0.12em] text-slate-500 uppercase';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-brand-primary px-5 py-2 text-sm font-semibold text-white hover:bg-brand-primary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary disabled:cursor-not-allowed disabled:opacity-60';
const secondaryButton =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold hover:border-brand-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary';
