{{--
    Supporteinblick in einen Abrechnungslauf.

    Angezeigt werden Status, Zeitraum und technische Kennzahlen. Es werden
    keine Dokumentinhalte und keine Rohdaten angezeigt.
--}}
@extends('layouts.admin')

@section('titel', 'Abrechnungslauf')

@section('content')
    <x-hvm.section-heading level="h1" title="Abrechnungslauf" />

    <div class="mt-6">
        <x-hvm.alert variant="info" label="Hinweis">
            Dieser Einblick erfolgt zu Supportzwecken und ist im Revisionsprotokoll vermerkt.
        </x-hvm.alert>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Stand des Laufs">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>Kennung</dt><dd class="font-mono text-xs">{{ $lauf->getKey() }}</dd></div>
                <div class="flex justify-between"><dt>Abrechnungsjahr</dt><dd>{{ $lauf->getAttribute('billing_year') }}</dd></div>
                <div class="flex justify-between">
                    <dt>Zeitraum</dt>
                    <dd>
                        {{ \Illuminate\Support\Carbon::parse((string) $lauf->getAttribute('period_start'))->format('d.m.Y') }}
                        bis
                        {{ \Illuminate\Support\Carbon::parse((string) $lauf->getAttribute('period_end'))->format('d.m.Y') }}
                    </dd>
                </div>
                <div class="flex justify-between"><dt>Status</dt><dd>{{ $lauf->getAttribute('status')->label() }}</dd></div>
                <div class="flex justify-between"><dt>Schritt</dt><dd>{{ $lauf->getAttribute('wizard_step') }}</dd></div>
                <div class="flex justify-between"><dt>Mieterabrechnungen</dt><dd>{{ $lauf->getAttribute('statement_count') }}</dd></div>
            </dl>
        </x-hvm.card>
    </div>
@endsection
