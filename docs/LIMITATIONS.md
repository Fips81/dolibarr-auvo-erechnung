# Einschränkungen und nicht behandelte Fälle

Dieses Dokument listet die Fälle, die AuVo E-Rechnung **nicht** (oder nur
teilweise) behandelt, das beobachtete Verhalten und den Grund für diese
Entscheidung. Im Zweifel über den Funktionsumfang des Moduls ist es maßgeblich.

Legende Verhalten:
- **Saubere Ablehnung**: Die Rechnung wird nicht umgewandelt, die normale PDF bleibt erhalten, eine Meldung erklärt den Grund (im Strikt-Modus blockierend).
- **Warnung**: Die Rechnung wird umgewandelt, eine Warnung weist aber auf die Einschränkung hin.
- **Still**: Der Fall wird mit einer hier dokumentierten Näherung behandelt.

## Fachliche Fälle

### Fremdwährung (BT-5 ≠ Firmenwährung)
**Verhalten: saubere Ablehnung.**
Dolibarr speichert die Aufschlüsselung der Umsatzsteuer nur in Firmenwährung.
Das XML in Firmenwährung auszugeben, während die sichtbare PDF in
Fremdwährung ist, würde das hybride Prinzip von ZUGFeRD/Factur-X verletzen
(der lesbare Teil muss dem strukturierten entsprechen). Eine vollständige
Unterstützung bräuchte die Beträge `multicurrency_total_*` je Position, den
`TaxCurrencyCode` (BT-6) und einen doppelten `TaxTotalAmount`. Die
Zuverlässigkeit der Fremdwährungsbeträge je Position in Dolibarr
(Umrechnungsrundungen) erlaubt es derzeit nicht, die Regeln BR-CO-*
zu garantieren. Die Diagnose zeigt einen Hinweis, wenn das Modul
Multiwährung aktiv ist.

### Lokale Steuern (localtax1/localtax2)
**Verhalten: saubere Ablehnung.**
EN16931 bildet nur die Umsatzsteuer ab. Lokale Zusatzsteuern (spanische IRPF,
RECARGO usw.) lassen sich nicht abbilden: Der Gesamtbetrag im XML würde vom
Bruttobetrag auf der PDF abweichen — gleiches Ablehnungsprinzip wie bei
Fremdwährung. In Deutschland und Frankreich werden diese Felder nicht genutzt.

### Abschlagsrechnungen im Bau (Situationsrechnungen)
**Verhalten: Warnung, Ausgabe als TypeCode 380.**
Die Situations-Positionen in Dolibarr tragen kumulierte Prozentsätze und
Sicherheitseinbehalte, deren Abbildung in EN16931 (BG-14 je Situation,
Verkettung BG-3 zu den vorherigen Situationen, Einbehalt als BT-115 oder als
Zuschlag) in den aktuellen Spezifikationen nicht stabil geregelt ist. Die
ausgegebenen Beträge sind die der Dolibarr-Rechnung (Differenz der Situation),
was in der Regel korrekt ist, die Konformität ist aber nicht garantiert —
daher die Warnung.

### Gutschriftsverfahren (TypeCode 389)
**Verhalten: nicht behandelt (Ausgabe als normale Rechnung).**
Dolibarr kennt bei Kundenrechnungen kein Gutschriftsverfahren
(Selbstfakturierung). Der Fall erfordert das Vertauschen der Rollen
Verkäufer/Käufer und einen eigenen Rechnungsrahmen — ohne verlässliche
Quelldaten wäre jede Ableitung falsch.

### Anzahlungen: Umsatzsteuer der Anzahlung in der Schlussrechnung
**Verhalten: nur Abzug des Bruttobetrags (BT-113), keine Warnung.**
Die Schlussrechnung zieht die Anzahlung über `TotalPrepaidAmount` (BT-113) ab
und verweist auf die Anzahlungsrechnung (BG-3). Die Steueraufschlüsselung der
Schlussrechnung bleibt die der vollständigen Rechnung: Die bereits auf die
Anzahlung entrichtete Umsatzsteuer wird nicht gesondert ausgewiesen (das von
manchen Plattformen empfohlene Schema „negative Positionen je Steuersatz“ ist
aus den Dolibarr-Daten nicht allgemein ableitbar). Das entspricht dem
Verhalten der meisten aktuellen ZUGFeRD/Factur-X-Implementierungen.

### Steuerbefreiungen (BT-120/BT-121)
**Verhalten: teilweise.**
Der Befreiungsgrund wird je nach Land des Verkäufers auf Deutsch (Verkäufer
in Deutschland) oder Französisch ausgegeben. Ausgegebene Fälle:

