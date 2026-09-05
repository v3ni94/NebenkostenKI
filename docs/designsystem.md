# Designsystem Smart Abrechnen, Konzept A "Editorial Klarheit"

Arbeitsgrundlage fuer das Ausrollen auf alle Seiten (Formulare, Tabellen, Wizard, Admin). Jedes Muster hat ein Klassenrezept, das ohne Rueckfrage uebernommen werden kann. Tokens liegen ausschliesslich in `resources/css/app.css` (`@theme`), Komponenten in `resources/views/components/hvm/`.

## 1. Grundidee

Ruhige, hochwertige Editorial-Aesthetik wie bei modernen Fintech- und Verwaltungsprodukten: Die Leinwand ist ein sehr helles, warmes Off-White, Sektionen wechseln zwischen Off-White und Weiss, und Tiefe entsteht durch Flaechenwechsel statt durch Schatten. Typografie traegt das Design: grosse Ueberschriften in Textschwarz mit leicht negativer Laufweite, klare Groessenskala, begrenzte Zeilenlaenge und viel Luft. Orange erscheint nur als feine Akzentlinie, als Pill, als Ziffer in der Schrittfolge und als primaerer Button.

## 2. Tokens

### Farben (Tailwind-Klassen `bg-*`, `text-*`, `border-*`, `divide-*`)

| Token | Hex | Verwendung |
| --- | --- | --- |
| `hvm-orange` | #E6A83C | Primaerbutton, Akzentlinie, Schrittziffer, Fokusring, Balken. Nie Fliesstext. |
| `hvm-orange-dark` | #C98F2B | Hover des Primaerbuttons, Icons in Orange auf hellem Grund, Stempel. |
| `hvm-orange-soft` | #FBF1DE | Flaeche der Akzent-Pill, Icon-Kreise. |
| `hvm-orange-tint` | #F3D9A6 | Sanfte Orange-Flaeche (Balken, aktive Pills), neu. |
| `hvm-canvas` | #FAF8F4 | Leinwand: `body`, ruhige Sektionen, Karten auf Weiss, neu. |
| `hvm-canvas-deep` | #F3F0EA | Zweite Stufe: Hover, Zebra-Zeilen, neutrale Badges, neu. |
| `hvm-linie` | #E6E3DD | Hauchduenne Linien und Kartenrahmen, neu. |
| `hvm-hellgrau` | #D7D8DA | Rahmen von Eingabefeldern und Sekundaerbuttons, Text auf Graphit. |
| `hvm-mittelgrau` | #9C9D9F | Hover-Rahmen, Platzhaltertext, neutraler Statuspunkt. |
| `hvm-umrissgrau` | #ECECEC | Bestand, weiterhin zulaessig fuer Flaechen. |
| `hvm-anthrazit` | #87888A | Nur Eyebrow-Labels auf Graphit, grosse Ueberschriften, Mockup-Balken. Nicht fuer Fliesstext. |
| `hvm-text-sekundaer` | #5C5C5E | Sekundaertext (Lead, Untertitel, Hilfetext), AA auf Weiss und Canvas, neu. |
| `hvm-textschwarz` | #1A1A1A | Fliesstext, Ueberschriften, aktive Pill, Dark-Button. |
| `hvm-graphit` | #141414 | Dunkle Flaechen (Footer, Datenschutz-Sektion), neu. |
| `hvm-graphit-soft` | #262626 | Karten und Linien auf Graphit, neu. |
| `status-success`, `status-warning`, `status-error`, `status-info` (+ `-soft`) | unveraendert | Nur ueber `x-hvm.badge`, `x-hvm.alert`, `x-hvm.stat`. |

Weitere Tokens: `shadow-hairline` (einzige Schattenstufe, 1 px Aussenlinie), `shadow-float` (nur schwebende Menues und Dialoge), `radius-card` (1.25 rem, informativ).

### Typografie-Skala

Systemschrift `system-ui, 'Helvetica Neue', Arial, sans-serif`, keine Webfonts. Basis: `h1` bis `h4` sind `font-semibold`, `tracking-tight` (bzw. `-0.01em` global), `text-wrap: balance`, Farbe Textschwarz.

