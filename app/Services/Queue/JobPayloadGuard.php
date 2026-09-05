<?php

declare(strict_types=1);

namespace App\Services\Queue;

use InvalidArgumentException;

/**
 * Technische Sperre fuer Queue-Payloads.
 *
 * VERBINDLICHE REGEL (Abschnitt 19, Migration 000800): In processing_jobs.payload
 * gehoeren ausschliesslich Referenz-IDs und kurze technische Parameter. Niemals
 * Dateiinhalte, OCR-Texte, Prompts, Provider-Antworten, Originaldateinamen oder
 * personenbezogene Klartexte. Queue-Payloads sind zusaetzlich aus Backups
 * auszuschliessen.
 *
 * Die Sperre ist absichtlich streng und laut: Ein unzulaessiger Payload ist ein
 * Programmierfehler und fuehrt zu einer Ausnahme, nicht zu einer stillen
 * Kuerzung.
 */
final class JobPayloadGuard
{
    /**
     * Maximale Laenge eines Zeichenkettenwerts. Eine ULID hat 26 Zeichen, ein
     * Fehlercode bis 120. Alles darueber ist kein technischer Parameter mehr.
     */
    private const MAX_VALUE_LENGTH = 128;

    private const MAX_KEYS = 20;

    /**
     * Schluesselbestandteile, die auf Inhalte hindeuten.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEY_FRAGMENTS = [
        'text', 'inhalt', 'content', 'body', 'prompt', 'antwort', 'response',
        'ocr', 'base64', 'datei', 'file', 'name', 'pfad', 'path', 'key',
        'secret', 'token', 'email', 'mail', 'adresse', 'address', 'iban',
        'excerpt', 'auszug', 'raw',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, bool|float|int|string|null>
     *
     * @throws InvalidArgumentException
     */
    public function sanitize(array $payload): array
    {
        if (count($payload) > self::MAX_KEYS) {
            throw new InvalidArgumentException(
                'Ein Queue-Payload darf hoechstens '.self::MAX_KEYS.' technische Parameter enthalten.'
            );
        }

        $result = [];

        foreach ($payload as $key => $value) {
            $this->assertKeyAllowed($key);
            $result[$key] = $this->assertValueAllowed($key, $value);
        }

        return $result;
    }

    private function assertKeyAllowed(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Der Payload-Schluessel "%s" ist unzulaessig. Erlaubt sind kurze Kleinbuchstabenschluessel.',
                $key
            ));
        }

        // "*_id" ist immer erlaubt, auch wenn der Name ein verbotenes Fragment
        // enthaelt, weil es sich um eine Referenz handelt.
        if (str_ends_with($key, '_id')) {
            return;
        }

        foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                throw new InvalidArgumentException(sprintf(
                    'Der Payload-Schluessel "%s" deutet auf einen Inhalt hin. In Queue-Payloads gehoeren '
                    .'ausschliesslich Referenz-IDs und technische Parameter.',
                    $key
                ));
            }
        }
    }

    private function assertValueAllowed(string $key, mixed $value): bool|float|int|string|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Der Wert von "%s" ist kein technischer Parameter. Verschachtelte Strukturen und Objekte '
                .'sind in Queue-Payloads gesperrt.',
                $key
            ));
        }

        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Der Wert von "%s" ist laenger als %d Zeichen und damit kein technischer Parameter.',
                $key,
                self::MAX_VALUE_LENGTH
            ));
        }

        if (str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")) {
            throw new InvalidArgumentException(sprintf(
                'Der Wert von "%s" enthaelt Zeilenumbrueche und ist damit kein technischer Parameter.',
                $key
            ));
        }

        return $value;
    }
}
