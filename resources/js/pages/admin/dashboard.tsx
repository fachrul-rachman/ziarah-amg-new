import { Head, Link, router } from '@inertiajs/react';
import { Download, Eye, Search } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type Booking = {
    id: number;
    public_reference: string;
    status: 'confirmed' | 'cancelled' | 'completed';
    visit_date: string;
    visit_time: string;
    zone: string;
    lot_number: string;
    customer_name: string;
    customer_phone: string;
};

type Filters = {
    search?: string;
    status?: string;
    date_from?: string;
    date_to?: string;
    visit_time?: string;
    zone_id?: number | string;
};

type PageLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type Props = {
    bookings: {
        data: Booking[];
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Filters;
    summary: {
        confirmed: number;
        cancelled: number;
        completed: number;
    };
    zones: { id: number; name: string }[];
    time_slots: string[];
};

const statusLabels = {
    confirmed: 'Terkonfirmasi',
    cancelled: 'Dibatalkan',
    completed: 'Selesai',
};

export default function AdminDashboard({
    bookings,
    filters,
    summary,
    zones,
    time_slots,
}: Props) {
    const [form, setForm] = useState({
        search: filters.search ?? '',
        status: filters.status ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
        visit_time: filters.visit_time ?? '',
        zone_id: String(filters.zone_id ?? ''),
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get('/admin', form, {
            preserveState: true,
            replace: true,
        });
    }

    const exportQuery = new URLSearchParams(
        Object.entries(filters)
            .filter(([, value]) => value !== null && value !== '')
            .map(([key, value]) => [key, String(value)]),
    ).toString();

    return (
        <AdminLayout>
            <Head title="Dashboard Admin" />
            <div className="px-4 py-6 sm:px-6 sm:py-8 lg:px-10">
                <div className="mx-auto max-w-6xl">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p className="text-sm font-semibold text-brand-primary">
                                Operasional ziarah
                            </p>
                            <h1 className="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
                                Dashboard booking
                            </h1>
                        </div>
                        <a
                            href={`/admin/bookings/export${exportQuery ? `?${exportQuery}` : ''}`}
                            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary"
                        >
                            <Download aria-hidden="true" className="size-5" />
                            Export Excel
                        </a>
                    </div>

                    <section
                        aria-label="Ringkasan status booking"
                        className="mt-6 grid gap-3 sm:grid-cols-3"
                    >
                        <SummaryCard
                            label="Terkonfirmasi"
                            value={summary.confirmed}
                        />
                        <SummaryCard
                            label="Dibatalkan"
                            value={summary.cancelled}
                        />
                        <SummaryCard
                            label="Selesai"
                            value={summary.completed}
                        />
                    </section>

                    <form
                        onSubmit={submit}
                        className="mt-6 grid gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <label className="sm:col-span-2">
                            <span className="mb-2 block text-sm font-semibold">
                                Cari booking
                            </span>
                            <span className="relative block">
                                <Search
                                    aria-hidden="true"
                                    className="absolute top-3 left-3 size-5 text-slate-400"
                                />
                                <input
                                    value={form.search}
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            search: event.target.value,
                                        })
                                    }
                                    placeholder="Kode, nama, telepon, zona, atau lot"
                                    className="min-h-11 w-full rounded-lg border border-slate-300 pr-3 pl-10 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                                />
                            </span>
                        </label>
                        <FilterSelect
                            label="Status"
                            value={form.status}
                            onChange={(value) =>
                                setForm({ ...form, status: value })
                            }
                        >
                            <option value="">Semua status</option>
                            <option value="confirmed">Terkonfirmasi</option>
                            <option value="cancelled">Dibatalkan</option>
                            <option value="completed">Selesai</option>
                        </FilterSelect>
                        <FilterSelect
                            label="Zona"
                            value={form.zone_id}
                            onChange={(value) =>
                                setForm({ ...form, zone_id: value })
                            }
                        >
                            <option value="">Semua zona</option>
                            {zones.map((zone) => (
                                <option key={zone.id} value={zone.id}>
                                    {zone.name}
                                </option>
                            ))}
                        </FilterSelect>
                        <FilterInput
                            label="Dari tanggal"
                            type="date"
                            value={form.date_from}
                            onChange={(value) =>
                                setForm({ ...form, date_from: value })
                            }
                        />
                        <FilterInput
                            label="Sampai tanggal"
                            type="date"
                            value={form.date_to}
                            onChange={(value) =>
                                setForm({ ...form, date_to: value })
                            }
                        />
                        <FilterSelect
                            label="Jam kunjungan"
                            value={form.visit_time}
                            onChange={(value) =>
                                setForm({ ...form, visit_time: value })
                            }
                        >
                            <option value="">Semua jam</option>
                            {time_slots.map((time) => (
                                <option key={time} value={time}>
                                    {time}
                                </option>
                            ))}
                        </FilterSelect>
                        <div className="flex items-end gap-2">
                            <button
                                type="submit"
                                className="min-h-11 flex-1 rounded-lg bg-brand-primary px-4 text-sm font-semibold text-white hover:bg-brand-primary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary"
                            >
                                Terapkan
                            </button>
                            <Link
                                href="/admin"
                                className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary"
                            >
                                Reset
                            </Link>
                        </div>
                    </form>

                    <section
                        aria-labelledby="booking-list-heading"
                        className="mt-6"
                    >
                        <h2 id="booking-list-heading" className="sr-only">
                            Daftar booking
                        </h2>
                        {bookings.data.length === 0 ? (
                            <p className="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-600">
                                Tidak ada booking yang sesuai dengan filter.
                            </p>
                        ) : (
                            <>
                                <div className="space-y-3 md:hidden">
                                    {bookings.data.map((booking) => (
                                        <BookingCard
                                            key={booking.id}
                                            booking={booking}
                                        />
                                    ))}
                                </div>
                                <div className="hidden overflow-x-auto rounded-xl border border-slate-200 bg-white md:block">
                                    <table className="w-full text-left text-sm">
                                        <thead className="bg-slate-50 text-slate-600">
                                            <tr>
                                                <TableHeading>
                                                    Kunjungan
                                                </TableHeading>
                                                <TableHeading>
                                                    Pemesan
                                                </TableHeading>
                                                <TableHeading>
                                                    Zona dan lot
                                                </TableHeading>
                                                <TableHeading>
                                                    Status
                                                </TableHeading>
                                                <TableHeading>
                                                    <span className="sr-only">
                                                        Aksi
                                                    </span>
                                                </TableHeading>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-200">
                                            {bookings.data.map((booking) => (
                                                <tr key={booking.id}>
                                                    <TableCell>
                                                        <p className="font-semibold">
                                                            {formatDate(
                                                                booking.visit_date,
                                                            )}
                                                        </p>
                                                        <p className="mt-1 text-slate-600">
                                                            {booking.visit_time}{' '}
                                                            WIB
                                                        </p>
                                                    </TableCell>
                                                    <TableCell>
                                                        <p className="font-medium">
                                                            {
                                                                booking.customer_name
                                                            }
                                                        </p>
                                                        <p className="mt-1 text-slate-600">
                                                            {
                                                                booking.customer_phone
                                                            }
                                                        </p>
                                                    </TableCell>
                                                    <TableCell>
                                                        {booking.zone} ·{' '}
                                                        {booking.lot_number}
                                                    </TableCell>
                                                    <TableCell>
                                                        <StatusBadge
                                                            status={
                                                                booking.status
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <DetailLink
                                                            id={booking.id}
                                                        />
                                                    </TableCell>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </>
                        )}
                    </section>

                    {bookings.total > 0 && (
                        <nav
                            aria-label="Paginasi booking"
                            className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <p className="text-sm text-slate-600">
                                Menampilkan {bookings.from}–{bookings.to} dari{' '}
                                {bookings.total}
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {bookings.links.map((link, index) =>
                                    link.url ? (
                                        <Link
                                            key={`${link.label}-${index}`}
                                            href={link.url}
                                            preserveScroll
                                            aria-current={
                                                link.active ? 'page' : undefined
                                            }
                                            className={`inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border px-3 text-sm font-semibold ${
                                                link.active
                                                    ? 'border-brand-primary bg-brand-primary text-white'
                                                    : 'border-slate-300 bg-white hover:bg-slate-50'
                                            }`}
                                        >
                                            {paginationLabel(link.label)}
                                        </Link>
                                    ) : (
                                        <span
                                            key={`${link.label}-${index}`}
                                            aria-disabled="true"
                                            className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-slate-200 px-3 text-sm text-slate-400"
                                        >
                                            {paginationLabel(link.label)}
                                        </span>
                                    ),
                                )}
                            </div>
                        </nav>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}

function SummaryCard({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4">
            <p className="text-sm text-slate-600">{label}</p>
            <p className="mt-1 text-2xl font-semibold">{value}</p>
        </div>
    );
}

function FilterInput({
    label,
    type,
    value,
    onChange,
}: {
    label: string;
    type: string;
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <label>
            <span className="mb-2 block text-sm font-semibold">{label}</span>
            <input
                type={type}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="min-h-11 w-full rounded-lg border border-slate-300 px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
            />
        </label>
    );
}

function FilterSelect({
    label,
    value,
    onChange,
    children,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    children: ReactNode;
}) {
    return (
        <label>
            <span className="mb-2 block text-sm font-semibold">{label}</span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
            >
                {children}
            </select>
        </label>
    );
}

function BookingCard({ booking }: { booking: Booking }) {
    return (
        <article className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="font-semibold">
                        {formatDate(booking.visit_date)}, {booking.visit_time}{' '}
                        WIB
                    </p>
                    <p className="mt-1 text-sm text-slate-600">
                        {booking.zone} · {booking.lot_number}
                    </p>
                </div>
                <StatusBadge status={booking.status} />
            </div>
            <div className="mt-4 border-t border-slate-100 pt-4">
                <p className="font-medium">{booking.customer_name}</p>
                <p className="mt-1 text-sm text-slate-600">
                    {booking.customer_phone}
                </p>
                <DetailLink id={booking.id} />
            </div>
        </article>
    );
}

function DetailLink({ id }: { id: number }) {
    return (
        <Link
            href={`/admin/bookings/${id}`}
            className="mt-3 inline-flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold text-brand-primary hover:bg-sky-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary md:mt-0"
        >
            <Eye aria-hidden="true" className="size-5" />
            Detail
        </Link>
    );
}

function StatusBadge({ status }: { status: Booking['status'] }) {
    const colour =
        status === 'confirmed'
            ? 'bg-sky-50 text-sky-800'
            : status === 'completed'
              ? 'bg-emerald-50 text-emerald-800'
              : 'bg-slate-100 text-slate-700';

    return (
        <span
            className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${colour}`}
        >
            {statusLabels[status]}
        </span>
    );
}

function TableHeading({ children }: { children: ReactNode }) {
    return (
        <th scope="col" className="px-4 py-3 font-semibold">
            {children}
        </th>
    );
}

function TableCell({ children }: { children: ReactNode }) {
    return <td className="px-4 py-4 align-top">{children}</td>;
}

function formatDate(date: string) {
    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(`${date}T00:00:00+07:00`));
}

function paginationLabel(label: string) {
    if (label.includes('Previous')) {
        return 'Sebelumnya';
    }

    if (label.includes('Next')) {
        return 'Berikutnya';
    }

    return label;
}
