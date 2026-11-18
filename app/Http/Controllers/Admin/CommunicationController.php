<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Communication;
use App\Models\Patient;
use App\Models\PatientFollowup;
use App\Support\Communications;
use Carbon\Carbon;

/**
 * Comunicaciones: la lista del día (recordatorios, cumpleaños, seguimientos,
 * inasistencias y saldos) con el mensaje listo para WhatsApp, el historial de
 * lo enviado, las plantillas y los controles programados.
 */
class CommunicationController extends Controller
{
    public function index()
    {
        $remindFor = request('dia') === 'hoy' ? now() : (request('fecha') ? Carbon::parse(request('fecha')) : now()->addDay());
        $queue = Communications::queue($remindFor);
        // La pestaña pedida o, si no, la primera con algo por enviar.
        $kind = array_key_exists((string) request('tipo'), $queue)
            ? request('tipo')
            : (collect($queue)->search(fn ($rows) => $rows->whereNull('sent')->isNotEmpty()) ?: array_key_first($queue));

        return view('admin.communications.index', [
            'queue' => $queue,
            'kind' => $kind,
            'remindFor' => $remindFor,
            'sentToday' => Communication::where('created_at', '>=', today())->count(),
            'upcoming' => PatientFollowup::pending()->whereDate('due_date', '>', today())->count(),
        ]);
    }

    /** Se llama al tocar «Enviar»: WhatsApp ya se abrió en otra pestaña con el texto. */
    public function send()
    {
        $communication = Communications::log(request()->all());

        if (request()->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $communication->id, 'at' => $communication->created_at->format('H:i')]);
        }

        return back()->with('success', 'Mensaje registrado.');
    }

    public function history()
    {
        $communications = Communication::with(['patient', 'user'])
            ->when(request('tipo'), fn ($q, $kind) => $q->where('kind', $kind))
            ->when(request('q'), fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p->search($term)))
            ->latest('id')->paginate(30)->withQueryString();

        return view('admin.communications.history', ['communications' => $communications]);
    }

    public function followups()
    {
        $filter = in_array(request('estado'), ['pendiente', 'hecho', 'descartado'], true) ? request('estado') : 'pendiente';
        $followups = PatientFollowup::with(['patient', 'creator'])->where('status', $filter)
            ->when(request('q'), fn ($q, $term) => $q->whereHas('patient', fn ($p) => $p->search($term)))
            ->orderBy('due_date', $filter === 'pendiente' ? 'asc' : 'desc')->paginate(30)->withQueryString();

        return view('admin.communications.followups', [
            'followups' => $followups,
            'filter' => $filter,
            'counts' => PatientFollowup::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function storeFollowup(Patient $patient)
    {
        $followup = Communications::schedule($patient, request()->all());

        return back()->with('success', "Control programado para el {$followup->due_date->format('d/m/Y')}.");
    }

    public function closeFollowup(PatientFollowup $followup)
    {
        Communications::close($followup, (string) request('status'));

        return back()->with('success', $followup->patient->full_name . ': ' . PatientFollowup::STATUSES[$followup->status][0] . '.');
    }

    public function templates()
    {
        return view('admin.communications.templates', [
            'kinds' => Communications::kinds(),
            'bodies' => collect(Communications::kinds())->mapWithKeys(fn ($k, $kind) => [$kind => Communications::template($kind)]),
            'sample' => Patient::where('active', true)->orderBy('id')->first(),
        ]);
    }

    public function saveTemplates()
    {
        Communications::saveTemplates((array) request('templates', []));

        return back()->with('success', 'Mensajes guardados.');
    }
}
