import { Head, useForm } from '@inertiajs/react';
import { LockKeyhole } from 'lucide-react';
import type { FormEvent } from 'react';

export default function AdminLogin() {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/admin/login', {
            onError: () => reset('password'),
        });
    }

    return (
        <>
            <Head title="Login Admin" />
            <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10 text-brand-ink">
                <section
                    aria-labelledby="login-heading"
                    className="w-full max-w-md rounded-2xl border border-slate-200 bg-brand-surface p-6 shadow-sm sm:p-8"
                >
                    <div className="mb-8">
                        <div className="mb-5 flex size-12 items-center justify-center rounded-xl bg-sky-50 text-brand-primary">
                            <LockKeyhole
                                aria-hidden="true"
                                className="size-6"
                            />
                        </div>
                        <p className="text-xs font-semibold tracking-[0.18em] text-brand-primary uppercase">
                            Al Azhar Memorial Garden
                        </p>
                        <h1
                            id="login-heading"
                            className="mt-2 text-2xl font-semibold tracking-tight"
                        >
                            Login admin
                        </h1>
                        <p className="mt-2 text-sm leading-6 text-slate-600">
                            Masukkan akun admin untuk mengelola layanan ziarah.
                        </p>
                    </div>

                    <form onSubmit={submit} noValidate className="space-y-5">
                        <div>
                            <label
                                htmlFor="email"
                                className="mb-2 block text-sm font-semibold"
                            >
                                Email
                            </label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                autoComplete="username"
                                autoFocus
                                required
                                value={data.email}
                                onChange={(event) =>
                                    setData('email', event.target.value)
                                }
                                aria-invalid={Boolean(errors.email)}
                                aria-describedby={
                                    errors.email ? 'email-error' : undefined
                                }
                                className="min-h-12 w-full rounded-lg border border-slate-300 px-3.5 text-base transition outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100 aria-invalid:border-red-600 aria-invalid:ring-red-100"
                            />
                            {errors.email && (
                                <p
                                    id="email-error"
                                    role="alert"
                                    className="mt-2 text-sm text-red-700"
                                >
                                    {errors.email}
                                </p>
                            )}
                        </div>

                        <div>
                            <label
                                htmlFor="password"
                                className="mb-2 block text-sm font-semibold"
                            >
                                Kata sandi
                            </label>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="current-password"
                                required
                                value={data.password}
                                onChange={(event) =>
                                    setData('password', event.target.value)
                                }
                                aria-invalid={Boolean(errors.password)}
                                aria-describedby={
                                    errors.password
                                        ? 'password-error'
                                        : undefined
                                }
                                className="min-h-12 w-full rounded-lg border border-slate-300 px-3.5 text-base transition outline-none focus:border-brand-primary focus:ring-3 focus:ring-sky-100 aria-invalid:border-red-600 aria-invalid:ring-red-100"
                            />
                            {errors.password && (
                                <p
                                    id="password-error"
                                    role="alert"
                                    className="mt-2 text-sm text-red-700"
                                >
                                    {errors.password}
                                </p>
                            )}
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-brand-primary px-4 font-semibold text-white transition hover:bg-brand-primary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {processing ? 'Memproses...' : 'Masuk'}
                        </button>
                    </form>
                </section>
            </main>
        </>
    );
}
