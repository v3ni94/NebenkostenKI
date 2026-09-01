<?php

declare(strict_types=1);

namespace App\Application\Reminder;

use App\Enums\ReminderWindow;
use App\Models\Property;
use App\Models\ReminderPreference;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Zugriff auf die Erinnerungseinstellungen.
 *
 * Eine Zeile mit property_id null gilt global fuer den Nutzer. Je Objekt kann
 * eine eigene Zeile bestehen. Fehlt eine Zeile, gilt der Standard: Erinnerungen
 * sind aktiv (Masterprompt 17.1).
 *
 * Der Abmeldetoken ist eine Zufallszeichenkette und keine ableitbare Kennung.
 * Er wird nur hier erzeugt und nie geloggt.
 */
class ReminderPreferences
{
    public const TOKEN_LAENGE = 64;

    public static function token(): string
    {
        return Str::random(self::TOKEN_LAENGE);
    }

    public function global(User $nutzer): ?ReminderPreference
    {
        /** @var ReminderPreference|null $eintrag */
        $eintrag = ReminderPreference::query()
            ->where('user_id', $nutzer->getKey())
            ->whereNull('property_id')
            ->first();

        return $eintrag;
    }

    public function fuerObjekt(User $nutzer, Property $objekt): ?ReminderPreference
    {
        /** @var ReminderPreference|null $eintrag */
        $eintrag = ReminderPreference::query()
            ->where('user_id', $nutzer->getKey())
            ->where('property_id', $objekt->getKey())
            ->first();

        return $eintrag;
    }

    /**
     * Objekteinstellung, bei Bedarf neu angelegt. Sie traegt den Abmeldetoken
     * des Erinnerungslinks.
     */
    public function objektEinstellung(User $nutzer, Property $objekt): ReminderPreference
    {
        $bestehend = $this->fuerObjekt($nutzer, $objekt);

        if ($bestehend instanceof ReminderPreference) {
            if (! is_string($bestehend->getAttribute('unsubscribe_token'))
                || $bestehend->getAttribute('unsubscribe_token') === '') {
                $bestehend->forceFill(['unsubscribe_token' => self::token()])->save();
            }

            return $bestehend;
        }

        /** @var ReminderPreference $neu */
        $neu = ReminderPreference::query()->create([
            'organization_id' => $objekt->getAttribute('organization_id'),
            'user_id' => $nutzer->getKey(),
            'property_id' => $objekt->getKey(),
            'is_active' => true,
            'q1_enabled' => true,
            'q2_enabled' => true,
            'q3_enabled' => true,
            'december_enabled' => true,
            'unsubscribe_token' => self::token(),
        ]);

        return $neu;
    }

    /**
     * Darf fuer dieses Fenster erinnert werden?
     *
     * Es zaehlt die globale UND die objektbezogene Einstellung. Eine fehlende
     * Zeile bedeutet aktiv.
     */
    public function darfErinnern(User $nutzer, Property $objekt, ReminderWindow $fenster): bool
    {
        $global = $this->global($nutzer);

        if ($global instanceof ReminderPreference && ! $global->isWindowEnabled($fenster)) {
            return false;
        }

        $objektwerte = $this->fuerObjekt($nutzer, $objekt);

        if ($objektwerte instanceof ReminderPreference && ! $objektwerte->isWindowEnabled($fenster)) {
            return false;
        }

        return true;
    }

    public function findeMitToken(string $token): ?ReminderPreference
    {
        if ($token === '') {
            return null;
        }

        /** @var ReminderPreference|null $eintrag */
        $eintrag = ReminderPreference::query()
            ->where('unsubscribe_token', $token)
            ->first();

        return $eintrag;
    }
}
