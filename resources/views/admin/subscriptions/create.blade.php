@extends('layouts.app')

@section('title', 'Asignar suscripción')

@section('page')
@include('admin.subscriptions.form', ['action' => route('subscriptions.store'), 'method' => 'POST', 'subscription' => null])
@endsection
