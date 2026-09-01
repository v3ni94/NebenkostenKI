{{-- Rechtliche und technische Livegang-Blocker nach Masterprompt 26. --}}
@extends('layouts.admin')

@section('titel', 'Livegang-Blocker')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Livegang-Blocker"
        lead="Die Prüfung liest ausschließlich den tatsächlichen Zustand. Es wird keine Angabe erfunden und keine Freigabe behauptet." />

    <div class="mt-6">
        @include('admin.partials.blockerliste', ['bericht' => $bericht])
    </div>

    <div class="mt-6">
        <x-hvm.card title="Hinweis zur Bewertung">
            <p>
                Die Freigabe des KI-Providers wird aus Sicht der Produktionsumgebung bewertet, auch wenn diese
                Seite lokal geöffnet wird. Damit zeigt die Liste den Zustand zum Livegang und nicht den Zustand
                der Entwicklungsumgebung.
            </p>
            <p class="mt-2">
                Zu Schlüsseln wird ausschließlich gemeldet, ob sie gesetzt sind. Werte werden nicht angezeigt,
                auch nicht teilweise maskiert.
            </p>
        </x-hvm.card>
    </div>
@endsection
