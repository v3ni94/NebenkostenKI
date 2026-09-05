# Designsystem Smart Abrechnen: "Editorial Klarheit" (Synthese)

Verbindliche Arbeitsanweisung fuer das Ausrollen auf alle uebrigen Seiten (Formulare, Tabellen, Wizard, Admin). Basis ist Konzept A "Editorial Klarheit" (Sieger des Designwettbewerbs), ergaenzt um die Pflichtaenderungen der Jury und die uebernommenen Bausteine der Konzepte B (Anwendungs-Shell, Status-Icons, Schrittanzeige) und C (Kontextklasse `.hvm-dark`, Fokusring auf dunklen Flaechen).

Jedes Muster hat ein Klassenrezept, das ohne Rueckfrage uebernommen wird. Tokens liegen ausschliesslich in `resources/css/app.css` (`@theme`), Komponenten in `resources/views/components/hvm/`. Wer ein Muster braucht, das hier fehlt, baut es aus den vorhandenen Bausteinen zusammen und ergaenzt danach dieses Dokument. Keine Sonderloesungen je Seite.

## 1. Grundidee

Ruhige, hochwertige Editorial-Aesthetik: Die Leinwand ist ein sehr helles, warmes Off-White, Sektionen wechseln zwischen Off-White und Weiss, Tiefe entsteht durch Flaechenwechsel und hauchduenne Linien statt durch Schatten. Typografie traegt das Design: grosse Ueberschriften in Textschwarz mit leicht negativer Laufweite, begrenzte Zeilenlaenge, viel Luft. HVM Orange erscheint nur als Akzent (Primaerbutton, Akzentlinie, Schrittziffer, Fortschritt, Fokusring). Die Anwendung ist eine Shell mit linker Navigation ab `lg`; dunkle Flaechen (Footer, Datenschutz, Admin-Kopf) sind Graphit mit der Kontextklasse `.hvm-dark`.

## 2. Tokens

### 2.1 Farben (Tailwind-Klassen `bg-*`, `text-*`, `border-*`, `divide-*`)

| Token | Hex | Verwendung |
| --- | --- | --- |
| `hvm-orange` | #E6A83C | Primaerbutton, Akzentlinie, Schrittziffer, Fortschrittssegmente, Kennlinien-Segment, Icons auf Graphit. Nie Text. |
| `hvm-orange-dark` | #C98F2B | Hover des Primaerbuttons, Fokusring auf hellem Grund, Icons in Orange auf hellem Grund, Stempel "Beispiel". Nie Fliesstext. |
| `hvm-orange-soft` | #FBF1DE | Flaeche der Akzent-Pill, Icon-Kreise, aktiver Eintrag der Seitenleiste. |
| `hvm-orange-tint` | #F3D9A6 | Sanfte Orange-Flaeche (Balken, aktive Pills). |
| `hvm-canvas` | #FAF8F4 | Leinwand: `body`, ruhige Sektionen, Karten auf Weiss. |
| `hvm-canvas-deep` | #F3F0EA | Zweite Stufe: Hover, Zebra-Zeilen, neutrale Badges, offene Fortschrittssegmente. |
| `hvm-linie` | #E6E3DD | Hauchduenne Linien und Kartenrahmen. |
| `hvm-hellgrau` | #D7D8DA | Rahmen von Eingabefeldern und Sekundaerbuttons, Fliesstext auf Graphit. |
| `hvm-mittelgrau` | #9C9D9F | Hover-Rahmen, Platzhaltertext, Link-Unterstreichung auf Graphit, Mockup-Balken. |
| `hvm-umrissgrau` | #ECECEC | Bestand, weiterhin zulaessig fuer Flaechen. |
| `hvm-anthrazit` | #87888A | Nur Mockup-Balken und grosse Ueberschriften ab 24 px fett. Nicht fuer Fliesstext, nicht fuer Eyebrows, nicht auf Graphit. |
| `hvm-text-sekundaer` | #5C5C5E | Sekundaertext (Lead, Untertitel, Hilfetext, Meta, Eyebrow), AA auf Weiss (6,4:1) und Canvas (6,0:1). Ersetzt Anthrazit ueberall im Text. |
| `hvm-textschwarz` | #1A1A1A | Fliesstext, Ueberschriften, aktive Pill, Dark-Button. |
| `hvm-graphit` | #141414 | Dunkle Flaechen (`.hvm-dark`). |
| `hvm-graphit-soft` | #262626 | Karten und Linien auf Graphit. |
| `status-success`, `status-warning`, `status-error`, `status-info` (+ `-soft`) | unveraendert | Nur ueber `x-hvm.badge`, `x-hvm.alert`, `x-hvm.stat`, `variant="danger"`. |

