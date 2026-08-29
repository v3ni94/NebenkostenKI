import type { StorageDriver } from "./interface";

/**
 * S3-kompatibler Storage-Treiber (optional, STORAGE_DRIVER=s3).
 * Platzhalter für spätere Phasen. Vorgesehen für produktive Mehrserver-Setups
 * bzw. wenn kein persistentes lokales Dateisystem zur Verfügung steht.
 */
export class S3StorageDriver implements StorageDriver {
  async put(): Promise<{ storageKey: string }> {
    throw new Error("S3StorageDriver.put ist noch nicht implementiert.");
  }

  async get(): Promise<Buffer> {
    throw new Error("S3StorageDriver.get ist noch nicht implementiert.");
  }

  async getUrl(): Promise<string> {
    throw new Error("S3StorageDriver.getUrl ist noch nicht implementiert.");
  }

  async delete(): Promise<void> {
    throw new Error("S3StorageDriver.delete ist noch nicht implementiert.");
  }
}
