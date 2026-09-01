{{--
    Livegang-Blocker als Liste.

    Jeder Eintrag nennt, was fehlt, welche Folge das hat und wer es
    bereitstellen muss. Es wird keine Angabe erfunden.

    Erwartet:
      $bericht  App\Application\Admin\LaunchBlockerReport
--}}
@if ($bericht->isClear())
    <x-hvm.alert variant="success" label="Erledigt" title="Kein offener Livegang-Blocker">
        Es ist kein offener Punkt festgestellt. Die Prüfung ersetzt keine Abnahme.
    </x-hvm.alert>
@else
    <div class="space-y-4">
        @foreach ($bericht->byArea() as $bereich => $blocker)
            <x-hvm.card :title="$bereich">
                <ul class="space-y-4">
                    @foreach ($blocker as $eintrag)
                        <li class="rounded border-l-4 {{ $eintrag->isBlocking() ? 'border-status-error bg-status-error-soft' : 'border-status-warning bg-status-warning-soft' }} px-4 py-3">
                            <p class="flex flex-wrap items-center gap-2">
                                <x-hvm.badge :variant="$eintrag->isBlocking() ? 'error' : 'warning'">
                                    {{ $eintrag->isBlocking() ? 'Blockierend' : 'Entscheidung offen' }}
                                </x-hvm.badge>
                                <span class="text-xs text-hvm-anthrazit">{{ $eintrag->key }}</span>
                            </p>
                            <dl class="mt-2 space-y-1 text-sm">
                                <div>
                                    <dt class="font-semibold">Was fehlt</dt>
                                    <dd>{{ $eintrag->missing }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold">Folge</dt>
                                    <dd>{{ $eintrag->consequence }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold">Wer stellt es bereit</dt>
                                    <dd>{{ $eintrag->responsible }}</dd>
                                </div>
                            </dl>
                        </li>
                    @endforeach
                </ul>
            </x-hvm.card>
        @endforeach
    </div>
@endif
