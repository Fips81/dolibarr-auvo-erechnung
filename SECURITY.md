# Sicherheitsrichtlinie — AuVo E-Rechnung

Dieses Dokument beschreibt das Bedrohungsmodell des Moduls AuVo E-Rechnung, die vorhandenen Schutzmaßnahmen, bewusste Einschränkungen und das Verfahren zur verantwortungsvollen Meldung einer Sicherheitslücke.

## Sicherheitslücke melden

Bitte eröffnen Sie für eine Sicherheitslücke **kein** öffentliches Issue. Schreiben Sie an:

**r.vogel@auvo-design.de**

Bitte angeben:

- Betroffene Modulversion (oder Commit-SHA)
- Beschreibung der Lücke und geschätzte Auswirkung
- Minimale Schritte zur Reproduktion
- Gegebenenfalls einen Proof of Concept

Wir verpflichten uns:

- den Eingang innerhalb von 72 Stunden zu bestätigen
- Sie über den Fortschritt der Analyse auf dem Laufenden zu halten
- Ihren Beitrag nach Veröffentlichung der Korrektur zu nennen (falls gewünscht)
- eine koordinierte Offenlegungsfrist von höchstens 90 Tagen einzuhalten, bevor Details öffentlich gemacht werden

Bitte vermeiden Sie alles, was einen Produktivbetrieb beeinträchtigen, auf Daten Dritter zugreifen oder eine Lücke über das zum Nachweis Notwendige hinaus ausnutzen könnte.

## Bedrohungsmodell

AuVo E-Rechnung ist ein Dolibarr-Modul, das die PDFs von Kundenrechnungen automatisch in das Format ZUGFeRD/Factur-X EN16931 umwandelt (PDF/A-3 mit eingebettetem CII-XML). Es läuft **innerhalb** einer authentifizierten Dolibarr-Instanz und hängt am Hook `afterPDFCreation`. Das Bedrohungsmodell ist das einer Geschäftsanwendung im Intranet.

### Rollen

| Rolle | Zugriff | Vertrauen |
|---|---|---|
| Dolibarr-Administrator | Konfiguration des Moduls, einschließlich `AUVOERECHNUNG_PHP_CLI_PATH` | **Hohes Vertrauen**. Ein kompromittierter Admin bedeutet ohnehin eine vollständige Kompromittierung von Dolibarr. |
| Dolibarr-Nutzer mit dem Recht, eine Rechnungs-PDF zu erzeugen | Löst die ZUGFeRD-Einbettung über den Standard-Hook aus | Internes Vertrauen. |
| Anonymer Nutzer (außerhalb von Dolibarr) | Kein Zugriff | Nicht betroffen: Das Modul stellt keinen öffentlichen Endpunkt bereit. |

### Angriffsfläche

- **Hook `afterPDFCreation`**: läuft im Kontext einer Rechnungs-PDF-Erzeugung (authentifizierter Nutzer)
- **Hooks `invoicecard`** (Buttons „ZUGFeRD prüfen / neu erzeugen“): nur für authentifizierte Nutzer mit Rechnungsrechten (`lire` / `creer`), geschützt durch CSRF-Token (`currentToken()`)
- **Admin-Konfigurationsseite**: `admin/setup.php`, nur für Admins über `accessforbidden()` + CSRF-Schutz beim Speichern
- **REST-API**: `class/api_auvoerechnung.class.php`, nur verfügbar, wenn das Dolibarr-Modul REST-API aktiv ist; API-Schlüssel erforderlich, Rechte `facture->lire` + `_checkAccessToResource()` werden geprüft
- **CLI-Skripte**: `scripts/inject_facturx.php`, `scripts/export_facturx_batch.php`, `tests/*.php`, `demo/*.php` — alle gegen direkten HTTP-Zugriff durch `PHP_SAPI === 'cli'` geschützt; die Ordner `scripts/`, `tests/`, `demo/` enthalten zusätzlich eine `.htaccess` mit `Require all denied`
- **Einziger ausgehender HTTP-Aufruf**: Prüfung der neuesten GitHub-Release über `api.github.com` beim Laden der Admin-Konfigurationsseite, mit 24-h-Cache (für Erfolge **und** Fehler) und 5 s Timeout (es werden keine lokalen Daten gesendet, nur ein anonymer `GET`)
- **Optionales externes Programm**: veraPDF (`AUVOERECHNUNG_VERAPDF_PATH`, Admin-Konstante), aufgerufen mit `escapeshellarg()` + `is_executable()`
- **Kein öffentlich erreichbarer Web-Endpunkt**

### **Nicht** Teil des Bedrohungsmodells

- Ein böswilliger Dolibarr-Administrator. Ein Admin kann in Dolibarr ohnehin alles (eigene Module, `/admin/tools/*`, Datenbankzugriff, `/admin/const.php`). Kein Mechanismus schützt vor einem feindlichen Admin (und kann es in der Dolibarr-Architektur auch nicht).
- Die Sicherheit der mitgelieferten Fremdbibliotheken (`atgp/factur-x`, `setasign/fpdi`, `setasign/fpdf`, `smalot/pdfparser`, `symfony/polyfill-mbstring`). Sie hängt von den Upstream-Maintainern und den eingebundenen Versionen ab.

