<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Procedure;
use App\Models\Specialty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tarifario en la API (solo lectura): para elegir procedimientos en la app. */
class ProcedureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $procedures = Procedure::with('specialty')->where('active', true)
            ->search($request->query('q'))
            ->when($request->query('specialty_id'), fn ($q, $id) => $q->where('specialty_id', $id))
            ->orderBy('sort_order')->orderBy('name')->get();

        return response()->json([
            'specialties' => Specialty::where('active', true)->orderBy('sort_order')->get(['id', 'name', 'color']),
            'data' => $procedures->map(fn (Procedure $p) => self::procedure($p))->values(),
        ]);
    }

    /** @return array<string, mixed> */
    public static function procedure(Procedure $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'specialty_id' => $p->specialty_id,
            'specialty' => $p->specialty?->name,
            'price' => (float) $p->price,
            'price_max' => $p->price_max !== null ? (float) $p->price_max : null,
            'duration_minutes' => $p->duration_minutes,
            'applies_to' => $p->applies_to,
            'applies_to_label' => $p->applies_to_label,
            'color' => $p->calendar_color,
        ];
    }
}
