<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Validation\Rule;

/**
 * Métodos de pago de la clínica (Configuración). Solo los de tipo «Efectivo»
 * entran al arqueo de la caja. No se borran: se desactivan.
 */
class PaymentMethodController extends Controller
{
    public function index()
    {
        PaymentMethod::ensureDefaults();

        return view('admin.payment-methods.index', [
            'methods' => PaymentMethod::orderByDesc('active')->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    public function store()
    {
        $method = PaymentMethod::create($this->validated() + ['sort' => (int) PaymentMethod::max('sort') + 1]);

        return back()->with('success', "Método {$method->name} agregado.");
    }

    public function update(PaymentMethod $method)
    {
        $method->update($this->validated($method));

        return back()->with('success', "Método {$method->name} actualizado.");
    }

    /** @return array<string, mixed> */
    protected function validated(?PaymentMethod $method = null): array
    {
        $data = request()->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(array_keys(PaymentMethod::TYPES))],
        ], [], ['name' => 'nombre', 'type' => 'tipo']);

        return $data + [
            'requires_reference' => request()->boolean('requires_reference'),
            'active' => $method ? request()->boolean('active') : true,
        ];
    }
}
