<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use Carbon\CarbonImmutable;

/**
 * Control del mes de los servicios básicos (luz, agua, alquiler…):
 *
 * - Mensual: pagado si hubo un pago (no anulado) dentro del mes.
 * - Anual: pagado si hubo uno en los 12 meses que terminan ese mes;
 *   vuelve a vencer un año después del último pago.
 */
final class BasicServices
{
    /**
     * @return array{month: CarbonImmutable, label: string, paid: int, pending: int, total: float,
     *               items: array<int, array{category: ExpenseCategory, status: string, paid_amount: float, last_amount: ?float, last_paid_on: ?CarbonImmutable, next_due: ?CarbonImmutable}>}
     */
    public static function month(?string $month = null): array
    {
        $start = self::parseMonth($month);
        $end = $start->endOfMonth();

        $items = ExpenseCategory::group('basico')->where('active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(function (ExpenseCategory $category) use ($start, $end) {
                $annual = $category->frequency === 'anual';
                $windowStart = $annual ? $end->subYear()->addDay()->startOfDay() : $start;

                $expenses = Expense::valid()->where('expense_category_id', $category->id);
                $inWindow = (clone $expenses)->whereBetween('spent_at', [$windowStart->toDateString(), $end->toDateString()]);
                $last = (clone $expenses)->where('spent_at', '<=', $end->toDateString())->latest('spent_at')->latest('id')->first();

                $paid = $inWindow->exists();
                $lastOn = $last ? CarbonImmutable::parse($last->spent_at) : null;

                return [
                    'category' => $category,
                    'status' => $paid ? 'pagado' : 'pendiente',
                    // Lo pagado dentro del mes (en un anual, puede ser 0 aunque esté al día).
                    'paid_amount' => round((float) (clone $expenses)->whereBetween('spent_at', [$start->toDateString(), $end->toDateString()])->sum('amount'), 2),
                    'last_amount' => $last ? (float) $last->amount : null,
                    'last_paid_on' => $lastOn,
                    'next_due' => $annual && $lastOn ? $lastOn->addYear() : null,
                ];
            });

        return [
            'month' => $start,
            'label' => ucfirst($start->locale('es')->translatedFormat('F Y')),
            'paid' => $items->where('status', 'pagado')->count(),
            'pending' => $items->where('status', 'pendiente')->count(),
            'total' => round($items->sum('paid_amount'), 2),
            'items' => $items->values()->all(),
        ];
    }

    public static function parseMonth(?string $month): CarbonImmutable
    {
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $date = CarbonImmutable::createFromFormat('!Y-m', $month);
            if ($date && $date->format('Y-m') === $month) {
                return $date->startOfMonth();
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }
}
