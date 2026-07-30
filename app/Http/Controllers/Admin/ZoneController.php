<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveZoneRequest;
use App\Models\Zone;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ZoneController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/zones', [
            'zones' => Zone::query()
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
        ]);
    }

    public function store(SaveZoneRequest $request): RedirectResponse
    {
        Zone::query()->create($request->validated());

        return redirect()->route('admin.zones.index');
    }

    public function update(
        SaveZoneRequest $request,
        Zone $zone,
    ): RedirectResponse {
        $zone->update($request->validated());

        return redirect()->route('admin.zones.index');
    }
}
