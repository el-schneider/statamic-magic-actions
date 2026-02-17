@extends('statamic::layout')

@section('title', __('Magic Actions Settings'))

@section('content')
    @include('magic-actions::cp.settings-form')
@stop