Weitere Tokens: `shadow-hairline` (einzige Schattenstufe, 1 px Aussenlinie), `shadow-float` (nur schwebende Menues und Dialoge), `radius-card` (1.25 rem, informativ).

### 2.2 Typografie-Skala

Systemschrift `system-ui, 'Helvetica Neue', Arial, sans-serif`, keine Webfonts. Basis: `h1` bis `h4` sind `font-semibold`, `letter-spacing -0.01em`, `text-wrap: balance`, `overflow-wrap: anywhere`, Farbe Textschwarz.

| Rolle | Klassen |
| --- | --- |
| Display (Hero, Seitenkopf Website) | `text-4xl leading-[1.05] font-semibold tracking-tight sm:text-5xl lg:text-6xl` |
| Seitenkopf Anwendung (h1) | `text-3xl font-semibold tracking-tight sm:text-4xl` (Standard von `x-hvm.page-header`) |
| Abschnitt (h2) | `text-3xl font-semibold tracking-tight sm:text-4xl`; im Portal fuer Unterabschnitte `text-2xl` |
| Kartentitel (h3) | `text-lg font-semibold tracking-tight sm:text-xl` |
| Eyebrow | `text-xs font-semibold tracking-[0.12em] uppercase text-hvm-text-sekundaer` plus `<span class="inline-block h-px w-8 bg-hvm-orange">` |
| Lead | `text-base leading-relaxed text-hvm-text-sekundaer sm:text-lg`, `max-w-prose` |
| Fliesstext | `text-base leading-relaxed text-hvm-textschwarz`, `max-w-prose` |
| Sekundaer, Hilfetext, Meta | `text-sm leading-relaxed text-hvm-text-sekundaer` |
| Kennzahl | `text-3xl font-semibold tracking-tight tabular sm:text-5xl` |
| Preis | `text-5xl font-semibold tracking-tight tabular sm:text-6xl` |
| Betrag in Tabellen und Listen | `tabular whitespace-nowrap` (Klasse `.betrag` in `.hvm-table`) |

Lange Komposita in Ueberschriften bekommen `&shy;` an der Wortfuge (z. B. `Betriebskosten&shy;abrechnung`, `Mieter&shy;abrechnung`). Prueft ein Test den Text wortgleich, wird der Test auf einen Teilsatz ohne Trennstelle umgestellt (Beispiel `tests/Feature/SitePagesTest.php`, Startseite und Preisseite). Betraege niemals mit `&shy;` und nie ohne `whitespace-nowrap`.

### 2.3 Abstaende

- Seitenbreite Website: `mx-auto max-w-7xl px-4 sm:px-6 lg:px-8` (Header und Inhalt am selben Raster).
- Sektion Website: `py-20 lg:py-28`. Hero: `pt-16 pb-20 lg:pt-24 lg:pb-28`.
- Anwendung `main` (in der Shell): `w-full max-w-6xl px-4 py-10 sm:px-6 lg:px-10 lg:py-12`; Abstand zwischen Bloecken `mt-10`, zwischen Bereichen `mt-16`.
- Innerhalb einer Sektion: Ueberschrift zu Inhalt `mt-12` bis `mt-14`, Karten-Grid `gap-5` oder `gap-6`, Listen `space-y-4`, Formularfelder `space-y-6`.
- Karteninnen: `p-6 sm:p-7` (md), `p-4 sm:p-5` (sm), grosse Feature-Karten `p-7 sm:p-9`.

### 2.4 Radien

- Karten und Flaechen: `rounded-2xl`; grosse Feature-, Preis- und Formularkarten: `rounded-3xl`.
- Eingabefelder, Navigationseintraege, Auswahloptionen: `rounded-xl`.
- Buttons, Pills, Badges, Icon-Kreise: `rounded-full`; Icon-Kacheln in Kennzahlkarten `rounded-xl`.

### 2.5 Schatten und Bewegung

Kein Schlagschatten auf Karten. Erlaubt: `shadow-hairline` als zusaetzliche Kontur (Mockup), `shadow-float` fuer schwebende Elemente. Uebergaenge nur `transition-colors duration-150`; `prefers-reduced-motion` ist global beruecksichtigt (app.css).

## 3. Komponentenkatalog

