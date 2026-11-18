<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Documentos y fotos del paciente en la API: radiografías, fotos intraorales,
 * consentimientos… La app los sube desde la cámara. Los archivos son privados:
 * se descargan con el token, nunca por una URL pública.
 */
class PatientDocumentController extends Controller
{
    public function index(Patient $patient): JsonResponse
    {
        $documents = $patient->documents()->with('uploader')->latest('taken_on')->latest('id')->get();

        return response()->json([
            'categories' => collect(PatientDocument::CATEGORIES)->map(fn ($c, $key) => ['key' => $key, 'label' => $c[0]])->values(),
            'data' => $documents->map(fn (PatientDocument $d) => self::document($d))->values(),
        ]);
    }

    /** Sube uno o varios archivos (`file` o `files[]`). */
    public function store(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required_without:files', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:15360'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:15360'],
            'category' => ['required', Rule::in(array_keys(PatientDocument::CATEGORIES))],
            'title' => ['nullable', 'string', 'max:150'],
            'taken_on' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'file.required_without' => 'Elige o toma una foto.',
            'file.mimes' => 'Solo fotos (JPG, PNG, WEBP, HEIC) o PDF.',
            'file.max' => 'El archivo puede pesar hasta 15 MB.',
        ], ['category' => 'tipo']);

        $files = $request->hasFile('files') ? $request->file('files') : [$request->file('file')];
        $created = [];
        foreach ($files as $index => $file) {
            $title = trim((string) ($data['title'] ?? '')) ?: (PatientDocument::CATEGORIES[$data['category']][0] . ' ' . now()->format('d/m/Y'));
            $created[] = PatientDocument::create([
                'patient_id' => $patient->id,
                'category' => $data['category'],
                'title' => count($files) > 1 ? "{$title} (" . ($index + 1) . ')' : $title,
                'path' => $file->store("patients/{$patient->company_id}/{$patient->id}", 'local'),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'taken_on' => $data['taken_on'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'uploaded_by' => $request->user()->id,
            ]);
        }

        return response()->json(['data' => collect($created)->map(fn ($d) => self::document($d->load('uploader')))->values()], 201);
    }

    /** El archivo, con el token (imágenes y PDF se muestran; lo demás se descarga). */
    public function file(Patient $patient, PatientDocument $document)
    {
        abort_unless($document->patient_id === $patient->id && Storage::disk('local')->exists($document->path), 404);

        // El nombre del archivo no admite barras (un título como «Foto 17/11/2026»).
        $name = str_replace(['/', '\\'], '-', $document->title) . '.' . pathinfo($document->path, PATHINFO_EXTENSION);

        return Storage::disk('local')->response($document->path, $name);
    }

    public function destroy(Patient $patient, PatientDocument $document): JsonResponse
    {
        abort_unless($document->patient_id === $patient->id, 404);
        $document->delete();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private static function document(PatientDocument $d): array
    {
        return [
            'id' => $d->id,
            'category' => $d->category,
            'category_label' => $d->category_label,
            'title' => $d->title,
            'mime' => $d->mime,
            'is_image' => $d->isImage(),
            'size_label' => $d->size_label,
            'taken_on' => $d->taken_on?->toDateString(),
            'notes' => $d->notes,
            'uploaded_by' => $d->uploader?->name,
            'file_path' => "/patients/{$d->patient_id}/documents/{$d->id}/file",
        ];
    }
}
