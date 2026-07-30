import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import AdminLayout from '@/layouts/admin-layout';

type Zone = {
    id: number;
    name: string;
    is_active: boolean;
};

export default function Zones({ zones }: { zones: Zone[] }) {
    const form = useForm({ name: '', is_active: true });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/admin/zones', {
            onSuccess: () => form.reset(),
        });
    }

    return (
        <AdminLayout>
            <Head title="Zona" />
            <div className="px-4 py-6 sm:px-6 sm:py-8 lg:px-10">
                <div className="mx-auto max-w-5xl">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Zona
                    </h1>
                    <p className="mt-2 text-sm text-slate-600">
                        Kelola zona yang dapat dipilih untuk booking ziarah.
                    </p>

                    <section className="mt-8 rounded-xl border border-slate-200 bg-white p-5">
                        <h2 className="font-semibold">Tambah zona</h2>
                        <form
                            onSubmit={submit}
                            className="mt-4 flex flex-col gap-4 sm:flex-row sm:items-end"
                        >
                            <div className="flex-1">
                                <label
                                    htmlFor="new-zone-name"
                                    className="mb-2 block text-sm font-semibold"
                                >
                                    Nama zona
                                </label>
                                <input
                                    id="new-zone-name"
                                    value={form.data.name}
                                    onChange={(event) =>
                                        form.setData('name', event.target.value)
                                    }
                                    aria-invalid={Boolean(form.errors.name)}
                                    aria-describedby={
                                        form.errors.name
                                            ? 'new-zone-name-error'
                                            : undefined
                                    }
                                    className="min-h-11 w-full rounded-lg border border-slate-300 px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                                />
                                {form.errors.name && (
                                    <p
                                        id="new-zone-name-error"
                                        role="alert"
                                        className="mt-2 text-sm text-red-700"
                                    >
                                        {form.errors.name}
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
                        aria-labelledby="zone-list-heading"
                    >
                        <h2 id="zone-list-heading" className="font-semibold">
                            Daftar zona
                        </h2>
                        {zones.length === 0 ? (
                            <p className="mt-3 rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
                                Belum ada zona.
                            </p>
                        ) : (
                            <div className="mt-3 space-y-3">
                                {zones.map((zone) => (
                                    <ZoneRow key={zone.id} zone={zone} />
                                ))}
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </AdminLayout>
    );
}

function ZoneRow({ zone }: { zone: Zone }) {
    const form = useForm({
        name: zone.name,
        is_active: zone.is_active,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.put(`/admin/zones/${zone.id}`, {
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
                    htmlFor={`zone-name-${zone.id}`}
                    className="mb-2 block text-sm font-semibold"
                >
                    Nama zona
                </label>
                <input
                    id={`zone-name-${zone.id}`}
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={Boolean(form.errors.name)}
                    className="min-h-11 w-full rounded-lg border border-slate-300 px-3 outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100"
                />
                {form.errors.name && (
                    <p role="alert" className="mt-2 text-sm text-red-700">
                        {form.errors.name}
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
