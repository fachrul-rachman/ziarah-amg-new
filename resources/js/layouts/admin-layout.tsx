import { Link, router, usePage } from '@inertiajs/react';
import {
    Clock3,
    LayoutDashboard,
    LogOut,
    MapPinned,
    PanelLeftClose,
    PanelLeftOpen,
    Settings,
} from 'lucide-react';
import { useState } from 'react';
import type { PropsWithChildren } from 'react';

const navigationItems = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard },
    { label: 'Zona', href: '/admin/zones', icon: MapPinned },
    { label: 'Slot waktu', href: '/admin/time-slots', icon: Clock3 },
    { label: 'Pengaturan', href: '/admin/settings', icon: Settings },
];

export default function AdminLayout({ children }: PropsWithChildren) {
    const { auth } = usePage().props;
    const [sidebarExpanded, setSidebarExpanded] = useState(true);

    return (
        <div className="min-h-screen bg-slate-50 text-brand-ink">
            <header className="border-b border-slate-200 bg-brand-surface md:hidden">
                <div className="flex min-h-16 items-center justify-between px-4">
                    <div>
                        <p className="text-xs font-semibold tracking-[0.18em] text-brand-primary uppercase">
                            AMG
                        </p>
                        <p className="font-semibold">Admin Ziarah</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => router.post('/admin/logout')}
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold text-brand-ink hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary"
                    >
                        <LogOut aria-hidden="true" className="size-5" />
                        Keluar
                    </button>
                </div>
                <AdminNavigation mobile />
            </header>

            <div className="mx-auto flex min-h-screen max-w-[1440px]">
                <aside
                    id="admin-sidebar"
                    className={`sticky top-0 hidden h-screen shrink-0 self-start overflow-y-auto border-r border-slate-200 bg-brand-surface transition-[width] md:flex md:flex-col ${
                        sidebarExpanded ? 'w-64' : 'w-20'
                    }`}
                >
                    <div
                        className={`flex min-h-20 items-center border-b border-slate-200 ${
                            sidebarExpanded
                                ? 'justify-between gap-3 px-5'
                                : 'justify-center px-3'
                        }`}
                    >
                        {sidebarExpanded && (
                            <div className="min-w-0">
                                <p className="truncate text-xs font-semibold tracking-[0.18em] text-brand-primary uppercase">
                                    Al Azhar Memorial Garden
                                </p>
                                <p className="mt-1 text-lg font-semibold">
                                    Admin Ziarah
                                </p>
                            </div>
                        )}
                        <button
                            type="button"
                            aria-controls="admin-sidebar"
                            aria-expanded={sidebarExpanded}
                            aria-label={
                                sidebarExpanded
                                    ? 'Tutup sidebar'
                                    : 'Buka sidebar'
                            }
                            title={
                                sidebarExpanded
                                    ? 'Tutup sidebar'
                                    : 'Buka sidebar'
                            }
                            onClick={() =>
                                setSidebarExpanded((expanded) => !expanded)
                            }
                            className="inline-flex size-11 shrink-0 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 hover:text-brand-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary"
                        >
                            {sidebarExpanded ? (
                                <PanelLeftClose
                                    aria-hidden="true"
                                    className="size-5"
                                />
                            ) : (
                                <PanelLeftOpen
                                    aria-hidden="true"
                                    className="size-5"
                                />
                            )}
                        </button>
                    </div>

                    <AdminNavigation collapsed={!sidebarExpanded} />

                    <div className="mt-auto border-t border-slate-200 p-4">
                        {sidebarExpanded && (
                            <p className="truncate px-2 text-sm text-slate-600">
                                {auth.user?.email}
                            </p>
                        )}
                        <button
                            type="button"
                            onClick={() => router.post('/admin/logout')}
                            aria-label={sidebarExpanded ? undefined : 'Keluar'}
                            title={sidebarExpanded ? undefined : 'Keluar'}
                            className={`mt-2 flex min-h-11 w-full items-center rounded-lg text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary ${
                                sidebarExpanded
                                    ? 'gap-3 px-3'
                                    : 'justify-center px-0'
                            }`}
                        >
                            <LogOut aria-hidden="true" className="size-5" />
                            <span
                                className={
                                    sidebarExpanded ? undefined : 'sr-only'
                                }
                            >
                                Keluar
                            </span>
                        </button>
                    </div>
                </aside>

                <main className="min-w-0 flex-1">{children}</main>
            </div>
        </div>
    );
}

function AdminNavigation({
    mobile = false,
    collapsed = false,
}: {
    mobile?: boolean;
    collapsed?: boolean;
}) {
    const { url } = usePage();

    return (
        <nav
            aria-label="Navigasi admin"
            className={
                mobile ? 'overflow-x-auto px-3 pb-3' : collapsed ? 'p-3' : 'p-4'
            }
        >
            <ul
                className={
                    mobile ? 'flex min-w-max gap-1' : 'flex flex-col gap-1'
                }
            >
                {navigationItems.map(({ label, href, icon: Icon }) => {
                    const active =
                        href === '/admin' ? url === href : url.startsWith(href);

                    return (
                        <li key={href}>
                            <Link
                                href={href}
                                aria-current={active ? 'page' : undefined}
                                title={collapsed ? label : undefined}
                                className={`flex min-h-11 items-center rounded-lg text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-primary ${
                                    collapsed
                                        ? 'justify-center px-0'
                                        : 'gap-3 px-3'
                                } ${
                                    active
                                        ? 'bg-sky-50 font-semibold text-brand-primary'
                                        : 'font-medium text-slate-600 hover:bg-slate-100 hover:text-brand-ink'
                                }`}
                            >
                                <Icon aria-hidden="true" className="size-5" />
                                <span
                                    className={
                                        collapsed ? 'sr-only' : undefined
                                    }
                                >
                                    {label}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
