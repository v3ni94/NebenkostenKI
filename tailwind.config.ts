import type { Config } from "tailwindcss";

/**
 * Tailwind-Konfiguration mit den CI-Design-Tokens der Hausverwaltung Müller GmbH.
 *
 * Hinweis: Diese App nutzt Tailwind CSS v4, dessen primärer Konfigurationsweg
 * die @theme-Direktive in src/app/globals.css ist (dort sind dieselben Werte
 * als CSS-Variablen/Utility-Tokens hinterlegt, z.B. bg-hvm-orange). Diese Datei
 * dokumentiert dieselben Tokens zusätzlich im klassischen Config-Format, falls
 * Tooling (z.B. IDE-Plugins, künftige v3-kompatible Pakete) eine JS/TS-Config
 * erwartet. Quelle der Wahrheit für Farbwerte ist die CI-Vorgabe der HVM.
 */
const config: Config = {
  content: [
    "./src/**/*.{js,ts,jsx,tsx,mdx}",
  ],
  theme: {
    extend: {
      colors: {
        "hvm-orange": "#E6A83C",
        "hvm-anthrazit": "#87888A",
        "hvm-mittelgrau": "#9C9D9F",
        "hvm-hellgrau": "#D7D8DA",
        "hvm-umrissgrau": "#ECECEC",
        "hvm-textschwarz": "#1A1A1A",
      },
    },
  },
  plugins: [],
};

export default config;
