<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Enums\ValidationSeverity;
use App\Rules\Engine\AbstractRule;
use App\Rules\Engine\Rule;
use App\Rules\Engine\RuleRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Metatest ueber alle Regeln.
 *
 * Geprueft werden Eindeutigkeit der Codes, vollstaendige deutsche Texte,
 * das Verbot von Gedankenstrichen und das Verbot von Garantieaussagen
 * beziehungsweise Einzelfall-Rechtsberatung.
 */
final class RuleMetadataTest extends TestCase
{
    /**
     * Zeichen und Wortfolgen, die in Nutzertexten nicht vorkommen duerfen.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_DASHES = ['–', '—', ' - ', '--'];

    /**
     * @var list<string>
     */
    private const array FORBIDDEN_CLAIMS = [
        'garantiert',
        'garantie',
        'rechtssicher',
        'wir beraten',
        'rechtsverbindlich',
        'auf jeden fall',
        'in jedem fall zulässig',
        'zweifelsfrei',
    ];

    #[Test]
    public function das_verzeichnis_enthaelt_alle_regelklassen(): void
    {
        $classes = glob(__DIR__.'/../../../app/Rules/Definitions/*.php');
        $this->assertIsArray($classes);

        $this->assertCount(count($classes), RuleRegistry::all());
    }

    #[Test]
    public function die_regelcodes_sind_eindeutig(): void
    {
        $codes = array_map(static fn (Rule $rule): string => $rule->code(), RuleRegistry::all());

        $this->assertSame($codes, array_values(array_unique($codes)));
        $this->assertNotContains('', $codes);
    }

    #[Test]
    public function jede_regel_hat_code_version_titel_und_referenz(): void
    {
        foreach (RuleRegistry::all() as $rule) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9_]+$/', $rule->code());
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $rule->version());
            $this->assertNotSame('', trim($rule->title()));
            $this->assertNotSame('', trim($rule->reference()));
        }
    }

    #[Test]
    public function jede_regel_hat_eine_nicht_leere_deutsche_beschreibung(): void
    {
        foreach (RuleRegistry::all() as $rule) {
            $this->assertGreaterThan(
                30,
                mb_strlen(trim($rule->description())),
                sprintf('Die Regel %s braucht eine aussagekräftige Beschreibung.', $rule->code())
            );
            $this->assertGreaterThan(
                20,
                mb_strlen(trim($rule->passedDescription())),
                sprintf('Die Regel %s braucht einen Text für den bestandenen Prüfschritt.', $rule->code())
            );
        }
    }

    #[Test]
    public function kein_regeltext_enthaelt_einen_gedankenstrich(): void
    {
        foreach (RuleRegistry::all() as $rule) {
            foreach ($this->texts($rule) as $label => $text) {
                foreach (self::FORBIDDEN_DASHES as $dash) {
                    $this->assertStringNotContainsString(
                        $dash,
                        $text,
                        sprintf('Regel %s, %s: Gedankenstriche sind in deutschen Texten unzulässig.', $rule->code(), $label)
                    );
                }
            }
        }
    }

    #[Test]
    public function kein_regeltext_enthaelt_eine_garantieaussage(): void
    {
        foreach (RuleRegistry::all() as $rule) {
            foreach ($this->texts($rule) as $label => $text) {
                foreach (self::FORBIDDEN_CLAIMS as $claim) {
                    $this->assertStringNotContainsStringIgnoringCase(
                        $claim,
                        $text,
                        sprintf('Regel %s, %s: Garantieaussagen sind unzulässig.', $rule->code(), $label)
                    );
                }
            }
        }
    }

    #[Test]
    public function jede_regel_hat_eine_severity_und_einen_gueltigkeitsbeginn(): void
    {
        foreach (RuleRegistry::all() as $rule) {
            $this->assertContains($rule->severity(), [
                ValidationSeverity::BLOCKER,
                ValidationSeverity::WARNUNG,
                ValidationSeverity::HINWEIS,
            ]);
            $this->assertGreaterThanOrEqual(
                AbstractRule::EARLIEST_VALID_FROM,
                $rule->validFrom()->format('Y-m-d')
            );
        }
    }

    #[Test]
    public function eine_regel_ist_vor_ihrem_gueltigkeitsbeginn_nicht_wirksam(): void
    {
        $rule = RuleRegistry::find('HEIZKOSTEN_CO2_STATUS');

        $this->assertInstanceOf(Rule::class, $rule);
        $this->assertFalse($rule->isEffectiveOn(new \DateTimeImmutable('2022-12-31')));
        $this->assertTrue($rule->isEffectiveOn(new \DateTimeImmutable('2023-01-01')));
    }

    #[Test]
    public function nur_regeln_mit_besonderem_schutzzweck_sind_nicht_wegklickbar(): void
    {
        $notResolvable = [];

        foreach (RuleRegistry::all() as $rule) {
            if (! $rule->isUserResolvable()) {
                $notResolvable[] = $rule->code();
            }
        }

        $this->assertSame(['HEIZKOSTEN_FALL_B_UNVOLLSTAENDIG'], $notResolvable);
    }

    /**
     * @return array<string, string>
     */
    private function texts(Rule $rule): array
    {
        return [
            'Titel' => $rule->title(),
            'Beschreibung' => $rule->description(),
            'Referenz' => $rule->reference(),
            'Bestanden' => $rule->passedDescription(),
        ];
    }
}