## Schutzmaßnahmen

### PDF-Einbettung: standardmäßig isolierter Subprozess

Standardmäßig (Modus `auto` mit verfügbarem `exec()`) wird das XML in einem **isolierten PHP-Subprozess** (`scripts/inject_facturx.php`) in die PDF eingebettet. Das ist der robusteste Weg: Der Prozess ist leer — keine Dolibarr- oder Fremdklassen geladen — und damit sind **keine Bibliothekskonflikte** möglich. Das PHP-CLI-Programm ist über `AUVOERECHNUNG_PHP_CLI_PATH` einstellbar. Schutz des Aufrufs:

- `escapeshellarg()` wird auf **alle** Bestandteile des Befehls angewendet (PHP-Programm, Skript, PDF, temporäre XML-Datei). Ein manipulierter Wert in der Konstante wird gequotet, die Shell sucht ein Programm mit genau diesem Namen, das nicht existiert → `command not found`. Keine Befehlsverkettung möglich.
- Prüfung per Regex `^[A-Za-z0-9/._:() \\-]+$` auf `AUVOERECHNUNG_PHP_CLI_PATH` vor dem Aufruf (`: \ ( )` und Leerzeichen für Windows-Pfade erlaubt). Jeder Wert mit exotischen Zeichen (`;`, `&`, `$`, Anführungszeichen usw.) wird abgelehnt.
- Bei absolutem Pfad prüft `is_executable()`, dass tatsächlich ein ausführbares Programm existiert.
- `function_exists('exec')` wird vorab geprüft.
- Das CLI-Skript `inject_facturx.php` verweigert jeden Aufruf über HTTP: `if (php_sapi_name() !== 'cli') { http_response_code(403); die(...); }`

**In-Process-Einbettung (ohne `exec()`)** — wird nur verwendet, wenn `exec()` deaktiviert ist (Modus `auto` auf gehärtetem Shared Hosting) oder ausdrücklich erzwungen (Modus `inprocess`). Sie lädt die Einbettungsbibliothek in den Web-Request und kommt damit ohne `exec()` aus. Bekanntes und **behandeltes** Risiko: Hat eine andere Komponente (das `tcpdi` von Dolibarr, ein anderes Modul mit eigenem `setasign/fpdi`) bereits eine **inkompatible** FPDF-Version geladen, würde die Instanziierung einen fatalen Kompilierungsfehler auslösen („Declaration … must be compatible“, `FpdfTplTrait::setPageFormat`), der sich nicht mit `try/catch` abfangen lässt. Das Modul **erkennt diesen Konflikt vorab** (Prüfung der bereits außerhalb des eigenen vendor geladenen FPDF/FPDI-Klassen) und **verzichtet sauber** auf die In-Process-Einbettung, statt den fatalen Fehler zu provozieren; die normale PDF bleibt dann erhalten. Deshalb bleibt der Subprozess der Standard, sobald `exec()` verfügbar ist.

### Umgang mit Dateien

- Die Quell-PDF stammt aus dem internen Dolibarr-Ablauf (Hook `afterPDFCreation`), nicht aus einem direkten Upload.
- Temporäre XML-Dateien werden mit `tempnam(DOL_DATA_ROOT.'/facturx/temp', 'facturx_')` angelegt (immer innerhalb des Dolibarr-`open_basedir`, Rechte 0600, nicht vorhersagbarer Name) und nach der Einbettung in einem `finally`-Block gelöscht.
- Das Schreiben der ZUGFeRD-PDF ist in jedem Modus **atomar** (In-Process wie Subprozess): Schreiben in eine Datei `.facturx.tmp`, dann `rename()` — ein Absturz, eine volle Platte oder ein Kill kann keine abgeschnittene PDF hinterlassen.
- Es werden keine vom Nutzer gelieferten Pfade zum Lesen oder Schreiben verwendet.

### XML-Erzeugung

- Das CII-XML wird programmatisch aus den Dolibarr-Objekten (`$invoice`, `$mysoc`, `$thirdparty`) **aufgebaut**. Keine Nutzerdaten werden roh eingefügt: Die Werte werden über die Hilfsfunktionen des Moduls mit `htmlspecialchars(..., ENT_XML1 | ENT_QUOTES, 'UTF-8')` maskiert.
- Im kritischen Pfad wird kein externes XML geparst.

### Interne XML-Prüfung vor der Einbettung

Seit v1.1.0 prüft das Modul vor dem Schreiben des XML auf die Platte und dem Aufruf des Einbettungs-Subprozesses immer:

