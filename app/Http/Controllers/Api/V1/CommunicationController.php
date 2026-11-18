<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Communication;
use App\Models\Patient;
use App\Models\PatientFollowup;
use App\Support\Communications;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comunicaciones en la API (solo wa.me): la lista «Para enviar hoy», registrar
 * lo enviado, el historial y los controles programados. La app abre WhatsApp
 * con el mensaje y avisa aquí; las reglas son las de App\Support\Communications.
 */
class CommunicationController extends Controller
{
    /**
     * Para enviar: recordatorios de las citas de mañana (o de hoy con ?dia=hoy,
     * o de ?fecha=), cumpleaños, controles vencidos, inasistencias y saldos vencidos.
     */
    public function queue(Request $request): JsonResponse
    {
        $remindFor = $request->query('dia') === 'hoy'
            ? now()
            : ($request->query('fecha') ? Carbon::parse($request->query('fecha')) : now()->addDay());
        $queue = Communications::queue($remindFor);

        return response()->json([
            'remind_for' => $remindFor->toDateString(),
            'kinds' => collect($queue)->map(fn ($rows, $kind) => [
                'key' => $kind,
                'label' => Communications::KINDS[$kind][0],
                'color' => Communications::KINDS[$kind][2],
                'total' => $rows->count(),
                'pending' => $rows->whereNull('sent')->count(),
            ])->values(),
            'pending' => collect($queue)->sum(fn ($rows) => $rows->whereNull('sent')->count()),
            'data' => collect($queue)->map(fn ($rows) => $rows->map(fn (array $row) => [
                'key' => $row['key'],
                'kind' => $row['kind'],
                'patient' => ['id' => $row['patient']->id, 'full_name' => $row['patient']->full_name, 'phone' => $row['patient']->phone],
                'detail' => $row['detail'],
                'message' => $row['message'],
                'whatsapp_url' => Communications::link($row['patient'], $row['message']),
                'appointment_id' => $row['refs']['appointment_id'] ?? null,
                'followup_id' => $row['refs']['followup_id'] ?? null,
                'sent' => $row['sent'] ? [
                    'at' => $row['sent']->created_at?->format('Y-m-d\TH:i:s'),
                    'user' => $row['sent']->user?->name,
                ] : null,
            ])->values()),
        ]);
    }

    /** Registra que se abrió WhatsApp con el mensaje (la clínica lo envía desde su teléfono). */
    public function send(Request $request): JsonResponse
    {
        $communication = Communications::log($request->all());

        return response()->json([
            'id' => $communication->id,
            'kind' => $communication->kind,
            'sent_at' => $communication->created_at?->toIso8601String(),
        ], 201);
    }

    /** Lo enviado, lo último primero; por tipo, paciente (?patient_id=) o búsqueda. */
    public function history(Request $request): JsonResponse
    {
        $items = Communication::with(['patient', 'user'])
            ->when($request->query('tipo'), fn ($q, $kind) => $q->where('kind', $kind))
            ->when($request->integer('patient_id'), fn ($q, $id) => $q->where('patient_id', $id))
            ->when($request->query('q'), fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p->search($term)))
            ->latest('id')->limit(50)->get();

        return response()->json([
            'data' => $items->map(fn (Communication $c) => [
                'id' => $c->id,
                'kind' => $c->kind,
                'kind_label' => $c->kind_label,
                'patient' => $c->patient ? ['id' => $c->patient->id, 'full_name' => $c->patient->full_name] : null,
                'message' => $c->message,
                'sent_at' => $c->created_at?->format('Y-m-d\TH:i:s'),
                'user' => $c->user?->name,
            ])->values(),
        ]);
    }

    /** Controles programados: pendientes (los vencidos primero), hechos o descartados; de un paciente con ?patient_id=. */
    public function followups(Request $request): JsonResponse
    {
        $status = in_array($request->query('estado'), ['pendiente', 'hecho', 'descartado'], true) ? $request->query('estado') : 'pendiente';
        $patientId = $request->integer('patient_id') ?: null;

        $items = PatientFollowup::with(['patient', 'creator'])
            ->where('status', $status)
            ->when($patientId, fn ($q) => $q->where('patient_id', $patientId))
            ->when($request->query('q'), fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p->search($term)))
            ->orderBy('due_date', $status === 'pendiente' ? 'asc' : 'desc')
            ->limit(100)->get();

        return response()->json([
            'status' => $status,
            'counts' => PatientFollowup::when($patientId, fn ($q) => $q->where('patient_id', $patientId))
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'reasons' => PatientFollowup::REASONS,
            'quick' => collect(PatientFollowup::QUICK)->map(fn ($in, $label) => ['label' => $label, 'in' => $in])->values(),
            'data' => $items->map(fn (PatientFollowup $f) => self::followup($f))->values(),
        ]);
    }

    public function schedule(Request $request, Patient $patient): JsonResponse
    {
        $followup = Communications::schedule($patient, $request->all());

        return response()->json(self::followup($followup->load('patient')), 201);
    }

    public function closeFollowup(Request $request, PatientFollowup $followup): JsonResponse
    {
        Communications::close($followup, (string) $request->input('status'));

        return response()->json(self::followup($followup->fresh(['patient', 'creator'])));
    }

    /** @return array<string, mixed> */
    private static function followup(PatientFollowup $f): array
    {
        return [
            'id' => $f->id,
            'patient' => $f->patient ? ['id' => $f->patient->id, 'full_name' => $f->patient->full_name, 'phone' => $f->patient->phone] : null,
            'due_date' => $f->due_date?->toDateString(),
            'reason' => $f->reason,
            'notes' => $f->notes,
            'status' => $f->status,
            'status_label' => PatientFollowup::STATUSES[$f->status][0] ?? $f->status,
            'overdue' => $f->isOverdue(),
            'consultation_id' => $f->consultation_id,
            'created_by' => $f->creator?->name,
        ];
    }
}
