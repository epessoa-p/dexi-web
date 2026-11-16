<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;

/**
 * Categorías de gasto, agrupadas por tipo: generales, servicios
 * básicos (mensual o anual), pagos a proveedores y pagos a personal.
 */
class ExpenseCategoryController extends Controller
{
    public function index()
    {
        ExpenseCategory::ensureDefaults();
        $categories = ExpenseCategory::withCount(['expenses' => fn ($q) => $q->valid()])
            ->orderByDesc('active')->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.finance.categories', [
            'groups' => collect(ExpenseCategory::GROUP_TITLES)->map(fn ($title, $group) => [
                'title' => $title,
                'items' => $categories->filter(fn (ExpenseCategory $c) => $c->group === $group)->values(),
            ]),
        ]);
    }

    public function store()
    {
        $category = ExpenseCategory::create(ExpenseCategory::validateForm(request()->all()));

        // «+ Nuevo servicio» desde Gastos › Servicios básicos vuelve allí.
        if (request('return') === 'basics') {
            return redirect()->route('expenses.index', ['tab' => 'basico'])->with('success', "Servicio {$category->name} agregado.");
        }

        return back()->with('success', "Categoría {$category->name} agregada.");
    }

    public function update(ExpenseCategory $category)
    {
        $category->update(ExpenseCategory::validateForm(request()->all() + ['active' => request()->boolean('active')], $category));

        return back()->with('success', 'Categoría actualizada.');
    }

    /** Con gastos, solo se da de baja: el historial no se rompe. */
    public function destroy(ExpenseCategory $category)
    {
        if ($category->expenses()->withTrashed()->exists()) {
            $category->update(['active' => false]);

            return back()->with('success', "{$category->name} tiene gastos: quedó inactiva.");
        }

        $category->delete();

        return back()->with('success', "Categoría {$category->name} eliminada.");
    }
}
