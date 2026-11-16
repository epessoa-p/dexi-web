<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Procedure;
use App\Models\Specialty;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Procedimientos y tarifario (Configuración). Agrupado por especialidad, con
 * alta y edición en modal, catálogo sugerido y ajuste de precios por %.
 */
class ProcedureController extends Controller
{
    public function index()
    {
        Specialty::ensureDefaults();
        $filter = request('filter');

        $procedures = Procedure::with('specialty')
            ->search(request('q'))
            ->when($filter === 'inactive', fn ($q) => $q->where('active', false), fn ($q) => $q->where('active', true))
            ->when($filter === 'no_price', fn ($q) => $q->where('price', '<=', 0))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $specialties = Specialty::orderBy('sort_order')->orderBy('name')->get();

        return view('admin.procedures.index', [
            'groups' => $specialties->map(fn (Specialty $s) => ['specialty' => $s, 'items' => $procedures->where('specialty_id', $s->id)->values()])
                ->push(['specialty' => null, 'items' => $procedures->whereNull('specialty_id')->values()])
                ->filter(fn ($g) => $g['items']->isNotEmpty())
                ->values(),
            'specialties' => $specialties->where('active', true)->values(),
            'filter' => $filter,
            'counts' => [
                'all' => Procedure::where('active', true)->count(),
                'no_price' => Procedure::where('active', true)->where('price', '<=', 0)->count(),
                'inactive' => Procedure::where('active', false)->count(),
            ],
            'total' => Procedure::count(),
        ]);
    }

    public function store()
    {
        $procedure = Procedure::create(Procedure::validateForm(request()->all()));

        return redirect()->route('procedures.index', ['q' => request('return_q')])->with('success', "«{$procedure->name}» agregado al tarifario.");
    }

    public function update(Procedure $procedure)
    {
        $procedure->update(Procedure::validateForm(request()->all() + ['active' => request()->boolean('active')], $procedure));

        return back()->with('success', "«{$procedure->name}» actualizado.");
    }

    /** Edición rápida del precio desde la lista (Enter y listo). */
    public function price(Procedure $procedure)
    {
        $data = request()->validate(['price' => ['required', 'numeric', 'min:0', 'max:9999999']], [], ['price' => 'precio']);
        $procedure->update([
            'price' => $data['price'],
            'price_max' => $procedure->price_max !== null && (float) $procedure->price_max > (float) $data['price'] ? $procedure->price_max : null,
        ]);

        if (request()->expectsJson()) {
            return response()->json(['ok' => true, 'price' => (float) $procedure->price, 'formatted' => \App\Support\Money::format($procedure->price)]);
        }

        return back()->with('success', "Precio de «{$procedure->name}» actualizado.");
    }

    /** Se archiva (soft delete): las citas, presupuestos y ventas viejas lo siguen mostrando. */
    public function destroy(Procedure $procedure)
    {
        $procedure->delete();

        return back()->with('success', "«{$procedure->name}» eliminado del tarifario.");
    }

    public function loadSuggested()
    {
        $count = Procedure::loadSuggested();

        return redirect()->route('procedures.index', $count ? ['filter' => 'no_price'] : [])
            ->with($count ? 'success' : 'info', $count
                ? "Se cargaron {$count} procedimientos. Ahora ponles tu precio."
                : 'El catálogo sugerido ya estaba cargado.');
    }

    /** Sube (o baja) los precios en un %; todos o los de una especialidad. Redondea a 0.50 si se pide. */
    public function adjustPrices()
    {
        $data = request()->validate([
            'percent' => ['required', 'numeric', 'between:-90,300', 'not_in:0'],
            'specialty_id' => ['nullable', Rule::exists('specialties', 'id')->where('company_id', app(\App\Support\Tenancy::class)->id())],
            'round' => ['nullable', 'boolean'],
        ], ['percent.not_in' => 'Escribe un porcentaje distinto de cero.'], ['percent' => 'porcentaje']);

        $factor = 1 + $data['percent'] / 100;
        $round = (bool) ($data['round'] ?? false);
        $count = 0;

        DB::transaction(function () use ($data, $factor, $round, &$count) {
            Procedure::where('active', true)->where('price', '>', 0)
                ->when($data['specialty_id'] ?? null, fn ($q, $id) => $q->where('specialty_id', $id))
                ->get()
                ->each(function (Procedure $p) use ($factor, $round, &$count) {
                    $fix = fn ($v) => $v === null ? null : ($round ? round($v * $factor * 2) / 2 : round($v * $factor, 2));
                    $p->update(['price' => $fix((float) $p->price), 'price_max' => $fix($p->price_max !== null ? (float) $p->price_max : null)]);
                    $count++;
                });
        });

        $verb = $data['percent'] > 0 ? 'subieron' : 'bajaron';

        return back()->with('success', "Se {$verb} {$count} precios un " . abs((float) $data['percent']) . ' %.');
    }
}
