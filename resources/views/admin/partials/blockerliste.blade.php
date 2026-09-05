{{--
    Livegang-Blocker als Liste.

    Jeder Eintrag nennt, was fehlt, welche Folge das hat und wer es
    bereitstellen muss. Es wird keine Angabe erfunden. Der Zustand steht als
    Badge mit Symbol und Text, nie nur als Farbe.

    Erwartet:
      $bericht  App\Application\Admin\LaunchBlockerReport
--}}
@if ($bericht->isClear())
    <x-hvm.alert variant="success" label="Erledigt" title="Kein offener Livegang-Blocker">
        Es ist kein offener Punkt festgestellt. Die Prüfung ersetzt keine Abnahme.
    </x-hvm.alert>
@else
    <div class="space-y-6">
        @foreach ($bericht->byArea() as $bereich => $blocker)
            <x-hvm.card :title="$bereich" eyebrow="Bereich">
                <ul class="space-y-4">
                    @foreach ($blocker as $eintrag)
                        <li class="rounded-2xl bg-hvm-canvas p-4 sm:p-5">
                            <p class="flex flex-wrap items-center gap-2">
                                <x-hvm.badge :variant="$eintrag->isBlocking() ? 'error' : 'warning'">
                                    {{ $eintrag->isBlocking() ? 'Blockierend' : 'Entscheidung offen' }}
                                </x-hvm.badge>
                                <span class="font-mono text-xs text-hvm-text-sekundaer">{{ $eintrag->key }}</span>
                            </p>
                            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                                <div class="min-w-0">
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Was fehlt</dt>
                                    <dd class="mt-1 leading-relaxed text-hvm-textschwarz">{{ $eintrag->missing }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Folge</dt>
                                    <dd class="mt-1 leading-relaxed text-hvm-textschwarz">{{ $eintrag->consequence }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Wer stellt es bereit</dt>
                                    <dd class="mt-1 leading-relaxed text-hvm-textschwarz">{{ $eintrag->responsible }}</dd>
                                </div>
                            </dl>
                        </li>
                    @endforeach
                </ul>
            </x-hvm.card>
        @endforeach
    </div>
@endif
