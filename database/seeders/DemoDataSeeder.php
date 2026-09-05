<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\BillingRun\BillingRunProgress;
use App\Application\Wizard\PreviewBuilder;
use App\Enums\AdminRole;
use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\ApportionmentStatus;
use App\Enums\BillingMode;
use App\Enums\CostItemSource;
use App\Enums\CostItemStatus;
use App\Enums\DocumentRelationType;
use App\Enums\DocumentType;
use App\Enums\ExtractedFieldStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Enums\Paragraph35aType;
use App\Enums\PrepaymentKind;
use App\Enums\PropertyKind;
use App\Enums\TenancyKind;
use App\Enums\TenancyStatus;
use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Enums\ValueSource;
use App\Models\AdminRoleAssignment;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\DocumentRelation;
use App\Models\ExtractedField;
use App\Models\Landlord;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Prepayment;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\TenancyPerson;
use App\Models\Unit;
use App\Models\User;
use App\Models\VacancyPeriod;
use App\Models\ValidationIssue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

/**
 * Demodaten fuer den manuellen Browsertest.
 *
 * ANLEITUNG: docs/testanleitung.md
 *
 * VERBINDLICHE REGELN
 *
 *  1. NUR AUF AUSDRUECKLICHEN AUFRUF. Der DatabaseSeeder zieht diesen Seeder
 *     nicht mit. Aufruf ausschliesslich ueber
 *     php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder
 *  2. NIEMALS PRODUKTIV. In der Umgebung production bricht der Seeder mit einer
 *     klaren Meldung ab, bevor irgendetwas geschrieben wird.
 *  3. ALLE DATEN FREI ERFUNDEN. Keine Bestandsdaten, keine echten Personen,
 *     keine echten Anschriften, keine echten IBAN.
 *  4. KEINE ORIGINALDATEIEN UND KEINE TEMPORAEREN UPLOADS. Die Demodaten
 *     setzen ausschliesslich die strukturierten Extraktionsdaten, so wie sie
 *     nach der Auswertung dauerhaft verbleiben. Das entspricht dem
 *     Loeschkonzept und zeigt dem Tester den echten Zustand.
 *  5. KEIN STATUS AUS DER HAND. Der Zustand der Abrechnungslaeufe entsteht
 *     ausschliesslich ueber BillingRunProgress und damit ueber die
 *     Statusmaschine. Es wird kein Status direkt geschrieben.
 *  6. IDEMPOTENZ DURCH ABBRUCH. Sind die Demokonten bereits vorhanden, bricht
 *     der Seeder mit einer klaren Meldung ab und erzeugt keine Dubletten.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Passwort aller Demokonten. Bewusst im Klartext dokumentiert, weil es
     * ausschliesslich fuer Testkonten in einer Testumgebung gilt.
     */
    public const string PASSWORT = 'demo-passwort-2026';

    public const string EMAIL_KUNDE = 'demo@smart-abrechnen.test';

    public const string EMAIL_ADMIN = 'demo-admin@smart-abrechnen.test';

    public const string EMAIL_ZWEITKUNDE = 'demo-zweitkunde@smart-abrechnen.test';

    /**
     * Beispiel-IBAN aus der oeffentlichen Dokumentation, kein echtes Konto.
     */
    private const string BEISPIEL_IBAN = 'DE02120300000000202051';

    private const string BEISPIEL_BIC = 'BYLADEM1001';

    /**
     * Zeilen der Abschlussuebersicht.
     *
     * @var list<string>
     */
    private array $uebersicht = [];

    public function run(): void
    {
        $this->pruefeUmgebung();
        $this->pruefeBestand();

        $kunde = $this->kundenkonto(
            self::EMAIL_KUNDE,
            'Katrin Demovogel',
            'Demovogel Hausbesitz',
            'Lindenhofweg 12',
            '40699',
            'Beispielstadt',
        );

        $admin = $this->adminkonto();

        $zweitkunde = $this->kundenkonto(
            self::EMAIL_ZWEITKUNDE,
            'Robert Zweitmann',
            'Zweitmann Immobilien',
            'Ahornallee 5',
            '41460',
            'Anderstadt',
        );

        $wegA = $this->wegAObjekt($kunde['organization']);
        $wegB = $this->wegBObjekt($kunde['organization']);
        $fremd = $this->zweitmandant($zweitkunde['organization'], $zweitkunde['user']);

        $entwurf = $this->laufEntwurf($kunde['organization'], $kunde['user'], $wegA['property']);
        $pruefung = $this->laufMitPruefaufgaben($kunde['organization'], $kunde['user'], $wegA);
        $vorschau = $this->laufBisVorschau($kunde['organization'], $kunde['user'], $wegB);

        $this->zeile('');
        $this->zeile('=== Demodaten angelegt ===================================');
        $this->zeile('');
        $this->zeile('KONTEN (Passwort jeweils: '.self::PASSWORT.')');
        $this->zeile('  Kunde          '.self::EMAIL_KUNDE.'   E-Mail bestaetigt');
        $this->zeile('  Adminrolle     '.self::EMAIL_ADMIN.'   Rolle ADMIN, E-Mail bestaetigt');
        $this->zeile('  Zweiter Kunde  '.self::EMAIL_ZWEITKUNDE.'   eigener Mandant');
        $this->zeile('');
        $this->zeile('OBJEKTE');
        $this->zeile('  Weg A  '.$wegA['property']->label.'  Eigentumswohnung, 1 Einheit, 1 Mietverhaeltnis');
        $this->zeile('  Weg B  '.$wegB['property']->label.'  Mehrfamilienhaus, 6 Einheiten, Mieterwechsel 30.06./01.07., Leerstand August');
        $this->zeile('  Fremd  '.$fremd->label.'  gehoert dem zweiten Kunden, dient der Pruefung der Mandantentrennung');
        $this->zeile('');
        $this->zeile('ABRECHNUNGSLAEUFE');
        $this->laufzeile('Entwurf, Einstieg beim Upload', $entwurf);
        $this->laufzeile('Offene Pruefaufgaben, Dublette und nicht umlagefaehige Position', $pruefung);
        $this->laufzeile('Bis zur Vorschau fortgeschritten', $vorschau);
        $this->zeile('');
        $this->zeile('URLS UNTER /app');
        $this->zeile('  Anmeldung        /login');
        $this->zeile('  Uebersicht       /app');
        $this->zeile('  Objekte          /app/objekte');
        $this->zeile('  Einheiten Weg B  /app/objekte/'.$wegB['property']->getKey().'/einheiten');
        $this->zeile('  Abrechnungen     /app/abrechnungen');
        $this->zeile('  Upload           /app/abrechnungen/'.$entwurf->getKey().'/upload');
        $this->zeile('  Kostenpruefung   /app/abrechnungen/'.$pruefung->getKey().'/kostenpruefung');
        $this->zeile('  Vorauszahlungen  /app/abrechnungen/'.$vorschau->getKey().'/vorauszahlungen');
        $this->zeile('  Schluessel       /app/abrechnungen/'.$vorschau->getKey().'/verteilerschluessel');
        $this->zeile('  Pruefbericht     /app/abrechnungen/'.$vorschau->getKey().'/pruefbericht');
        $this->zeile('  Vorschau         /app/abrechnungen/'.$vorschau->getKey().'/vorschau');
        $this->zeile('  Datenschutz      /app/datenschutz');
        $this->zeile('  Adminbereich     /admin  (Konto '.self::EMAIL_ADMIN.')');
        $this->zeile('  Fremdes Objekt   /app/objekte/'.$fremd->getKey().'/einheiten  (mit dem Kundenkonto: 403 oder 404 erwartet)');
        $this->zeile('');
        $this->zeile('Adminkonto-Kennung: '.$admin->getKey());
        $this->zeile('==========================================================');

        $this->ausgeben();
    }

    // -----------------------------------------------------------------
    // Sicherungen
    // -----------------------------------------------------------------

    private function pruefeUmgebung(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'Der Demodaten-Seeder ist in der Umgebung production gesperrt. '
                .'Er erzeugt Testkonten mit bekanntem Passwort und frei erfundene Kundendaten. '
                .'Setzen Sie APP_ENV auf local oder testing und wiederholen Sie den Aufruf.'
            );
        }
    }

    private function pruefeBestand(): void
    {
        $vorhanden = User::query()
            ->whereIn('email', [self::EMAIL_KUNDE, self::EMAIL_ADMIN, self::EMAIL_ZWEITKUNDE])
            ->exists();

        if ($vorhanden) {
            throw new RuntimeException(
                'Die Demodaten sind bereits vorhanden. Der Seeder legt sie nicht doppelt an. '
                .'Setzen Sie die Datenbank zuerst zurueck: '
                .'php artisan migrate:fresh --seed und danach erneut '
                .'php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder'
            );
        }

        if (CostCategory::query()->doesntExist()) {
            throw new RuntimeException(
                'Es sind keine Kostenarten vorhanden. Bitte zuerst den Grundstock laden: '
                .'php artisan db:seed --class=Database\\Seeders\\CostCategorySeeder'
            );
        }
    }

    // -----------------------------------------------------------------
    // Konten
    // -----------------------------------------------------------------

    /**
     * @return array{user: User, organization: Organization, landlord: Landlord}
     */
    private function kundenkonto(
        string $email,
        string $name,
        string $organisation,
        string $strasse,
        string $plz,
        string $ort,
    ): array {
        /** @var User $user */
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(self::PASSWORT),
        ]);

        /** @var Organization $organization */
        $organization = Organization::factory()->create([
            'name' => $organisation,
            'type' => OrganizationType::PRIVATPERSON,
            'billing_address_line' => $strasse,
            'billing_postal_code' => $plz,
            'billing_city' => $ort,
            'contact_email' => $email,
        ]);

        OrganizationUser::factory()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => OrganizationRole::OWNER,
        ]);

        /** @var Landlord $landlord */
        $landlord = Landlord::factory()->create([
            'organization_id' => $organization->getKey(),
            'sender_name' => $name,
            'address_line' => $strasse,
            'postal_code' => $plz,
            'city' => $ort,
            'email' => $email,
            'iban' => self::BEISPIEL_IBAN,
            'bic' => self::BEISPIEL_BIC,
            'account_holder' => $name,
        ]);

        return ['user' => $user, 'organization' => $organization, 'landlord' => $landlord];
    }

    private function adminkonto(): User
    {
        $konto = $this->kundenkonto(
            self::EMAIL_ADMIN,
            'Silke Demoprueferin',
            'Demoverwaltung Betrieb',
            'Rheinstrasse 3',
            '40789',
            'Beispielstadt',
        );

        AdminRoleAssignment::factory()->create([
            'user_id' => $konto['user']->getKey(),
            'role' => AdminRole::ADMIN,
            'reason' => 'Demodaten fuer den manuellen Browsertest',
        ]);

        return $konto['user'];
    }

    // -----------------------------------------------------------------
    // Weg A: Eigentumswohnung
    // -----------------------------------------------------------------

    /**
     * @return array{property: Property, unit: Unit, tenancy: Tenancy}
     */
    private function wegAObjekt(Organization $organization): array
    {
        /** @var Property $property */
        $property = Property::factory()->create([
            'organization_id' => $organization->getKey(),
            'landlord_id' => Landlord::query()->where('organization_id', $organization->getKey())->value('id'),
            'label' => 'Lindenhofweg 12, Wohnung 7',
            'address_line' => 'Lindenhofweg 12',
            'postal_code' => '40699',
            'city' => 'Beispielstadt',
            'kind' => PropertyKind::EIGENTUMSWOHNUNG,
            'total_living_area_sqm' => '74.3000',
            'total_heated_area_sqm' => '74.3000',
            'mea_denominator' => '1000.000000',
        ]);

        /** @var Unit $unit */
        $unit = Unit::factory()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
            'label' => 'Wohnung 7',
            'location' => '2. OG rechts',
            'unit_number' => '7',
            'living_area_sqm' => '74.3000',
            'heated_area_sqm' => '74.3000',
            'mea' => '86.500000',
            'room_count' => 3,
            'individual_key_1_value' => '1.0000',
        ]);

        /** @var Tenancy $tenancy */
        $tenancy = Tenancy::factory()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
            'unit_id' => $unit->getKey(),
            'tenant_display_name' => 'Eheleute Sandmann',
            'kind' => TenancyKind::WOHNRAUM,
            'status' => TenancyStatus::AKTIV,
            'delivery_address_line' => 'Lindenhofweg 12',
            'delivery_postal_code' => '40699',
            'delivery_city' => 'Beispielstadt',
            'starts_on' => '2023-04-01',
            'ends_on' => null,
            'monthly_operating_prepayment_cent' => 14500,
            'monthly_heating_prepayment_cent' => 9500,
        ]);

        TenancyPerson::factory()->create([
            'organization_id' => $organization->getKey(),
            'tenancy_id' => $tenancy->getKey(),
            'salutation' => 'Frau',
            'first_name' => 'Anke',
            'last_name' => 'Sandmann',
            'email' => 'anke.sandmann@beispiel.invalid',
        ]);

        return ['property' => $property, 'unit' => $unit, 'tenancy' => $tenancy];
    }

    // -----------------------------------------------------------------
    // Weg B: Mehrfamilienhaus
    // -----------------------------------------------------------------

    /**
     * @return array{property: Property, units: list<Unit>, tenancies: list<Tenancy>}
     */
    private function wegBObjekt(Organization $organization): array
    {
        /** @var Property $property */
        $property = Property::factory()->create([
            'organization_id' => $organization->getKey(),
            'landlord_id' => Landlord::query()->where('organization_id', $organization->getKey())->value('id'),
            'label' => 'Buchenstrasse 40',
            'address_line' => 'Buchenstrasse 40',
            'postal_code' => '40699',
            'city' => 'Beispielstadt',
            'kind' => PropertyKind::MEHRFAMILIENHAUS,
            'total_living_area_sqm' => '432.0000',
            'total_heated_area_sqm' => '432.0000',
            'mea_denominator' => '1000.000000',
            'individual_key_1_label' => 'Stellplaetze',
        ]);

        $flaechen = ['62.0000', '68.0000', '74.0000', '74.0000', '80.0000', '74.0000'];
        $lagen = ['EG links', 'EG rechts', '1. OG links', '1. OG rechts', '2. OG links', '2. OG rechts'];

        $units = [];

        foreach ($flaechen as $index => $flaeche) {
            /** @var Unit $unit */
            $unit = Unit::factory()->create([
                'organization_id' => $organization->getKey(),
                'property_id' => $property->getKey(),
                'label' => 'Wohnung '.($index + 1),
                'location' => $lagen[$index],
                'unit_number' => (string) ($index + 1),
                'living_area_sqm' => $flaeche,
                'heated_area_sqm' => $flaeche,
                'mea' => number_format((float) $flaeche / 432 * 1000, 6, '.', ''),
                'room_count' => 3,
                'individual_key_1_value' => '1.0000',
            ]);

            $units[] = $unit;
        }

        $mieter = [
            ['Familie Ostermann', '2019-05-01', null, 12500, 8000],
            ['Herr Vollrath', '2021-09-01', null, 13500, 8500],
            ['Frau Kleinschmidt', '2020-02-01', '2025-06-30', 14500, 9000],
            ['Familie Brendel', '2018-11-01', null, 14500, 9000],
            ['Herr und Frau Lindqvist', '2022-07-01', null, 16000, 10000],
            ['Frau Hagedorn', '2017-03-01', '2025-07-31', 14500, 9000],
        ];

        $tenancies = [];

        foreach ($mieter as $index => $daten) {
            $tenancies[] = $this->mietverhaeltnis(
                $organization,
                $property,
                $units[$index],
                $daten[0],
                $daten[1],
                $daten[2],
                $daten[3],
                $daten[4],
            );
        }

        // Mieterwechsel zum 30.06./01.07.2025 in Wohnung 3.
        $tenancies[] = $this->mietverhaeltnis(
            $organization,
            $property,
            $units[2],
            'Herr Petzold',
            '2025-07-01',
            null,
            15000,
            9500,
        );

        // Leerstandsmonat August 2025 in Wohnung 6, danach neue Mietpartei.
        VacancyPeriod::factory()->create([
            'organization_id' => $organization->getKey(),
            'unit_id' => $units[5]->getKey(),
            'starts_on' => '2025-08-01',
            'ends_on' => '2025-08-31',
            'reason' => 'Neuvermietung in Vorbereitung, Wohnung stand leer',
        ]);

        $tenancies[] = $this->mietverhaeltnis(
            $organization,
            $property,
            $units[5],
            'Frau Nowak',
            '2025-09-01',
            null,
            15500,
            9500,
        );

        return ['property' => $property, 'units' => $units, 'tenancies' => $tenancies];
    }

    private function mietverhaeltnis(
        Organization $organization,
        Property $property,
        Unit $unit,
        string $name,
        string $von,
        ?string $bis,
        int $betriebskostenCent,
        int $heizkostenCent,
    ): Tenancy {
        /** @var Tenancy $tenancy */
        $tenancy = Tenancy::factory()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
            'unit_id' => $unit->getKey(),
            'tenant_display_name' => $name,
            'kind' => TenancyKind::WOHNRAUM,
            'status' => $bis === null ? TenancyStatus::AKTIV : TenancyStatus::BEENDET,
            'delivery_address_line' => $property->address_line,
            'delivery_postal_code' => $property->postal_code,
            'delivery_city' => $property->city,
            'starts_on' => $von,
            'ends_on' => $bis,
            'monthly_operating_prepayment_cent' => $betriebskostenCent,
            'monthly_heating_prepayment_cent' => $heizkostenCent,
        ]);

        return $tenancy;
    }

    private function zweitmandant(Organization $organization, User $user): Property
    {
        /** @var Property $property */
        $property = Property::factory()->create([
            'organization_id' => $organization->getKey(),
            'landlord_id' => Landlord::query()->where('organization_id', $organization->getKey())->value('id'),
            'label' => 'Ahornallee 5',
            'address_line' => 'Ahornallee 5',
            'postal_code' => '41460',
            'city' => 'Anderstadt',
            'kind' => PropertyKind::MEHRFAMILIENHAUS,
            'total_living_area_sqm' => '150.0000',
            'total_heated_area_sqm' => '150.0000',
            'mea_denominator' => '1000.000000',
        ]);

        /** @var Unit $unit */
        $unit = Unit::factory()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
            'label' => 'Wohnung links',
            'location' => 'EG links',
            'unit_number' => '1',
            'living_area_sqm' => '75.0000',
            'heated_area_sqm' => '75.0000',
            'mea' => '500.000000',
        ]);

        $this->mietverhaeltnis($organization, $property, $unit, 'Herr Sattler', '2024-01-01', null, 13000, 8000);

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $user->getKey(),
            'property_id' => $property->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'billing_year' => 2025,
            'mode' => BillingMode::FULL_PROPERTY,
        ]);

        return $property;
    }

    // -----------------------------------------------------------------
    // Lauf 1: frischer Entwurf
    // -----------------------------------------------------------------

    private function laufEntwurf(Organization $organization, User $user, Property $property): BillingRun
    {
        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $user->getKey(),
            'property_id' => $property->getKey(),
            'period_start' => '2024-01-01',
            'period_end' => '2024-12-31',
            'billing_year' => 2024,
            'mode' => BillingMode::QUICK_CONDO,
        ]);

        return $lauf;
    }

    // -----------------------------------------------------------------
    // Lauf 2: offene Pruefaufgaben in der Kostenpruefung
    // -----------------------------------------------------------------

    /**
     * @param  array{property: Property, unit: Unit, tenancy: Tenancy}  $wegA
     */
    private function laufMitPruefaufgaben(Organization $organization, User $user, array $wegA): BillingRun
    {
        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $user->getKey(),
            'property_id' => $wegA['property']->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'billing_year' => 2025,
            'mode' => BillingMode::QUICK_CONDO,
        ]);

        $hausgeld = $this->hausgeldabrechnung($organization, $lauf);
        $grundsteuer = $this->grundsteuerbescheid($organization, $lauf);
        $heizkosten = $this->heizkostenabrechnung($organization, $lauf);

        // Vorgeschlagene Kostenpositionen aus der Auswertung. Jede Position ist
        // noch offen und in der Kostenpruefung ausdruecklich zu entscheiden.
        $reinigung = $this->kostenposition($organization, $lauf, $hausgeld, 'GEBAEUDEREINIGUNG', [
            'description' => 'Gebaeudereinigung Treppenhaus',
            'supplier_name' => 'Reinigungsdienst Feldmann GmbH',
            'invoice_number' => 'HGA-2025-014',
            'amount_cent' => 41880,
        ]);

        // Bewusste Dublette: gleicher Betrag, gleicher Aussteller, zweite
        // Unterlage. Sie erzeugt das Warnbanner Dublettenverdacht.
        $dublette = $this->kostenposition($organization, $lauf, $grundsteuer, 'GEBAEUDEREINIGUNG', [
            'description' => 'Gebaeudereinigung Treppenhaus (zweite Unterlage)',
            'supplier_name' => 'Reinigungsdienst Feldmann GmbH',
            'invoice_number' => 'HGA-2025-014',
            'amount_cent' => 41880,
            'duplicate_of_cost_item_id' => $reinigung->getKey(),
            'duplicate_confidence' => '0.9200',
            'confidence' => '0.6100',
        ]);

        // Bewusst nicht umlagefaehig: Verwalterverguetung aus der
        // Hausgeldabrechnung. Sie erzeugt das entsprechende Warnbanner.
        $this->kostenposition($organization, $lauf, $hausgeld, 'VERWALTUNGSKOSTEN', [
            'description' => 'Verwalterverguetung der WEG',
            'supplier_name' => 'WEG-Verwaltung Kranzbach GmbH',
            'invoice_number' => 'HGA-2025-002',
            'amount_cent' => 38400,
            'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
            'excluded_from_apportionment' => true,
        ]);

        $this->kostenposition($organization, $lauf, $grundsteuer, 'GRUNDSTEUER', [
            'description' => 'Grundsteuer 2025',
            'supplier_name' => 'Stadt Beispielstadt',
            'invoice_number' => 'GST-2025-889',
            'amount_cent' => 43200,
            'document_date' => '2025-01-28',
        ]);

        $this->kostenposition($organization, $lauf, $heizkosten, 'HEIZUNG', [
            'description' => 'Heizkosten laut externer Abrechnung',
            'supplier_name' => 'Waermedienst Nordlicht GmbH',
            'invoice_number' => 'HKA-2025-4471',
            'amount_cent' => 118600,
            'is_heating_cost' => true,
        ]);

        $this->kostenposition($organization, $lauf, $hausgeld, 'SACHVERSICHERUNG', [
            'description' => 'Gebaeudeversicherung, Anteil der Einheit',
            'supplier_name' => 'Beispiel Versicherung AG',
            'invoice_number' => 'HGA-2025-007',
            'amount_cent' => 22740,
        ]);

        DocumentRelation::factory()->create([
            'organization_id' => $organization->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'from_document_id' => $grundsteuer->getKey(),
            'to_document_id' => $hausgeld->getKey(),
            'relation_type' => DocumentRelationType::DUBLETTE,
            'confidence' => '0.9200',
            'note' => 'Gleicher Betrag, gleicher Aussteller, gleiche Belegnummer',
        ]);

        ValidationIssue::factory()->create([
            'organization_id' => $organization->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'rule_code' => 'DEMO-DUBLETTE-001',
            'rule_version' => '1.0.0',
            'severity' => ValidationSeverity::WARNUNG,
            'status' => ValidationIssueStatus::OFFEN,
            'blocks_finalization' => false,
            'title' => 'Zwei Positionen mit gleichem Betrag und gleicher Belegnummer',
            'description' => 'Die Position "Gebaeudereinigung Treppenhaus" erscheint zweimal. '
                .'Bitte entscheiden Sie, welche Position bestehen bleibt.',
            'entity_type' => CostItem::class,
            'entity_id' => $dublette->getKey(),
        ]);

        // Zustand ausschliesslich ueber die Statusmaschine.
        app(BillingRunProgress::class)->pruefungErforderlich($lauf, $user);

        return $lauf->refresh();
    }

    // -----------------------------------------------------------------
    // Lauf 3: bis zur Vorschau fortgeschritten
    // -----------------------------------------------------------------

    /**
     * @param  array{property: Property, units: list<Unit>, tenancies: list<Tenancy>}  $wegB
     */
    private function laufBisVorschau(Organization $organization, User $user, array $wegB): BillingRun
    {
        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $user->getKey(),
            'property_id' => $wegB['property']->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'billing_year' => 2025,
            'mode' => BillingMode::FULL_PROPERTY,
        ]);

        // Mindestens zehn Kostenarten, jede mit bestaetigter Position.
        $positionen = [
            ['GRUNDSTEUER', 'Grundsteuer 2025', 'Stadt Beispielstadt', 214800, AllocationKeyType::MEA],
            ['WASSERVERSORGUNG', 'Wasserversorgung', 'Stadtwerke Beispielstadt', 168400, AllocationKeyType::WOHNFLAECHE],
            ['ENTWAESSERUNG', 'Entwaesserung und Abwasser', 'Stadtwerke Beispielstadt', 142600, AllocationKeyType::WOHNFLAECHE],
            ['NIEDERSCHLAGSWASSER', 'Niederschlagswasser', 'Stadtwerke Beispielstadt', 38900, AllocationKeyType::WOHNFLAECHE],
            ['MUELLBESEITIGUNG', 'Muellbeseitigung', 'Abfallwirtschaft Beispielstadt', 124500, AllocationKeyType::EINHEITEN],
            ['STRASSENREINIGUNG', 'Strassenreinigung', 'Stadt Beispielstadt', 42300, AllocationKeyType::WOHNFLAECHE],
            ['GEBAEUDEREINIGUNG', 'Gebaeudereinigung Treppenhaus', 'Reinigungsdienst Feldmann GmbH', 186000, AllocationKeyType::WOHNFLAECHE],
            ['GARTENPFLEGE', 'Gartenpflege', 'Gartenbau Wiesengrund', 96400, AllocationKeyType::WOHNFLAECHE],
            ['ALLGEMEINSTROM', 'Allgemeinstrom und Beleuchtung', 'Stadtwerke Beispielstadt', 54800, AllocationKeyType::WOHNFLAECHE],
            ['HAUSWART', 'Hauswart, nur umlagefaehige Taetigkeiten', 'Hausservice Berger', 228000, AllocationKeyType::WOHNFLAECHE],
            ['SACHVERSICHERUNG', 'Sach- und Gebaeudeversicherung', 'Beispiel Versicherung AG', 132400, AllocationKeyType::WOHNFLAECHE],
            ['HAFTPFLICHTVERSICHERUNG', 'Haus- und Grundbesitzerhaftpflicht', 'Beispiel Versicherung AG', 28600, AllocationKeyType::WOHNFLAECHE],
        ];

        foreach ($positionen as $index => $daten) {
            // Je Position eine eigene Unterlage, wie im echten Ablauf. Nur die
            // Metadaten bleiben, die Originaldatei ist geloescht.
            $beleg = $this->dokument(
                $organization,
                $lauf,
                $index + 1,
                sprintf('Dokument %02d - %s', $index + 1, $daten[1]),
                DocumentType::RECHNUNG,
            );

            $position = $this->kostenposition($organization, $lauf, $beleg, $daten[0], [
                'description' => $daten[1],
                'supplier_name' => $daten[2],
                'invoice_number' => sprintf('RE-2025-%03d', $index + 101),
                'amount_cent' => $daten[3],
                'status' => CostItemStatus::BESTAETIGT,
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $user->getKey(),
                'confidence' => '0.9600',
            ]);

            $kategorie = $position->getAttribute('cost_category_id');

            if (is_string($kategorie)) {
                $this->verteilerschluessel($organization, $lauf, $kategorie, $daten[4], $wegB['units'], $user);
            }
        }

        // Vorauszahlungen je Mietverhaeltnis, taggenau auf den
        // Abrechnungszeitraum begrenzt.
        foreach ($wegB['tenancies'] as $tenancy) {
            $this->vorauszahlung($organization, $lauf, $tenancy);
        }

        $this->berechneUndVorschau($lauf, $user);

        return $lauf->refresh();
    }

    /**
     * Berechnung und Vorschau ueber die Anwendungsdienste. Scheitert die
     * Erzeugung der Vorschau, bleibt der Lauf im erreichten Zustand; der
     * Tester erzeugt die Vorschau dann selbst.
     */
    private function berechneUndVorschau(BillingRun $lauf, User $user): void
    {
        try {
            app(PreviewBuilder::class)->rebuild($lauf, $user);
        } catch (Throwable $exception) {
            $this->zeile('  Hinweis: Die Vorschau konnte nicht vorab erzeugt werden ('
                .$exception->getMessage().'). Der Lauf bleibt im erreichten Zustand.');
        }
    }

    /**
     * @param  list<Unit>  $units
     */
    private function verteilerschluessel(
        Organization $organization,
        BillingRun $lauf,
        string $kategorieId,
        AllocationKeyType $typ,
        array $units,
        User $user,
    ): void {
        $nenner = match ($typ) {
            AllocationKeyType::WOHNFLAECHE => '432.000000',
            AllocationKeyType::MEA => '1000.000000',
            default => (string) count($units),
        };

        /** @var AllocationKey $schluessel */
        $schluessel = AllocationKey::factory()->create([
            'organization_id' => $organization->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'cost_category_id' => $kategorieId,
            'key_type' => $typ,
            'source' => AllocationKeySource::MIETVERTRAG,
            'denominator' => $nenner,
            'measurement_unit' => match ($typ) {
                AllocationKeyType::WOHNFLAECHE => 'm2',
                AllocationKeyType::MEA => 'Anteile',
                default => 'Einheiten',
            },
            'label' => $typ->label(),
            'confirmed_at' => now(),
            'confirmed_by_user_id' => $user->getKey(),
        ]);

        foreach ($units as $unit) {
            $zaehler = match ($typ) {
                AllocationKeyType::WOHNFLAECHE => (string) $unit->getAttribute('living_area_sqm'),
                AllocationKeyType::MEA => (string) $unit->getAttribute('mea'),
                default => '1.000000',
            };

            AllocationKeyValue::factory()->create([
                'organization_id' => $organization->getKey(),
                'allocation_key_id' => $schluessel->getKey(),
                'unit_id' => $unit->getKey(),
                'numerator' => $zaehler,
                'source' => ValueSource::MIETVERTRAG,
            ]);
        }
    }

    private function vorauszahlung(Organization $organization, BillingRun $lauf, Tenancy $tenancy): void
    {
        $von = $this->spaeteres($this->datum($tenancy->getAttribute('starts_on')) ?? '2025-01-01', '2025-01-01');
        $bis = $this->frueheres($this->datum($tenancy->getAttribute('ends_on')) ?? '2025-12-31', '2025-12-31');

        $monate = $this->monate($von, $bis);

        $betriebskosten = (int) $tenancy->getAttribute('monthly_operating_prepayment_cent') * $monate;
        $heizkosten = (int) $tenancy->getAttribute('monthly_heating_prepayment_cent') * $monate;

        Prepayment::factory()->create([
            'organization_id' => $organization->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'tenancy_id' => $tenancy->getKey(),
            'kind' => PrepaymentKind::BETRIEBSKOSTEN,
            'period_start' => $von,
            'period_end' => $bis,
            'target_cent' => $betriebskosten,
            'actual_cent' => $betriebskosten,
            'source' => ValueSource::ZAHLUNGSUEBERSICHT,
            'confirmed_at' => now(),
        ]);

        Prepayment::factory()->create([
            'organization_id' => $organization->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'tenancy_id' => $tenancy->getKey(),
            'kind' => PrepaymentKind::HEIZKOSTEN,
            'period_start' => $von,
            'period_end' => $bis,
            'target_cent' => $heizkosten,
            'actual_cent' => $heizkosten,
            'source' => ValueSource::ZAHLUNGSUEBERSICHT,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * Datumswert als ISO-Zeichenkette, sonst null.
     */
    private function datum(mixed $wert): ?string
    {
        if ($wert instanceof \DateTimeInterface) {
            return $wert->format('Y-m-d');
        }

        return is_string($wert) && $wert !== '' ? substr($wert, 0, 10) : null;
    }

    private function spaeteres(string $links, string $rechts): string
    {
        return $links > $rechts ? $links : $rechts;
    }

    private function frueheres(string $links, string $rechts): string
    {
        return $links < $rechts ? $links : $rechts;
    }

    private function monate(string $von, string $bis): int
    {
        $start = new \DateTimeImmutable($von);
        $ende = new \DateTimeImmutable($bis);

        $monate = ((int) $ende->format('Y') - (int) $start->format('Y')) * 12
            + ((int) $ende->format('n') - (int) $start->format('n'))
            + 1;

        return max(1, $monate);
    }

    // -----------------------------------------------------------------
    // Unterlagen: ausschliesslich strukturierte Extraktionsdaten
    // -----------------------------------------------------------------

    private function dokument(
        Organization $organization,
        BillingRun $lauf,
        int $nummer,
        string $bezeichnung,
        DocumentType $typ,
    ): Document {
        /** @var Document $dokument */
        $dokument = Document::factory()->create([
            'organization_id' => $organization->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'sequence_number' => $nummer,
            'source_label' => $bezeichnung,
            'document_type' => $typ,
            'document_type_confidence' => '0.9600',
            'page_count' => 3,
        ]);

        return $dokument;
    }

    /**
     * @param  array<string, mixed>  $felder
     */
    private function extraktion(
        Organization $organization,
        BillingRun $lauf,
        Document $dokument,
        array $felder,
    ): void {
        foreach ($felder as $pfad => $wert) {
            ExtractedField::factory()->create([
                'organization_id' => $organization->getKey(),
                'billing_run_id' => $lauf->getKey(),
                'document_id' => $dokument->getKey(),
                'schema_key' => $pfad,
                'schema_version' => '1.0.0',
                'value' => ['wert' => $wert],
                'page_number' => 1,
                'source_excerpt' => 'Demodaten, frei erfundener Beispielwert',
                'confidence' => '0.9400',
                'status' => ExtractedFieldStatus::AUTOMATISCH_ERKANNT,
            ]);
        }
    }

    private function hausgeldabrechnung(Organization $organization, BillingRun $lauf): Document
    {
        $dokument = $this->dokument(
            $organization,
            $lauf,
            1,
            'Dokument 01 - WEG-Hausgeldabrechnung 2025',
            DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL,
        );

        $this->extraktion($organization, $lauf, $dokument, [
            'abrechnungsart' => 'EINZELABRECHNUNG',
            'weg_bezeichnung' => 'WEG Lindenhofweg 12',
            'objektanschrift' => 'Lindenhofweg 12, 40699 Beispielstadt',
            'verwalter' => 'WEG-Verwaltung Kranzbach GmbH',
            'abrechnungszeitraum_von' => '2025-01-01',
            'abrechnungszeitraum_bis' => '2025-12-31',
            'einheitsbezeichnung' => 'Wohnung 7',
            'wohnungsnummer' => '7',
            'miteigentumsanteil' => '86.500000',
            'miteigentumsanteil_nenner' => '1000.000000',
            'wohnflaeche_qm' => '74.30',
            'hausgeldvorauszahlungen_cent' => 348000,
            'abrechnungsspitze_cent' => 21400,
            'ruecklagenzufuehrung_cent' => 72000,
            'verwalterverguetung_cent' => 38400,
            'instandhaltung_reparatur_cent' => 46800,
            'heizkosten_anteil_einheit_cent' => 118600,
            'grundsteuer_enthalten' => false,
            'kostenaufschluesselung_vorhanden' => true,
            'kostenarten[0].bezeichnung' => 'Gebaeudereinigung Treppenhaus',
            'kostenarten[0].gesamtkosten_cent' => 484000,
            'kostenarten[0].anteil_einheit_cent' => 41880,
            'kostenarten[0].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[0].verwalter_kennzeichnung_umlagefaehig' => true,
            'kostenarten[1].bezeichnung' => 'Verwalterverguetung',
            'kostenarten[1].gesamtkosten_cent' => 444000,
            'kostenarten[1].anteil_einheit_cent' => 38400,
            'kostenarten[1].kategorie' => 'VERWALTERVERGUETUNG',
            'kostenarten[1].verwalter_kennzeichnung_umlagefaehig' => false,
            'kostenarten[2].bezeichnung' => 'Gebaeudeversicherung',
            'kostenarten[2].gesamtkosten_cent' => 262900,
            'kostenarten[2].anteil_einheit_cent' => 22740,
            'kostenarten[2].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[2].verwalter_kennzeichnung_umlagefaehig' => true,
        ]);

        return $dokument;
    }

    private function grundsteuerbescheid(Organization $organization, BillingRun $lauf): Document
    {
        $dokument = $this->dokument(
            $organization,
            $lauf,
            2,
            'Dokument 02 - Grundsteuerbescheid 2025',
            DocumentType::GRUNDSTEUERBESCHEID,
        );

        $this->extraktion($organization, $lauf, $dokument, [
            'belegart' => 'BESCHEID',
            'aussteller' => 'Stadt Beispielstadt',
            'belegnummer' => 'GST-2025-889',
            'belegdatum' => '2025-01-28',
            'objektanschrift' => 'Lindenhofweg 12, 40699 Beispielstadt',
            'einheitsbezeichnung' => 'Wohnung 7',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
            'gesamtbetrag_brutto_cent' => 43200,
            'positionen[0].bezeichnung' => 'Grundsteuer B, Jahresbetrag',
            'positionen[0].betrag_brutto_cent' => 43200,
            'positionen[0].vorgeschlagene_kostenart' => 'GRUNDSTEUER',
        ]);

        return $dokument;
    }

    private function heizkostenabrechnung(Organization $organization, BillingRun $lauf): Document
    {
        $dokument = $this->dokument(
            $organization,
            $lauf,
            3,
            'Dokument 03 - Externe Heizkostenabrechnung 2025',
            DocumentType::HEIZKOSTENABRECHNUNG,
        );

        $this->extraktion($organization, $lauf, $dokument, [
            'abrechnungsdienst' => 'Waermedienst Nordlicht GmbH',
            'abrechnungsnummer' => 'HKA-2025-4471',
            'objektanschrift' => 'Lindenhofweg 12, 40699 Beispielstadt',
            'abrechnungszeitraum_von' => '2025-01-01',
            'abrechnungszeitraum_bis' => '2025-12-31',
            'gesamtkosten_heizung_cent' => 892000,
            'gesamtkosten_warmwasser_cent' => 268000,
            'gesamtkosten_summe_cent' => 1160000,
            'grundkostenanteil_prozent' => '30.00',
            'co2_kosten_gesamt_cent' => 84000,
            'anzahl_einheiten' => 12,
            'einheiten[0].einheitsbezeichnung' => 'Wohnung 7',
            'einheiten[0].nutzer_name' => 'Eheleute Sandmann',
            'einheiten[0].nutzungszeitraum_von' => '2025-01-01',
            'einheiten[0].nutzungszeitraum_bis' => '2025-12-31',
            'einheiten[0].heizung_grundkosten_cent' => 22300,
            'einheiten[0].heizung_verbrauchskosten_cent' => 74200,
            'einheiten[0].warmwasser_grundkosten_cent' => 6700,
            'einheiten[0].warmwasser_verbrauchskosten_cent' => 15400,
            'einheiten[0].co2_kosten_anteil_cent' => 6300,
            'einheiten[0].summe_cent' => 118600,
            'einheiten[0].vorauszahlungen_cent' => 114000,
        ]);

        return $dokument;
    }

    /**
     * @param  array<string, mixed>  $abweichungen
     */
    private function kostenposition(
        Organization $organization,
        BillingRun $lauf,
        Document $dokument,
        string $kategoriecode,
        array $abweichungen,
    ): CostItem {
        $kategorie = CostCategory::query()->where('code', $kategoriecode)->orderBy('valid_from')->first();

        /** @var CostItem $position */
        $position = CostItem::factory()->create(array_merge([
            'organization_id' => $organization->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'document_id' => $dokument->getKey(),
            'cost_category_id' => $kategorie?->getKey(),
            'net_amount_cent' => null,
            'vat_amount_cent' => null,
            'vat_rate_percent' => null,
            'document_date' => '2025-12-08',
            'service_period_start' => '2025-01-01',
            'service_period_end' => '2025-12-31',
            'source' => CostItemSource::KI_EXTRAKTION,
            'status' => CostItemStatus::VORGESCHLAGEN,
            'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
            'paragraph_35a_type' => Paragraph35aType::NONE,
            'confidence' => '0.9300',
            'source_page' => 1,
        ], $abweichungen));

        return $position;
    }

    // -----------------------------------------------------------------
    // Konsolenausgabe
    // -----------------------------------------------------------------

    private function laufzeile(string $zweck, BillingRun $lauf): void
    {
        $status = $lauf->getAttribute('status');
        $statuswert = is_object($status) && property_exists($status, 'value') ? (string) $status->value : (string) $status;

        $this->zeile(sprintf(
            '  %s | Zeitraum %s | Status %s',
            $zweck,
            (string) $lauf->getAttribute('billing_year'),
            $statuswert,
        ));
        $this->zeile('    /app/abrechnungen/'.$lauf->getKey());
    }

    private function zeile(string $text): void
    {
        $this->uebersicht[] = $text;
    }

    private function ausgeben(): void
    {
        if ($this->command === null) {
            return;
        }

        foreach ($this->uebersicht as $zeile) {
            $this->command->getOutput()->writeln($zeile);
        }
    }
}
