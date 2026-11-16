@extends('layouts.app')

@section('title', 'Nuevo consultorio')

@section('page')
@include('admin.branches.form', ['action' => route('branches.store'), 'method' => 'POST', 'branch' => null])
@endsection