@extends('setup.layout')

@section('title', $step->label())

@section('content')
    @include('setup.partials.steps.'.$step->value)
@endsection
