<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Application\Account\AuditRecorder;
use App\Application\Account\EmailVerification;
use App\Application\Account\OrganizationContext;
use App\Application\Account\TwoFactorPreparation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\AccountEmailRequest;
use App\Http\Requests\Portal\AccountRequest;
use App\Http\Requests\Portal\ReminderPreferenceRequest;
use App\Models\Property;
use App\Models\ReminderPreference;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Kontobereich.
 *
 * Vorgabe des Masterprompts, Abschnitt 8.2: dauerhaftes Nutzerkonto mit
 * Rechnungsanschrift, Erinnerungs- und Datenschutzoptionen.
 *
 * Die Aenderung der E-Mail-Adresse setzt die Bestaetigung zurueck und versendet
 * einen neuen Link. Bis zur Bestaetigung sind Zahlung und finaler Download
 * gesperrt. Die Aenderung erfordert zusaetzlich das aktuelle Passwort, damit
 * eine uebernommene Sitzung das Konto nicht uebernehmen kann.
 */
class AccountController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly EmailVerification $verification,
        private readonly TwoFactorPreparation $zweiFaktor,
        private readonly AuditRecorder $audit,
    ) {}

    public function edit(): View
    {
        $organisation = $this->context->organization();
        $this->authorize('view', $organisation);

        /** @var list<Property> $objekte */
        $objekte = $this->context->properties()->orderBy('label')->get()->all();

        return view('portal.konto.index', [
            'benutzer' => $this->context->user(),
            'organisation' => $organisation,
            'verifiziert' => $this->verification->isVerified($this->context->user()),
            'objekte' => $objekte,
            'global' => $this->globaleErinnerung(),
            'objektErinnerungen' => $this->objektErinnerungen(),
            'zweiFaktorStatus' => $this->zweiFaktor->statusLabel($this->context->user()),
        ]);
    }

    public function update(AccountRequest $request): RedirectResponse
    {
        $organisation = $this->context->organization();
        $this->authorize('update', $organisation);

        $benutzer = $this->context->user();

        DB::transaction(function () use ($request, $organisation, $benutzer): void {
            $benutzer->fill(['name' => (string) $request->string('name')])->save();

            $organisation->fill([
                'name' => (string) $request->string('organization_name'),
                'type' => (string) $request->string('organization_type'),
                'billing_name' => $request->input('billing_name'),
                'billing_address_line' => $request->input('billing_address_line'),
                'billing_address_extra' => $request->input('billing_address_extra'),
                'billing_postal_code' => $request->input('billing_postal_code'),
                'billing_city' => $request->input('billing_city'),
                'vat_id' => $request->input('vat_id'),
            ])->save();
        });

        $this->audit->record(
            action: 'account.updated',
            subject: $organisation,
            actor: $benutzer,
            organization: $organisation,
        );

        return redirect()
            ->route('portal.konto.edit')
            ->with('status', 'Ihre Angaben sind gespeichert.');
    }

    /**
     * Aenderung der E-Mail-Adresse mit erneuter Verifizierung.
     */
    public function updateEmail(AccountEmailRequest $request): RedirectResponse
    {
        $benutzer = $this->context->user();
        $neu = Str::lower((string) $request->string('email'));
        $alt = $benutzer->getAttribute('email');

        if (is_string($alt) && $alt === $neu) {
            return redirect()
                ->route('portal.konto.edit')
                ->with('status', 'Die E-Mail-Adresse ist unverändert.');
        }

        $benutzer->forceFill(['email' => $neu])->save();
        $this->verification->reset($benutzer);
        $this->verification->send($benutzer);

        $this->audit->record(
            action: 'account.email_changed',
            subject: $benutzer,
            actor: $benutzer,
            organization: $this->context->organizationId(),
        );

        return redirect()
            ->route('portal.konto.edit')
            ->with('status', 'Ihre neue E-Mail-Adresse ist gespeichert. Wir haben Ihnen einen '
                .'Bestätigungslink an die neue Adresse gesendet. Bis zur Bestätigung sind Zahlung und '
                .'Download gesperrt.');
    }

    /**
     * Erinnerungseinstellungen global und je Objekt.
     */
    public function updateReminders(ReminderPreferenceRequest $request): RedirectResponse
    {
        $organisation = $this->context->organization();
        $this->authorize('view', $organisation);

        $benutzer = $this->context->user();
        $objekte = $this->context->properties()->pluck('id')->all();

        /** @var array<string, mixed> $objektwerte */
        $objektwerte = is_array($request->input('objekte')) ? $request->input('objekte') : [];

        DB::transaction(function () use ($request, $benutzer, $objekte, $objektwerte): void {
            $global = $this->globaleErinnerung();

            $global->fill([
                'is_active' => $request->boolean('global_active'),
                'q1_enabled' => $request->boolean('q1_enabled'),
                'q2_enabled' => $request->boolean('q2_enabled'),
                'q3_enabled' => $request->boolean('q3_enabled'),
                'december_enabled' => $request->boolean('december_enabled'),
                'deactivated_at' => $request->boolean('global_active') ? null : now(),
                'reactivated_at' => $request->boolean('global_active') ? now() : null,
            ])->save();

            foreach ($objekte as $objektId) {
                if (! is_string($objektId)) {
                    continue;
                }

                $aktiv = (bool) ($objektwerte[$objektId] ?? false);

                /** @var ReminderPreference $eintrag */
                $eintrag = ReminderPreference::query()->firstOrNew([
                    'user_id' => $benutzer->getKey(),
                    'property_id' => $objektId,
                ]);

                $eintrag->fill([
                    'organization_id' => $this->context->organizationId(),
                    'user_id' => $benutzer->getKey(),
                    'property_id' => $objektId,
                    'is_active' => $aktiv,
                    'q1_enabled' => $aktiv,
                    'q2_enabled' => $aktiv,
                    'q3_enabled' => $aktiv,
                    'december_enabled' => $aktiv,
                    'deactivated_at' => $aktiv ? null : now(),
                ]);

                if ($eintrag->getAttribute('unsubscribe_token') === null) {
                    $eintrag->setAttribute('unsubscribe_token', Str::random(64));
                }

                $eintrag->save();
            }
        });

        $this->audit->record(
            action: 'account.reminders_updated',
            subject: $organisation,
            actor: $benutzer,
            organization: $organisation,
        );

        return redirect()
            ->route('portal.konto.edit')
            ->with('status', 'Ihre Erinnerungseinstellungen sind gespeichert.');
    }

    private function globaleErinnerung(): ReminderPreference
    {
        /** @var ReminderPreference|null $eintrag */
        $eintrag = ReminderPreference::query()
            ->where('user_id', $this->context->user()->getKey())
            ->whereNull('property_id')
            ->first();

        if ($eintrag instanceof ReminderPreference) {
            return $eintrag;
        }

        /** @var ReminderPreference $neu */
        $neu = ReminderPreference::query()->create([
            'organization_id' => $this->context->organizationId(),
            'user_id' => $this->context->user()->getKey(),
            'property_id' => null,
            'is_active' => true,
            'q1_enabled' => true,
            'q2_enabled' => true,
            'q3_enabled' => true,
            'december_enabled' => true,
            'unsubscribe_token' => Str::random(64),
        ]);

        return $neu;
    }

    /**
     * Erinnerungseinstellungen je Objekt, nach Objekt-ID.
     *
     * @return array<string, bool>
     */
    private function objektErinnerungen(): array
    {
        $ergebnis = [];

        foreach ($this->context->reminderPreferences()->whereNotNull('property_id')->get() as $eintrag) {
            $objektId = $eintrag->getAttribute('property_id');

            if (is_string($objektId)) {
                $ergebnis[$objektId] = (bool) $eintrag->getAttribute('is_active');
            }
        }

        return $ergebnis;
    }
}
