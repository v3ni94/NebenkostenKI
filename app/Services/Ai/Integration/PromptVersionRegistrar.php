<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Enums\AiCallPurpose;
use App\Models\AiPromptVersion;
use Illuminate\Support\Carbon;

/**
 * Fuehrt ai_prompt_versions nach: kein Prompt- oder Modellwechsel ohne
 * Protokollierung (ADR-009, Abschnitt 6.4).
 *
 * Je Zweck ist genau eine Version aktiv. Wird eine bislang unbekannte Version
 * verwendet, wird die bisher aktive Version desselben Zwecks deaktiviert und
 * die neue aktiviert. Damit ist im Nachhinein feststellbar, mit welchem Prompt
 * ein bezahlter Berechnungsstand entstanden ist.
 *
 * SONDERFALL GLEICHE VERSION, ANDERER HASH: Das ist ein Verstoss gegen die
 * Versionierungsdisziplin, denn der Prompttext hat sich geaendert, ohne dass
 * die Version erhoeht wurde. Der Datensatz wird auf den neuen Hash gesetzt und
 * traegt anschliessend einen festen Hinweistext in notes, damit der Vorgang im
 * Adminbereich sichtbar bleibt. Ein stilles Ueberschreiben waere der
 * schlechtere Weg, ein zweiter Datensatz ist wegen des eindeutigen
 * Schluessels (purpose, version) nicht moeglich.
 *
 * DATENSCHUTZ: Gespeichert werden ausschliesslich Zweck, Version, SHA-256 des
 * Prompttextes und der Aktivierungsstand. Der Prompttext selbst wird niemals
 * persistiert.
 */
final class PromptVersionRegistrar
{
    public const HASH_MISMATCH_NOTE = 'Der Prompthash hat sich bei unveraenderter Version geaendert. '
        .'Bitte die Promptversion erhoehen, damit ein Berechnungsstand reproduzierbar bleibt.';

    /**
     * Auflösungen dieses Prozesses, damit ein Stapellauf nicht je Dokument
     * erneut liest.
     *
     * @var array<string, AiPromptVersion>
     */
    private array $cache = [];

    public function register(AiCallPurpose $purpose, string $version, string $hash): ?AiPromptVersion
    {
        $version = trim($version);
        $hash = trim($hash);

        if ($version === '' || $hash === '') {
            return null;
        }

        $cacheKey = $purpose->value.'|'.$version.'|'.$hash;

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $existing = AiPromptVersion::query()
            ->where('purpose', $purpose->value)
            ->where('version', $version)
            ->first();

        $record = $existing instanceof AiPromptVersion
            ? $this->refresh($existing, $hash)
            : $this->create($purpose, $version, $hash);

        return $this->cache[$cacheKey] = $record;
    }

    private function create(AiCallPurpose $purpose, string $version, string $hash): AiPromptVersion
    {
        $this->deactivateOthers($purpose, null);

        $record = new AiPromptVersion;

        $record->fill([
            'purpose' => $purpose,
            'version' => $version,
            'hash' => mb_substr($hash, 0, 64),
            'is_active' => true,
            'activated_at' => Carbon::now(),
        ]);

        $record->save();

        return $record;
    }

    private function refresh(AiPromptVersion $record, string $hash): AiPromptVersion
    {
        $attributes = [];

        if ($record->getAttribute('hash') !== mb_substr($hash, 0, 64)) {
            $attributes['hash'] = mb_substr($hash, 0, 64);
            $attributes['notes'] = self::HASH_MISMATCH_NOTE;
        }

        if ($record->getAttribute('is_active') !== true) {
            $purpose = $record->getAttribute('purpose');

            if ($purpose instanceof AiCallPurpose) {
                $this->deactivateOthers($purpose, (string) $record->getKey());
            }

            $attributes['is_active'] = true;
            $attributes['activated_at'] = Carbon::now();
            $attributes['deactivated_at'] = null;
        }

        if ($attributes !== []) {
            $record->forceFill($attributes)->save();
        }

        return $record;
    }

    private function deactivateOthers(AiCallPurpose $purpose, ?string $exceptId): void
    {
        $query = AiPromptVersion::query()
            ->where('purpose', $purpose->value)
            ->where('is_active', true);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        $query->update([
            'is_active' => false,
            'deactivated_at' => Carbon::now(),
        ]);
    }
}