| Fall | Kategorie | VATEX-Code | Text bei deutschem Verkäufer |
|---|---|---|---|
| Kleinunternehmer | E | — | Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG |
| Innergemeinschaftliche Lieferung (Ware, Käufer mit USt-IdNr. in anderem EU-Land) | K | VATEX-EU-IC | Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b i. V. m. § 6a UStG) |
| Sonstige Leistung an Unternehmer in anderem EU-Land | AE | VATEX-EU-AE | Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge, Art. 196 MwStSystRL) |
| Ausfuhr Ware ins Drittland | G | VATEX-EU-G | Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a i. V. m. § 6 UStG) |
| Leistung ins Drittland | G | VATEX-EU-G | Nicht im Inland steuerbare Leistung (§ 3a Abs. 2 UStG) |
| Sonstige Positionen mit 0 % | E | — | Umsatzsteuerfrei |

Für eine inländische Position mit 0 % (z. B. § 4 Nr. 14 oder Nr. 21 UStG)
gibt das Modul den allgemeinen Grund „Umsatzsteuerfrei“ **ohne** VATEX-Code
aus: Die Rechtsgrundlage lässt sich aus den Dolibarr-Daten nicht ableiten.
Den tatsächlichen Grund in der Positionsbeschreibung angeben oder bei
wiederkehrendem Bedarf eine Zuordnung je Produkt anfragen.

### Inländisches Reverse Charge (§ 13b UStG, Bauleistungen)
**Verhalten: nicht automatisch erkannt.**
Eine Inlandsposition mit 0 % fällt in die allgemeine Kategorie `E`. Die
Einstufung als inländisches AE bräuchte ein Kennzeichen je Position oder je
Geschäftspartner (Extrafield), das das Modul nicht anlegt, um das Datenmodell
nicht zu verändern. AE wird dagegen für **innergemeinschaftliche Leistungen**
korrekt ausgegeben (Erkennung über `product_type` + USt-IdNr. des Käufers).

### Kategorien O, Z, L, M
**Verhalten: nicht ausgegeben.**
`O` (nicht steuerbar): wird nie erzeugt — die Kleinunternehmerregelung ist
eine Befreiung (`E`), kein nicht steuerbarer Umsatz. `Z` (Nullsatz): im
deutschen Recht nur für Sonderfälle (z. B. § 12 Abs. 3 UStG Photovoltaik);
derzeit nicht abgebildet. `L`/`M` (Kanaren/Ceuta): außerhalb des
Anwendungsbereichs.

### Sicherheitseinbehalt, strukturiertes Skonto (BT-20/SKONTO)
**Verhalten: nur Freitext.**
Skontobedingungen werden über die Notiz AAB geführt (in Deutschland
optional, in den Moduleinstellungen hinterlegbar). Die strukturierte
SKONTO-Syntax ist eine Konvention des ZUGFeRD-Profils EXTENDED bzw. von
XRechnung und gehört nicht zum hier erzeugten Profil EN16931.

## Übermittlung

### XRechnung
**Teilweise.** Für Kunden mit Leitweg-ID (oder E-Rechnungsformat „XRechnung“)
erzeugt das Modul zusätzlich zur ZUGFeRD-PDF eine **XRechnung 3.0 als eigene
XML-Datei** (`{Rechnung}-XRECHNUNG.xml`, Syntax CII). Noch nicht enthalten:
- ZUGFeRD-PDF im **Profil XRECHNUNG** (XRechnung-XML in der PDF eingebettet):
  die eingebettete Bibliothek kennt dieses Profil nicht; die PDF bleibt
  ZUGFeRD EN16931. Für die OZG-RE des Bundes die XML-Datei verwenden.
- **Skonto** in der XRechnung-Syntax (`#SKONTO#TAGE=…#PROZENT=…#`, BT-20).
- **Lieferantennummer** (BT-29 vom Auftraggeber) als eigenes Feld.
- Die Variante **UBL** und die **XRechnung-Extension**.
- Der Versand (OZG-RE-Upload, E-Mail-Kanal, Peppol) bleibt Handarbeit.

Die interne Prüfung deckt die „fatal“-Regeln BR-DE-1 bis -9, -14 bis -16,
-18, -23, -31 und PEPPOL-EN16931-R001/R010/R020 ab. Verbindlich ist der
offizielle KoSIT-Validator.

