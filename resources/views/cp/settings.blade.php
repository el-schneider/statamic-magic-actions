@extends('statamic::layout')

@section('title', __('Magic Actions Settings'))

@section('content')
    <publish-form
        title="{{ __('Magic Actions Settings') }}"
        action="{{ cp_route('magic-actions.settings.update') }}"
        method="post"
        :blueprint='@json($blueprint)'
        :meta='@json($meta)'
        :values='@json($values)'
    ></publish-form>
@stop
