<?php

declare(strict_types=1);

namespace App\Services\Pdf\View;

use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Calculation\Result\StatementLine;
use App\Domain\Calculation\Result\UnitStatementResult;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use DateTimeImmutable;

/**
 * Alle Angaben einer Mieterabrechnung für die Ausgabe (Abschnitt 14.1, 11.1).
 *
 * Diese Klasse rechnet NICHTS. Sie gruppiert ausschließlich das bereits
 * berechnete UnitStatementResult und die zugehörigen Stammdaten für die
 * Darstellung. Alle Beträge stammen unverändert aus dem Ergebnisobjekt.
 */
final readonly class TenantStatementView
{
    /**
     * @param  list<string>  $heatingCategoryKeys  Kategorien, die im Heizkostenblock ausgewiesen werden
     * @param  list<VoucherEntry>  $vouchers  nur strukturierte Extraktionsdaten
     */
    public function __construct(
        public LandlordSender $sender,
        public PostalAddress $recipient,
        public StatementSubject $subject,
        public UnitStatementResult $result,
        public DateTimeImmutable $statementDate,
        public array $heatingCategoryKeys = [],
        public array $vouchers = [],
        public bool $showVoucherIndex = false,
        public bool $showBankAccount = true,
    ) {}

    /**
     * Betreff nach Abschnitt 14.1.
     */
    public function subjectLine(): string
    {
        return 'Betriebskostenabrechnung '.$this->result->billingPeriod->format();
    }

    /**
     * Kostenzeilen ohne Heizkosten.
     *
     * @return list<StatementLine>
     */
    public function regularLines(): array
    {
        return array_values(array_filter(
            $this->result->lines,
            fn (StatementLine $line): bool => ! in_array($line->categoryKey, $this->heatingCategoryKeys, true)
        ));
    }

    /**
     * Kostenzeilen des Heizkostenblocks.
     *
     * @return list<StatementLine>
     */
    public function heatingLines(): array
    {
        return array_values(array_filter(
            $this->result->lines,
            fn (StatementLine $line): bool => in_array($line->categoryKey, $this->heatingCategoryKeys, true)
        ));
    }

    public function hasHeatingBlock(): bool
    {
        return $this->heatingLines() !== [];
    }

    /**
     * Zwischensumme der Kostenzeilen ohne Heizkosten.
     */
    public function subtotalWithoutHeating(): Money
    {
        return $this->sumShares($this->regularLines());
    }

    public function heatingSubtotal(): Money
    {
        return $this->sumShares($this->heatingLines());
    }

    /**
     * Zeilen mit ausdrücklich bestätigter Ersatzverteilung. Sie werden im PDF
     * gekennzeichnet, weil keine Zwischenablesung vorliegt.
     *
     * @return list<StatementLine>
     */
    public function substituteDistributionLines(): array
    {
        return array_values(array_filter(
            $this->result->lines,
            static fn (StatementLine $line): bool => $line->substituteDistributionConfirmed
        ));
    }

    public function hasSubstituteDistribution(): bool
    {
        return $this->substituteDistributionLines() !== [];
    }

    /**
     * Kennzeichnungen und Annahmen aus dem Ergebnisobjekt. Sie werden
     * unverändert gedruckt.
     *
     * @return list<string>
     */
    public function notices(): array
    {
        $notices = $this->result->assumptions;

        if ($this->result->prepaymentAssumedFromTarget) {
            $notices[] = 'Es wurden die vereinbarten Sollvorauszahlungen angesetzt, weil keine Ist-Zahlungen vorlagen. Diese Übernahme wurde ausdrücklich bestätigt.';
        }

        foreach ($this->substituteDistributionLines() as $line) {
            $notices[] = sprintf(
                'Für die Kostenart %s liegt keine Zwischenablesung vor. Es wurde eine ausdrücklich bestätigte Ersatzverteilung angewendet.',
                $line->categoryLabel
            );
        }

        return array_values(array_unique($notices));
    }

    /**
     * Prüfhinweise, die im Mieter-PDF ausgewiesen werden.
     *
     * @return list<CheckFinding>
     */
    public function findings(): array
    {
        return $this->result->findings;
    }

    /**
     * Verteilerschlüssel für die Anlage "Erläuterung der Verteilerschlüssel".
     *
     * @return list<array{label: string, explanation: string, categories: list<string>}>
     */
    public function allocationKeyExplanations(): array
    {
        /** @var array<string, array{label: string, explanation: string, categories: list<string>}> $grouped */
        $grouped = [];

        foreach ($this->result->lines as $line) {
            $key = $line->allocationKeyLabel;

            if (! array_key_exists($key, $grouped)) {
                $grouped[$key] = [
                    'label' => $line->allocationKeyLabel,
                    'explanation' => $line->allocationExplanation,
                    'categories' => [],
                ];
            }

            if (! in_array($line->categoryLabel, $grouped[$key]['categories'], true)) {
                $grouped[$key]['categories'][] = $line->categoryLabel;
            }
        }

        return array_values($grouped);
    }

    /**
     * @return list<StatementLine>
     */
    public function taxBenefitLines(TaxBenefitCategory $category): array
    {
        return $this->result->linesWithTaxBenefit($category);
    }

    public function hasTaxBenefitContent(): bool
    {
        return $this->taxBenefitLines(TaxBenefitCategory::HOUSEHOLD_SERVICE) !== []
            || $this->taxBenefitLines(TaxBenefitCategory::CRAFTSMAN_SERVICE) !== [];
    }

    public function bankAccount(): ?BankAccount
    {
        return $this->showBankAccount ? $this->sender->bankAccount : null;
    }

    /**
     * @param  list<StatementLine>  $lines
     */
    private function sumShares(array $lines): Money
    {
        $total = Money::zero();

        foreach ($lines as $line) {
            $total = $total->plus($line->share);
        }

        return $total;
    }
}
