@extends('layouts.site')

@section('meta_title', 'Fehler 429')
@section('meta_robots', 'noindex, nofollow')

@section('content')
    @include('errors.partials.inhalt', [
        'code' => 429,
        'titel' => 'Zu viele Anfragen',
        'text' => 'Von Ihrer Verbindung sind in kurzer Zeit sehr viele Anfragen eingegangen. Bitte warten Sie einen Moment und versuchen Sie es dann erneut.',
        'hinweis' => null,
        'zurueck' => null,
    ])
@endsection
