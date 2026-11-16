@extends('layouts.app')

@section('title', 'Editar · ' . $patient->full_name)

@section('page')
@include('admin.patients.form')
@endsection
