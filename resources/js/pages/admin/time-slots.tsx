import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type TimeSlot = {
    id: number;
    start_time: string;
    is_active: boolean;
};

export default function TimeSlots({ timeSlots }: { timeSlots: TimeSlot[] }) {
    const form = useForm({ start_time: '07:00', is_active: true });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/admin/time-slots');
    }

    return (
        <AdminLayout>
            <Head title="Slot Waktu" />
            <div className="px-4 py-6 sm:px-6 sm:py-8 lg:px-10">
                <div className="mx-auto max-w-5xl">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Slot waktu
                    </h1>
                    <p className="mt-2 text-sm text-slate-600">
                        Setiap slot harus dimulai tepat pada jam penuh.
                    </p>

                    <section className="mt-8 rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="font-semibold">Tambah slot</h2>
                        <form
                            onSubmit={submit}
                            className="mt-4 flex flex-col gap-4 sm:flex-row sm:items-end"
                        >
                            <div className="flex-1">
                                <label
                                    htmlFor="new-start-time"
                                    className="mb-2 block text-sm font-semibold"
                                >
                                    Jam mulai
                                </label>
                                <input
                                    id="new-start-time"
                                    type="time"
                                    step="3600"
                                    value={form.data.start_time}
                                    onChange={(event) =>
                                        form.setData(
                                            'start_time',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={Boolean(
                                        form.errors.start_time,
                                    )}
                                    className="min-h-11 w-full rounded-lg border border-slate-300 px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                                />
                                {form.errors.start_time && (
                                    <p
                                        role="alert"
                                        className="mt-2 text-sm text-red-700"
                                    >
                                        {form.errors.start_time}
                                    </p>
                                )}
                            </div>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="min-h-11 rounded-lg bg-brand-primary px-5 font-semibold text-white hover:bg-brand-primary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary disabled:opacity-60"
                            >
                                Tambah
                            </button>
                        </form>
                    </section>

                    <section
                        className="mt-6"
                        aria-labelledby="time-slot-list-heading"
                    >
                        <h2
                            id="time-slot-list-heading"
                            className="font-semibold"
                        >
                            Daftar slot
                        </h2>
                        {timeSlots.length === 0 ? (
                            <p className="mt-3 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
                                Belum ada slot waktu.
                            </p>
                        ) : (
                            <div className="mt-3 space-y-3">
                                {timeSlots.map((timeSlot) => (
                                    <TimeSlotRow
                                        key={timeSlot.id}
                                        timeSlot={timeSlot}
                                    />
                                ))}
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AdminLayout>
    );
}

function TimeSlotRow({ timeSlot }: { timeSlot: TimeSlot }) {
    const form = useForm({
        start_time: timeSlot.start_time,
        is_active: timeSlot.is_active,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.put(`/admin/time-slots/${timeSlot.id}`, {
            preserveScroll: true,
        });
    }

    return (
        <form
            onSubmit={submit}
            className="grid gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_auto_auto] sm:items-end"
        >
            <div>
                <label
                    htmlFor={`start-time-${timeSlot.id}`}
                    className="mb-2 block text-sm font-semibold"
                >
                    Jam mulai
                </label>
                <input
                    id={`start-time-${timeSlot.id}`}
                    type="time"
                    step="3600"
                    value={form.data.start_time}
                    onChange={(event) =>
                        form.setData('start_time', event.target.value)
                    }
                    aria-invalid={Boolean(form.errors.start_time)}
                    className="min-h-11 w-full rounded-lg border border-slate-300 px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                />
                {form.errors.start_time && (
                    <p role="alert" className="mt-2 text-sm text-red-700">
                        {form.errors.start_time}
                    </p>
                )}
            </div>
            <label className="flex min-h-11 items-center gap-2 text-sm font-medium">
                <input
                    type="checkbox"
                    checked={form.data.is_active}
                    onChange={(event) =>
                        form.setData('is_active', event.target.checked)
                    }
                    className="size-5 accent-brand-primary"
                />
                Aktif
            </label>
            <button
                type="submit"
                disabled={form.processing}
                className="min-h-11 rounded-lg border border-slate-300 px-4 font-semibold hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary disabled:opacity-60"
            >
                Simpan
            </button>
        </form>
    );
}
