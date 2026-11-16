<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Personal;
use App\Models\Schedule;
use App\Models\ScheduleBlock;
use App\Models\Specialty;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Agenda › Disponibilidad: quién atiende pacientes (color y especialidad), su
 * horario semanal y los bloqueos (vacaciones, feriados).
 */
class AvailabilityController extends Controller
{
    public function index()
    {
        Specialty::ensureDefaults();

        return view('admin.availability.index', [
            'team' => Personal::with(['schedules', 'specialty', 'cargo'])->where('active', true)
                ->orderByDesc('attends_patients')->orderBy('full_name')->get(),
            'specialties' => Specialty::where('active', true)->orderBy('sort_order')->get(),
            'blocks' => ScheduleBlock::with('personal')->where('ends_at', '>=', now()->startOfDay())->orderBy('starts_at')->get(),
            'selected' => request('personal'),
        ]);
    }

    /** Datos de agenda del profesional + su horario semanal completo (reemplaza el anterior). */
    public function update(Personal $personal)
    {
        $companyId = app(Tenancy::class)->id();
        $data = request()->validate([
            'attends_patients' => ['nullable', 'boolean'],
            'specialty_id' => ['nullable', Rule::exists('specialties', 'id')->where('company_id', $companyId)],
            'agenda_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'schedule' => ['nullable', 'array'],
            'schedule.*.weekday' => ['required', 'integer', 'between:1,7'],
            'schedule.*.starts_at' => ['required', 'date_format:H:i'],
            'schedule.*.ends_at' => ['required', 'date_format:H:i'],
        ], [], ['schedule.*.starts_at' => 'hora de inicio', 'schedule.*.ends_at' => 'hora de fin']);

        $blocks = collect($data['schedule'] ?? [])->map(fn ($b) => ['weekday' => (int) $b['weekday'], 'starts_at' => $b['starts_at'], 'ends_at' => $b['ends_at']]);

        foreach ($blocks as $block) {
            if ($block['ends_at'] <= $block['starts_at']) {
                throw ValidationException::withMessages(['schedule' => Schedule::WEEKDAYS[$block['weekday']] . ': la hora de fin debe ser posterior a la de inicio.']);
            }
        }
        foreach ($blocks->groupBy('weekday') as $weekday => $day) {
            $sorted = $day->sortBy('starts_at')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                if ($sorted[$i]['starts_at'] < $sorted[$i - 1]['ends_at']) {
                    throw ValidationException::withMessages(['schedule' => Schedule::WEEKDAYS[$weekday] . ': hay bloques que se pisan.']);
                }
            }
        }

        DB::transaction(function () use ($personal, $data, $blocks) {
            $personal->update([
                'attends_patients' => (bool) ($data['attends_patients'] ?? false),
                'specialty_id' => $data['specialty_id'] ?? null,
                'agenda_color' => $data['agenda_color'] ?? null,
            ]);
            $personal->schedules()->delete();
            foreach ($blocks as $block) {
                $personal->schedules()->create($block + ['company_id' => $personal->company_id]);
            }
        });

        return redirect()->route('availability.index', ['personal' => $personal->id])->with('success', "Agenda de {$personal->full_name} guardada.");
    }

    public function storeBlock()
    {
        $companyId = app(Tenancy::class)->id();
        $data = request()->validate([
            'personal_id' => ['nullable', Rule::exists('personal', 'id')->where('company_id', $companyId)],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'all_day' => ['nullable', 'boolean'],
            'from_time' => ['nullable', 'date_format:H:i'],
            'to_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:120'],
        ], ['to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.'], ['reason' => 'motivo', 'from' => 'desde', 'to' => 'hasta']);

        $allDay = (bool) ($data['all_day'] ?? true) || empty($data['from_time']) || empty($data['to_time']);
        $starts = Carbon::parse($data['from'])->setTimeFromTimeString($allDay ? '00:00' : $data['from_time']);
        $ends = Carbon::parse($data['to'])->setTimeFromTimeString($allDay ? '23:59:59' : $data['to_time']);
        if ($ends->lte($starts)) {
            throw ValidationException::withMessages(['to_time' => 'El bloqueo termina antes de empezar.']);
        }

        ScheduleBlock::create(['personal_id' => $data['personal_id'] ?? null, 'starts_at' => $starts, 'ends_at' => $ends, 'reason' => trim($data['reason']), 'created_by' => auth()->id()]);

        return back()->with('success', 'Bloqueo agregado a la agenda.');
    }

    public function destroyBlock(ScheduleBlock $block)
    {
        $block->delete();

        return back()->with('success', 'Bloqueo eliminado.');
    }
}
