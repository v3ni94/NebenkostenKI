@extends('layouts.site')

@section('meta_title', 'Fehler 403')
@section('meta_robots', 'noindex, nofollow')

@php
    /*
     * Die Anwendung gibt an einigen Stellen eine eigene deutsche Begruendung
     * mit (abort(403, '...')), zum Beispiel wenn dem Konto kein Bereich
     * zugeordnet ist oder ein Bestaetigungslink abgelaufen ist. Sie wird
     * angezeigt; die englischen Standardtexte des Frameworks nicht.
     */
    $meldung = isset($exception) && $exception instanceof \Throwable ? trim($exception->getMessage()) : '';
    $standardtexte = ['', 'Forbidden', 'This action is unauthorized.'];
    $text = in_array($meldung, $standardtexte, true)
        ? 'Sie sind für diese Seite oder Handlung nicht berechtigt. Möglicherweise gehört der Inhalt zu einem anderen Konto oder Ihre Sitzung ist abgelaufen.'
        : $meldung;
@endphp

@section('content')
    @include('errors.partials.inhalt', [
        'code' => 403,
        'titel' => 'Kein Zugriff',
        'text' => $text,
        'hinweis' => null,
        'zurueck' => null,
    ])
@endsection
