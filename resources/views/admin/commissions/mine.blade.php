@extends('layouts.app')

@section('title', 'Mis comisiones')

@section('page')
<div class="container-fluid" style="max-width: 1100px;">
    <div class="page-header">
        <div>
            <h1><i class="bi bi-wallet"></i> Mis comisiones</h1>
            <p class="page-subtitle">Lo que ganaste por lo que atendiste y se cobró, y lo que ya te pagaron.</p>
        </div>
        <form method="GET" class="page-actions d-flex align-items-center gap-2 flex-wrap">
            <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control form-control-sm w-auto" aria-label="Desde">
            <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control form-control-sm w-auto" aria-label="Hasta">
            <button class="btn btn-sm btn-light" type="submit"><i class="bi bi-funnel"></i> Ver</button>
        </form>
    </div>
    @include('admin.commissions.partials.rows', ['rows' => $rows, 'pendingAll' => $pendingAll])
</div>
@endsection