### Anbindung an Plattformen (Frankreich: PDP/PPF; Deutschland: Portale)
**Nicht behandelt.** Das Modul erzeugt das **Format**. Die **Übermittlung**
(Upload-API, Empfangsbestätigungen, Statusmeldungen) ist je Plattform
verschieden und erfordert Zugangsdaten — das wäre ein eigenes Begleitmodul.

### Verzeichnis (Prüfung der SIREN des Empfängers, nur Frankreich)
**Nicht behandelt.** Die API des französischen Verzeichnisses ist nur für
zugelassene Plattformen zugänglich. Das Modul prüft aber Vorhandensein und
Länge von SIREN/SIRET des Käufers (Warnungen + Diagnose).

### Art des Umsatzes (Lieferung / Leistung / gemischt, nur Frankreich)
**Nicht behandelt.** Angabe der französischen Reform ohne eigenes BT im Profil
EN16931 (wird über die PPF-Flüsse transportiert, nicht über das CII-XML).

### Routing an Dienststellen des Empfängers
**Teilweise.** In Deutschland verfügt die **Leitweg-ID** (öffentliche
Auftraggeber, Portale ZRE/OZG-RE) über ein eigenes Extrafield am
Geschäftspartner (`lfxleitwegid`, auf der Firmenkarte) und hat bei einem
deutschen Käufer in BT-10 Vorrang vor `ref_client` — ein Feld je
Geschäftspartner ist hier sinnvoll, weil die Leitweg-ID eine stabile
Routing-Angabe des Kunden ist.

In Frankreich läuft das Routing an Dienststellen (Chorus Pro, Großkunden)
über **BT-10 BuyerReference**, befüllt aus dem Standardfeld
„Kundenreferenz“ (`ref_client`) der Rechnung. Die elektronische Adresse
BT-34/BT-49 ist dort fest auf die SIREN gesetzt (`schemeID="0225"`,
PPF-Verzeichnis).

## Technik

### Offizielle Schematron-Prüfung
**Teilweise (bewusst).** Das offizielle EN16931-Schematron ist XSLT 2.0 und
mit der XSL-Erweiterung von PHP (XSLT 1.0) nicht ausführbar. Das Modul bringt
stattdessen einen PHP-Validator mit, der die am häufigsten geprüften Rechen-
und Konsistenzregeln abdeckt (BR-CO-10..17, BR-27, BR-61, BR-16,
BR-IC-02/11/12, BR-AE-02, BR-*-08/10, BR-CO-25/26, BR-09/11). Für eine
vollständige Prüfung im Einzelfall einen externen Validator nutzen (z. B.
Mustang, KoSIT-Validator, B2Brouter) — das XML lässt sich über die API oder
`scripts/export_facturx_batch.php` exportieren. Vorsicht bei Online-Validatoren
mit veralteten Artefakten (~2021): bekannte Fehlalarme bei mehrfachem BG-3 und
beim Endpoint `0225` — siehe „Online-Validatoren: bekannte Fehlalarme“ im
README.

### Garantiertes PDF/A-3
**Teilweise.** Das Modul erzwingt, was es kontrolliert (XMP, AFRelationship
`Alternative`, ICC sRGB, `/F 4`) und **prüft** `MAIN_PDF_FORCE_FONT`
(Diagnose + Warnung). Ein eigenes PDF-Template (CMYK-Bilder, nicht
eingebettete Schriften eines Fremdmoduls) kann die Konformität aber brechen:
Die optionale Nachprüfung mit veraPDF (`AUVOERECHNUNG_VERAPDF_PATH`) erkennt das.

### Strikt-Modus ≠ Löschen der PDF
Der Hook `afterPDFCreation` greift **nach** der Erzeugung der normalen PDF
durch Dolibarr. Im Strikt-Modus blockiert ein Fehler die Rückmeldung an den
Nutzer, die PDF (ohne ZUGFeRD) bleibt aber auf der Platte und könnte manuell
verschickt werden. Die Datei zu löschen wäre destruktiv (es ist das Dokument
des Nutzers); mit dem Button „ZUGFeRD prüfen“ lässt sich vor dem Versand
kontrollieren.

### ZUGFeRD-Kennzeichen in der Rechnungsliste
**Nicht behandelt.** Eine zusätzliche Spalte in den Dolibarr-Listen bräuchte
aufwendige Listen-Hooks (`printFieldListTitle`/`Value`), die jede PDF öffnen
müssten. Die Prüfung ist auf Anforderung verfügbar: Button auf der
Rechnungsseite, API `/status`, Stapelexport (Bericht NO_XML).

### Order-X (Angebote / Aufträge)
**Nicht behandelt.** Order-X ist ein eigener Standard (andere Profile und XSD).