| Rolle | Klassen |
| --- | --- |
| Display (Hero, Seitenkopf Website) | `text-4xl leading-[1.05] font-semibold tracking-tight sm:text-5xl lg:text-6xl` |
| Seitenkopf Portal (h1) | `text-3xl font-semibold tracking-tight sm:text-4xl` (Standard von `x-hvm.section-heading`) |
| Abschnitt (h2) | `text-3xl font-semibold tracking-tight sm:text-4xl`; im Portal fuer Unterabschnitte `text-2xl` |
| Kartentitel (h3) | `text-lg font-semibold tracking-tight sm:text-xl` |
| Eyebrow | `text-xs font-semibold tracking-[0.12em] uppercase text-hvm-text-sekundaer` plus `<span class="inline-block h-px w-8 bg-hvm-orange">` |
| Lead | `text-base leading-relaxed text-hvm-text-sekundaer sm:text-lg`, `max-w-prose` |
| Fliesstext | `text-base leading-relaxed text-hvm-textschwarz`, `max-w-prose` |
| Sekundaer, Hilfetext | `text-sm leading-relaxed text-hvm-text-sekundaer` |
| Kennzahl | `text-4xl font-semibold tracking-tight tabular sm:text-5xl` (Klasse `tabular` = Tabellenziffern) |
| Preis | `text-5xl font-semibold tracking-tight tabular sm:text-6xl` |

Lange Komposita in Ueberschriften bekommen `&shy;`, ausser wenn ein Test den Text wortgleich prueft.

### Abstaende

- Seitenbreite: `mx-auto max-w-7xl px-4 sm:px-6 lg:px-8`.
- Sektion Website: `py-20 lg:py-28` (80 / 112 px). Hero: `pt-16 pb-20 lg:pt-24 lg:pb-28`.
- Portal `main`: `py-10 lg:py-14`; Abstand zwischen Bloecken `mt-10`, zwischen Bereichen `mt-16`.
- Innerhalb einer Sektion: Ueberschrift zu Inhalt `mt-12` bis `mt-14`, Karten-Grid `gap-5` oder `gap-6`, Listen `space-y-4`, Formularfelder `space-y-6`.
- Karteninnen: `p-6 sm:p-7` (md), `p-5` (sm), grosse Feature-Karten `p-7 sm:p-9`.

### Radien

- Karten und Flaechen: `rounded-2xl`; grosse Feature-, Preis- und Formularkarten: `rounded-3xl`.
- Eingabefelder, Menuepunkte mobil: `rounded-xl`.
- Buttons, Pills, Badges, Navigationspunkte, Icon-Kreise: `rounded-full`.
- Innere Flaechen in Karten (Summenzeile, Hinweisfeld): `rounded-2xl`.

### Schatten

Kein Schlagschatten auf Karten. Tiefe entsteht durch Flaechenwechsel (Weiss auf Canvas, Canvas auf Weiss, Graphit als Kontrast). Erlaubt: `shadow-hairline` als zusaetzliche Kontur (z. B. Mockup), `shadow-float` fuer schwebende Elemente.

## 3. Komponentenkatalog

| Komponente | Props | Verwendung |
| --- | --- | --- |
| `x-hvm.button` | `variant` primary, secondary, ghost, dark, inverse; `size` sm, md, lg; `href`; `type` | Pillform. Genau ein `primary` je Ansicht (wichtigste Handlung). `secondary` fuer Nebenhandlungen, `ghost` fuer textnahe Handlungen (z. B. Entfernen), `inverse` auf Graphit, `dark` als zweiter starker Akzent. |
| `x-hvm.card` | `title`, `level`, `eyebrow`, `accent`, `tone` white, canvas, dark; `padding` md, sm, none | Standardflaeche. `tone="canvas"` auf weissen Sektionen, Standard Weiss auf Canvas. `padding="none"` plus `divide-y divide-hvm-linie` fuer Listen. `accent` setzt eine kurze orange Linie. |
| `x-hvm.badge` | `variant` neutral, akzent, info, success, warning, error; `dot` | Statusvarianten tragen automatisch einen Punkt; Text nennt immer die Bedeutung. |
| `x-hvm.alert` | `variant`, `label`, `title` | Meldung mit Symbol und Statuswort, `rounded-2xl`, softe Flaeche, feiner Rahmen. |
| `x-hvm.section-heading` | `title`, `eyebrow`, `level`, `lead`, `align`, `size` sm, md, lg | Abschnitts- und Seitenkopf. `size="lg"` fuer Website-Seitenkoepfe. |
| `x-hvm.page-header` (neu) | `title`, `eyebrow`, `lead`; Slot `actions` | Portal-Seitenkopf: h1 links, Buttons rechts, mobil gestapelt. |
| `x-hvm.step` | `number`, `title`, `level`, `note` | Orange Ziffernkreis (44 px), Titel, Text in Sekundaerfarbe. Mehrere Schritte als `<ol class="divide-y divide-hvm-linie">` mit `<li class="py-7 first:pt-0 last:pb-0">`. |
| `x-hvm.faq-item` | `question`, `open`, `level` | Frage mit rundem Chevron-Knopf, Trennlinie `hvm-linie`. |
| `x-hvm.field` (neu) | `name`, `label`, `id`, `type` (text, email, password, number, date, textarea, select, ...), `value`, `hint`, `required`, `autocomplete`, `optional`; weitere Attribute gehen ans Feld | Label, Hilfetext, Eingabefeld (`.hvm-input`, min-h-12) und Fehleranzeige mit `aria-invalid` und `aria-describedby`. Fuer `select` und `textarea` liefert der Slot den Inhalt. |
| `x-hvm.stat` (neu) | `label`, `value`, `variant`, `note`, `href` | Kennzahlkarte mit Statuspunkt, grosser Ziffer und Beschriftung. |
| `x-hvm.empty-state` (neu) | `title`, `icon`, `level`; Slot `action` | Gestrichelte Karte in Canvas mit Icon, Titel, Satz und Handlung. |
| `x-hvm.list-row` (neu) | `title`, `subtitle`, `level`, `href`; Slot `actions` | Listenzeile: Titel und Untertitel links, Status im Slot, Buttons rechts, mobil gestapelt. |
| `x-hvm.icon` (neu) | `name`, `class` | Inline-SVG (24er Raster, 1,75 px Strich, `currentColor`), immer `aria-hidden`. Namen: upload, document, house, key, shield, euro, check, check-circle, x-circle, info, warning, arrow-right, plus, clock, list, user, lock, mail, trash, search, sparkle, layers, calendar. |
| `x-hvm.legal-placeholder-banner`, `x-hvm.logo` | unveraendert | Rechtstext-Warnung bleibt sichtbar; Logo aus `public/ci`. |

