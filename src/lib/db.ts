import { PrismaClient } from "@prisma/client";

/**
 * Prisma-Client-Singleton.
 *
 * In der Entwicklung (Hot Reload durch Next.js) würde jeder Modul-Reload
 * sonst einen neuen PrismaClient und damit einen neuen Connection-Pool
 * erzeugen. Deshalb wird die Instanz global zwischengehalten.
 */
const globalForPrisma = globalThis as unknown as {
  prisma: PrismaClient | undefined;
};

export const db =
  globalForPrisma.prisma ??
  new PrismaClient({
    log: process.env.NODE_ENV === "development" ? ["warn", "error"] : ["error"],
  });

if (process.env.NODE_ENV !== "production") {
  globalForPrisma.prisma = db;
}
