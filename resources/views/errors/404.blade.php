@extends('layouts.site')

@section('meta_title', 'Fehler 404')
@section('meta_robots', 'noindex, nofollow')

@section('content')
    @include('errors.partials.inhalt', [
        'code' => 404,
        'titel' => 'Seite nicht gefunden',
        'text' => 'Die aufgerufene Seite gibt es nicht oder nicht mehr. Bitte prüfen Sie die Adresse oder nutzen Sie die Navigation.',
        'hinweis' => null,
        'zurueck' => null,
    ])
@endsection