CSS-Klassen: `.hvm-kennlinie` (3 px Markenband), `.hvm-input`, `.hvm-check`, `.hvm-table`, `.hvm-table-zebra`, `.hvm-prose`, `.tabular`.

## 4. Layoutmuster (Klassenrezepte)

### Seitenkopf Website

```blade
<section class="bg-hvm-canvas">
  <div class="mx-auto max-w-7xl px-4 pt-16 pb-20 sm:px-6 lg:px-8 lg:pt-24 lg:pb-28">
    <x-hvm.badge variant="akzent">Bereich</x-hvm.badge>
    <h1 class="mt-6 text-4xl leading-[1.05] font-semibold tracking-tight text-hvm-textschwarz sm:text-5xl lg:text-6xl">Titel</h1>
    <p class="mt-7 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer sm:text-xl">Lead</p>
  </div>
</section>
```

### Seitenkopf Portal

```blade
<x-hvm.page-header eyebrow="Bereich" title="Titel" lead="Ein Satz.">
  <x-slot:actions><x-hvm.button href="..." variant="primary">Anlegen</x-hvm.button></x-slot:actions>
</x-hvm.page-header>
```

Unterabschnitt im Portal: `<p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Eyebrow</p><h2 class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Titel</h2>` in einer `flex flex-wrap items-end justify-between gap-3`, Inhalt danach `mt-6`.

### Abschnitt Website

Sektionen wechseln Canvas und Weiss; weisse Sektionen tragen `border-y border-hvm-linie`.

```blade
<section class="bg-hvm-canvas">  {{-- oder: border-y border-hvm-linie bg-white --}}
  <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
    <x-hvm.section-heading eyebrow="Eyebrow" title="Titel" lead="Lead" />
    <div class="mt-14 grid gap-6 lg:grid-cols-2">...</div>
  </div>
</section>
```

Zweispaltiges Editorial-Muster (Text links, Inhalt rechts): `grid gap-12 lg:grid-cols-12`, Text `lg:col-span-5` (oder 4), Inhalt `lg:col-span-7` (oder 8).

### Karte

- Auf Canvas: `<x-hvm.card>` (Weiss). Auf Weiss: `<x-hvm.card tone="canvas">`.
- Manuell: `rounded-2xl border border-hvm-linie bg-white p-6 sm:p-7`.
- Feature-Karte mit Kennlinie: `overflow-hidden rounded-3xl border border-hvm-linie bg-white` plus `<div class="hvm-kennlinie" aria-hidden="true"></div>` als erstes Kind, Inhalt `p-7 sm:p-9`.
- Icon-Kreis in Karten: `flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark`.
- Innere Hervorhebung: `rounded-2xl bg-hvm-canvas p-4` (auf weisser Karte).

### Formular

