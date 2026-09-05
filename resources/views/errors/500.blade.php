@extends('layouts.site')

@section('meta_title', 'Fehler 500')
@section('meta_robots', 'noindex, nofollow')

@section('content')
    @include('errors.partials.inhalt', [
        'code' => 500,
        'titel' => 'Es ist ein Fehler aufgetreten',
        'text' => 'Die Anfrage konnte nicht verarbeitet werden. Der Fehler wurde protokolliert. Bitte versuchen Sie es in wenigen Minuten erneut.',
        'hinweis' => 'Ihre bereits gespeicherten Daten sind davon nicht betroffen.',
        'zurueck' => null,
    ])
@endsection
