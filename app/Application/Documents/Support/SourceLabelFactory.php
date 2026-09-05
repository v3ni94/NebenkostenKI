<?php

declare(strict_types=1);

namespace App\Application\Documents\Support;

use App\Enums\DocumentType;

/**
 * Neutrale Quellenbezeichnung eines Dokuments (Abschnitt 6.3 Schritt 3).
 *
 * VERBINDLICH: Dauerhaft steht ausschliesslich diese Bezeichnung, gebildet aus
 * der laufenden Nummer im Abrechnungslauf und der Dokumentart, zum Beispiel
 * "Dokument 01 - Grundsteuerbescheid". Der Originaldateiname wird nie
 * gespeichert. Er kann Namen, Anschriften oder Aktenzeichen enthalten und
 * waere damit ein personenbezogenes Datum ueber eine geloeschte Datei.
 *
 * Die Bezeichnung ist zugleich der Quellenbezug jedes extrahierten Feldes und
 * erscheint in Pruefbericht, Vorschau und PDF.
 */
final class SourceLabelFactory
{
    /**
     * Bezeichnung vor der Klassifikation. Es wird nicht geraten, sondern
     * ausdruecklich als noch nicht bestimmt ausgewiesen.
     */
    public function pending(int $sequenceNumber): string
    {
        return sprintf('Dokument %02d - Nicht klassifiziert', $sequenceNumber);
    }

    public function forType(int $sequenceNumber, DocumentType $type): string
    {
        return sprintf('Dokument %02d - %s', $sequenceNumber, $type->label());
    }

    /**
     * Kuerzt auf die Spaltenbreite von documents.source_label.
     */
    public function truncate(string $label): string
    {
        return mb_substr($label, 0, 190);
    }
}
