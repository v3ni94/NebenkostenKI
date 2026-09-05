{{--
    Supporteinblick in einen Abrechnungslauf.

    Angezeigt werden Status, Zeitraum und technische Kennzahlen. Es werden
    keine Dokumentinhalte und keine Rohdaten angezeigt.
--}}
@extends('layouts.admin')

@section('titel', 'Abrechnungslauf')

@section('content')
    <x-hvm.page-header
        eyebrow="Supporteinblick"
        title="Abrechnungslauf"
        :back="route('admin.organisationen')"
        back-label="Organisationen" />

    <div class="mt-8">
        <x-hvm.alert variant="info" label="Hinweis">
            Dieser Einblick erfolgt zu Supportzwecken und ist im Revisionsprotokoll vermerkt.
        </x-hvm.alert>
    </div>

    <div class="mt-10 max-w-3xl">
        <x-hvm.card title="Stand des Laufs" eyebrow="Lauf">
            <dl class="divide-y divide-hvm-linie">
                <x-hvm.kv label="Kennung" :mono="true">{{ $lauf->getKey() }}</x-hvm.kv>
                <x-hvm.kv label="Abrechnungsjahr">{{ $lauf->getAttribute('billing_year') }}</x-hvm.kv>
                <x-hvm.kv label="Zeitraum">
                    {{ \Illuminate\Support\Carbon::parse((string) $lauf->getAttribute('period_start'))->format('d.m.Y') }}
                    bis
                    {{ \Illuminate\Support\Carbon::parse((string) $lauf->getAttribute('period_end'))->format('d.m.Y') }}
                </x-hvm.kv>
                <x-hvm.kv label="Status">{{ $lauf->getAttribute('status')->label() }}</x-hvm.kv>
                <x-hvm.kv label="Schritt">{{ $lauf->getAttribute('wizard_step') }}</x-hvm.kv>
                <x-hvm.kv label="Mieterabrechnungen">{{ $lauf->getAttribute('statement_count') }}</x-hvm.kv>
            </dl>
        </x-hvm.card>
    </div>
@endsection