| Komponente | Props | Verwendung |
| --- | --- | --- |
| `x-hvm.button` | `variant` primary, secondary, ghost, danger, dark, inverse; `size` sm, md, lg; `href`; `type` | Pillform, 44/48/56 px. Genau ein `primary` je Ansicht. `secondary` Nebenhandlung, `ghost` textnah, `danger` destruktiv (Entfernen, Loeschen, nie als Textlink), `inverse` weiss auf Graphit. Unter `sm` duerfen lange Beschriftungen umbrechen. Auf `.hvm-dark` passen sich secondary, ghost, danger und dark automatisch an. |
| `x-hvm.card` | `title`, `level`, `eyebrow`, `accent`, `tone` white, canvas, dark; `padding` md, sm, none; `kennlinie` | Standardflaeche. `tone="canvas"` auf weissen Sektionen. `tone="dark"` setzt `.hvm-dark` auf die Karte. `kennlinie` setzt das Markenband als Kartenkante. `padding="none"` plus `divide-y divide-hvm-linie` fuer Listen (Slot wird direkt gerendert). Innerhalb `.hvm-dark` wird jede helle Karte automatisch Graphit soft. |
| `x-hvm.badge` | `variant` neutral, akzent, info, success, warning, error; `icon` | Statusvarianten tragen automatisch ihr Symbol (Tabelle in Abschnitt 4.9); Text nennt immer die Bedeutung. `:icon="false"` unterdrueckt das Symbol (z. B. Marken-Pill). |
| `x-hvm.alert` | `variant`, `label`, `title` | Meldung mit Symbol und Statuswort, `rounded-2xl`, softe Flaeche. Darf unveraendert auf `.hvm-dark` stehen. |
| `x-hvm.section-heading` | `title`, `eyebrow`, `level`, `lead`, `align`, `size` sm, md, lg | Abschnitts- und Seitenkopf. Passt sich auf `.hvm-dark` an. |
| `x-hvm.page-header` | `title`, `eyebrow`, `lead`, `size`, `back`, `backLabel`; Slots default, `actions` | Seitenkopf der Anwendung: h1 links, Buttons rechts, mobil gestapelt, optional Zurueck-Link. |
| `x-hvm.stepper` (neu) | `steps` (Liste aus `label`, `state` done/current/open, `href`, `note`), `label`, `compact`; Slot | Wizard-Fortschritt: ein Segment je Schritt, Zustand im Text, Zeile "Schritt X von N". |
| `x-hvm.step` | `number`, `title`, `level`, `note` | Erklaerende Schrittfolge (Website). Orange Ziffernkreis 44 px. |
| `x-hvm.faq-item` | `question`, `open`, `level` | Frage mit rundem Chevron-Knopf. |
| `x-hvm.field` | `name`, `label`, `id`, `type` (text, email, password, number, date, tel, url, textarea, select, checkbox, radio-group, checkbox-group), `value`, `checked`, `options`, `inline`, `hint`, `required`, `autocomplete`, `optional`, `errorKey`; weitere Attribute gehen ans Feld | Label, Hilfetext, Eingabefeld (`.hvm-input`, 48 px) oder Auswahloptionen (`.hvm-choice`, 44 px) und Fehleranzeige mit `aria-invalid`, `aria-describedby`. Gruppen als `fieldset`/`legend`, `old()` fuer Arrays. |
| `x-hvm.stat` | `label`, `value`, `variant`, `icon`, `note`, `href` | Kennzahlkarte mit Icon-Kachel in Statusfarbe, Label mit fester Mindesthoehe (zwei Zeilen), Ziffer in Textschwarz. |
| `x-hvm.empty-state` | `title`, `icon`, `level`; Slot `action` | Gestrichelte Karte mit Icon, Titel, Satz und Handlung. |
| `x-hvm.list-row` | `title`, `subtitle`, `level`, `href`, `stacked`; Slots default, `actions` | Listenzeile. Standard: Inhalt links, Buttons rechts. `stacked`: Titelzeile mit Buttons, darunter Inhalt ueber volle Breite. |
| `x-hvm.icon` | `name`, `class` | Inline-SVG, 24er Raster, `currentColor`, immer `aria-hidden`. Namen siehe Kopf von `icon.blade.php`. |
| `x-hvm.legal-placeholder-banner`, `x-hvm.logo` | unveraendert | Rechtstext-Warnung bleibt sichtbar; Logo aus `public/ci`. |

CSS-Klassen: `.hvm-kennlinie` (3 px Markenband), `.hvm-dark` (dunkle Kontextflaeche), `.hvm-nav-item` (Seitenleiste), `.hvm-input`, `.hvm-check`, `.hvm-choice`, `.hvm-table`, `.hvm-table-zebra`, `.hvm-table-stack`, `.betrag`, `.hvm-prose`, `.tabular`.

## 4. Layoutmuster (Klassenrezepte)

