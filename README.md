# AuVo E-Rechnung

[![Neueste Version](https://img.shields.io/github/v/release/Fips81/dolibarr-auvo-erechnung?label=version&sort=semver)](https://github.com/Fips81/dolibarr-auvo-erechnung/releases/latest)

**E-Rechnungen für Dolibarr**: Das Modul erzeugt aus jeder Kundenrechnung automatisch eine **ZUGFeRD-Rechnung im Profil EN16931 (COMFORT)**, also eine PDF/A-3 mit eingebettetem CrossIndustryInvoice-XML. Dieses Format heißt in Frankreich **Factur-X** und ist dort identisch. XRechnung ist für eine spätere Version geplant.

Für Verkäufer in Deutschland gibt es deutsche Befreiungsgründe (§ 19 UStG, Reverse Charge, innergemeinschaftliche Lieferung, Ausfuhr), Steuernummer und Leitweg-ID. Für Verkäufer in Frankreich deckt das Modul zusätzlich die **BR-FR**-Regeln der französischen Reform ab (Norm XP Z12-012, Ausgabe Juni 2026).

Ein Fork von [LemonFacturX](https://hellolemon.fr) (Lemon, Webagentur aus Clermont-Ferrand), weiterentwickelt und für den Einsatz in Deutschland angepasst von [auvo-design](https://auvo-design.de) — siehe [Lizenz](#lizenz). Frühere Namen: AuvoZugferd (bis 3.9.x), DoliZUGFeRD (4.0.x).

> AuVo E-Rechnung ist ein unabhängiges Modul für Dolibarr. Es ist kein offizielles Produkt des Forums elektronische Rechnung Deutschland (FeRD), der Dolibarr Foundation oder von Lemon und wird von diesen nicht unterstützt. ZUGFeRD, Factur-X, XRechnung und Dolibarr sind Namen bzw. Marken ihrer jeweiligen Inhaber.

## Voraussetzungen

- **Dolibarr** 19.0 → 24.x — Mindestversion bei Aktivierung geprüft (`need_dolibarr_version`)
- **PHP** 8.1+ (getestet auf 8.2/8.4) — bei Aktivierung geprüft (`phpmin`)
- **Funktion `exec()`**: standardmäßig für die PDF-Injektion genutzt (isolierter PHP-Subprozess, der robusteste Weg). **Nicht zwingend erforderlich**: ist `exec()` deaktiviert (`disable_functions`, gehärtetes Shared Hosting), schaltet das Modul automatisch auf **In-Process**-Injektion um. Der Modus ist einstellbar (`AUVOERECHNUNG_INJECTION_MODE`: `auto` / `inprocess` / `subprocess`) — siehe Abschnitt Injektion.
- **Eine PDF-Schriftart mit eingebetteten Glyphen** (für PDF/A-3-Gültigkeit erforderlich). Keine Konstante von Hand zu setzen: das Modul liefert einen **Selektor „Schriftart der erzeugten PDF"** in seiner Konfiguration, der jede Schriftart mit ✓ (eingebettet, konform) oder ⚠ (Base-14, nicht konform) markiert. `pdfahelvetica` ist standardmäßig vorgeschlagen.

## Installation

1. **Das Archiv der letzten Release herunterladen** von
   [github.com/Fips81/dolibarr-auvo-erechnung/releases/latest](https://github.com/Fips81/dolibarr-auvo-erechnung/releases/latest).

   Das an die Release angehängte Asset `module_auvoerechnung-X.Y.Z.zip` herunterladen (und **nicht** den
   "Download ZIP"-Button des Quellcodes — siehe Warnung weiter unten).

2. Entpacken und den Ordner `auvoerechnung/` in das Custom-Verzeichnis von Dolibarr kopieren:

   ```bash
   unzip module_auvoerechnung-X.Y.Z.zip
   cp -r auvoerechnung/ /var/www/html/custom/
   chown -R www-data:www-data /var/www/html/custom/auvoerechnung
   ```

3. Modul aktivieren: **Start > Konfiguration > Module**
4. Konfigurieren über **Start > Konfiguration > Module > AuVo E-Rechnung**:
   - Bankkonto (IBAN/BIC)
   - Standard-Zahlungsart (Überweisung, SEPA-Überweisung, SEPA-Lastschrift, Lastschrift)
   - Verkäufer-/Käufer-Kennungen: SIRET in BT-29/BT-46 (schemeID 0009), SIREN in BT-30/BT-47 (schemeID 0002)
   - Umsatzsteuer-Fälligkeit (BT-8: Soll-/Ist-Versteuerung), Rechnungsstellungsrahmen (BT-23)
   - Fehlerbehandlungsmodus (best-effort / strikt), Kontrolle der Geschäftsregeln
   - Optional: Pfad zur PHP-CLI, Pfad zu veraPDF und gesetzliche Pflichtangaben
5. Eine **eingebettete Schriftart** im Selektor **„Schriftart der erzeugten PDF"** der Modulkonfiguration wählen (`pdfahelvetica` standardmäßig, mit ✓ markiert)
6. Die **Diagnose** unten auf der Konfigurationsseite des Moduls prüfen (grüne Häkchen = OK)

> **Achtung** — Verwenden Sie nicht den "Download ZIP"-Button der Startseite des Repos
> (der rohe Quellcode). Dieses Archiv entpackt sich zu `dolibarr-auvo-erechnung-main/`
> statt zu `auvoerechnung/`, was die Dolibarr-Installation kaputt macht (Fehler *"You requested
> a website or a page that does not exists"* beim Öffnen der Modul-Konfigurationsseite).
> Laden Sie das ZIP-Asset der Release herunter, oder klonen Sie direkt mit `git clone`
> in einen Ordner namens `auvoerechnung` (siehe Abschnitt [Update](#update)) – das Repo
> heißt `dolibarr-auvo-erechnung`, der Modulordner muss aber `auvoerechnung` heißen.

## Update

```bash
# Alte Version sichern (zur Sicherheit)
cp -r /var/www/html/custom/auvoerechnung /var/www/html/custom/auvoerechnung.bak

# Neue Version holen
git clone https://github.com/Fips81/dolibarr-auvo-erechnung.git /tmp/auvoerechnung-new
rm -rf /var/www/html/custom/auvoerechnung
mv /tmp/auvoerechnung-new /var/www/html/custom/auvoerechnung
chown -R www-data:www-data /var/www/html/custom/auvoerechnung
```

> **Nach jedem Update: Modul einmal deaktivieren und wieder aktivieren**
> (**Start > Einstellungen > Module > AuVo E-Rechnung**). Zusatzfelder wie die
> **Leitweg-ID** am Geschäftspartner (seit 3.9.5) und die Chorus-Pro-Felder an der
> Rechnung werden nur bei der Aktivierung angelegt — nicht schon durch das
> Kopieren der neuen Dateien. Wer von einer älteren Version ohne Leitweg-ID
> aktualisiert, sieht das Feld sonst nicht. Beim Deaktivieren gehen keine Daten
> verloren: Einstellungen und bereits eingetragene Werte (z. B. Leitweg-IDs)
> bleiben erhalten.

### Umstieg von DoliZUGFeRD (4.0.x) oder AuvoZugferd (3.x) auf AuVo E-Rechnung 5.0

Das Modul hieß bis 3.9.x **AuvoZugferd** (Ordner `auvozugferd`) und in 4.0.x **DoliZUGFeRD** (Ordner `dolizugferd`). Seit 5.0 heißt es **AuVo E-Rechnung**, der Modulordner ist `auvoerechnung`. Für Dolibarr ist das ein neues Modul. Beim ersten Aktivieren übernimmt es die bisherigen Einstellungen automatisch, von beiden früheren Namen.

1. **Das alte Modul deaktivieren** (Start > Einstellungen > Module). Nicht zuerst löschen: Alle Versionen tragen dieselbe Modulnummer und dürfen nicht gleichzeitig aktiv sein.
2. Den alten Ordner **aus `custom/` heraus** verschieben. Ein umbenannter Ordner innerhalb von `custom/` würde weiter erkannt.
   ```bash
   mv /var/www/html/custom/dolizugferd /root/dolizugferd.alt     # bzw. custom/auvozugferd
   ```
3. AuVo E-Rechnung wie unter [Installation](#installation) beschrieben nach `custom/auvoerechnung` kopieren und **aktivieren**.

Dabei passiert automatisch:
- Die Einstellungen `DOLIZUGFERD_*` bzw. `AUVOZUGFERD_*` werden als `AUVOERECHNUNG_*` übernommen (Bankkonto, Zahlungsart, Hinweistexte usw.). Die alten Einträge bleiben in der Datenbank stehen, ein Zurückwechseln ist also möglich.
- Die Zusatzfelder **Leitweg-ID** (Geschäftspartner) und **Chorus Pro** (Rechnung) werden auf das neue Modul umgehängt. Die eingetragenen Werte bleiben unverändert.

Bitte beachten:
- Die **REST-API** liegt jetzt unter `/api/index.php/auvoerechnung/…`.
- Bereits erzeugte Rechnungs-PDFs bleiben unverändert gültig.
- Das GitHub-Repo heißt jetzt `dolibarr-auvo-erechnung`. Alte Links auf `dolibarr_zugferd` leitet GitHub weiter.

Dolibarr benachrichtigt nicht automatisch über Updates eines Custom-Moduls; die Konfigurationsseite des Moduls zeigt jedoch ein Banner an, wenn eine neuere Release auf GitHub veröffentlicht wurde (Check alle 24h, Cache in der DB). Siehe Abschnitt **Changelog** am Ende dieser README für Änderungen und eventuelle Migrationen.

## Architektur

```
auvoerechnung/
├── core/modules/modAuvoERechnung.class.php   # Modul-Deskriptor (Nr. 194202)
├── core/lib/
│   ├── auvoerechnung.lib.php                 # XML-Generator EN16931
│   └── auvoerechnung_rules.php               # Validator für Geschäftsregeln (BR-*)
├── class/
│   ├── actions_auvoerechnung.class.php       # Hooks afterPDFCreation + invoicecard
│   └── api_auvoerechnung.class.php           # REST-API (xml / status)
├── scripts/
│   ├── inject_facturx.php                   # PDF-Injektion (Subprozess, nur CLI)
│   └── export_facturx_batch.php             # Stapelexport der eingebetteten XML
├── admin/setup.php                          # Konfigurationsseite + Diagnose
├── langs/fr_FR + en_US + de_DE/auvoerechnung.lang    # Übersetzungen
├── tests/
│   ├── unit-tests.php                       # Standalone-Tests (ohne Dolibarr, CI)
│   └── run-tests.php                        # Integrationstests (Fixtures aus demo/)
├── docs/LIMITATIONS.md                      # Nicht behandelte Fälle und Gründe
└── vendor/                                  # Lib atgp/factur-x v3.4.1 + Abhängigkeiten
```

## Funktionsweise

Das Modul klinkt sich in den Hook `afterPDFCreation` (Kontext `pdfgeneration`) ein. Bei jeder Erzeugung einer Kundenrechnungs-PDF:

1. **Bereichsprüfung**: Multiwährung, lokale Steuern (localtax) und nicht abbildbare Daten → sauberer Verzicht (klassische PDF bleibt erhalten)
2. **Prüfung** der Pflichtangaben (Verkäufer, Käufer, IBAN, PDF/A-Schriftart) — konsolidierte Warnungen
3. **Erzeugung des XML** CrossIndustryInvoice EN16931 mit den Daten der Dolibarr-Rechnung
4. **Interne Validierung**: well-formed + XSD EN16931 + **Geschäftsregeln BR-\*** (Teilmenge des Schematron in PHP)
5. **Injektion** des XML in die PDF über die Lib `atgp/factur-x` (standardmäßig isolierter Subprozess, In-Process falls `exec()` nicht verfügbar; atomares Schreiben, `AFRelationship=Alternative`)
6. **Optionale Post-Validierung mit veraPDF** (PDF/A-3b)

#### Injektionsmodus (`AUVOERECHNUNG_INJECTION_MODE`)

| Modus | Verhalten |
|------|--------------|
| `auto` (Standard) | **Subprozess**, falls `exec()` verfügbar ist (isolierter PHP-Prozess, kein Risiko von Bibliothekskonflikten); **In-Process** nur, wenn `exec()` deaktiviert ist (gehärtetes Shared Hosting) |
| `inprocess` | Injektion **ausschließlich** In-Process — nie `exec()`. Nur für Server ohne `exec()` reservieren: ein Versionskonflikt zwischen FPDF/FPDI und einem anderen Modul kann die Injektion verhindern (das Modul erkennt diesen Fall und behält die klassische PDF) |
| `subprocess` | **Ausschließlich** PHP-CLI-Subprozess (historisches Verhalten; erfordert `exec()`) |

**Warum standardmäßig der Subprozess**: In-Process lädt die Injektionsbibliothek (`setasign/fpdi` + `setasign/fpdf`) in die Web-Anfrage, wo eine andere Komponente (das `tcpdi` von Dolibarr, ein anderes Modul) bereits eine **inkompatible** Version von FPDF geladen haben kann → nicht abfangbarer fataler Kompilierungsfehler. Der Subprozess läuft in einem jungfräulichen PHP-Prozess, ohne möglichen Konflikt. Einstellbar unter **Konfiguration → Block „Technik"**.

Auf der **Rechnungsfiche** (validierte Rechnung), zwei Buttons:
- **ZUGFeRD prüfen**: extrahiert das eingebettete XML aus der PDF und validiert es erneut (XSD + Geschäftsregeln) — vor dem Versand zu verwenden
- **ZUGFeRD neu erzeugen**: erzeugt die PDF (und damit die Injektion) neu — nützlich nach einem Modul-Update oder einer Datenkorrektur

### Unterstützung von ODT-Vorlagen (seit 3.8.0)

Standardmäßig erfolgt die Factur-X-Injektion nur bei **nativen TCPDF**-Rechnungsvorlagen (sponge / crabe / octopus), über den Hook `afterPDFCreation`. **ODT**-Vorlagen erzeugen eine `.odt`-Datei (dann eine `.pdf` nach Konvertierung) und lösen nur ODT-Hooks aus: ohne spezielle Konfiguration fand keine Injektion statt.

Seit 3.8.0 klinkt sich das Modul auch in den Hook `afterODTCreation` (Kontext `odtgeneration`) ein und injiziert das XML in die aus der LibreOffice-Konvertierung entstandene PDF. **Zwei Voraussetzungen** auf Dolibarr-Seite (**Konfiguration → Sonstiges → Weitere**):

| Konstante | Wert | Rolle |
|-----------|--------|------|
| `MAIN_ODT_AS_PDF` | `libreoffice` | Konvertiert die `.odt` bei der Erzeugung in eine `.pdf` (erfordert **auf dem Server installiertes LibreOffice**, erreichbares Binary `soffice`) |
| `MAIN_ODT_AS_PDFA` | `1` | Erzwingt den Export als **PDF/A-1** (PDF 1.4). Unverzichtbar: ohne das erzeugt LibreOffice eine PDF ≥ 1.5 (komprimierte Xref-Tabelle), die die Injektionsbibliothek (FPDI) nicht lesen kann, und die Injektion schlägt sauber mit einer Warnung fehl |

**Best-Effort**-Verhalten: fehlt eine dieser Voraussetzungen (Konvertierung deaktiviert, PDF/A nicht erzwungen, PDF nicht auffindbar, LibreOffice fehlt), **injiziert das Modul nicht**, behält die klassische PDF/ODT und zeigt eine **klare Warnung** mit der zu setzenden Konstante — nie eine Fatal Error oder eine beschädigte PDF. Der Strikt-Modus (`AUVOERECHNUNG_STRICT_MODE`) macht aus dieser Warnung einen blockierenden Fehler, wie beim TCPDF-Ablauf.

> **Status** — die Injektionskette wurde **intern auf LibreOffice 7.4 validiert** (die von LibreOffice erzeugte PDF/A-1 wird von der Injektionsbibliothek (FPDI) gelesen, das ZUGFeRD/Factur-X wird darin eingebettet, der Handler `afterODTCreation` leitet die richtige PDF ab und injiziert) **und unter realen Bedingungen**: ein Nutzer hat die Erzeugung einer Rechnung über ein ODT-Template auf seinem Dolibarr bestätigt, mit dem Factur-X-XML in der PDF vorhanden (Haupt-Factur-X + 2. PDF Chorus). Bei unterschiedlichen Vorlagen (`.odt`/`.ods`) bleibt es ratsam, die PDF mit einem Factur-X-Validator zu prüfen (Button **ZUGFeRD prüfen** der Rechnungsfiche, oder ein Online-Validator wie FNFE-MPE), um das PDF/A-3-Niveau zu bestätigen. Probleme bitte über [SECURITY.md](SECURITY.md) / r.vogel@auvo-design.de melden.
>
> **Aktivierung**: dieser neue Hook-Kontext wird erst nach **Deaktivierung/Reaktivierung** des Moduls berücksichtigt (die Deaktivierung löscht keine Daten).

### Sicherheit

- CLI-Skripte (`scripts/`, `tests/`, `demo/`) geschützt durch `PHP_SAPI === 'cli'` **und** `.htaccess` `Require all denied`
- **`exec()` nicht zwingend**: auf einem gehärteten Hosting, das es deaktiviert, schaltet das Modul auf In-Process-Injektion um und bleibt funktionsfähig (Kompatibilität mit Shared Hosting). Wird der Subprozess genutzt (Standardfall), ist der Befehl abgesichert: `escapeshellarg()` auf allen Tokens, PHP-CLI-Binary konfigurierbar über `AUVOERECHNUNG_PHP_CLI_PATH`, per Regex und `is_executable()` validiert, falls absolut angegeben
- **Atomares** Schreiben der PDF (temporäre Datei + `rename()`), unabhängig vom Modus
- Interne XML-Validierung vor PDF-Injektion (well-formed + XSD EN16931 + Geschäftsregeln)
- Modus `AUVOERECHNUNG_STRICT_MODE`: Wahl zwischen fail-open (best-effort) und fail-closed (strikt)
- CSRF-Schutz beim Admin-POST und bei den Aktionen der Rechnungsfiche (`currentToken()`)
- REST-API: Rechte `facture->lire` + `_checkAccessToResource()`; Buttons der Fiche: Rechte `lire`/`creer`
- Nur ein ausgehender HTTP-Aufruf: GitHub-Versionscheck alle 24h (Cache in der DB, auch Fehlschläge)

Bedrohungsmodell, detaillierte Schutzmaßnahmen und Meldeprozess: siehe [SECURITY.md](SECURITY.md). Kontakt für Disclosure: **r.vogel@auvo-design.de**.

## XRechnung

Für öffentliche Auftraggeber in Deutschland (und Firmenkunden, die es verlangen) erzeugt das Modul zusätzlich eine **XRechnung 3.0** nach der Spezifikation der KoSIT, als eigene Datei `{Rechnung}-XRECHNUNG.xml` neben der PDF.

- **Wann:** automatisch, sobald beim Geschäftspartner eine **Leitweg-ID** eingetragen ist. Über das Feld **„E-Rechnungsformat“** am Geschäftspartner lässt sich das erzwingen („XRechnung“) oder abschalten („ZUGFeRD“).
- **Pflichtangaben:** Ansprechpartner mit Telefon und E-Mail (Moduleinstellungen, Block „XRechnung“; leer = Ersteller der Rechnung), E-Mail der Firma, PLZ/Ort von Firma und Kunde, IBAN bei Überweisung, Leitweg-ID oder Kundenreferenz. Fehlt etwas, erscheint beim Erzeugen der PDF eine Liste der fehlenden Angaben, und es wird keine XRechnung geschrieben.
- **Bund (OZG-RE):** Die Datei über die Weboberfläche hochladen oder über den freigeschalteten E-Mail-Kanal senden (eine Rechnung pro E-Mail, von der bei der OZG-RE hinterlegten Absenderadresse). Keine zusätzliche PDF-Kopie schicken.
- **Aktualität:** Die KoSIT veröffentlicht zum 01.02. und 01.07. Die Version der Kennung (Standard `3.0`) ist in den Moduleinstellungen einstellbar.

Details und noch fehlende Teile: [docs/LIMITATIONS.md](docs/LIMITATIONS.md#xrechnung).

## Abgebildete Daten (Dolibarr → Factur-X)

| Factur-X-Feld | Dolibarr-Quelle |
|---|---|
| BT-1 Invoice ID | `$invoice->ref` |
| BT-2 Issue date | `$invoice->date` |
| BT-3 Type code | 380 / 381 (Gutschrift) / 384 (Korrektur) / 386 (Anzahlung) |
| BT-8 VAT due date code | Dolibarr-Umsatzsteuerregelung (`TAX_MODE`): 5 Soll-, 72 Ist-Versteuerung, weggelassen falls unbestimmt |
| BT-9 Due date | `$invoice->date_lim_reglement` |
| BT-10 Buyer reference | `$invoice->ref_client` (Service-Code / Verpflichtungsnummer Chorus Pro), bei deutschem Behörden-Käufer: Leitweg-ID |
| BT-13 Order reference | Referenz der ersten verknüpften Kundenbestellung |
| BT-23 Business process | Rechnungsstellungsrahmen, gewählt **pro Rechnung** (Chorus-Pro-Profil: `A1` standardmäßig), weggelassen außerhalb von Chorus Pro |
| BT-25/BG-3 Preceding invoice | `fk_facture_source` (Gutschrift/Korrektur) + verrechnete Anzahlungen |
| Verkäufer / Käufer | `$mysoc` / `$invoice->thirdparty` |
| BT-29/BT-46 ID Niederlassung | `idprof2` → SIRET (14 Ziffern) unter schemeID 0009 (ISO 6523) — für Chorus Pro |
| BT-30/BT-47 gesetzliche ID | `idprof2` → SIREN (9 Ziffern) unter schemeID 0002 (ISO 6523) — von BR-FR-10 / Plateformes Agréées gefordert |
| BT-31/BT-32 Tax registration | `tva_intra`, oder SIREN `schemeID="FC"` (Kleinunternehmerregelung), bei deutschem Kleinunternehmer: Steuernummer |
| BT-34/BT-49 Endpoint | SIREN `schemeID="0225"` (PPF-Verzeichnis), Rückfall auf E-Mail `EM` |
| BT-72 Delivery date | `$invoice->delivery_date` falls angegeben (bei innergemeinschaftlicher Kategorie K erzwungen) |
| BT-73/74 (BG-14) Period | Min/Max der Leistungsdaten der Positionen |
| BT-80 ShipTo country | Käuferland (ausgegeben für Kategorie K, BR-IC-12) |
| BT-89/90/91 Direct debit | RUM (Standard-Bankverbindung des Tiers), ICS (`PRELEVEMENT_ICS`), Debitor-IBAN — Zahlungsart 59 |
| BG-21 Document allowances | Dolibarr-Positionen mit negativem Betrag (feste Rabatte) |
| BT-113 TotalPrepaidAmount | `$invoice->getSumDepositsUsed()` bei verrechneter Anzahlung |
| BT-121 VATEX | VATEX-FR-FRANCHISE / VATEX-EU-IC / VATEX-EU-AE / VATEX-EU-G |
| BT-129 unitCode | Von `$line->fk_unit` auf UN/ECE Rec 20 abgebildet |
| BT-146 Unit price | `total_ht/qty`, bis zu 4 Dezimalstellen |
| BT-151 CategoryCode | Kontextabhängig berechnet (S / K / AE / G / E) |
| IBAN / BIC | Ausgewähltes Dolibarr-Bankkonto |

### Unterstützte Rechnungstypen

| Dolibarr-Fall | TypeCode EN16931 | Mapping |
|---|---|---|
| Standardrechnung | **380** | Commercial invoice |
| `TYPE_REPLACEMENT` | **384** | Corrected invoice + BG-3-Referenz auf die ersetzte Rechnung |
| `TYPE_CREDIT_NOTE` | **381** | Credit note — **Beträge positiv ausgegeben** (BR-27), BG-3 auf die Ursprungsrechnung |
| `TYPE_DEPOSIT` | **386** | Prepayment / advance invoice (Anzahlung) |
| `TYPE_SITUATION` | 380 + Warnung | Teilweise Unterstützung, siehe [docs/LIMITATIONS.md](docs/LIMITATIONS.md) |

**Konvention für Gutschriften**: Dolibarr speichert negative Summen; EN16931 verlangt positive Beträge auf einer 381. Seit 3.0.0 werden alle Beträge einer Gutschrift invertiert (`DuePayableAmount` = positive Summe, ohne Nullkappung — BR-CO-16) und die Ursprungsrechnung wird in BG-3 referenziert (in FR verpflichtende Angabe). Eine Gutschrift ohne verknüpfte Ursprungsrechnung erzeugt eine Warnung.

Eine Schlussrechnung, die eine Anzahlung verrechnet, schreibt `TotalPrepaidAmount` (BT-113), passt `DuePayableAmount` an und referenziert die Anzahlungsrechnung in BG-3.

### Umsatzsteuerkategorien (BT-151)

| CategoryCode | VATEX (BT-121) | Auslösender Fall |
|---|---|---|
| **S** | — | USt > 0 |
| **K** | VATEX-EU-IC | EU-Käufer außerhalb FR mit USt-IdNr + USt 0 + **Waren**-Position (`product_type` 0) — mit ShipTo (BT-80) und Lieferdatum (BR-IC-11/12) |
| **AE** | VATEX-EU-AE | EU-Käufer außerhalb FR mit USt-IdNr + USt 0 + **Dienstleistungs**-Position (`product_type` 1) — Art. 196 Richtlinie 2006/112/EG |
| **G** | VATEX-EU-G | Käufer außerhalb EU + USt 0 |
| **E** | VATEX-FR-FRANCHISE | Aussteller in Kleinunternehmerregelung (293 B CGI) — SIREN als Steuerkennung `FC` veröffentlicht |
| **E** | — | USt 0 standardmäßig (Befreiung ohne bestimmbare Rechtsgrundlage, generischer Grund) |

Befreite Kategorien erzeugen systematisch einen lesbaren `ExemptionReason` und, wenn die Rechtsgrundlage bestimmbar ist, einen VATEX-Code `ExemptionReasonCode`.

**Nicht abgedeckte Fälle** (inländisches Reverse-Charge AE FR→FR, Codes O/Z/L/M usw.): siehe [docs/LIMITATIONS.md](docs/LIMITATIONS.md), das jeden nicht behandelten Fall und dessen Grund dokumentiert.

### Rabatte und Rundungen

- **Feste Rabatte** (Dolibarr-Positionen mit negativem Betrag): in Dokument-Rabatte **BG-21** umgewandelt (`SpecifiedTradeAllowanceCharge` + BT-107) — eine Position mit negativem Preis würde BR-27 verletzen. Prozentuale Rabatte bleiben in den Nettopreisen verrechnet (konform).
- **Rundungen**: die USt-Aufschlüsselung wird nach (Kategorie, Satz) berechnet und dann mit den Rechnungssummen **abgeglichen** (eine eventuelle Rundungsdifferenz wird der Hauptkategorie zugeschlagen); alle BG-22-Summen werden von unten nach oben neu berechnet, um die Regeln BR-CO-10/11/13/14/15/16/17 zu gewährleisten, auch bei Rechnungen mit vielen Positionen.

### Mapping UN/ECE-Einheiten

Die Positionsmengen verwenden den UN/ECE-Rec-20-Code, der der Dolibarr-Einheit entspricht (`llx_c_units.short_label`):

| Dolibarr | UN/ECE | | Dolibarr | UN/ECE |
|---|---|---|---|---|
| h | HUR | | kg | KGM |
| d | DAY | | l | LTR |
| min | MIN | | m | MTR |
| week | WEE | | m² (`m2`) | MTK |
| month | MON | | m³ (`m3`) | MTQ |
| p, pc, pcs, u | C62 | | km | KMT |

Ist die Einheit nicht abgebildet oder `fk_unit` nicht gesetzt, wird der Code `C62` (Stück) als Fallback verwendet. Mengen werden mit bis zu 4 Dezimalstellen ausgegeben.

### Doppelter Kreislauf B2B (PDP) und B2G (Chorus Pro) — seit 3.4.0

Die französische Reform beruht auf **zwei getrennten, dauerhaften Netzwerken**, die im selben Feld `SpecifiedLegalOrganization/ID` Gegensätzliches verlangen:
- **PDP / B2B** (Netzwerk der Plateformes Agréées): **SIREN** (9 Ziffern), konform zu EN16931 / Regel BR-FR-10;
- **Chorus Pro / B2G** (öffentlicher Sektor): **SIRET** (14 Ziffern), Routing-Schlüssel seines Verzeichnisses.

Dasselbe XML kann nicht beide erfüllen. AuVo E-Rechnung löst das, **ohne jemals die Haupt-PDF zu beeinträchtigen**:

- Die **PDF der Rechnung bleibt das Standard-Factur-X EN16931** (Profil PDP, SIREN) — das ist der Zweck des Moduls, immer konform.
- Fällt die Rechnung unter den öffentlichen Sektor, erzeugt das Modul **zusätzlich** eine zweite Datei **`{ref}-CHORUS.pdf`** in der Dokumentenliste, im Profil Chorus Pro (SIRET-14 in `SpecifiedLegalOrganization` + Felder BT-10/12/13). Diese reichen Sie bei Chorus Pro ein, die andere bei Ihrer PDP.

**Reiter „Chorus Pro"**: die Chorus-Einstellungen werden in einem eigenen Reiter auf der Rechnungsfiche vorgenommen (sie belegen nicht den Reiter „Zusätzliche Eigenschaften").

**Auslösung der Chorus-PDF** (ein einziges Signal genügt):
1. „Chorus-Pro-Rechnung" im Chorus-Pro-Reiter der Rechnung ankreuzen;
2. eines der Chorus-Felder ausfüllen (Service-Code / Verpflichtungsnummer / Vertragsnummer);
3. automatisch, wenn die SIRET des Käufers die des Zentralstaats ist (`110002011…`).

**Chorus-Felder** (Chorus-Pro-Reiter, im Chorus-XML übernommen):
| Feld | EN16931-Code | CII-Element |
|---|---|---|
| Rechnungsstellungsrahmen (24 Werte A1–A25) | BT-23 | `BusinessProcessSpecifiedDocumentContextParameter` |
| Service-Code des Ausführenden | BT-10 | `BuyerReference` |
| Nummer der rechtlichen Verpflichtung | BT-13 | `BuyerOrderReferencedDocument` |
| Vertragsnummer | BT-12 | `ContractReferencedDocument` |

Der **Rechnungsstellungsrahmen** (BT-23) ist für Chorus verpflichtend und wird pro Rechnung gewählt (A1 = Einreichung durch den Lieferanten, Standard; A9/A10 Unterauftragsvergabe, A12+ Mitauftragsvergabe, A3 Gerichtskosten usw.).

**Aktionsmenü**: die Factur-X-Aktionen der Rechnungsfiche sind in einem Dropdown-Menü **„ZUGFeRD ▾"** gebündelt (Prüfen / Neu erzeugen / Chorus-PDF erzeugen).

> ⚠️ Die Chorus-PDF korrigiert das **Format**. Die Einreichung gelingt nur, wenn Aussteller und öffentliche Empfängerstruktur **tatsächlich an Chorus Pro angebunden** sind (gültiger Service-Code, gültige Verpflichtungsnummer). Das XML stellt die Anbindung nicht her.
>
> **Update von < 3.4.0**: Modul deaktivieren und wieder aktivieren, damit die Chorus-Extrafields auf den Rechnungen angelegt werden (keine Daten werden gelöscht).

### Gesetzliche Pflichtangaben FR (BR-FR-05)

Das XML enthält automatisch die Pflichthinweise:
- **PMD**: Verzugszinsen (3-facher gesetzlicher Zinssatz, Art. L.441-10)
- **PMT**: pauschale Beitreibungsentschädigung (40 €)
- **AAB**: Skonto für vorzeitige Zahlung

## Modulkonstanten

Alle sind über den Administrationsbildschirm des Moduls konfigurierbar (**Start > Konfiguration > Module > AuVo E-Rechnung**).

| Konstante | Typ | Standard | Beschreibung |
|---|---|---|---|
| `AUVOERECHNUNG_BANK_ACCOUNT` | int | 0 | ID des Dolibarr-Bankkontos |
| `AUVOERECHNUNG_PAYMENT_MEANS` | string | 30 | UNTDID-4461-Code: 30 Überweisung, 58 SEPA-Überweisung, 59 SEPA-Lastschrift, 49 Lastschrift |
| `AUVOERECHNUNG_STRICT_MODE` | int | 0 | 0 = best-effort (Standard), 1 = strikt (siehe unten) |
| `AUVOERECHNUNG_BR_CHECK` | int | 1 | Interne Prüfung der EN16931-Geschäftsregeln vor der Injektion |
| `AUVOERECHNUNG_INJECTION_MODE` | string | auto | Injektionsmodus: `auto` (In-Process + Rückfall auf Subprozess), `inprocess` (ohne exec), `subprocess` (nur exec) |
| `AUVOERECHNUNG_PHP_CLI_PATH` | string | *(leer)* | Pfad zum PHP-CLI-Binary, **nur** vom Modus `subprocess` genutzt (leer = Auto-Erkennung; siehe Hinweis unten) |
| `AUVOERECHNUNG_VERAPDF_PATH` | string | *(leer)* | Pfad zu veraPDF: Post-Validierung PDF/A-3b jeder erzeugten PDF (nicht blockierend) |
| `AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER` | string | *(leer)* | Suffix, das der Verkäufer-SIREN im elektronischen Endpoint BT-34 angehängt wird (z. B. `_Status`) — von manchen PA gefordert; leer = reine SIREN |
| `AUVOERECHNUNG_NOTE_PMD/PMT/AAB` | text | FR-Hinweise | Gesetzliche Pflichtangaben BR-FR-05 (der Standardtext gilt, wenn das Feld leer gelassen wird) |
| `AUVOERECHNUNG_NOTES_IN_FOOTER` | int | 0 | BR-FR-05-Hinweise in die Rechnungsfußzeile kopieren (`INVOICE_FREE_TEXT`) |
| `AUVOERECHNUNG_NOTES_OVERWRITE` | int | 0 | Kopie in die Fußzeile: bestehenden Dolibarr-Hinweis überschreiben (1) statt unsere Hinweise anzuhängen (0) |
| `AUVOERECHNUNG_CHORUS_ENABLED` | int | 0 | Chorus-Pro-Funktionen aktivieren (Reiter, Menü, 2. PDF) — opt-in |

> **Hinweis PHP CLI**: nur **im Modus `subprocess`** relevant (die Standard-Injektion ist In-Process und nutzt kein externes Binary). In diesem Modus erkennt das Modul das richtige PHP-CLI-Binary automatisch. Auf Servern mit mehreren PHP-Versionen, oder wenn die Auto-Erkennung fehlschlägt, `AUVOERECHNUNG_PHP_CLI_PATH` mit dem vollständigen Pfad konfigurieren (z. B. `/usr/bin/php8.2`). **Nicht** `PHP_BINARY` verwenden: im php-fpm-Kontext zeigt diese Konstante auf das fpm-Binary, nicht auf die CLI.

> **Hinweis SEPA-Lastschrift (59)**: das Modul veröffentlicht die Gläubiger-ID (Dolibarr-Konstante `PRELEVEMENT_ICS`, BT-90), die Mandatsreferenz (Standard-Bankverbindung des Tiers, BT-89) und den Debitor-IBAN (BT-91). Fehlende Daten werden per Warnung gemeldet.

### Strikt-Modus vs. best-effort

Standardmäßig läuft das Modul im **best-effort**-Modus: ist das Factur-X-XML ungültig oder schlägt die PDF-Injektion fehl, wird dem Nutzer eine Warnung angezeigt und die klassische PDF (ohne eingebettetes Factur-X) bleibt erhalten. Fehler werden im `syslog` mit dem Tag `AuVo E-Rechnung` protokolliert.

Im **Strikt-Modus** (`AUVOERECHNUNG_STRICT_MODE=1`) liefert dieselbe Situation einen sichtbaren, blockierenden Fehler, und Verstöße gegen Geschäftsregeln (BR-\*) werden blockierend. **Bewusste Einschränkung**: da der Hook erst nach der PDF-Erzeugung durch Dolibarr greift, bleibt die bereits erzeugte klassische PDF auch im Strikt-Modus auf der Festplatte erhalten — vor dem Versand „ZUGFeRD prüfen" nutzen, um eine Datei zu kontrollieren.

### Interne Validierung

Vor der PDF-Injektion validiert das Modul das XML systematisch:

1. **Well-formed**: `DOMDocument::loadXML()`
2. **XSD EN16931**: `DOMDocument::schemaValidate()` gegen die eingebettete XSD
3. **Geschäftsregeln** (`AUVOERECHNUNG_BR_CHECK`, standardmäßig aktiviert): Teilmenge der EN16931-Schematron-Regeln in PHP geprüft — Berechnungsregeln BR-CO-10..17, BR-27 (negative Preise), BR-61 (IBAN), BR-16, BR-IC-02/11/12 (innergemeinschaftlich), BR-AE-02, Befreiungsgründe BR-\*-10, BR-CO-25/26, BR-09/11 — **plus das Regelwerk Frankreich BR-FR** (siehe nächster Abschnitt)

Das vollständige offizielle Schematron (XSLT 2.0) ist in PHP nicht ausführbar: für eine erschöpfende Validierung einen externen Validator nutzen — siehe [docs/LIMITATIONS.md](docs/LIMITATIONS.md).

### Konformität XP Z12-012 (Frankreich-Regeln)

Regeln des Reform-Grundgerüsts Frankreich, vom Modul geprüft oder ausgegeben. Dieser gesamte Block — sowohl die Prüfungen des internen Validators **als auch** die im XML ausgegebenen Elemente — ist an einen **in Frankreich ansässigen Verkäufer** gebunden (Mutterland + gleichgestellte Überseegebiete, vgl. BR-FR-MAP-14): Factur-X wird auch außerhalb Frankreichs genutzt (ZUGFeRD), und das Reform-Grundgerüst ist einem ausländischen Verkäufer gegenüber nicht bindend — die EN16931-Regeln hingegen bleiben universell. Siehe [Verkäufer außerhalb Frankreichs (ZUGFeRD)](#verkäufer-außerhalb-frankreichs-zugferd).

| Regel | Gegenstand | Behandlung |
|---|---|---|
| BR-FR-01 / BR-FR-02 | Rechnungskennung (BT-1): max. 35 Zeichen, alphanumerisch + `- + _ /` (kein Leerzeichen erlaubt) | Blockierender Verstoß (Validator) + vorgelagerte Warnung |
| BR-FR-04 | Dokumenttyp (BT-3): geschlossene FR-Liste (380, 381, 384, 386, verschiedene Gutschrift-/Korrekturarten…) | Blockierender Verstoß (defensive Allowlist) |
| BR-FR-05 / BR-FR-06 | Hinweise PMD, PMT, AAB (Verzugszinsen, Pauschale 40 €, Skonto), jeweils genau einmal | Automatisch ausgegeben, überschreibbar |
| BR-FR-07 / BR-FR-20 / BR-FR-31 | Hinweis **BAR**, der den erwarteten Vorgang qualifiziert: `B2B` (e-invoicing, Regelfall), `B2BINT` (steuerpflichtiger Käufer außerhalb Frankreichs), `B2C` (nicht steuerpflichtiger Käufer, **auch außerhalb Frankreichs ansässig** — bewusste Wahl: die geschlossene Liste von BR-FR-20, Revision Juli 2025, enthält keinen Wert „B2C international") — gleichgestellte Überseegebiete zu Frankreich gerechnet (BR-FR-MAP-14) | Automatisch ausgegeben (Profil PDP), genau ein Vorkommen |
| BR-FR-08 | Rechnungsstellungsrahmen (BT-23): Grundgerüst `B1`/`S1`/`M1` je nach Art der Positionen (Regelfall: Einreichung durch den Lieferanten), `B4`/`S4`/`M4` für eine Schlussrechnung nach Anzahlung (nie auf der Anzahlung selbst — BR-FR-CO-08); AIFE-Rahmen `A1..A25` pro Rechnung im Chorus-B2G-Profil | Automatisch ausgegeben |
| BR-FR-09 | Konsistenz SIRET/SIREN (Verkäufer und Käufer): 14-stellige SIRET, deren erste 9 Ziffern = SIREN | Blockierender Verstoß + vorgelagerte Warnung |
| BR-FR-16 | USt-Satz vs. geschlossene französische Liste (Mutterland + Überseegebiete) | **Nur Warnung** — ein legitimer Satz wird nie blockiert |
| BR-FR-CO-04 / BR-FR-CO-05 | Korrekturrechnung (384…): genau **eine** Referenz auf die Ursprungsrechnung (BT-25) mit deren Datum — der Generator referenziert dann in BG-3 NUR die ersetzte Rechnung (verrechnete Anzahlungen bleiben über BT-113 geführt); Gutschrift (381…): mindestens eine datierte Referenz | Blockierender Verstoß (+ vorgelagerte Warnung, falls die Ursprungsrechnung nicht verknüpft ist) |
| BR-FR-23 / BR-FR-25 | Elektronische Adresse 0225: nur alphanumerisch + `- _ .` (das konfigurierte Verkäufer-Suffix wird bei der Ausgabe bereinigt), max. 125 Zeichen | Blockierender Verstoß (Zeichensatz) + Warnung (Länge) |
| BR-FR-MAP-03 / MAP-29 | USt-Fälligkeit (BT-8): `5` = Soll-Versteuerung (einziger vom PPF erwarteter CII-Wert), `72` = Ist-Versteuerung, abgeleitet von `TAX_MODE` | Automatisch ausgegeben |

Zur Erinnerung: „blockierende Verstöße" unterbrechen die Erzeugung nur im Strikt-Modus (`AUVOERECHNUNG_STRICT_MODE=1`); im best-effort-Modus werden sie in der Warnmeldung und im Syslog konsolidiert.

**Bewusste Einschränkungen**:
- das Profil **EXTENDED-CTC-FR wird nicht ausgegeben** (das Modul erzeugt EN16931) — angestrebtes Ziel für 2027; die Variante „Positionsreferenz" einer Gutschrift (EXT-FR-FE-136, BR-FR-CO-05) ist damit gegenstandslos;
- die **Konstruktion der Flüsse 1 / 10.1** zum PPF (Kürzungen BR-FR-MAP-\*, Umcodierung der Überseeländer-Codes…) wird an die **Plateforme Agréée delegiert**: das Modul liefert das Factur-X-Quelldokument;
- BR-FR-10/11 (SIREN im PPF-Verzeichnis vorhanden und aktiv) erfordern den Zugriff auf das Verzeichnis: nicht von diesem Modul abgedeckt (Sache der Plattform bzw. eines PDP-Anbindungsmoduls).

### Verkäufer außerhalb Frankreichs (ZUGFeRD)

Factur-X und ZUGFeRD 2.x sind **dieselbe** deutsch-französische Spezifikation: das vom Modul erzeugte CII-XML (Profil EN16931, eingebettet unter dem Namen `factur-x.xml`, den ZUGFeRD seit 2.1 vorschreibt) ist direkt als ZUGFeRD-Rechnung nutzbar. Die eingebetteten Schemata sind die von Factur-X 1.09.2 (ZUGFeRD 2.5.2).

Trägt die Firmenfiche (`$mysoc`) ein anderes Land als Frankreich (gleichgestellte Überseegebiete ausgenommen, BR-FR-MAP-14), injiziert der Generator keine Elemente des französischen Rechts mehr:

| Element | Verkäufer FR | Verkäufer außerhalb FR |
|---|---|---|
| Hinweise **PMD / PMT / AAB** (BR-FR-05) | Standardtexte aus dem Code de commerce, überschreibbar | **kein Standard** — nur explizit gesetzte `AUVOERECHNUNG_NOTE_*`-Konstanten werden ausgegeben |
| Hinweis **BAR** (BR-FR-07/20/31) | ausgegeben (`B2B` / `B2BINT` / `B2C`) | **nicht ausgegeben** (qualifiziert einen Vorgang der FR-Reform) |
| Rechnungsstellungsrahmen **BT-23** (BR-FR-08) | ausgegeben (`B1`/`S1`/`M1`…) | **nicht ausgegeben** (optional in EN16931, XP-Z12-012-eigene Codes) |
| Vorgelagerte **SIREN/SIRET**-Prüfung | aktiv | deaktiviert (französische Kennungen) |
| Regelblock **BR-FR** des Validators | aktiv | deaktiviert |

**Deutscher Fall — Kleinunternehmer (§ 19 UStG).** Ist „Umsatzsteuerpflichtig" auf der Firmenfiche auf *Nein* gesetzt, wechselt die USt-Kategorie zu `E` mit dem Grund `Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG` und **ohne** VATEX-Code (kein Code der CEF-Liste passt; der Freitext erfüllt BR-E-10). Da keine USt-IdNr vorliegt, fehlt BT-31: das Modul veröffentlicht dann die **Steuernummer** in BT-32 (`SpecifiedTaxRegistration/ID schemeID="FC"`), um BR-CO-26 zu erfüllen.

> ⚠️ **Wo die Steuernummer eintragen** — Dolibarr-Konventionen für Deutschland: `idprof1` = **USt-IdNr**, `idprof2` = **USt-Nr**, also die Steuernummer. Es ist also das Feld **„Prof Id 2"** der Firmenfiche zu befüllen. Das Feld `idprof1` wird nur als Rückfall gelesen, und nur, wenn sein Wert nicht wie eine USt-IdNr aussieht — eine USt-IdNr darf nie unter dem Schema `FC` statt `VA` veröffentlicht werden.

Deutsche Verzugshinweise (§ 288 BGB) werden **nicht** automatisch ausgegeben: in die drei Felder „Gesetzliche Pflichtangaben" der Modulkonfiguration einzutragen, wenn sie im XML und der PDF-Fußzeile erscheinen sollen.

## REST-API

Mit aktiviertem Dolibarr-REST-API-Modul (Benutzer-API-Schlüssel, Rechnungsrechte):

| Endpoint | Beschreibung |
|---|---|
| `GET /api/index.php/auvoerechnung/invoice/{id}/xml` | Neu erzeugtes Factur-X-XML + Warnungen + BR-Verstöße |
| `GET /api/index.php/auvoerechnung/invoice/{id}/status` | PDF vorhanden? XML eingebettet? BR-Verstöße des eingebetteten XML |

## Stapelexport

```bash
php scripts/export_facturx_batch.php /pfad/export [2026]
```

Extrahiert das eingebettete Factur-X-XML aller validierten Rechnungen (des angegebenen Jahres, falls angegeben) nach `<ref>.xml`, mit Bericht `OK / NO_PDF / NO_XML` — für Audit, Archivierung oder manuelle Einreichung bei einer Plattform.

## Eingebettete Abhängigkeiten

Der Ordner `vendor/` enthält die benötigten Libs (kein Composer auf dem Server erforderlich):

- `atgp/factur-x` v3.4.1 — Erzeugung von Factur-X-PDFs
- `setasign/fpdi` v2.6.6 — Lesen/Schreiben von PDFs
- `setasign/fpdf` 1.8.6 — PDF-Engine (von atgp genutzt, **nicht** von Dolibarr)
- `smalot/pdfparser` v2.12.5 — PDF-Parsing
- `symfony/polyfill-mbstring` — mbstring-Kompatibilität

## PDF/A-3-Konformität

Die PDF/A-3-Konformität wird sichergestellt durch:
- **Eingebettete Schriftarten**: gewählt über den Selektor **„Schriftart der erzeugten PDF"** des Moduls (`pdfahelvetica` standardmäßig); die Diagnose prüft, dass eine eingebettete Schriftart gesetzt ist. Ohne eingebettete Schriftart (Helvetica Base-14) ist die PDF/A-3 ungültig, auch wenn die Darstellung korrekt erscheint.
- **AFRelationship `Alternative`**: konform zur Factur-X-Spezifikation für das Profil EN16931 (in 3.0.0 korrigiert, vorher `Data`)
- **Annotationen /F-Flag**: Patch angewendet in `vendor/setasign/fpdf/fpdf.php` (Ergänzung `/F 4` bei Links)
- **ICC-Profil sRGB** + **XMP-Metadaten**: von der Lib `atgp/factur-x` verwaltet
- **Optionale Post-Validierung mit veraPDF** (`AUVOERECHNUNG_VERAPDF_PATH`), um nicht konforme Custom-PDF-Vorlagen zu erkennen

> **Hinweis**: hardcodiert ein Drittmodul (z. B. Meilensteine) die Schriftart `'Helvetica'`, muss es gepatcht werden, um `pdf_getPDFFont($outputlangs)` zu nutzen.

## Einschränkungen und nicht behandelte Fälle

Jeder nicht behandelte Fall (Multiwährung, lokale Steuern, Baufortschritt, Selbstfakturierung, inländisches Reverse-Charge, PDP-Konnektor, Verzeichnis, Order-X...) ist mit seinem Verhalten und dem Grund der Entscheidung in **[docs/LIMITATIONS.md](docs/LIMITATIONS.md)** dokumentiert.

## Validierung und Tests

**Offizieller KoSIT-Validator:** `tests/kosit-validate.sh` prüft jedes XML, das die Unit-Tests als gültig einstufen, mit dem Validator der KoSIT, für ZUGFeRD/Factur-X gegen die EN16931-Regeln und für XRechnung gegen die XRechnung-Regeln. Abgelehnte Dateien oder Fehler lassen den Lauf scheitern, Warnungen werden angezeigt. Das läuft bei jedem Push in der GitHub-CI und vor jedem Release-Build. Lokal (Java 11+ nötig, das Bundle wird einmalig geladen und per SHA-256 geprüft):

```bash
bash tests/kosit-validate.sh                 # java aus dem PATH
JAVA=/pfad/zu/java bash tests/kosit-validate.sh
```

Externe Validierung über den [B2Brouter Factur-X Validator](https://www.b2brouter.net/fr/factur-x-validator/) oder den FNFE-MPE-Validator.

### Online-Validatoren: bekannte Fehlalarme

Nicht alle Online-Validatoren bündeln dieselbe Version der Schemata und Codelisten. Eine Datei, **konform zu den aktuellen offiziellen Artefakten**, kann von einem Validator abgelehnt werden, der auf Artefakten von ~2021 stehengeblieben ist. Zwei fälschlicherweise identifizierte Ablehnungen (Juni 2026, Datei gegengeprüft mit xmllint/XSD 1.08 + veraPDF 146/146 + zweitem Validator):

| Angezeigte Ablehnung | Ursache seitens Validator | Realität |
|---|---|---|
| XSD: „`InvoiceReferencedDocument`… not expected. Expected is (`ReceivableSpecifiedTradeAccountingAccount`)" | XSD aus der ZUGFeRD-2.1.1-Ära: `InvoiceReferencedDocument` ohne `maxOccurs` → nur 1 erlaubt. Das 2. Vorkommen (Schlussrechnung mit mehreren verrechneten Anzahlungen/Gutschriften) lässt es fehlschlagen | BG-3 ist **0..n** in EN16931; die XSD Factur-X 1.09.2 (hier eingebettet) deklariert es als `maxOccurs="unbounded"` |
| „[BR-CL-25] Die Schema-Kennung… MUSS zur CEF-EAS-Codeliste gehören" beim Endpoint `0225` | EAS-Liste der [Validierungsartefakte](https://github.com/ConnectingEurope/eInvoicing-EN16931) ≤ 1.3.9 (Okt. 2021), die bei `0220` endet | `0225` (elektronische SIREN-Adresse, Verzeichnis der FR-Reform) ist in der aktuellen EAS-Liste enthalten |

Im Zweifelsfall gilt: XSD-Validierung gegen die eingebetteten Factur-X-1.09.2-Schemata (`xmllint --schema`), **aktuelle** Schematron-Artefakte der Europäischen Kommission, und veraPDF für PDF/A-3. Das XML nicht degradieren (das mehrfache BG-3 entfernen, `0225` ändern), um einen veralteten Validator zufriedenzustellen.

### Standalone-Unit-Tests (CI)

`tests/unit-tests.php` läuft **ohne Dolibarr** (eingebettete Stubs): Szenarien und Assertions decken Gutschriften, Rabatte BG-21, innergemeinschaftlich K/AE, Export, Kleinunternehmerregelung, Rundungs-Stresstest mit 50 Positionen, Anzahlungen, SEPA-Lastschrift, Multiwährung, Formate, die Frankreich-Regeln XP Z12-012 (BT-1, SIREN-/SIRET-Konsistenz, BG-3 von Gutschriften/Korrekturen — inkl. 384 mit verrechneter Anzahlung, BT-23-Grundgerüst, BAR-Hinweis, Endpoint-Bereinigung, USt-Sätze, Nichtanwendung des BR-FR-Blocks bei ausländischem Verkäufer), den deutschen Kleinunternehmer-Fall (BT-32 aus idprof2, § 19 UStG ohne VATEX-Code, kein FR-Regelwerk) und die Leitweg-ID-Priorität in BT-10 für deutsche Behörden-Käufer ab. Jedes erzeugte XML wird **XSD- und geschäftsregelgeprüft**.

```bash
php tests/unit-tests.php
```

Automatisch von der GitHub-CI (`.github/workflows/ci.yml`) bei jedem Push/PR ausgeführt, und vor jedem Release-Build.

### Integrationstests

`tests/run-tests.php` deckt die 10 Fixture-Fälle (`demo/fixtures.php`) gegen ein echtes Dolibarr ab: TypeCode, CategoryCode, unitCode, optionale Blöcke, Beträge, XSD-Validierung.

```bash
php tests/run-tests.php   # exit 0 = OK, 1 = Fehlschlag
```

## Changelog

### Unveröffentlicht

- **XRechnung 3.0 (CIUS KoSIT)**, erster Schritt: Für Kunden mit Leitweg-ID oder mit dem neuen Geschäftspartner-Feld **„E-Rechnungsformat“** = XRechnung wird zusätzlich zur ZUGFeRD-PDF die Datei `{Rechnung}-XRECHNUNG.xml` erzeugt. Inhalt: BT-24 `…#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0`, BT-23 Peppol Billing, Ansprechpartner des Verkäufers (BG-6, neu in den Moduleinstellungen, sonst Ersteller der Rechnung), elektronische Adresse des Käufers = Leitweg-ID mit Schema `0204`, Kundenreferenz als Bestellnummer (BT-13). Fehlen Pflichtangaben, wird keine Datei geschrieben und die Ursachen werden angezeigt. Neue interne Prüfregeln BR-DE-* / PEPPOL-EN16931-R001/R010/R020.
- **Korrektur BR-CO-26 (betrifft auch ZUGFeRD):** Verkäufer ohne USt-IdNr. (z. B. Kleinunternehmer) wurden vom offiziellen Validator abgelehnt, weil die Steuernummer (BT-32) die Regel nicht erfüllt. Jetzt wird bei deutschen Verkäufern die Handelsregisternummer (Prof-ID 3) als BT-30 ausgegeben, sonst die Steuernummer zusätzlich als Verkäuferkennung BT-29. Die interne Prüfung von BR-CO-26 entspricht jetzt dem offiziellen Schematron.
- Geprüft mit dem offiziellen KoSIT-Validator 1.6.3 und der XRechnung-Konfiguration vom 31.08.2026 (Bundle 3.0.2).
- **Bestellnummer (BT-13) korrigiert:** Bei einer Rechnung aus einem Kundenauftrag wurde die interne Auftragsnummer (z. B. `CO2606-0042`) als Bestellnummer des Käufers ausgegeben. Jetzt kommt BT-13 aus dem Feld „Ihr Zeichen“ (Kunden-BestellNr.) des Auftrags. Ist es leer und hat der Kunde eine Leitweg-ID, wird „Ihr Zeichen“ der Rechnung verwendet, für ZUGFeRD und XRechnung.
- **Offizieller KoSIT-Validator in der CI:** Alle XML aus den Unit-Tests (derzeit 34, ZUGFeRD und XRechnung) werden bei jedem Push und vor jedem Release mit dem Validator der KoSIT geprüft (`tests/kosit-validate.sh`, Bundle XRechnung 3.0.2 vom 31.08.2026).
- **Nach dem Update das Modul einmal deaktivieren und wieder aktivieren**, damit das neue Feld „E-Rechnungsformat“ angelegt wird.
- Meldungen im Dolibarr-Log (Syslog) auf Englisch statt Französisch, z. B. „Factur-X PDF generated for …“ statt „PDF Factur-X généré pour …“. Die Meldungen für Nutzer bleiben in deren Sprache (Sprachdateien).

### 5.0.0 — Umbenennung in AuVo E-Rechnung (Oktober 2026)

- **Neuer Name**: DoliZUGFeRD heißt jetzt **AuVo E-Rechnung** (Modulordner `auvoerechnung`, Konstanten `AUVOERECHNUNG_*`, REST-API `/auvoerechnung/…`, Release-Asset `module_auvoerechnung-X.Y.Z.zip`, Repo `Fips81/dolibarr-auvo-erechnung`). Der Name nennt das Thema statt eines Standards: Er passt zur geplanten XRechnung-Erweiterung und vermeidet fremde Marken im Modulnamen.
- **Migration** von DoliZUGFeRD 4.0.x und AuvoZugferd 3.x bei der ersten Aktivierung — siehe [Umstieg](#umstieg-von-dolizugferd-40x-oder-auvozugferd-3x-auf-auvo-e-rechnung-50).
- Hinweis im README, dass das Modul kein offizielles Produkt von FeRD, der Dolibarr Foundation oder von Lemon ist.
- Modulnummer bleibt **194202**.

### 4.0.1 — Offizielle Modulnummer (Oktober 2026)

- Modulnummer von 486213 auf **194202** geändert, die erste Nummer des im Dolibarr-Wiki (*List of modules id*) für AuVo Design reservierten Blocks 194202–194211. Damit sind Kollisionen mit Modulen anderer Anbieter ausgeschlossen. Dolibarr speichert die Aktivierung über den Modulnamen, deshalb bleibt das Modul nach dem Update aktiv, und Einstellungen sowie Daten bleiben erhalten. Eine erneute Aktivierung ist nicht nötig.

### 4.0.0 — Umbenennung in DoliZUGFeRD (Oktober 2026)

- **Neuer Name**: AuvoZugferd heißt jetzt **DoliZUGFeRD** (Modulordner `dolizugferd`, Konstanten `DOLIZUGFERD_*`, REST-API `/dolizugferd/…`, Release-Asset `module_dolizugferd-X.Y.Z.zip`). Automatische Übernahme der Einstellungen und Zusatzfelder bei der ersten Aktivierung — siehe [Umstieg von AuvoZugferd](#umstieg-von-auvozugferd-version-3x-auf-auvoerechnung-40).
- **Steuernummer aus dem richtigen Feld**: In Dolibarr ist bei deutschen Firmen Prof-ID 1 die Steuernummer und Prof-ID 2 das Amtsgericht. Das Modul las bisher Prof-ID 2 — ein dort eingetragenes Amtsgericht wäre als Steuernummer (BT-32) ausgegeben worden. Jetzt wird Prof-ID 1 gelesen (Prof-ID 2 nur noch, wenn sie wie eine Steuernummer aussieht).
- **Keine Steuernummer mehr als USt-IdNr.**: Ein Wert ohne Ländercode im Feld USt-IdNr. (z. B. eine dort eingetragene Steuernummer) wird nicht mehr als USt-IdNr. (BT-31, `schemeID="VA"`) ausgegeben, sondern — wenn es eine Steuernummer ist — als BT-32. Neue Warnung bei der Rechnungserzeugung und in der Diagnose; neue Prüfregel BR-CO-09.
- **Diagnose ohne Frankreich-Ballast**: SIRET/SIREN-Prüfung und der Block „Adressierung über zugelassene Plattform (PA)“ erscheinen nur noch bei Verkäufern in Frankreich. Bei deutschen Verkäufern prüft die Diagnose stattdessen Steuernummer bzw. USt-IdNr.
- **Deutsche Befreiungsgründe im XML**: Bei Verkäufern in Deutschland werden die Befreiungsgründe (BT-120) für innergemeinschaftliche Lieferung (§ 4 Nr. 1b UStG), Reverse Charge (Art. 196 MwStSystRL), Ausfuhr (§ 4 Nr. 1a UStG / § 3a Abs. 2 UStG) und sonstige 0-%-Positionen auf Deutsch statt auf Französisch ausgegeben.
- **Korrektur innergemeinschaftliche Erkennung**: Die Kategorien K/AE wurden gegen „Käuferland ≠ FR“ geprüft. Ein deutscher Verkäufer mit einem deutschen Käufer (mit USt-IdNr.) und 0 % bekam dadurch fälschlich K bzw. AE. Verglichen wird jetzt mit dem Land des Verkäufers.
- **Übersetzbare Prüfmeldungen**: Die Meldungen der internen Geschäftsregel-Prüfung (BR-*), der XSD-Prüfung und der PDF-Einbettung laufen über die Sprachdateien (de/en/fr) statt fest auf Französisch.
- Beschreibungen der Modul-Konstanten, Ausgaben von `scripts/export_facturx_batch.php`, `SECURITY.md` und `docs/LIMITATIONS.md` auf Deutsch.
- Aufräumen für die öffentliche Weitergabe: Hinweise auf „privaten Fork“ entfernt, Lemon-spezifische Demo-Infrastruktur (`reset-demo.sh`, `rebuild-demo.sh`, `fixtures-rich.php`, Demo-Admin `axel`) entfernt, `docs/spec-acomptes.md` (historisch) entfernt.
- Kompatibilität bis Dolibarr 24.x angegeben (im Einsatz auf 24.0.2).

### 3.9.5 — Rebrand, deutsche Ergänzungen (August 2026)

Fork, umbenannt von LemonFacturX zu AuvoZugferd (siehe [Lizenz](#lizenz) für die GPL-Copyright-Behandlung). Seit dem letzten dokumentierten Stand außerdem:
- **Deutsche Unterstützung**: Kleinunternehmer/§ 19 UStG (Steuernummer als BT-32 aus `idprof2`), Unterdrückung der FR-only-Elemente für ausländische Verkäufer, deutsche Übersetzung (`langs/de_DE`), UI-Beschriftung „ZUGFeRD" statt „Factur-X" für deutsche Nutzer.
- **Leitweg-ID** (BT-10) für deutsche Behörden-Käufer: neues Extrafield auf der Tiersfiche, Priorität Leitweg-ID > Chorus-Service-Code > `ref_client`.
- `atgp/factur-x` aktualisiert v3.3.0 → v3.4.1 (Fix eines Tippfehlers im PDF/A-OutputIntent-Schlüssel, robustere XML-Namespace-Behandlung beim Lesen fremder Factur-X/ZUGFeRD-Dateien).

### 3.9.2 (Juli 2026)

**Das Bankkonto des XML folgt dem der Rechnung** ([issue #16](https://github.com/Fips81/dolibarr-auvo-erechnung/issues/16)). Das Modul veröffentlichte stets den in der Konfiguration fest eingestellten IBAN/BIC, auch wenn auf Tiers- oder Rechnungsebene ein anderes Konto gewählt war (die PDF zeigte dagegen das richtige) — daher ein inkonsistenter IBAN zwischen PDF und XML. Die Auflösungsreihenfolge ist nun: **auf der Rechnung gewähltes Konto (`fk_account`) → in der Modulkonfiguration eingestelltes Konto (Rückfall)**. Abwärtskompatibel: ohne Konto auf der Rechnung bleibt das bisherige Verhalten erhalten.

### 3.9.1 (Juli 2026)

`ActionsAuvoERechnung::verifyInvoicePdf()` wird **öffentlich**: sie wird auch von externen Modulen aufgerufen (z. B. aus einem Reiter „Elektronische Rechnungsstellung" mit Button „Factur-X prüfen"), um das Ergebnis anzuzeigen, ohne den Reiter zu verlassen. Keine weitere Änderung.

### 3.9.0 (Juli 2026)

**Konformität mit XP Z12-012 (Ausgabe Juni 2026) — Mindest-Grundgerüst der Reform.**
- **Frankreich-Regelblock im Validator**: BR-FR-01/02 (BT-1 ≤ 35 Zeichen, strikter Zeichensatz ohne Leerzeichen), BR-FR-04 (geschlossene Liste der Dokumenttypen BT-3), BR-FR-09 (SIRET-/SIREN-Konsistenz Verkäufer und Käufer), BR-FR-23 (Zeichensatz der elektronischen Adressen unter schemeID 0225).
- **Pflichtreferenzen** (BR-FR-CO-04/05): eine Gutschrift ohne Referenz auf die Ursprungsrechnung ist jetzt **blockiert** (nicht mehr nur eine Warnung); eine Korrekturrechnung (384) verlangt genau eine datierte Referenz — und aggregiert verrechnete Anzahlungen nicht mehr in BG-3 (Fehler behoben).
- **BT-23-Rechnungsstellungsrahmen** für das Profil PDP/B2B ausgegeben (B1/S1/M1 aus den Positionen abgeleitet, x4 für eine Schlussrechnung nach Anzahlungen) — der Chorus-Pro-B2G-Pfad (AIFE-Codes) ist unverändert.
- **BAR-Hinweis** (BR-FR-20): erwartete Verarbeitungsqualifikation der Plattformen (B2B / B2BINT / B2C) im XML ausgegeben, aus dem Profil des Tiers abgeleitet.
- Bereinigung des Verkäufer-Endpoint-Suffix + Längengrenze (BR-FR-23/25); BT-8 gegen die Codeliste geprüft (Mapping 5/72 bereits korrekt, Dokumentation erweitert).
- **+50 Unit-Tests** (177 insgesamt) für alle neuen Regeln.
- Dokumentierte bewusste Einschränkung: Profil EXTENDED-CTC-FR (mehrere Verkäufer, Unterpositionen) nicht ausgegeben — Ziel 2027; die Konstruktion der Flüsse 1/10.1 obliegt der Plateforme Agréée.

### 3.8.0 (Juni 2026)

**Neu: Unterstützung von ODT-Rechnungsvorlagen.** Das Modul klinkt sich nun auch in den Hook `afterODTCreation` (Kontext `odtgeneration`) ein und injiziert das Factur-X in die aus einer ODT-Vorlage per LibreOffice konvertierte PDF (Haupt-Factur-X + ggf. 2. PDF Chorus). Voraussetzung: `MAIN_ODT_AS_PDF = libreoffice` + `MAIN_ODT_AS_PDFA = 1` (sonst ist die erzeugte PDF ≥ 1.5 / komprimierte Xref, für FPDI unlesbar). Code zu 100 % best-effort: jede fehlende Bedingung degradiert sauber mit einer expliziten Warnung, ohne Fatal Error oder beschädigte PDF. Intern validiert (LibreOffice 7.4) und unter realen Bedingungen (ODT-Erzeugung bei einem Nutzer bestätigt); siehe Abschnitt „Unterstützung von ODT-Vorlagen". **Modul reaktivieren**, damit der neue Hook-Kontext berücksichtigt wird.

### 3.7.3 (Juni 2026)

**EN16931-Konformitätskorrektur.** Die SIRET-Kennung des Verkäufers (BT-29) und des Käufers (BT-46) wurde in `ram:ID schemeID="0009"` auf Ebene des `TradeParty` ausgegeben. EN16931 liest das `schemeID` jedoch nur auf `ram:GlobalID`: auf `ram:ID` ist das Attribut „not used in the given context" und ließ die **Schematron-Validierung fehlschlagen** (festgestellt mit dem FNFE-MPE-Validator — „Fully Valid: NO"). Die qualifizierte Kennung wandert nun in `ram:GlobalID schemeID="0009"`. Die SIREN in `SpecifiedLegalOrganization` (BT-30, `schemeID="0002"`) und das Chorus-Pro-Profil ändern sich nicht. Betroffene Rechnungen neu erzeugen, um von der Korrektur zu profitieren.

### 3.7.2 (Juni 2026)

**REST-API-Korrektur.** Die API exponiert die Core-Klasse `Facture` nicht mehr in ihrer Spec (`@return object` statt `@return Facture`): das `@return Facture` ließ die Erzeugung von `/explorer/swagger.json` mit **HTTP 500** abstürzen, sobald das Modul aktiv war (Restler versuchte, die gesamte Core-Klasse zu modellieren). Direkte authentifizierte API-Aufrufe waren nicht betroffen — nur der Explorer/die OpenAPI-Spec.

### 3.7.1 (Juni 2026)

**Regressionskorrektur (anzuwenden).** 3.7.0 hatte die In-Process-Injektion zum Standardmodus gemacht, was auf einem Standard-Dolibarr eine Fatal Error `Declaration of FpdfTplTrait::setPageFormat must be compatible with TCPDF::setPageFormat` auslöst: `pdf_getInstance()` lädt bei jeder Erzeugung die Engine `tcpdi` (`class FPDF extends TCPDF`), und die Injektionsbibliothek FPDI erbt dann davon → Signaturkonflikt, Rechnungserzeugung kaputt. Die Injektion läuft wieder über einen **isolierten PHP-Subprozess**, sobald `exec()` verfügbar ist (jungfräulicher Prozess, kein Konflikt möglich — Verhalten wie vor 3.7.0). In-Process wird nur versucht, wenn `exec()` deaktiviert ist, mit einer **Absicherung**, die die klassische PDF statt eines Absturzes beibehält, und einer **Diagnose**, die das weitere Vorgehen anzeigt (`exec()` aktivieren, oder `MAIN_DISABLE_TCPDI=1` setzen).

### 3.7.0 (Juni 2026)

Einstellbarer Injektionsmodus (`AUVOERECHNUNG_INJECTION_MODE`) mit In-Process-Injektion ohne `exec()`. **Nicht so verwenden — in 3.7.1 korrigiert** (FPDF/TCPDF-Konflikt über die `tcpdi`-Engine von Dolibarr).

### 3.6.3 (Juni 2026)

**PDF-Schriftart**-Selektor in der Konfiguration (Factur-X-Kompatibilität für jede Schriftart angezeigt); gesetzliche Pflichtangaben BR-FR-05 auf der PDF zentriert.

### 3.6.2 (Juni 2026)

Kopie der gesetzlichen Pflichtangaben BR-FR-05 in die Rechnungsfußzeile (`INVOICE_FREE_TEXT`) mit Option „Überschreiben"; Button, um die Chorus-Felder in die öffentliche Notiz der Rechnung zu übertragen.

### 3.6.1 (Juni 2026)

Korrektur: die Chorus-Felder erscheinen unter **Dolibarr 23** nicht mehr als störender Block im PDF-Rumpf (`printable=0` für die Chorus-Extrafields erzwungen).

### 3.6.0 (Juni 2026)

Kompatibilität mit **Dolibarr 23** (Chorus-Reiter und -Hooks), bessere Performance bei Rechnungsentwürfen, Verkäufer-Endpoint-Suffix (BT-34) für die Adressierung der Plateformes Agréées.

### 3.5.1 (Juni 2026)

Korrektur der PHP-CLI-Binary-Erkennung unter **Windows**.

### 3.5.0 (Juni 2026)

Vereinfachte Einstellungen und **automatische Erkennung des PHP-CLI-Binary** (im üblichen Fall keine manuelle Konfiguration mehr nötig).

### 3.4.0 (Juni 2026)

Unterstützung von **Chorus Pro (B2G)**: Erzeugung einer zweiten, dem Chorus-Profil gewidmeten PDF, ohne je das Standard-Factur-X zu verändern.

### 3.2.1 (Juni 2026)

SIREN aus dem Dolibarr-SIREN-Feld (`idprof1`) gelesen statt aus der SIRET abgeleitet.

### 3.2.0 (Juni 2026)

**SIRET und SIREN in zwei getrennten Feldern** (SIRET → `ram:ID` 0009 BT-29/46, SIREN → `SpecifiedLegalOrganization` 0002 BT-30/47) — behebt die Ablehnung bei der Einreichung auf den Plateformes Agréées (BR-FR-10).

### 3.1.0 (Juni 2026)

Gesetzliche Pflichtangaben BR-FR-05 per Klick aus der Konfiguration auf der PDF sichtbar gemacht.

### 3.0.3 (Juni 2026)

SIREN-/Routing-Warnung: Kriterium „nicht umsatzsteuerpflichtig" ergänzt, um bestimmte Tiers nicht mehr fälschlich zu melden.

### 3.0.2 (Juni 2026)

Ein-Klick-Korrektur von `MAIN_PDF_FORCE_FONT` aus der Diagnose.

### 3.0.1 (Juni 2026)

Keine SIREN-Warnung mehr für Privatkunden (B2C).

### 3.0.0 (Juni 2026)

Große Konformitäts-Überarbeitung — **vor dem Update die Verhaltensänderungen lesen**.

**Konformitätskorrekturen (vorher blockierend)**:
- **Gutschriften (381)**: Beträge nun **positiv** ausgegeben (BR-27) mit exaktem `DuePayableAmount` (BR-CO-16 — die Nullkappung erzeugte von Schematron-Validatoren abgelehnte Gutschriften) + BG-3-Referenz auf die Ursprungsrechnung.
- **AFRelationship `Alternative`** statt `Data` (von der Factur-X-Spezifikation für das Profil EN16931 gefordert; `Data` wurde von Mustang/FNFE als Fehler gemeldet).
- **Feste Rabatte**: in Dokument-Rabatte BG-21 umgewandelt (Positionen mit negativem Preis verletzten BR-27).
- **Innergemeinschaftlich (K)**: ShipTo-Lieferland (BR-IC-12) und Lieferdatum (BR-IC-11) ausgegeben; Unterscheidung **K (Waren) / AE (Dienstleistungen Art. 196)** über `product_type`.
- **BR-61**: Zahlungsmittelblock weggelassen, falls Überweisung ohne konfigurierten IBAN (statt eines abgelehnten XML).
- **USt-Aufschlüsselung nach (Kategorie, Satz)** + Rundungsabgleich mit den Rechnungssummen (BR-CO-14/17); BG-22-Summen von unten nach oben neu berechnet (BR-CO-10..16).
- **Multiwährung und lokale Steuern**: erkannt und sauber abgelehnt (das XML wich zuvor still von der sichtbaren PDF ab).
- **SIREN/SIRET nur für französische Tiers**: die lokale Kennung eines ausländischen Tiers (deutsche HRB...) wird nicht mehr unter einem SIREN-/SIRET-Schema veröffentlicht — Rückfall auf E-Mail für den Endpoint.

**Verhaltensänderungen**:
- **Zwei Kennungen in zwei getrennten Feldern (seit 3.2.0)**: die **SIRET** (Niederlassung, 14 Ziffern) geht in `ram:ID` schemeID 0009 (BT-29/BT-46), und die **SIREN** (juristische Einheit, 9 Ziffern) in `SpecifiedLegalOrganization/ram:ID` schemeID 0002 (BT-30/BT-47). Bis 3.1.x setzte das Modul fälschlich die SIRET in `SpecifiedLegalOrganization`, was die Regel **BR-FR-10** („Verkäufer-SIREN pflicht, genau 9 Ziffern") und die Einreichung auf den Plateformes Agréées scheitern ließ („das mit der Sitzung verknüpfte Unternehmen entspricht nicht dem Verkäufer"). Chorus Pro behält seine SIRET über BT-29/BT-46. Keine Einstellung nötig: jede Kennung an ihrem Platz.
- **Gesetzliche Pflichtangaben BR-FR-05 (PMD/PMT/AAB)**: der Standardtext greift nun tatsächlich, wenn das Feld leer gelassen wird (zuvor erzeugten die bei Aktivierung leer angelegten Konstanten leere Hinweise im XML).
- **Zahlungsart-Bezeichnungen korrigiert**: 58 = SEPA-**Überweisung** (nicht Lastschrift); neuer Code 59 = SEPA-Lastschrift (mit Gläubiger-ID/Mandatsreferenz/Debitor-IBAN BT-89/90/91). **Einstellung prüfen, falls „58 - SEPA-Lastschrift" gewählt war**.
- **BT-72**: reales Lieferdatum (`delivery_date`) oder Block weggelassen — das Ausstellungsdatum wird nicht mehr als Lieferdatum vorgetäuscht (außer beim innergemeinschaftlichen Rückfall).
- Mengen und Einzelpreise mit bis zu 4 Dezimalstellen ausgegeben.

**Neu ausgegebene Daten**: BT-8 (USt Soll-/Ist-Versteuerung, Konfiguration), BT-10 (`ref_client`), BT-13 (verknüpfte Bestellung), BT-23 (Rechnungsstellungsrahmen, Konfiguration), BG-3 (vorangehende Rechnungen: Gutschriften, Korrekturen 384, verrechnete Anzahlungen), BG-14 (Zeitraum aus den Leistungsdaten), BT-121 (VATEX-Codes), BT-89/90/91 (Lastschrift).

**Werkzeuge**:
- Interner Validator für **EN16931-Geschäftsregeln** (Teilmenge des Schematron in PHP) vor der Injektion — blockierend im Strikt-Modus.
- Buttons **„Factur-X prüfen"** / **„Factur-X neu erzeugen"** auf der Rechnungsfiche.
- **REST-API** (`/auvoerechnung/invoice/{id}/xml` und `/status`) und **Stapelexport** (`scripts/export_facturx_batch.php`).
- Optionale Post-Validierung mit **veraPDF**; erweiterte Diagnose (`MAIN_PDF_FORCE_FONT`, `exec()`, PHP-CLI-Binary, Multiwährungs-Hinweis).
- Suite von **Standalone-Unit-Tests** (ohne Dolibarr) + **GitHub-CI** (Lint + Tests bei jedem Push/PR, und vor jeder Release).
- Vollständige **en_US**-Übersetzung; Hook- und Kontrollmeldungen internationalisiert.

**Robustheit und Sicherheit**:
- **Atomares** Schreiben der PDF im Subprozess (eine volle Festplatte kann die PDF nicht mehr abschneiden); Schreibrückgaben geprüft; `catch \Throwable`.
- CLI-Schutz auf `demo/*` und `tests/*` (die Fixtures erzeugten einen per HTTP erreichbaren Demo-Admin, falls das Repo unter dem Web-Root geklont war) + `.htaccess`-Zugriffsverweigerung auf `demo/`, `tests/`, `scripts/`.
- GitHub Actions per SHA gepinnt; Cache der Fehlschläge beim Versionscheck (Admin-Seite hakt nicht mehr, wenn GitHub nicht erreichbar ist); `curl_init`-Absicherung; `entity`-Filter auf Bankkonten (Multicompany) und Kontakte.
- Voraussetzungen im Deskriptor hinterlegt (`phpmin` 8.1, `need_dolibarr_version` 16).
- Präfigierte globale Funktionen (`xmlEncode`/`formatAmount` → `auvoerechnung_xml_encode`/`auvoerechnung_format_amount`).

**Migration**: keine DB-Migration, aber **Modul deaktivieren und wieder aktivieren** nach dem Update, um den neuen Hook `invoicecard` (Buttons der Rechnungsfiche) zu registrieren. Danach prüfen: (1) die Zahlungsart-Einstellung, falls „58" für Lastschrift gewählt war → auf 59 umstellen; (2) kürzlich nicht übermittelte Gutschriften neu erzeugen, um von der Korrektur zu profitieren. Die gesetzliche Kennung BT-30/BT-47 ist jetzt immer SIRET/0009 (keine Einstellung mehr).

### 2.1.2 (Juni 2026)

Chorus-Pro-Korrektur — gesetzliche Kennung **SIRET** (statt SIREN) in `SpecifiedLegalOrganization`:

- **`<ram:SpecifiedLegalOrganization>/ID` (BT-30 Verkäufer / BT-47 Käufer)**: gibt nun die **vollständige SIRET (14 Ziffern)** statt der SIREN (9 Ziffern) aus, `schemeID="0002"` beibehalten. Chorus Pro identifiziert Strukturen über ihre SIRET und lehnte eine 9-stellige SIREN ab. Die Datei blieb EN16931-gültig, daher bestand sie Factur-X-Validatoren, wurde aber bei der Chorus-Pro-Übermittlung abgelehnt.
- **Unabhängig von der Routing-Adressierung**: der Endpoint BT-34/BT-49 (`schemeID="0225"`, in 2.1.0 eingeführt) trägt weiterhin die SIREN.
- **Diagnose**: Warnung, falls die SIRET des Ausstellers (BT-30) oder Käufers (BT-47) nicht 14 Ziffern hat.

### 2.1.1 (Mai 2026)

- **Kleinunternehmerregelung**: die Diagnose meldet die fehlende innergemeinschaftliche USt-IdNr nicht mehr als Fehler für ein nicht steuerpflichtiges Unternehmen (293 B CGI).

### 2.1.0 (Mai 2026)

- **Endpoint BT-34 / BT-49**: SIREN mit `schemeID="0225"` (PPF-Verzeichnis) statt E-Mail — vom Routing des Plateformes-Agréées-Netzwerks gefordert. Konfigurierbares Schema (`AUVOERECHNUNG_ENDPOINT_SCHEME`), Rückfall auf E-Mail für Tiers ohne SIREN.

### 2.0.2 (Mai 2026)

- **Windows-Kompatibilität** ([#4](https://github.com/Fips81/dolibarr-auvo-erechnung/issues/4), [PR #5](https://github.com/Fips81/dolibarr-auvo-erechnung/pull/5) von [@Charlymd](https://github.com/Charlymd)): temporäres XML in `DOL_DATA_ROOT/facturx/temp/`, Regex `AUVOERECHNUNG_PHP_CLI_PATH` erweitert.
- **Kleinunternehmerregelung** ([#6](https://github.com/Fips81/dolibarr-auvo-erechnung/issues/6)): Kategorie `E` (statt `O`), SIREN als `SpecifiedTaxRegistration schemeID="FC"` veröffentlicht (BR-CO-26/BR-E-09).

### 2.0.1 (Mai 2026)

- **Korrigieren**-Buttons der Diagnose nach Fehlertyp gezielt; Prüfung der benötigten Dolibarr-Module.

### 1.1.1 (April 2026)

Wartung der vendorten Abhängigkeiten: `atgp/factur-x` v3.3.0, `smalot/pdfparser` v2.12.5, `setasign/fpdf` 1.8.6 (Patch `/F 4` erneut angewendet), `setasign/fpdi` v2.6.6, `symfony/polyfill-mbstring` v1.36.0.

### 1.1.0 (April 2026)

Modul öffentlich auf GitHub verteilt: Anzahlungen (386, `TotalPrepaidAmount`), kontextabhängiger CategoryCode, Mapping UN/ECE-Einheiten, interne XSD-Validierung, Strikt-Modus, demo/ + tests/.

### 1.0.0

Erste Version: EN16931-XML-Erzeugung, PDF/A-3-Injektion, B2Brouter-Konformität im Standardfall.

## Lizenz

Dieses Modul steht unter der Lizenz [GPLv3](https://www.gnu.org/licenses/gpl-3.0.html) — Copyright (C) 2026 [SASU Lemon](https://hellolemon.fr), Copyright (C) 2026 [auvo-design](https://auvo-design.de) (Fork, umbenannt und verändert als AuVo E-Rechnung).

## Über dieses Projekt

AuVo E-Rechnung wird von auvo-design gepflegt und steht allen Dolibarr-Nutzern frei zur Verfügung. Ursprüngliche Basis entwickelt von [Lemon](https://hellolemon.fr) als LemonFacturX. Fehler und Wünsche bitte als [GitHub-Issue](https://github.com/Fips81/dolibarr-auvo-erechnung/issues) melden; Kontakt: r.vogel@auvo-design.de
