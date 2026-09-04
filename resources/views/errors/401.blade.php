@extends('layouts.site')

@section('meta_title', 'Fehler 401')
@section('meta_robots', 'noindex, nofollow')

@section('content')
    @include('errors.partials.inhalt', [
        'code' => 401,
        'titel' => 'Anmeldung erforderlich',
        'text' => 'Für diese Seite ist eine Anmeldung erforderlich. Bitte melden Sie sich an und rufen Sie die Seite danach erneut auf.',
        'hinweis' => null,
        'zurueck' => null,
    ])
@endsection
