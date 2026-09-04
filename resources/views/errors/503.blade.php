@extends('layouts.site')

@section('meta_title', 'Fehler 503')
@section('meta_robots', 'noindex, nofollow')

@section('content')
    @include('errors.partials.inhalt', [
        'code' => 503,
        'titel' => 'Die Anwendung ist vorübergehend nicht erreichbar',
        'text' => 'Zurzeit werden Wartungsarbeiten durchgeführt. Bitte versuchen Sie es in wenigen Minuten erneut.',
        'hinweis' => 'Bereits gespeicherte Daten und Abrechnungen bleiben erhalten.',
        'zurueck' => null,
    ])
@endsection