1. **Wohlgeformtheit** über `DOMDocument::loadXML()`. Ein kaputtes XML (unwahrscheinlich, da programmatisch erzeugt, aber als zusätzliche Absicherung) wird abgelehnt, bevor es die Fremdbibliothek erreicht.
2. **XSD-Konformität EN16931** über `DOMDocument::schemaValidate()` gegen das mitgelieferte Schema `vendor/atgp/factur-x/xsd/factur-x/en16931/Factur-X_1.09.2_EN16931.xsd`. Fehler werden zur Diagnose in `dol_syslog` protokolliert.

Im Modus `AUVOERECHNUNG_STRICT_MODE=1` blockieren fehlgeschlagene Prüfungen die Erzeugung mit einer sichtbaren Fehlermeldung. Im best-effort-Modus (Standard) erscheint eine Warnung und die normale PDF bleibt verfügbar (fail-open). So lässt sich je nach Konformitätsbedarf bewusst zwischen fail-open und fail-closed wählen.

### CSRF auf der Admin-Seite

Das Speichern der Konstanten in `admin/setup.php` ist zusätzlich zur Prüfung `$user->admin` durch die Standard-CSRF-Prüfung von Dolibarr (`currentToken()`) geschützt. Dolibarr erzeugt das Token bei jeder Darstellung neu, und der Vergleich nutzt `currentToken()` (Wert der laufenden Übermittlung), nicht `newToken()`, das das Token der **nächsten** Übermittlung erzeugt.

### FPDF-Patch für PDF/A-3

Die mitgelieferte Bibliothek `setasign/fpdf` erhält einen Patch, der den Annotationen das Flag `/F 4` hinzufügt (PDF/A-3-Konformität). Der Patch wird beim Bau des vendor-Ordners angewendet und schafft keinen Angriffsvektor.

### Dolibarr-Konstanten

Alle Konstanten des Moduls werden im Klartext in `llx_const` gespeichert (Dolibarr-Konvention). Keine davon ist ein Geheimnis.

| Konstante | Bedeutung |
|---|---|
| `AUVOERECHNUNG_BANK_ACCOUNT` | ID des eingestellten Bankkontos |
| `AUVOERECHNUNG_PAYMENT_MEANS` | Zahlungsart-Code UNTDID 4461 |
| `AUVOERECHNUNG_STRICT_MODE` | Fehlerverhalten (best-effort / strikt) |
| `AUVOERECHNUNG_BR_CHECK` | Interne Prüfung der EN16931-Geschäftsregeln |
| `AUVOERECHNUNG_INJECTION_MODE` | Einbettungsmodus des XML (auto / In-Process / Subprozess) |
| `AUVOERECHNUNG_PHP_CLI_PATH` | Pfad zum PHP-Programm (per Regex geprüft) |
| `AUVOERECHNUNG_VERAPDF_PATH` | Pfad zum veraPDF-Programm (optional, `is_executable()` geprüft) |
| `AUVOERECHNUNG_ENDPOINT_SUFFIX_SELLER` | Suffix zur SIREN des Verkäufers im Endpoint BT-34 (nur Frankreich) |
| `AUVOERECHNUNG_NOTE_PMD/PMT/AAB` | Pflichthinweise (Verzug, Mahnpauschale, Skonto) |
| `AUVOERECHNUNG_NOTES_IN_FOOTER` | Übernahme der Hinweise in die Rechnungsfußzeile (Schalter) |
| `AUVOERECHNUNG_NOTES_OVERWRITE` | Übernahme Fußzeile: überschreiben (1) oder anhängen (0) |
| `AUVOERECHNUNG_CHORUS_ENABLED` | Schalter für die Chorus-Pro-Funktionen (nur Frankreich) |
| `AUVOERECHNUNG_UPDATE_CHECK_CACHE` | JSON-Cache der neuesten GitHub-Version (TTL 24 h, Erfolge und Fehler) |

## Mitgelieferte Abhängigkeiten

Der Ordner `vendor/` enthält folgende Bibliotheken (bei der Installation ist kein Composer nötig):

| Bibliothek | Aufgabe |
|---|---|
| `atgp/factur-x` v3.5.0 | Erzeugung der Factur-X/ZUGFeRD-PDF |
| `setasign/fpdi` | Lesen/Schreiben von PDFs |
| `setasign/fpdf` | PDF-Engine (von atgp genutzt, Patch `/F 4` angewendet) |
| `smalot/pdfparser` | Parsen von PDFs |
| `symfony/polyfill-mbstring` | mbstring-Kompatibilität |

Diese Bibliotheken werden nicht von auvo-design gepflegt. Ihre Sicherheit hängt von den Upstream-Maintainern ab. Upstream-CVEs erreichen uns über die üblichen Kanäle (GitHub, composer audit usw.); bei Bedarf wird eine neue Version mit aktualisiertem vendor veröffentlicht.

## Bisherige Sicherheitshinweise

_Bisher wurde keine behobene Sicherheitslücke veröffentlicht._

---

Fragen zur Sicherheit dieses Moduls: r.vogel@auvo-design.de