### 4.1 Anwendungs-Shell (layouts/portal.blade.php)

Ab `lg`: Grid `lg:grid-cols-[17rem_minmax(0,1fr)]`, linke Seitenleiste `sticky top-0 h-screen` in Weiss mit Logo, Markenzusatz (nie gekuerzt, zweizeilig), Navigation (`.hvm-nav-item` mit Icon, aktiver Eintrag ueber `aria-current="page"`), Kontoblock und Abmelden unten. Unter `lg`: weisse Kopfleiste mit Marke und Abmelden, darunter Pill-Navigation `flex flex-wrap gap-1` (alle Eintraege sichtbar, kein horizontales Scrollen). Neue Bereiche werden ausschliesslich in `$navigation` des Layouts eingetragen (`route`, `label`, `icon`). Seiten ohne Navigation (`@section('ohne_navigation')`) bekommen die schmale Kopfleiste.

### 4.2 Seitenkopf Website

```blade
<section class="bg-hvm-canvas">
  <div class="mx-auto max-w-7xl px-4 pt-16 pb-20 sm:px-6 lg:px-8 lg:pt-24 lg:pb-28">
    <x-hvm.badge variant="akzent" :icon="false">Bereich</x-hvm.badge>
    <h1 class="mt-6 text-4xl leading-[1.05] font-semibold tracking-tight text-hvm-textschwarz sm:text-5xl lg:text-6xl">Titel mit Kom&shy;positum</h1>
    <p class="mt-7 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer sm:text-xl">Lead</p>
  </div>
</section>
```

### 4.3 Seitenkopf Anwendung (Portal, Wizard, Admin)

```blade
<x-hvm.page-header eyebrow="Bereich" title="Titel" lead="Ein Satz.">
  <x-slot:actions><x-hvm.button href="..." variant="primary">Anlegen</x-hvm.button></x-slot:actions>
</x-hvm.page-header>
```

Wizard-Seiten: `eyebrow="Schritt 3 von 12"`, direkt darunter `<x-hvm.stepper class="mt-8" :steps="..." />`. Unterabschnitt: `<p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Eyebrow</p><h2 class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Titel</h2>` in `flex flex-wrap items-end justify-between gap-3`, Inhalt danach `mt-6`.

### 4.4 Abschnitt Website

Sektionen wechseln Canvas und Weiss; weisse Sektionen tragen `border-y border-hvm-linie`.

```blade
<section class="bg-hvm-canvas">  {{-- oder: border-y border-hvm-linie bg-white --}}
  <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
    <x-hvm.section-heading eyebrow="Eyebrow" title="Titel" lead="Lead" />
    <div class="mt-14 grid gap-6 lg:grid-cols-2">...</div>
  </div>
</section>
```

Zweispaltig (Text links, Inhalt rechts): `grid gap-12 lg:grid-cols-12`, Text `lg:col-span-5`, Inhalt `lg:col-span-7`. Grids, die auf Mobil einspaltig sind, tragen `grid-cols-1` und die Kinder `min-w-0` (sonst weitet ein Kind den Viewport auf).

### 4.5 Karte hell und dunkel

- Auf Canvas: `<x-hvm.card>` (Weiss). Auf Weiss: `<x-hvm.card tone="canvas">`.
- Manuell: `rounded-2xl border border-hvm-linie bg-white p-6 sm:p-7`.
- Feature- oder Formularkarte mit Kennlinie: `<x-hvm.card :kennlinie="true" class="rounded-3xl">` oder manuell `overflow-hidden rounded-3xl border border-hvm-linie bg-white` plus `<div class="hvm-kennlinie" aria-hidden="true"></div>` als erstes Kind.
- Dunkle Karte: `<x-hvm.card tone="dark">` (setzt `.hvm-dark`, Titel Weiss, Text Hellgrau, Buttons und Badges passen sich an).
- Karte innerhalb einer `.hvm-dark`-Sektion: normale `<x-hvm.card>` verwenden, sie wird automatisch `border-hvm-graphit-soft bg-hvm-graphit-soft/40`.
- Icon-Kreis: `flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark`; Icon-Kachel (Kennzahl): `h-10 w-10 rounded-xl` in `bg-status-*-soft text-status-*`.
- Innere Hervorhebung: `rounded-2xl bg-hvm-canvas p-4` (auf weisser Karte).

### 4.6 Formular

