<?php

declare(strict_types=1);

namespace App\Application\Wizard;

use App\Application\Calculation\Dto\AssembledCalculationInput;
use App\Domain\Calculation\Result\CalculationRunResult;
use App\Domain\Calculation\Result\UnitStatementResult;
use App\Models\BillingRun;
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
        $sender = $this->sender($billingRun);
        $subjectBase = $this->propertyAddressLine($billingRun);
        $tenancies = $this->tenancies($billingRun);
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
                $billingRun->landlord->show_bank_details_on_statement ?? false,
            );
        }

        return $views;
    }

    public function ownerOverviewView(
        BillingRun $billingRun,
        CalculationRunResult $result,
    ): OwnerOverviewView {
        $landlord = $billingRun->landlord;

        return new OwnerOverviewView(
            $result->ownerOverview,
            $this->today(),
            $landlord instanceof Landlord ? $this->landlordAddress($landlord) : null,
            $this->propertyAddressLine($billingRun),
            $result->findings,
            [],
            [],
            (string) $billingRun->getKey(),
        );
    }

    private function sender(BillingRun $billingRun): LandlordSender
    {
        $landlord = $billingRun->landlord;

        if (! $landlord instanceof Landlord) {
            return new LandlordSender(new PostalAddress($billingRun->property->label));
        }

        return new LandlordSender(
            $this->landlordAddress($landlord),
            $landlord->phone,
            $landlord->email,
            $this->bankAccount($landlord),
        );
    }

    private function landlordAddress(Landlord $landlord): PostalAddress
    {
        return new PostalAddress(
            $landlord->sender_name,
            $landlord->address_extra,
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
