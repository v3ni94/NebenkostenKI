/**
 * Storage-Abstraktion für hochgeladene/erzeugte Dateien (Belege, Abrechnungs-PDFs,
 * externe Heizkostenabrechnungen). Konkrete Implementierungen: local.ts (Default,
 * Dateisystem) und s3.ts (optional, S3-kompatibel). Auswahl über STORAGE_DRIVER
 * (.env), siehe .env.example.
 */
export interface StoragePutResult {
  /** Eindeutiger Schlüssel/Pfad, wird als Document.storageKey persistiert. */
  storageKey: string;
}

export interface StorageDriver {
  /** Speichert eine Datei und liefert den Storage-Key zurück. */
  put(params: {
    key: string;
    data: Buffer | Uint8Array;
    contentType: string;
  }): Promise<StoragePutResult>;

  /** Lädt eine gespeicherte Datei anhand ihres Storage-Keys. */
  get(storageKey: string): Promise<Buffer>;

  /** Liefert eine (ggf. zeitlich begrenzte) URL zum Abruf der Datei. */
  getUrl(storageKey: string): Promise<string>;

  /** Löscht eine Datei dauerhaft. */
  delete(storageKey: string): Promise<void>;
}
