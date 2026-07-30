<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveTimeSlotRequest;
use App\Models\TimeSlot;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TimeSlotController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/time-slots', [
            'timeSlots' => TimeSlot::query()
                ->orderBy('start_time')
                ->get(['id', 'start_time', 'is_active'])
                ->map(fn (TimeSlot $timeSlot): array => [
                    'id' => $timeSlot->id,
                    'start_time' => substr($timeSlot->start_time, 0, 5),
                    'is_active' => $timeSlot->is_active,
                ]),
        ]);
    }

    public function store(SaveTimeSlotRequest $request): RedirectResponse
    {
        TimeSlot::query()->create($request->validated());

        return redirect()->route('admin.time-slots.index');
    }

    public function update(
        SaveTimeSlotRequest $request,
        TimeSlot $timeSlot,
    ): RedirectResponse {
        $timeSlot->update($request->validated());

        return redirect()->route('admin.time-slots.index');
    }
}