```blade
<div class="mx-auto max-w-md">                      {{-- Formulare mit vielen Feldern: max-w-2xl --}}
  <x-hvm.section-heading eyebrow="Bereich" title="Titel" lead="Satz." />
  <div class="mt-10 overflow-hidden rounded-3xl border border-hvm-linie bg-white">
    <div class="hvm-kennlinie" aria-hidden="true"></div>
    <form method="POST" action="..." class="space-y-6 p-6 sm:p-8">
      @csrf
      <x-hvm.field name="email" label="E-Mail-Adresse" type="email" autocomplete="email" :required="true" />
      <x-hvm.field name="notiz" label="Notiz" type="textarea" hint="Optional." :optional="true" />
      <x-hvm.field name="art" label="Art" type="select"><option>...</option></x-hvm.field>
      <div class="flex items-center gap-3">
        <input id="ok" name="ok" type="checkbox" value="1" class="hvm-check">
        <label for="ok" class="text-sm text-hvm-textschwarz">Beschriftung</label>
      </div>
      <div class="flex flex-wrap gap-3">
        <x-hvm.button type="submit" variant="primary" size="lg">Speichern</x-hvm.button>
        <x-hvm.button href="..." variant="secondary" size="lg">Abbrechen</x-hvm.button>
      </div>
    </form>
  </div>
</div>
```

Mehrspaltige Felder: `grid gap-6 sm:grid-cols-2`. Feldgruppen mit Zwischenueberschrift: `<fieldset class="space-y-6"><legend class="text-lg font-semibold tracking-tight text-hvm-textschwarz">...</legend>`. Bestehende `<input>` ohne Komponente erhalten `class="hvm-input"`; Fehlermeldung `<p id="{id}-fehler" class="mt-2 text-sm font-medium text-status-error">`.

### Tabelle

```blade
<div class="overflow-hidden rounded-3xl border border-hvm-linie bg-white">
  <div class="overflow-x-auto">
    <table class="hvm-table hvm-table-zebra min-w-[40rem] text-base">
      <caption class="sr-only">...</caption>
      <thead><tr><th scope="col">Position</th><th scope="col" class="text-right">Betrag</th></tr></thead>
      <tbody><tr><th scope="row" class="font-medium">...</th><td class="text-right">...</td></tr></tbody>
    </table>
  </div>
</div>
```

Kopfzeile: Versalien in Sekundaerfarbe mit 2 px Orange-Linie (kein oranger Balken mehr). Betraege rechtsbuendig, Tabellenziffern kommen automatisch.

### Liste

Mehrere Eintraege in einer Karte:

```blade
<x-hvm.card padding="none" class="divide-y divide-hvm-linie">
  @foreach ($eintraege as $e)
    <x-hvm.list-row :title="$e->label" :subtitle="$e->adresse" :href="route(...)">
      @include('portal.partials.status', ['status' => ...])
      <x-slot:actions>
        <x-hvm.button href="..." variant="secondary" size="sm">Bearbeiten</x-hvm.button>
      </x-slot:actions>
    </x-hvm.list-row>
  @endforeach
</x-hvm.card>
```

Eintraege mit viel Inhalt (Objekte): je Eintrag eine eigene `<x-hvm.card padding="none">` in `space-y-4`. Aufzaehlungen in Listen: `flex gap-2` mit `<span class="mt-2 h-1 w-1 rounded-full bg-hvm-mittelgrau">` statt `list-disc`. Checklisten: `flex gap-3` mit `<x-hvm.icon name="check" />` in `text-hvm-orange-dark`.

### Leerzustand

```blade
<x-hvm.empty-state icon="house" title="Noch kein Objekt">
  <p>Sie haben noch kein Objekt angelegt.</p>
  <x-slot:action><x-hvm.button href="..." variant="primary">Objekt anlegen</x-hvm.button></x-slot:action>
</x-hvm.empty-state>
```

### Statuskarte

```blade
<div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
  <x-hvm.stat label="Bitte prüfen" :value="3" variant="warning" />
</div>
```

Variante ueber `PortalStatusCategory::variant($kategorie)`.

### Schrittanzeige (Wizard)

- Vertikal: `<ol class="divide-y divide-hvm-linie">` mit `<li class="py-7 first:pt-0 last:pb-0"><x-hvm.step number="1" title="..." /></li>`.
- Fortschritt horizontal: `<ol class="flex flex-wrap gap-2">`, je Schritt eine Pill `inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium`; aktueller Schritt `border-hvm-textschwarz bg-hvm-textschwarz text-white`, erledigt `border-hvm-linie bg-white text-hvm-textschwarz` mit `<x-hvm.icon name="check" class="h-4 w-4 text-status-success" />`, offen `border-hvm-linie bg-hvm-canvas text-hvm-text-sekundaer`. Zusaetzlich ein Text "Schritt 3 von 12" in `text-sm text-hvm-text-sekundaer`.
- Fortschrittsbalken: `h-2 w-full rounded-full bg-hvm-canvas-deep` mit innerem `h-2 rounded-full bg-hvm-orange w-[NN%]` (Prozent als Arbitrary Value, keine Inline-Styles noetig).

