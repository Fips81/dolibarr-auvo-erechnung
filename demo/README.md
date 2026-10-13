# Demo-Daten — AuVo E-Rechnung

`fixtures.php` legt in einer Dolibarr-**Testinstanz** einen festen Satz an Stammdaten und Rechnungen an. Die Integrationstests (`tests/run-tests.php`) bauen darauf auf.

> **Achtung** — Das Skript schreibt direkt in die Datenbank (Firmendaten, Bankkonto, Geschäftspartner, Rechnungen) und überschreibt die Firmenangaben. **Niemals auf einer Produktivinstanz ausführen.**

Der Ordner `demo/` ist nicht im Release-ZIP enthalten (`.gitattributes` → `export-ignore`).

## Verwendung

```bash
# In einer frisch installierten Dolibarr-Testinstanz mit aktiviertem Modul
php htdocs/custom/auvoerechnung/demo/fixtures.php

# Danach die PDFs der Rechnungen F001–F010 in Dolibarr erzeugen und prüfen:
php htdocs/custom/auvoerechnung/tests/run-tests.php
```

Die Integrationstests erwarten die Rechnungen unter den IDs 1 bis 10, also eine leere Datenbank vor dem Lauf.

## Angelegte Daten

- 1 ausstellende Firma: DEMO SASU (Frankreich), SIREN 732829320 (Beispiel aus der Factur-X-Spezifikation), USt-IdNr. FR44732829320, IBAN FR7630001007941234567890185 (Test-IBAN der Banque de France)
- 6 Geschäftspartner für die verschiedenen Fälle
- 10 Rechnungen:

| Ref | Geschäftspartner | Getesteter Fall |
|---|---|---|
| F001 | FR Standard | Einfache Rechnung, 20 % USt |
| F002 | FR Standard | Mehrere Steuersätze (20 % + 5,5 %) |
| F003 | FR Standard | 0 % USt (Kleinunternehmer-Regelung) |
| F004 | FR Standard | Gutschrift (`TYPE_CREDIT_NOTE`) |
| F005 | FR Standard | Position in Stunden (10 h × 80 €) |
| F006 | FR Standard | Position in Tagen (5 Tage × 450 €) |
| F007 | FR ohne E-Mail | Fall BR-FR-13: Käufer ohne E-Mail |
| F008 | DE GmbH | Reverse Charge EU, 0 % USt mit USt-IdNr. |
| F009 | FR Standard | Anzahlungsrechnung (`TYPE_DEPOSIT`) 240 € brutto |
| F010 | FR Standard | Schlussrechnung mit verrechneter F009 (Test `getSumDepositsUsed()`) |

Die Demo-Firma sitzt in Frankreich, damit auch die französischen BR-FR-Regeln abgedeckt sind. Die deutschen Fälle (§ 19 UStG, Reverse Charge, Leitweg-ID, Befreiungsgründe) prüfen die Unit-Tests in `tests/unit-tests.php`. Diese laufen ohne Dolibarr-Datenbank:

```bash
php tests/unit-tests.php
```
