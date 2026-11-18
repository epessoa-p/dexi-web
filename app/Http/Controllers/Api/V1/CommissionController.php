<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\CommissionController as WebCommissionController;
use App\Models\Commission;
use App\Models\Personal;
use App\Support\Commissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comisiones en la API (ESTRUCTURA_CUENTAS_Y_GASTOS.md §7): lo que se debe
 * (de cualquier fecha), el detalle de un profesional y pagar una liquidación.
 * Valida y liquida igual que la web.
 */
class CommissionController extends WebCommissionController
{
    public function pendingList(): JsonResponse
    {
        $rows = Commissions::pending();

        return response()->json([
            'total' => round((float) $rows->sum('total'), 2),
            'data' => $rows->map(fn ($row) => [
                'personal_id' => $row['professional']?->id,
                'name' => $row['professional']?->full_name,
                'count' => $row['count'],
                'total' => $row['total'],
                'since' => $row['since'] ? \Carbon\Carbon::parse($row['since'])->toDateString() : null,
            ])->values(),
        ]);
    }

    public function detailFor(Personal $personal): JsonResponse
    {
        [$from, $to] = $this->period();

        return response()->json([
            'personal_id' => $personal->id,
            'name' => $personal->full_name,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'pending_total' => round((float) Commission::where('personal_id', $personal->id)->pending()->sum('amount'), 2),
            'data' => $this->rowsFor($personal, $from, $to)->map(fn (Commission $c) => [
                'id' => $c->id,
                'earned_at' => $c->earned_at->toIso8601String(),
                'description' => $c->description,
                'sale' => $c->sale?->code,
                'patient' => $c->sale?->patient?->full_name,
                'kind' => $c->kind,
                'base' => (float) $c->base,
                'rate' => $c->rate_label,
                'amount' => (float) $c->amount,
                'settlement' => $c->settlement?->code,
            ])->values(),
        ]);
    }

    /** Lo del propio odontólogo (su usuario vinculado a una ficha de Personal), sin el permiso de ver las de todos. */
    public function mineApi(): JsonResponse
    {
        $personal = Personal::where('user_id', auth()->id())->first();
        abort_unless($personal, 404, 'Tu usuario no está vinculado a una ficha de odontólogo.');

        return $this->detailFor($personal);
    }

    public function pay(Request $request, Personal $personal): JsonResponse
    {
        $settlement = $this->settleFor($personal, $request->all());

        return response()->json([
            'id' => $settlement->id,
            'code' => $settlement->code,
            'total' => (float) $settlement->total,
            'commissions_count' => $settlement->commissions_count,
            'expense_id' => $settlement->expense_id,
        ], 201);
    }
}