```blade
<div class="mx-auto max-w-md">                      {{-- viele Felder: max-w-2xl --}}
  <x-hvm.section-heading eyebrow="Bereich" title="Titel" lead="Satz." />
  <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
    <form method="POST" action="..." class="space-y-6 p-6 sm:p-8">
      @csrf
      <x-hvm.field name="email" label="E-Mail-Adresse" type="email" autocomplete="email" :required="true" />
      <x-hvm.field name="notiz" label="Notiz" type="textarea" hint="Optional." :optional="true" />
      <x-hvm.field name="art" label="Art" type="select"><option value="">Bitte wählen</option>...</x-hvm.field>
      <x-hvm.field name="remember" label="Angemeldet bleiben" type="checkbox" value="1" />
      <x-hvm.field name="schluessel" label="Umlageschlüssel" type="radio-group" :required="true"
                   :options="['flaeche' => 'Wohnfläche', 'personen' => ['label' => 'Personentage', 'hint' => 'Nicht bei Leerstand.']]" />
      <x-hvm.field name="dokumente" label="Vorhandene Unterlagen" type="checkbox-group" :inline="true"
                   :options="['hausgeld' => 'Hausgeldabrechnung', 'grundsteuer' => 'Grundsteuerbescheid']" />
      <div class="flex flex-wrap gap-3">
        <x-hvm.button type="submit" variant="primary" size="lg">Speichern</x-hvm.button>
        <x-hvm.button href="..." variant="secondary" size="lg">Abbrechen</x-hvm.button>
      </div>
    </form>
  </x-hvm.card>
</div>
```

Regeln: Mehrspaltige Felder `grid gap-6 sm:grid-cols-2`. Feldgruppen mit Zwischenueberschrift `<fieldset class="space-y-6"><legend class="text-lg font-semibold tracking-tight text-hvm-textschwarz">`. `id`, `name`, `type`, `required`, `autocomplete` bleiben wie bisher (Tests). Fehler kommen automatisch aus `$errors` (`errorKey` fuer Punkt-Notation, z. B. `errorKey="einheiten.0.flaeche"`). Kein `@error` innerhalb eines Komponenten-Tags (Blade kompiliert es dort nicht). Bestehende `<input>` ohne Komponente erhalten `class="hvm-input"`, Auswahloptionen `<label class="hvm-choice"><input class="hvm-check"> Text</label>`; Fehlermeldung `<p id="{id}-fehler" class="mt-2 text-sm font-medium text-status-error">`. Datei-Uploads: Label plus `<input type="file" class="hvm-input">`.

### 4.7 Tabelle (Desktop klassisch, mobil gestapelt)

```blade
<div class="overflow-hidden rounded-3xl border border-hvm-linie bg-white">
  <table class="hvm-table hvm-table-zebra hvm-table-stack text-base">
    <caption class="sr-only">Beschreibung</caption>
    <thead><tr><th scope="col">Position</th><th scope="col">Rechenweg</th><th scope="col" class="betrag">Betrag</th></tr></thead>
    <tbody>
      <tr>
        <th scope="row" class="font-medium">Zeilentitel</th>
        <td class="text-hvm-text-sekundaer" data-label="Rechenweg">Text</td>
        <td class="betrag" data-label="Betrag">24,90 EUR</td>
      </tr>
    </tbody>
  </table>
</div>
```

Kopfzeile: Versalien in Sekundaerfarbe mit 2 px Orange-Linie (kein oranger Balken). Unter 640 px wird jede Zeile ein Block; der Zeilenkopf ist die Ueberschrift, jede Zelle zeigt ihr `data-label`. Jede `td` bekommt `data-label` mit dem Spaltentitel. Betraege `class="betrag"` (rechtsbuendig, kein Umbruch). Tabellen mit mehr als fuenf Spalten (Admin): auf Mobil ebenfalls `.hvm-table-stack`; nur wenn eine Tabelle zwingend als Raster lesbar bleiben muss (Vergleichsmatrix), `overflow-x-auto` mit `tabindex="0"` und `aria-label` am Scrollcontainer.

### 4.8 Liste und Listenzeile

Kurze Eintraege in einer Karte:

```blade
<x-hvm.card padding="none" class="divide-y divide-hvm-linie">
  @foreach ($eintraege as $e)
    <x-hvm.list-row :title="$e->label" :subtitle="$e->adresse" :href="route(...)">
      @include('portal.partials.status', ['status' => ...])
      <x-slot:actions><x-hvm.button href="..." variant="secondary" size="sm">Öffnen</x-hvm.button></x-slot:actions>
    </x-hvm.list-row>
  @endforeach
</x-hvm.card>
```

