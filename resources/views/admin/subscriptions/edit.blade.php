@extends('layouts.app')

@section('title', 'Editar suscripción')

@section('page')
@include('admin.subscriptions.form', ['action' => route('subscriptions.update', $subscription), 'method' => 'PUT', 'subscription' => $subscription])
@endsection
