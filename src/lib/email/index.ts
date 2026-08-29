import nodemailer from "nodemailer";

/**
 * E-Mail-Versand (Verifizierung, Passwort-Reset, Benachrichtigungen) über
 * nodemailer/SMTP (siehe .env: SMTP_HOST/PORT/USER/PASS/FROM). Platzhalter
 * für Phase 1.
 */
export function getMailTransport() {
  const host = process.env.SMTP_HOST;
  const port = Number(process.env.SMTP_PORT ?? 587);
  const user = process.env.SMTP_USER;
  const pass = process.env.SMTP_PASS;
  if (!host || !user || !pass) {
    throw new Error("SMTP-Konfiguration unvollständig (.env: SMTP_HOST/USER/PASS).");
  }
  return nodemailer.createTransport({ host, port, auth: { user, pass } });
}