### Buttons

Genau ein Primaerbutton je Ansicht, links in der Buttonreihe. Reihenfolge: primary, secondary, ghost. Icons in Buttons `h-4 w-4` (sm, md) oder `h-5 w-5` (lg), Pfeil rechts, Plus links. Auf Graphit: `variant="inverse"`. Formular-Submit auf schmalen Karten: `size="lg" class="w-full"`.

### Badges

Statusbadge immer mit Kategorietext (`x-hvm.badge :variant="$status->variante()"`). Neutrale Etiketten (Anzahl, Typ) `variant="neutral"`. Marken-Pill (Claim, Bereichsname im Seitenkopf) `variant="akzent"`.

### Meldungen

`x-hvm.alert` direkt unter dem Seitenkopf oder ueber dem betroffenen Formular, `mb-8` im Portal. Fehlerliste als `list-disc pl-5`. Keine Meldung nur ueber Farbe, das Statuswort bleibt.

### Dunkle Flaechen

- Container: `bg-hvm-graphit text-hvm-hellgrau`, Ueberschriften `text-white`, Eyebrow `text-hvm-hellgrau` (auf Graphit ist auch `text-hvm-anthrazit` fuer Eyebrows zulaessig, weil Versalien fett).
- Karten auf Graphit: `rounded-3xl border border-hvm-graphit-soft bg-hvm-graphit-soft/40`, Trennlinien `divide-hvm-graphit-soft`.
- Orange auf Graphit nur fuer Icons und die Kennlinie, Buttons als `variant="inverse"`. Links `text-white underline decoration-hvm-anthrazit underline-offset-4 hover:decoration-white`.
- Alerts (softe Flaechen) duerfen unveraendert auf Graphit stehen.
- Der Footer der Website ist immer Graphit und endet mit der Kennlinie.

## 5. Regeln fuer Orange

Erlaubt: Primaerbutton (Flaeche), Eyebrow-Strich (`h-px w-8`), Akzentlinie in Karten (`h-1 w-10 rounded-full`), Schrittziffer (Kreis), Fokusring, Fortschrittsbalken, Kennlinien-Segment, Icons in `text-hvm-orange-dark` auf hellem Grund, Icon-Kreise in `bg-hvm-orange-soft`, Stempel "Vorschau", Tabellenkopf-Unterlinie, Link-Unterstreichung `decoration-hvm-orange decoration-2`.

Nicht erlaubt: Fliesstext oder Ueberschriften in Orange, orange Flaechen hinter Text (ausser Button), mehr als ein Primaerbutton je Ansicht, orange Tabellenkoepfe, orange Rahmen um Karten.

## 6. Do und Don't

Do

- Textschwarz fuer Fliesstext und Ueberschriften, `hvm-text-sekundaer` fuer Lead, Untertitel und Hilfetext.
- Zeilenlaenge mit `max-w-prose` begrenzen, Sektionen grosszuegig (`py-20 lg:py-28`).
- Flaechen wechseln: Weiss auf Canvas, Canvas auf Weiss, Graphit als Ruhepunkt.
- Buttons und Pills rund, Karten `rounded-2xl` oder `rounded-3xl`, Eingabefelder `rounded-xl`.
- Status immer als Text plus Punkt oder Symbol.
- Touchziele mindestens 44 px (`min-h-11`), Eingabefelder 48 px.
- Icons nur aus `x-hvm.icon`, immer dekorativ.
- Mobile first: `grid-cols-2` fuer Kennzahlen, Buttonreihen `flex flex-wrap gap-3`, breite Tabellen in `overflow-x-auto`.

Don't

- Kein Schlagschatten auf Karten, keine Verlaeufe, keine neuen Buntfarben.
- Kein Anthrazit fuer Fliesstext (Kontrast 3,4:1).
- Kein Orange fuer Text, keine orangen Tabellenkoepfe.
- Keine Webfonts, keine externen Ressourcen, keine Base64-Bilder.
- Keine Gedankenstriche in sichtbaren Texten, kein `&shy;` in Texten, die Tests wortgleich pruefen.
- Keine Aenderung der Inline-Bloecke im `<head>` (CSP-Hash).
- Keine Status- oder Vermieterangaben ueber mehrere Elemente verteilen, wenn ein Test die Zeichenkette zusammenhaengend prueft.
