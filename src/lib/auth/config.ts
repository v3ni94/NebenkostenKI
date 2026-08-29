/**
 * Auth.js-Grundgerüst (E-Mail + Passwort, Argon2id-Hashing).
 *
 * Platzhalter für Phase 1: Provider-Konfiguration, Session-Strategie (JWT vs.
 * Datenbank-Session) und die Anbindung an das User-Modell (prisma/schema.prisma)
 * werden im Auth-Arbeitsschritt von Phase 1 ausgearbeitet. AUTH_SECRET wird
 * ausschließlich aus .env gelesen, siehe .env.example.
 */
import argon2 from "argon2";

/** Hasht ein Klartext-Passwort mit Argon2id (empfohlene Parameter, OWASP). */
export async function hashPassword(plainPassword: string): Promise<string> {
  return argon2.hash(plainPassword, { type: argon2.argon2id });
}

/** Verifiziert ein Klartext-Passwort gegen einen gespeicherten Argon2id-Hash. */
export async function verifyPassword(
  hash: string,
  plainPassword: string,
): Promise<boolean> {
  return argon2.verify(hash, plainPassword);
}

// TODO (Phase 1): NextAuth({ providers: [Credentials(...)], adapter: PrismaAdapter(db), ... })
// wird hier ergänzt, sobald der Login-/Registrierungs-Flow implementiert wird.
