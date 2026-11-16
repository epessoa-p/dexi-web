<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Documentos y fotos de la ficha. Disco privado: cada archivo se sirve por
 * esta ruta, con permiso y dentro de la clínica (el binding pasa por el scope).
 */
class PatientDocumentController extends Controller
{
    public function store(Patient $patient)
    {
        $data = request()->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,pdf,dcm', 'max:15360'],
            'category' => ['required', Rule::in(array_keys(PatientDocument::CATEGORIES))],
            'title' => ['nullable', 'string', 'max:150'],
            'taken_on' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'files.required' => 'Elige al menos un archivo.',
            'files.*.mimes' => 'Solo fotos (JPG, PNG, WEBP, HEIC), PDF o DICOM.',
            'files.*.max' => 'Cada archivo puede pesar hasta 15 MB.',
        ], ['category' => 'tipo', 'taken_on' => 'fecha']);

        $files = request()->file('files');
        foreach ($files as $index => $file) {
            $title = trim((string) ($data['title'] ?? '')) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            PatientDocument::create([
                'patient_id' => $patient->id,
                'category' => $data['category'],
                'title' => count($files) > 1 && ! empty($data['title']) ? "{$title} (" . ($index + 1) . ')' : $title,
                'path' => $file->store("patients/{$patient->company_id}/{$patient->id}", 'local'),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'taken_on' => $data['taken_on'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'uploaded_by' => auth()->id(),
            ]);
        }

        $count = count($files);

        return redirect()->route('patients.show', [$patient, 'tab' => 'documentos'])
            ->with('success', $count === 1 ? 'Archivo agregado a la ficha.' : "{$count} archivos agregados a la ficha.");
    }

    public function show(Patient $patient, PatientDocument $document)
    {
        abort_unless($document->patient_id === $patient->id && Storage::disk('local')->exists($document->path), 404);

        // Imágenes y PDF se abren en el navegador; lo demás se descarga.
        $inline = $document->isImage() || $document->mime === 'application/pdf';
        $name = $document->title . '.' . pathinfo($document->path, PATHINFO_EXTENSION);

        return Storage::disk('local')->response($document->path, $name, [], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Patient $patient, PatientDocument $document)
    {
        abort_unless($document->patient_id === $patient->id, 404);
        $document->delete();

        return redirect()->route('patients.show', [$patient, 'tab' => 'documentos'])->with('success', 'Archivo eliminado.');
    }
}
