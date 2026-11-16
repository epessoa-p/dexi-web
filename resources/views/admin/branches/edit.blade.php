@extends('layouts.app')

@section('title', 'Editar consultorio')

@section('page')
@include('admin.branches.form', ['action' => route('branches.update', $branch), 'method' => 'PUT', 'branch' => $branch])
@endsection