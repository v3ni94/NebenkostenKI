<?php

declare(strict_types=1);

namespace App\Application\Wizard;

use App\Application\Calculation\Dto\AssembledCalculationInput;
use App\Domain\Calculation\Result\CalculationRunResult;
use App\Domain\Calculation\Result\UnitStatementResult;
use App\Models\BillingRun;
use App\Models\HeatingStatement;
use App\Models\Landlord;
use App\Models\Tenancy;
use App\Services\Pdf\View\BankAccount;
use App\Services\Pdf\View\LandlordSender;
use App\Services\Pdf\View\OwnerOverviewView;
use App\Services\Pdf\View\PostalAddress;
use App\Services\Pdf\View\StatementSubject;
use App\Services\Pdf\View\TenantStatementView;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Baut die Ausgabeobjekte der PDF-Schicht aus dem Berechnungsergebnis.
 *
 * Diese Klasse rechnet nichts. Sie ergänzt ausschließlich die Stammdaten für
 * Absender, Empfänger und Objektangaben.
 *
 * Absender und inhaltlich Verantwortlicher ist der Vermieter beziehungsweise
 * Eigentümer, niemals automatisch die Hausverwaltung Müller GmbH
 * (Masterprompt 2.2). Fehlende Angaben werden nicht ergänzt, sondern
 * weggelassen.
 */
final class StatementViewFactory
{
    /**
     * @return list<TenantStatementView>
     */
    public function tenantViews(
        BillingRun $billingRun,
        CalculationRunResult $result,
        AssembledCalculationInput $assembled,
    ): array {
        $landlord = $this->landlord($billingRun);
        $sender = $this->sender($billingRun, $landlord);
        $subjectBase = $this->propertyAddressLine($billingRun);
        $tenancies = $this->tenancies($billingRun);
        $manualHeating = $this->manualHeatingStatement($billingRun);
        $views = [];

        foreach ($result->statements as $statement) {
            $tenancyId = $assembled->tenancyId($statement->occupancyKey);
            $tenancy = $tenancyId === null ? null : ($tenancies[$tenancyId] ?? null);

            $views[] = new TenantStatementView(
                $sender,
                $this->recipient($statement, $tenancy),
                new StatementSubject(
                    $billingRun->property->label,
                    $subjectBase,
                    $statement->unitLabel,
                    null,
                ),
                $statement,
                $this->today(),
                $assembled->heatingCategoryKeys,
                [],
                false,
                $landlord instanceof Landlord && $landlord->show_bank_details_on_statement,
                $manualHeating instanceof HeatingStatement,
            );
        }

        return $views;
    }

    /**
     * Vermieter des Laufs. Wurde er erst nach Anlage des Laufs am Objekt
     * erfasst, gilt der Vermieter des Objekts.
     */
    private function landlord(BillingRun $billingRun): ?Landlord
    {
        $landlord = $billingRun->landlord;

        if ($landlord instanceof Landlord) {
            return $landlord;
        }

        $landlord = $billingRun->property->landlord;

        return $landlord instanceof Landlord ? $landlord : null;
    }

    public function ownerOverviewView(
        BillingRun $billingRun,
        CalculationRunResult $result,
    ): OwnerOverviewView {
        $landlord = $this->landlord($billingRun);
        $manualHeating = $this->manualHeatingStatement($billingRun);
        $origin = $manualHeating?->getAttribute('calculation_origin');

        return new OwnerOverviewView(
            $result->ownerOverview,
            $this->today(),
            $landlord instanceof Landlord ? $this->landlordAddress($landlord) : null,
            $this->propertyAddressLine($billingRun),
            $result->findings,
            [],
            [],
            (string) $billingRun->getKey(),
            $manualHeating instanceof HeatingStatement,
            is_string($origin) && $origin !== '' ? $origin : null,
        );
    }

    /**
     * Manuell erfasste Heizkosten des Laufs (Fall B). Sie loesen die
     * sachlichen Vermerke im Mieter-PDF und im internen Blatt aus.
     */
    private function manualHeatingStatement(BillingRun $billingRun): ?HeatingStatement
    {
        $statement = HeatingStatement::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('manual_entry', true)
            ->orderBy('created_at')
            ->first();

        return $statement instanceof HeatingStatement ? $statement : null;
    }

    private function sender(BillingRun $billingRun, ?Landlord $landlord): LandlordSender
    {
        if (! $landlord instanceof Landlord) {
            // Auf dem regulaeren Weg nicht erreichbar: die Pruefregel
            // VERMIETER_FEHLT sperrt Vorschau und Finalisierung ohne
            // Vermieter. Der Zweig bleibt nur fuer Altbestaende, damit ein
            // bereits bezahlter Lauf weiterhin gerendert werden kann.
            return new LandlordSender(new PostalAddress($billingRun->property->label));
        }

        return new LandlordSender(
            $this->landlordAddress($landlord),
            $landlord->phone,
            $landlord->email,
            $this->bankAccount($landlord),
        );
    }

    /**
     * Anschrift des Vermieters. Bei einer Firma steht diese in der ersten
     * Zeile, der Name der Person in der zweiten; ein Adresszusatz folgt dort.
     */
    private function landlordAddress(Landlord $landlord): PostalAddress
    {
        $company = is_string($landlord->company_name) ? trim($landlord->company_name) : '';
        $extra = is_string($landlord->address_extra) ? trim($landlord->address_extra) : '';

        if ($company === '') {
            return new PostalAddress(
                $landlord->sender_name,
                $extra === '' ? null : $extra,
                $landlord->address_line,
                $landlord->postal_code,
                $landlord->city,
                $landlord->country === 'DE' ? null : $landlord->country,
            );
        }

        $secondLine = $extra === '' ? $landlord->sender_name : $landlord->sender_name.', '.$extra;

        return new PostalAddress(
            $company,
            $secondLine,
            $landlord->address_line,
            $landlord->postal_code,
            $landlord->city,
            $landlord->country === 'DE' ? null : $landlord->country,
        );
    }

    private function bankAccount(Landlord $landlord): ?BankAccount
    {
        if (! $landlord->show_bank_details_on_statement) {
            return null;
        }

        if (! is_string($landlord->iban) || trim($landlord->iban) === '') {
            return null;
        }

        return new BankAccount(
            $landlord->account_holder ?? $landlord->sender_name,
            $landlord->iban,
            $landlord->bic,
        );
    }

    private function recipient(UnitStatementResult $statement, ?Tenancy $tenancy): PostalAddress
    {
        if (! $tenancy instanceof Tenancy) {
            return new PostalAddress($statement->tenantLabel);
        }

        return new PostalAddress(
            $tenancy->tenant_display_name,
            $tenancy->delivery_address_extra,
            $tenancy->delivery_address_line,
            $tenancy->delivery_postal_code,
            $tenancy->delivery_city,
            $tenancy->delivery_country === 'DE' ? null : $tenancy->delivery_country,
        );
    }

    private function propertyAddressLine(BillingRun $billingRun): string
    {
        $property = $billingRun->property;

        return trim(sprintf(
            '%s, %s %s',
            $property->address_line,
            $property->postal_code,
            $property->city
        ));
    }

    /**
     * @return array<string, Tenancy>
     */
    private function tenancies(BillingRun $billingRun): array
    {
        $billingRun->loadMissing('property.units.tenancies');
        $map = [];

        foreach ($billingRun->property->units as $unit) {
            foreach ($unit->tenancies as $tenancy) {
                $map[(string) $tenancy->getKey()] = $tenancy;
            }
        }

        return $map;
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    }
}