Eintraege mit viel Inhalt (Objekte): je Eintrag eine eigene `<x-hvm.card padding="none">` in `space-y-4` mit `<x-hvm.list-row :stacked="true">`; der Slot enthaelt `grid gap-5 border-t border-hvm-linie pt-5 lg:grid-cols-12 lg:gap-8` mit Details `lg:col-span-4` und Status `lg:col-span-8`, sodass die Karte auf Desktop keine Leerflaeche hat. Destruktive Handlung immer `variant="danger"` mit Icon `trash`. Aufzaehlungen in Statusdetails: `flex gap-2` mit `<span class="mt-2 h-1.5 w-1.5 rounded-full bg-hvm-text-sekundaer">`. Checklisten: `flex gap-3` mit `<x-hvm.icon name="check" />` in `text-hvm-orange-dark`.

### 4.9 Statuskarte, Badge und Statuszuordnung

```blade
<div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
  <x-hvm.stat label="Bitte prüfen" :value="3" variant="warning" />
</div>
```

Variante ueber `PortalStatusCategory::variant($kategorie)`. Verbindliche Zuordnung Kategorie, Variante, Symbol (gilt fuer Badge, Stat und Statusbox):

| Kategorie | Variante | Icon |
| --- | --- | --- |
| Erledigt | success | check-circle |
| Bitte pruefen | warning | eye |
| Fehlt noch | info | inbox |
| Blockiert die Abrechnung | error | alert |

Statusbox mit Hinweis und Details: `portal/partials/status.blade.php` (Badge mit Icon und Text, Satz, Detailliste). Nie nur Farbe.

### 4.10 Schrittanzeige (Wizard)

```blade
<x-hvm.stepper class="mt-8" :steps="[
  ['label' => 'Konto und Abrechnungsjahr', 'state' => 'done', 'href' => route(...)],
  ['label' => 'Unterlagen hochladen', 'state' => 'current'],
  ['label' => 'Automatische Analyse', 'state' => 'open'],
]">
  Jeder Schritt speichert sofort. Sie können jederzeit unterbrechen und später ohne Datenverlust fortfahren.
</x-hvm.stepper>
```

Erledigte und aktuelle Segmente Orange, offene Canvas deep; Zustand zusaetzlich im Text ("Erledigt:", "Aktuell:", sr-only) und in der Zeile "Schritt X von N". Bei zwoelf Schritten `:compact="true"` (nur Segmente plus Zeile). Das bestehende Partial `portal/wizard/partials/fortschritt.blade.php` wird beim Ausrollen durch `x-hvm.stepper` ersetzt (`WizardProgress::bar()` liefert `nummer()`, `label()`, `aktuell`, `erreichbar`, `kategorie`; `state` = `aktuell` ? current : (Kategorie Erledigt ? done : open), `note` = Kategorie). Erklaerende Schrittfolgen auf der Website: `<ol class="divide-y divide-hvm-linie">` mit `<li class="py-7 first:pt-0 last:pb-0"><x-hvm.step number="1" title="..." /></li>`.

### 4.11 Leerzustand

```blade
<x-hvm.empty-state icon="house" title="Noch kein Objekt">
  <p>Sie haben noch kein Objekt angelegt.</p>
  <x-slot:action><x-hvm.button href="..." variant="primary">Objekt anlegen</x-hvm.button></x-slot:action>
</x-hvm.empty-state>
```

### 4.12 Buttons

Genau ein Primaerbutton je Ansicht (im Seitenkopf oder als Formular-Submit), links in der Buttonreihe. Reihenfolge: primary, secondary, ghost oder danger. Zeilenhandlungen in Listen sind `secondary size="sm"`. Icons in Buttons `h-4 w-4` (sm, md) oder `h-5 w-5` (lg), Pfeil rechts, Plus links. Auf Graphit: `variant="inverse"` fuer die Hauptbewegung, `secondary` und `ghost` passen sich automatisch an. Formular-Submit auf schmalen Karten `size="lg" class="w-full"`. Beschriftungen in Kopfleisten `whitespace-nowrap`.

### 4.13 Badges

Statusbadge immer mit Kategorietext (`<x-hvm.badge :variant="$status->variante()">{{ $status->kategorie }}</x-hvm.badge>`), das Symbol kommt automatisch. Neutrale Etiketten (Anzahl, Typ) `variant="neutral"`. Marken-Pill (Claim, Bereichsname im Seitenkopf) `variant="akzent" :icon="false"`.

### 4.14 Meldungen

`x-hvm.alert` direkt unter dem Seitenkopf oder ueber dem betroffenen Formular, `mb-8` in der Anwendung. Fehlerliste als `list-disc pl-5`. Feldfehler ueber `x-hvm.field`. Keine Meldung nur ueber Farbe, das Statuswort bleibt.

### 4.15 Dunkle Flaechen und Admin-Kopfleiste

