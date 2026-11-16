<?php

namespace App\Support;

use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Alta y edición de pacientes, con UNA sola validación para la web y la API.
 *
 * - El documento es único por clínica (entre los no borrados).
 * - Un menor de edad necesita el nombre y el teléfono de su tutor.
 * - El número de historia (HC-000001) se asigna al crear y no cambia.
 */
final class Patients
{
    /** @return array<string, mixed> atributos listos para guardar */
    public static function validate(array $input, ?Patient $patient = null): array
    {
        $companyId = app(Tenancy::class)->id();

        $data = validator($input, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'document_type' => ['nullable', Rule::in(array_keys(Patient::DOCUMENT_TYPES))],
            'document_number' => ['nullable', 'string', 'max:30',
                Rule::unique('patients', 'document_number')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($patient?->id)],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'gender' => ['nullable', Rule::in(array_keys(Patient::GENDERS))],
            'occupation' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $companyId)],

            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:120'],
            'emergency_phone' => ['nullable', 'string', 'max:30'],

            'guardian_name' => ['nullable', 'string', 'max:120'],
            'guardian_relationship' => ['nullable', 'string', 'max:40'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],

            'referral_source' => ['nullable', Rule::in(array_keys(Patient::REFERRAL_SOURCES))],
            'referred_by' => ['nullable', 'string', 'max:120'],
            'insurance' => ['nullable', 'string', 'max:120'],

            'conditions' => ['nullable', 'array'],
            'conditions.*' => [Rule::in(array_keys(Patient::CONDITIONS))],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'medications' => ['nullable', 'string', 'max:1000'],
            'pregnant' => ['nullable', 'boolean'],
            'blood_type' => ['nullable', Rule::in(Patient::BLOOD_TYPES)],
            'medical_notes' => ['nullable', 'string', 'max:3000'],

            'notes' => ['nullable', 'string', 'max:3000'],
            'active' => ['nullable', 'boolean'],
        ], [
            'document_number.unique' => 'Ya hay un paciente con ese documento en la clínica.',
            'birth_date.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
        ], [
            'first_name' => 'nombres', 'last_name' => 'apellidos', 'document_number' => 'número de documento',
            'birth_date' => 'fecha de nacimiento', 'guardian_name' => 'nombre del tutor', 'guardian_phone' => 'teléfono del tutor',
            'allergies' => 'alergias', 'medications' => 'medicación',
        ])->validate();

        // Un menor de edad se atiende con un tutor responsable.
        $minor = ! empty($data['birth_date']) && now()->subYears(18)->lt($data['birth_date']);
        if ($minor && (blank($data['guardian_name'] ?? null) || blank($data['guardian_phone'] ?? null))) {
            throw ValidationException::withMessages([
                blank($data['guardian_name'] ?? null) ? 'guardian_name' : 'guardian_phone' => 'Es menor de edad: anota el nombre y el teléfono de su tutor.',
            ]);
        }

        $data['first_name'] = self::name($data['first_name']);
        $data['last_name'] = self::name($data['last_name']);
        $data['conditions'] = array_values(array_unique($data['conditions'] ?? []));
        $data['pregnant'] = (bool) ($data['pregnant'] ?? false) && ($data['gender'] ?? null) !== 'M';
        $data['active'] = array_key_exists('active', $input) && $input['active'] !== null ? (bool) $data['active'] : ($patient?->active ?? true);

        // Se sella la fecha de la anamnesis solo si cambió algo médico.
        $medical = ['conditions', 'allergies', 'medications', 'pregnant', 'blood_type', 'medical_notes'];
        $changed = ! $patient || collect($medical)->contains(fn ($field) => $patient->{$field} != ($data[$field] ?? null));
        if ($changed && collect($medical)->contains(fn ($field) => ! blank($data[$field] ?? null) && $data[$field] !== [])) {
            $data['medical_updated_at'] = now();
        }

        return $data;
    }

    public static function create(array $input): Patient
    {
        $data = self::validate($input);

        return DB::transaction(fn () => Patient::create($data + [
            'number' => Patient::nextNumber(),
            'created_by' => auth()->id(),
        ]));
    }

    public static function update(Patient $patient, array $input): Patient
    {
        $patient->update(self::validate($input, $patient));

        return $patient;
    }

    /** «maría josé» → «María José» (respeta lo que ya viene con mayúsculas mezcladas). */
    private static function name(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value));

        return $value === mb_strtolower($value) || $value === mb_strtoupper($value)
            ? mb_convert_case(mb_strtolower($value), MB_CASE_TITLE)
            : $value;
    }
}
