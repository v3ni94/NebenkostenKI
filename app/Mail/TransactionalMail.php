<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\GeneratedDocumentKind;
use App\Models\GeneratedDocument;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Gemeinsame Grundlage aller Transaktionsmails.
 *
 * VERBINDLICHE REGELN (Masterprompt 16, 19):
 *
 *  1. Jede Nachricht wird als HTML UND als reiner Text ausgeliefert. Die
 *     Textfassung ist keine Notloesung, sie enthaelt denselben Inhalt.
 *  2. Der Absender kommt ausschliesslich aus der Mailkonfiguration
 *     (config/mail.php, MAIL_FROM_*). Im Code steht keine Adresse.
 *  3. Finale Mieterabrechnungen werden niemals unverschluesselt angehaengt.
 *     Zulaessig ist ausschliesslich die Leistungsrechnung der Hausverwaltung
 *     Mueller GmbH. Die Pruefung liegt zusaetzlich im MailDispatcher, damit
 *     eine spaetere Unterklasse die Regel nicht versehentlich aufweicht.
 *  4. Keine Werbung, keine Superlative, kein Tracking und keine Zaehlpixel.
 *  5. Deutsche Sie-Anrede, kurze Saetze, keine Gedankenstriche.
 */
abstract class TransactionalMail extends Mailable
{
    /**
     * Technischer Schluessel fuer das Protokoll in email_messages.
     */
    abstract public function template(): string;

    /**
     * Betreffzeile in deutscher Sprache.
     */
    abstract public function betreff(): string;

    /**
     * Name der Blade-Vorlage ohne Endung. Die Textfassung liegt unter
     * demselben Namen mit dem Zusatz "-text".
     */
    abstract public function blade(): string;

    /**
     * Vorlagendaten. Es gehoeren ausschliesslich Angaben hinein, die der
     * Empfaenger ohnehin kennt oder benoetigt.
     *
     * @return array<string, mixed>
     */
    abstract public function daten(): array;

    /**
     * Kritische Konto- und Zahlungsnachrichten werden auch an eine
     * unterdrueckte Adresse versendet (Masterprompt 17.2).
     */
    public function istKritisch(): bool
    {
        return false;
    }

    /**
     * Erzeugte Dokumente, die angehaengt werden duerfen.
     *
     * Zulaessig ist ausschliesslich die HVM-Leistungsrechnung. Alle anderen
     * Artefakte, insbesondere Mieterabrechnungen, werden ueber einen zeitlich
     * begrenzten kontogebundenen Downloadlink bereitgestellt.
     *
     * @return list<GeneratedDocument>
     */
    public function anhangDokumente(): array
    {
        return [];
    }

    /**
     * Fassung der Nachricht fuer einen erneuten Versand aus dem
     * Wiederholungspuffer. Zeitlich begrenzte Bestandteile, insbesondere
     * signierte Downloadlinks, sind zu diesem Zeitpunkt moeglicherweise
     * abgelaufen und werden hier neu erzeugt. Eine Nachricht ohne solche
     * Bestandteile wird unveraendert zurueckgegeben.
     */
    public function fuerErneutenVersand(): static
    {
        return $this;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->betreff());
    }

    public function content(): Content
    {
        return new Content(
            view: $this->blade(),
            text: $this->blade().'-text',
            with: $this->daten(),
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $anhaenge = [];

        foreach ($this->anhangDokumente() as $dokument) {
            if ($dokument->getAttribute('kind') !== GeneratedDocumentKind::HVM_RECHNUNG) {
                continue;
            }

            $disk = $dokument->getAttribute('storage_disk');
            $pfad = $dokument->getAttribute('storage_path');

            if (! is_string($disk) || ! is_string($pfad) || $disk === '' || $pfad === '') {
                continue;
            }

            $anhaenge[] = Attachment::fromStorageDisk($disk, $pfad)
                ->as('rechnung.pdf')
                ->withMime('application/pdf');
        }

        return $anhaenge;
    }
}