- Container: `<section class="hvm-dark">` (oder `<footer class="hvm-dark">`). Darin `x-hvm.section-heading`, `x-hvm.card`, `x-hvm.button`, `x-hvm.badge`, `x-hvm.stat`, `x-hvm.field`, `x-hvm.list-row`, `x-hvm.empty-state`, `x-hvm.stepper` ohne weitere Angaben verwenden; sie invertieren sich ueber `[.hvm-dark_&]:`-Varianten.
- Freier Text auf Graphit: Ueberschriften `text-white`, Fliesstext `text-hvm-hellgrau`, Eyebrow `text-hvm-hellgrau`, Links `text-white underline decoration-hvm-mittelgrau underline-offset-4 hover:decoration-white`. Kein Anthrazit, kein Mittelgrau, kein Sekundaertext-Token auf Graphit.
- Orange auf Graphit nur fuer Icons (`text-hvm-orange`), Segmente und die Kennlinie. Primaerbutton bleibt Orange.
- Jede dunkle Sektion traegt die Kennlinie oben (`<div class="hvm-kennlinie" aria-hidden="true"></div>` als erstes Kind) oder, beim Footer, unten.
- Fokus auf Graphit: automatisch Orange mit weisser Innenkante (app.css).
- Admin-Kopfleiste (layouts/admin.blade.php beim Ausrollen): `<header class="hvm-dark">` mit Kennlinie, `<span class="text-xs font-semibold tracking-[0.12em] text-hvm-orange uppercase">Interner Bereich</span>` (Versalien-Label, kein Fliesstext), Titel `text-lg font-semibold text-white`, Navigation als `.hvm-nav-item`-Liste auf Weiss unter der Kopfleiste oder als Seitenleiste nach Muster 4.1, Blocker-Zaehler als `<x-hvm.badge variant="akzent">`. Arbeitsbereich `bg-hvm-canvas`.

### 4.16 Kopfleiste Website

Container `mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8`, Marke links (Logo `h-9`, Name `text-base`, Markenzusatz `text-[11px] leading-[1.25] xl:max-w-[11rem]` zweizeilig, nie `truncate`), Pill-Navigation ab `xl` (`text-[13px] px-3 py-2.5 whitespace-nowrap`), rechts `Anmelden` (ghost) und `Kostenlos starten` (primary), darunter Menue-Knopf. Nachgemessen: 1280 bis 1920 px eine Zeile mit 151 px Reserve fuer einen siebten Menuepunkt.

## 5. Regeln fuer Orange

Erlaubt: Primaerbutton (Flaeche), Eyebrow-Strich (`h-px w-8`), Akzentlinie in Karten (`h-1 w-10 rounded-full`), Schrittziffer (Kreis), Fokusring, Fortschrittssegmente und -balken, Kennlinien-Segment, Icons in `text-hvm-orange-dark` auf hellem Grund und `text-hvm-orange` auf Graphit, Icon-Kreise in `bg-hvm-orange-soft`, aktiver Eintrag der Seitenleiste (Orange soft plus 3 px Kante), Stempel "Beispiel", Tabellenkopf-Unterlinie, Link-Unterstreichung `decoration-hvm-orange decoration-2`, Versalien-Label "Interner Bereich" im Admin.

Nicht erlaubt: Fliesstext, Ueberschriften, Betraege oder Eyebrow-Texte in Orange (Kontrast 2,8:1), orange Flaechen hinter Text (ausser Button), mehr als ein Primaerbutton je Ansicht, orange Tabellenkoepfe, orange Rahmen um Karten.

## 6. Verbotsliste

