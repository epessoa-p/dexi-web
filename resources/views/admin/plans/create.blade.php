@extends('layouts.app')

@section('title', 'Nuevo plan')

@section('page')
@include('admin.plans.form', ['action' => route('plans.store'), 'method' => 'POST', 'plan' => null])
@endsection
