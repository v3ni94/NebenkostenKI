import type { StorageDriver } from "./interface";

/**
 * Lokaler Storage-Treiber (Default gemäß STORAGE_DRIVER=local).
 * Platzhalter für Phase 1: Implementierung folgt, sobald der Upload-Flow
 * (Wizard-Schritte) ansteht. Vorgesehen: Ablage unterhalb eines konfigurierbaren
 * Basisverzeichnisses außerhalb des Web-Roots.
 */
export class LocalStorageDriver implements StorageDriver {
  async put(): Promise<{ storageKey: string }> {
    throw new Error("LocalStorageDriver.put ist noch nicht implementiert (Phase 1).");
  }

  async get(): Promise<Buffer> {
    throw new Error("LocalStorageDriver.get ist noch nicht implementiert (Phase 1).");
  }

  async getUrl(): Promise<string> {
    throw new Error("LocalStorageDriver.getUrl ist noch nicht implementiert (Phase 1).");
  }

  async delete(): Promise<void> {
    throw new Error("LocalStorageDriver.delete ist noch nicht implementiert (Phase 1).");
  }
}
