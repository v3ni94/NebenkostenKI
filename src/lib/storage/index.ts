import type { StorageDriver } from "./interface";
import { LocalStorageDriver } from "./local";
import { S3StorageDriver } from "./s3";

/**
 * Liefert den konfigurierten Storage-Treiber gemäß STORAGE_DRIVER (.env).
 * Default: "local".
 */
export function getStorageDriver(): StorageDriver {
  const driver = process.env.STORAGE_DRIVER ?? "local";
  switch (driver) {
    case "s3":
      return new S3StorageDriver();
    case "local":
    default:
      return new LocalStorageDriver();
  }
}
