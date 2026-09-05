<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status eines Abrechnungslaufs.
 *
 * Die Reihenfolge entspricht dem gefuehrten Ablauf. FAILED und CANCELLED sind
 * Endzustaende ohne Finalisierung, FINALIZED ist der bezahlte Endzustand.
 */
enum BillingRunStatus: string
{
    case DRAFT = 'DRAFT';
    case UPLOADING = 'UPLOADING';
    case EXTRACTING = 'EXTRACTING';
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    case READY_FOR_CALCULATION = 'READY_FOR_CALCULATION';
    case CALCULATED = 'CALCULATED';
    case PREVIEW_READY = 'PREVIEW_READY';
    case CHECKOUT_PENDING = 'CHECKOUT_PENDING';
    case PAID = 'PAID';
    case FINALIZING = 'FINALIZING';
    case FINALIZED = 'FINALIZED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Entwurf',
            self::UPLOADING => 'Unterlagen werden hochgeladen',
            self::EXTRACTING => 'Unterlagen werden ausgewertet',
            self::REVIEW_REQUIRED => 'Bitte prüfen',
            self::READY_FOR_CALCULATION => 'Bereit zur Berechnung',
            self::CALCULATED => 'Berechnet',
            self::PREVIEW_READY => 'Vorschau bereit',
            self::CHECKOUT_PENDING => 'Zahlung eingeleitet',
            self::PAID => 'Bezahlt',
            self::FINALIZING => 'Abrechnungen werden erstellt',
            self::FINALIZED => 'Abgeschlossen',
            self::FAILED => 'Fehlgeschlagen',
            self::CANCELLED => 'Abgebrochen',
        };
    }

    /**
     * Endzustand, der keine weitere Verarbeitung mehr ausloest.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::FINALIZED, self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }

    /**
     * Zahlung ist bestaetigt, der Calculation Snapshot ist gesperrt.
     */
    public function isPaid(): bool
    {
        return match ($this) {
            self::PAID, self::FINALIZING, self::FINALIZED => true,
            default => false,
        };
    }

    /**
     * Abrechnungsrelevante Nutzereingaben sind noch aenderbar.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::DRAFT, self::UPLOADING, self::EXTRACTING,
            self::REVIEW_REQUIRED, self::READY_FOR_CALCULATION,
            self::CALCULATED, self::PREVIEW_READY => true,
            default => false,
        };
    }
}
