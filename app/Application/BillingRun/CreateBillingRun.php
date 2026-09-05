<?php

declare(strict_types=1);

namespace App\Application\BillingRun;

use App\Application\Account\AuditRecorder;
use App\Enums\BillingMode;
use App\Enums\BillingRunStatus;
use App\Enums\PropertyKind;
use App\Enums\TenancyKind;
use App\Models\BillingRun;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Anlage eines Abrechnungslaufs.
 *
 * Vorgabe des Masterprompts, Schritt 1: Abrechnungszeitraum mit Standard
 * 01.01. bis 31.12. des Vorjahres, unterjaehrig zulaessig, Auswahl oder
 * automatische Empfehlung von Schnellabrechnung und Vollobjektabrechnung.
 *
 * Der Lauf entsteht im Status DRAFT. Von dort fuehrt der gefuehrte Ablauf
 * weiter, jeder Schritt speichert und ist jederzeit unterbrechbar.
 */
class CreateBillingRun
{
    public const AUDIT_ACTION = 'billing_run.created';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Vorgeschlagener Abrechnungszeitraum: das komplette Vorjahr.
     *
     * @return array{start: string, end: string, jahr: int}
     */
    public static function defaultPeriod(?Carbon $heute = null): array
    {
        $jahr = (int) ($heute ?? Carbon::now())->format('Y') - 1;

        return [
            'start' => sprintf('%04d-01-01', $jahr),
            'end' => sprintf('%04d-12-31', $jahr),
            'jahr' => $jahr,
        ];
    }

    /**
     * Automatische Wegerkennung.
     *
     * Vorgabe des Masterprompts, Abschnitt 5.3: Das System darf den
     * wahrscheinlich passenden Weg vorschlagen, der Nutzer kann wechseln. Eine
     * Eigentumswohnung mit genau einer Einheit spricht fuer die
     * Schnellabrechnung, alles andere fuer die vollstaendige Objektabrechnung.
     */
    public static function suggestMode(Property $property): BillingMode
    {
        $einheiten = $property->units()->count();
        $art = $property->getAttribute('kind');

        if ($art === PropertyKind::EIGENTUMSWOHNUNG && $einheiten <= 1) {
            return BillingMode::QUICK_CONDO;
        }

        return BillingMode::FULL_PROPERTY;
    }

    /**
     * Hinweis auf gewerbliche Mietverhaeltnisse im Objekt.
     *
     * Vorgabe des Masterprompts und ARCHITECTURE.md: Gewerbemietverhaeltnisse
     * sind im Datenmodell vorbereitet, werden aber nicht automatisch
     * finalisiert. Der Hinweis ist ausdruecklich und darf nicht unterdrueckt
     * werden.
     */
    public static function commercialHint(Property $property): ?string
    {
        $anzahl = $property->tenancies()->where('kind', TenancyKind::GEWERBE->value)->count();

        if ($anzahl === 0) {
            return null;
        }

        return sprintf(
            'In diesem Objekt %s %d gewerbliches Mietverhältnis%s hinterlegt. Gewerbliche Mietverhältnisse '
            .'werden nicht automatisch finalisiert. Bitte prüfen Sie die Umlagevereinbarung und die '
            .'umsatzsteuerliche Behandlung gesondert.',
            $anzahl === 1 ? 'ist' : 'sind',
            $anzahl,
            $anzahl === 1 ? '' : 'se'
        );
    }

    public function handle(
        Property $property,
        User $actor,
        string $periodStart,
        string $periodEnd,
        BillingMode $mode,
    ): BillingRun {
        /** @var BillingRun $lauf */
        $lauf = DB::transaction(function () use ($property, $actor, $periodStart, $periodEnd, $mode): BillingRun {
            // Folgejahresuebernahme: der letzte finalisierte Lauf desselben
            // Objekts wird als Vorjahresreferenz vermerkt. Vorjahreswerte
            // dienen ausschliesslich dem Vergleich und niemals als neue Kosten
            // (Masterprompt 8.3).
            $vorjahr = $property->billingRuns()
                ->where('status', BillingRunStatus::FINALIZED->value)
                ->orderByDesc('period_end')
                ->first();

            /** @var BillingRun $neu */
            $neu = BillingRun::query()->create([
                'organization_id' => $property->getAttribute('organization_id'),
                'created_by_user_id' => $actor->getKey(),
                'property_id' => $property->getKey(),
                'landlord_id' => $property->getAttribute('landlord_id'),
                'previous_billing_run_id' => $vorjahr?->getKey(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'billing_year' => (int) substr($periodStart, 0, 4),
                'mode' => $mode,
                'status' => BillingRunStatus::DRAFT,
                'wizard_step' => 1,
            ]);

            return $neu;
        });

        $organizationId = $property->getAttribute('organization_id');

        $this->audit->record(
            action: self::AUDIT_ACTION,
            subject: $lauf,
            actor: $actor,
            organization: is_string($organizationId) ? $organizationId : null,
            metadata: [
                'weg' => $mode->value,
                'zeitraum_start' => $periodStart,
                'zeitraum_ende' => $periodEnd,
            ],
        );

        return $lauf;
    }
}
