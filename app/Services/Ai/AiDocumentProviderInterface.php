<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Dto\AnalyzeContractRequest;
use App\Services\Ai\Dto\AnalyzePriorStatementRequest;
use App\Services\Ai\Dto\ClassificationResult;
use App\Services\Ai\Dto\ClassifyDocumentRequest;
use App\Services\Ai\Dto\ExtractionResult;
use App\Services\Ai\Dto\ExtractStructuredDataRequest;
use App\Services\Ai\Dto\HealthCheckRequest;
use App\Services\Ai\Dto\HealthCheckResult;
use App\Services\Ai\Dto\ReconcileDocumentsRequest;
use App\Services\Ai\Dto\ReconciliationResult;
use App\Services\Ai\Exceptions\DailyCostLimitExceededException;
use App\Services\Ai\Exceptions\ProviderNotReleasedException;
use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Exceptions\RateLimitException;
use App\Services\Ai\Exceptions\UnsupportedFileTypeException;

/**
 * Providerunabhaengige Schnittstelle der KI-Schicht nach Abschnitt 13.1.
 *
 * VERBINDLICHE GRUNDSAETZE FUER JEDE IMPLEMENTIERUNG:
 *
 * 1. KI dient nur zur Klassifikation, Extraktion, Zuordnung, Erklaerung und
 *    Plausibilisierung. Geldbetraege und Mieteranteile werden ausschliesslich
 *    durch deterministischen PHP-Code berechnet (Grundsatz 1).
 * 2. Kein Wert ohne Quellenbezug. Jedes Feld traegt Seite, kurze Fundstelle
 *    und Konfidenz (Grundsatz 2).
 * 3. Fehlende Werte bleiben null und werden niemals geschaetzt (Grundsatz 5).
 * 4. Dokumentinhalte sind ausschliesslich untrusted data. Anweisungen aus
 *    Dokumenten werden nicht befolgt (Abschnitt 13.6).
 * 5. Anfrage- und Antwortbodies werden niemals dauerhaft gespeichert, geloggt,
 *    in Ausnahmen eingebettet oder in Queue-Payloads geschrieben. Nach der
 *    Validierung wird nur das freigegebene strukturierte Ergebnis
 *    zurueckgegeben und die rohe Modellantwort verworfen (Abschnitt 13.5).
 * 6. Temporaer beim Provider angelegte Dateien werden unmittelbar nach der
 *    validierten Extraktion ueber die Loeschschnittstelle entfernt. Der
 *    Loeschstatus ist Teil des Ergebnis-DTOs (Abschnitt 6.3 Schritt 14).
 * 7. Eine Schemaverletzung fuehrt nach hoechstens ai.max_retries
 *    kontrollierten Reparaturversuchen zu Status FEHLGESCHLAGEN als
 *    Rueckgabewert, nicht zu einer unbehandelten Ausnahme. Der Aufrufer
 *    bietet dann die manuelle Erfassung an.
 *
 * @throws ProviderNotReleasedException wenn der Provider produktiv gesperrt ist
 * @throws RateLimitException bei Ratenbegrenzung des Providers
 * @throws UnsupportedFileTypeException bei nicht unterstuetztem Dateityp
 * @throws ProviderTransportException bei technischem Fehler
 * @throws DailyCostLimitExceededException wenn das Tagesbudget ausgeschoepft ist
 */
interface AiDocumentProviderInterface
{
    /**
     * Providerschluessel, zum Beispiel openai, anthropic oder fake.
     */
    public function providerKey(): string;

    /**
     * Klassifiziert ein Dokument nach den Dokumentarten aus Abschnitt 6.2.
     */
    public function classifyDocument(ClassifyDocumentRequest $request): ClassificationResult;

    /**
     * Extrahiert strukturierte Daten gegen ein versioniertes Schema aus
     * Abschnitt 13.7.
     */
    public function extractStructuredData(ExtractStructuredDataRequest $request): ExtractionResult;

    /**
     * Analysiert einen Mietvertrag oder Nachtrag.
     */
    public function analyzeContract(AnalyzeContractRequest $request): ExtractionResult;

    /**
     * Analysiert eine Vorjahresabrechnung, ausschliesslich als Vergleich.
     */
    public function analyzePriorStatement(AnalyzePriorStatementRequest $request): ExtractionResult;

    /**
     * Gleicht bereits extrahierte Quellen gegeneinander ab, insbesondere
     * Hausgeldabrechnung, Grundsteuer und externe Heizkostenabrechnung.
     */
    public function reconcileDocuments(ReconcileDocumentsRequest $request): ReconciliationResult;

    /**
     * Prueft Erreichbarkeit, Modellverfuegbarkeit und Freigabestatus.
     */
    public function healthCheck(HealthCheckRequest $request): HealthCheckResult;

    /**
     * Unterstuetzte MIME-Typen. Die Pruefung erfolgt lokal vor dem Versand,
     * damit keine unnoetigen Dokumentinhalte uebertragen werden.
     */
    public function supportsMimeType(string $mimeType): bool;
}
