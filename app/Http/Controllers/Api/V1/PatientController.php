<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Support\Patients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pacientes en la API: la misma validación que la web (App\Support\Patients).
 */
class PatientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = Patient::where('active', ! $request->boolean('inactive'))
            ->search($request->query('q'))
            ->when($request->boolean('alerts'), fn ($q) => $q->withAlerts())
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Patient $p) => self::summary($p))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Catálogos para armar el formulario en la app. */
    public function options(): JsonResponse
    {
        $pairs = fn (array $items) => collect($items)->map(fn ($label, $key) => ['key' => $key, 'label' => is_array($label) ? $label[0] : $label])->values();

        return response()->json([
            'document_types' => $pairs(Patient::DOCUMENT_TYPES),
            'genders' => $pairs(Patient::GENDERS),
            'referral_sources' => $pairs(Patient::REFERRAL_SOURCES),
            'conditions' => collect(Patient::CONDITIONS)->map(fn ($c, $key) => ['key' => $key, 'label' => $c[0], 'critical' => $c[1]])->values(),
            'blood_types' => Patient::BLOOD_TYPES,
            'document_categories' => $pairs(PatientDocument::CATEGORIES),
        ]);
    }

    public function show(Patient $patient): JsonResponse
    {
        return response()->json(self::detail($patient->loadCount('documents')));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(self::detail(Patients::create($request->all())->loadCount('documents')), 201);
    }

    public function update(Request $request, Patient $patient): JsonResponse
    {
        return response()->json(self::detail(Patients::update($patient, $request->all())->loadCount('documents')));
    }

    /** @return array<string, mixed> */
    public static function summary(Patient $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'full_name' => $p->full_name,
            'document' => $p->document_label,
            'age' => $p->age,
            'age_label' => $p->age_label,
            'phone' => $p->phone,
            'whatsapp_link' => $p->whatsapp_link,
            'alerts' => $p->medicalAlerts(),
            'active' => (bool) $p->active,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Patient $p): array
    {
        return self::summary($p) + $p->only([
            'first_name', 'last_name', 'document_type', 'document_number', 'gender', 'occupation', 'branch_id',
            'whatsapp', 'email', 'address', 'emergency_contact', 'emergency_phone',
            'guardian_name', 'guardian_relationship', 'guardian_phone', 'referral_source', 'referred_by', 'insurance',
            'allergies', 'medications', 'pregnant', 'blood_type', 'medical_notes', 'notes',
        ]) + [
            'birth_date' => $p->birth_date?->toDateString(),
            'conditions' => $p->conditions ?? [],
            'medical_updated_at' => $p->medical_updated_at?->toIso8601String(),
            'documents_count' => $p->documents_count ?? null,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
