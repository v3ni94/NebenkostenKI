@extends('layouts.site')

@section('meta_title', 'Fehler 419')
@section('meta_robots', 'noindex, nofollow')

@section('content')
    @include('errors.partials.inhalt', [
        'code' => 419,
        'titel' => 'Die Seite war zu lange geöffnet',
        'text' => 'Aus Sicherheitsgründen ist das Formular nach längerer Zeit ohne Eingabe abgelaufen und wurde nicht übernommen.',
        'hinweis' => 'Bitte gehen Sie zur vorherigen Seite zurück, laden Sie sie neu und senden Sie Ihre Eingaben erneut ab. Bereits gespeicherte Angaben bleiben erhalten.',
        'zurueck' => url()->previous() !== url()->current() ? url()->previous() : null,
    ])
@endsection