- Kein Anthrazit (`text-hvm-anthrazit`) als Fliesstext, Hilfetext, Meta oder Eyebrow. Ersatz: `text-hvm-text-sekundaer` (Sekundaertext) oder `text-hvm-textschwarz` (Inhalt). Arbeitsvorrat: `grep -rn "text-hvm-anthrazit" resources/views`.
- Kein Orange als Text (siehe Abschnitt 5).
- Kein `truncate`, `line-clamp` oder `overflow-hidden` an Pflichttexten (Markenzusatz, Betreiberangaben, Rechtstext-Warnung, Statuskategorie, Betraege). Pflichttexte brechen um oder werden kleiner, aber vollstaendig gezeigt.
- Keine externen Ressourcen (Skripte, Fonts, Bilder, iframes), keine Base64-Bilder, keine Webfonts. Illustrationen nur als Inline-SVG (`x-hvm.icon`) oder CSS/HTML-Mockup mit erkennbar runden Beispielwerten und Beschriftung "Beispiel".
- Keine Aenderung der Inline-Bloecke im `<head>` (CSP-Hash), keine weiteren Inline-Skripte; `style=""` nur in begruendeten Ausnahmen.
- Kein Schlagschatten auf Karten, keine Verlaeufe, keine neuen Buntfarben; jeder neue Token nur als Tint oder Shade im `@theme`-Block mit Kommentar.
- Kein `whitespace-nowrap` an Texten, die auf 390 px breiter als der Viewport werden koennen (Ausnahme: Betraege, Buttons ab `sm`, Kopfleisten).
- Keine Gedankenstriche in sichtbaren Texten. Sichtbare Texte bleiben wortgleich (Tests); `&shy;` nur mit angepasstem Test.
- Keine Status- oder Vermieterangaben ueber mehrere Elemente verteilen, wenn ein Test die Zeichenkette zusammenhaengend prueft.
- Kein Status nur ueber Farbe: immer Text plus Symbol.
- Kein `@error` innerhalb eines Komponenten-Tags; gebundene `aria-*`-Attribute mit `null` statt leerer Zeichenkette.
- Keine Sondervarianten fuer dunkle Flaechen; `.hvm-dark` und die vorhandenen `[.hvm-dark_&]:`-Varianten verwenden.

## 7. Pruefliste je Seite (vor jedem Commit)

1. Bestand lesen, Muster aus Abschnitt 4 waehlen, sichtbare Texte wortgleich uebernehmen.
2. `cd <WORKTREE> && npm run build` fehlerfrei.
3. Server starten und die Seite bei 1366 px und 390 px aufnehmen: `node <SCRATCH>/design/shots.js http://127.0.0.1:<PORT> <RUN>/shots full`. Der Report muss fuer jede Aufnahme `scrollWidth 390 / innerWidth 390` zeigen; das Skript misst gegen einen festen 390-px-Viewport ohne Mobil-Emulation, jeder Ueberlauf ist ein echter Fehler. Bei Ueberlauf das verursachende Element suchen (Elemente mit `getBoundingClientRect().right > 390`), beheben, erneut bauen und messen.
4. Screenshots mit dem Read-Tool ansehen: kein Wortbruch in Ueberschriften und Betraegen, Markenzusatz vollstaendig, alle vier Navigationseintraege sichtbar, ein Primaerbutton, Status mit Icon und Text, kein Anthrazit im Fliesstext, keine leere Kartenhaelfte auf Desktop.
5. Kontraste neuer Text-Flaechen-Paare pruefen (AA: 4,5:1 normal, 3,0:1 ab 24 px oder 19 px fett) und im Commit nennen.
6. Tastatur: Fokusring sichtbar (hell Orange dunkel, auf Graphit Orange mit weisser Innenkante), Reihenfolge sinnvoll, Touchziele mindestens 44 px.
7. `cd <WORKTREE> && vendor/bin/pint --dirty` bestanden.
8. `cd <WORKTREE> && php artisan test tests/Feature/SitePagesTest.php tests/Feature/FehlerseitenTest.php tests/Feature/Auth tests/Feature/Portal tests/Feature/Admin tests/Feature/Wizard tests/Feature/Upload` gruen; bei Aenderungen an Rechtstexten oder PDFs die volle Suite.
9. Server stoppen, Commit mit den vorgegebenen Trailern, nicht pushen, nicht mergen.

## 8. Do und Don't (Kurzfassung)

Do

- Textschwarz fuer Fliesstext und Ueberschriften, `hvm-text-sekundaer` fuer Lead, Untertitel, Hilfetext, Meta und Eyebrow.
- Zeilenlaenge mit `max-w-prose` begrenzen, Sektionen grosszuegig (`py-20 lg:py-28`).
- Flaechen wechseln: Weiss auf Canvas, Canvas auf Weiss, Graphit (`.hvm-dark`) als Ruhepunkt mit Kennlinie.
- Buttons und Pills rund, Karten `rounded-2xl` oder `rounded-3xl`, Eingabefelder `rounded-xl`.
- Status immer als Text plus Symbol nach der Tabelle in 4.9.
- Touchziele mindestens 44 px (`min-h-11`, `.hvm-choice`), Eingabefelder 48 px.
- Icons nur aus `x-hvm.icon`, immer dekorativ.
- Mobile first: `grid-cols-1` mit `min-w-0`-Kindern, `grid-cols-2` fuer Kennzahlen, Buttonreihen `flex flex-wrap gap-3`, Tabellen `.hvm-table-stack`.
- Beispielzahlen in Mockups rund und als "Beispiel" beschriftet.

Don't

- Siehe Verbotsliste in Abschnitt 6.
