@extends('layouts.site')

@section('meta_title', 'Fehler 405')
@section('meta_robots', 'noindex, nofollow')

@section('content')
    @include('errors.partials.inhalt', [
        'code' => 405,
        'titel' => 'Diese Anfrage ist hier nicht möglich',
        'text' => 'Die Seite kann auf diesem Weg nicht aufgerufen werden. Bitte nutzen Sie die Schaltflächen und Verweise der Anwendung.',
        'hinweis' => null,
        'zurueck' => null,
    ])
@endsection
