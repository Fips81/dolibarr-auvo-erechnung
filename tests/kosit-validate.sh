#!/usr/bin/env bash
#
# Valide avec le validateur OFFICIEL de la KoSIT tous les XML jugés conformes
# par les tests unitaires (tests/unit-tests.php) : scénarios « EN16931 (CII) »
# pour ZUGFeRD/Factur-X et « EN16931 XRechnung (CII) » pour XRechnung.
#
# Échoue si un document est rejeté ou si le rapport contient une erreur ;
# les avertissements sont affichés (annotations GitHub en CI) sans faire échouer.
#
# Usage (depuis la racine du module) :
#   bash tests/kosit-validate.sh
# Variables facultatives :
#   JAVA             binaire Java 11+ (défaut : java du PATH)
#   KOSIT_CACHE_DIR  cache du bundle téléchargé (défaut : ~/.cache/kosit)
#
set -euo pipefail

# Bundle XRechnung de la KoSIT : validateur + configuration + Schematron.
# Mise à jour : nouvelle URL + somme SHA-256 du zip (sha256sum).
BUNDLE_NAME="xrechnung-3.0.2-bundle-2026-08-31"
BUNDLE_URL="https://projekte.kosit.org/api/v4/projects/356/packages/maven/de/xeinkauf/xrechnung-3.0.2-bundle/2026-08-31/${BUNDLE_NAME}.zip"
BUNDLE_SHA256="5f5426741a03e0b9abc7de83acd97309c09498241d132fc035f9c830be7203de"
VALIDATOR_JAR="validator-1.6.3-standalone.jar"
CONFIG_ZIP="xrechnung-3.0.2-validator-configuration-2026-08-31.zip"

JAVA="${JAVA:-java}"
CACHE_DIR="${KOSIT_CACHE_DIR:-$HOME/.cache/kosit}"
MODULE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUNDLE_DIR="$CACHE_DIR/$BUNDLE_NAME"

unzip_to() { python3 -I -c 'import sys, zipfile; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])' "$1" "$2"; }

# --- Bundle (téléchargé une fois, intégrité vérifiée) -----------------------
if [ ! -f "$BUNDLE_DIR/.ready" ]; then
	mkdir -p "$CACHE_DIR"
	echo "Lade KoSIT-Bundle $BUNDLE_NAME"
	curl -fsSL --retry 3 -o "$CACHE_DIR/$BUNDLE_NAME.zip" "$BUNDLE_URL"
	echo "$BUNDLE_SHA256  $CACHE_DIR/$BUNDLE_NAME.zip" | sha256sum -c -
	rm -rf "$BUNDLE_DIR"
	mkdir -p "$BUNDLE_DIR/config"
	unzip_to "$CACHE_DIR/$BUNDLE_NAME.zip" "$BUNDLE_DIR"
	unzip_to "$BUNDLE_DIR/$CONFIG_ZIP" "$BUNDLE_DIR/config"
	touch "$BUNDLE_DIR/.ready"
fi

"$JAVA" -version 2>&1 | head -1

# --- XML produits par les tests unitaires ------------------------------------
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/xml" "$WORK/reports"
LFX_DUMP_DIR="$WORK/xml" php "$MODULE_DIR/tests/unit-tests.php" > "$WORK/unit.log" || { cat "$WORK/unit.log"; exit 1; }
count=$(find "$WORK/xml" -name '*.xml' | wc -l)
if [ "$count" -eq 0 ]; then
	echo "Die Unit-Tests haben keine XML-Dateien erzeugt" >&2
	exit 1
fi
echo "$count Dokumente zu prüfen"

# --- Validation ----------------------------------------------------------------
status=0
"$JAVA" -jar "$BUNDLE_DIR/$VALIDATOR_JAR" \
	-s "$BUNDLE_DIR/config/scenarios.xml" -r "$BUNDLE_DIR/config" \
	-o "$WORK/reports" "$WORK/xml"/*.xml > "$WORK/validator.log" 2>&1 || status=$?
grep -E "Acceptable:|ACCEPTABLE|REJECT" "$WORK/validator.log" | grep -v "^\s*$" | tail -n 3 || true

# --- Analyse des rapports : erreurs = échec, avertissements = affichés ----------
python3 -I - "$WORK/reports" "${GITHUB_ACTIONS:-}" <<'PY' || status=1
import glob, re, sys
errors, warnings = [], []
for f in sorted(glob.glob(sys.argv[1] + '/*-report.xml')):
    doc = f.rsplit('/', 1)[-1].replace('-report.xml', '.xml')
    s = open(f, encoding='utf-8').read()
    # Validator 1.6 : <rep:assessment><rep:reject> ; anciennes versions : acceptRecommendation
    if '<rep:reject>' in s or '<rep:acceptRecommendation>REJECT' in s:
        errors.append(f'{doc}: REJECT')
    for level, code, text in re.findall(r'<rep:message[^>]*level="(\w+)"[^>]*code="([^"]+)"[^>]*>([^<]*)', s):
        line = f'{doc}: [{level}] {code} {text.strip()[:200]}'
        (errors if level in ('error', 'fatal') else warnings).append(line)
gh = sys.argv[2] == 'true'
for w in warnings:
    print(('::warning::' if gh else 'WARNUNG ') + w)
for e in errors:
    print(('::error::' if gh else 'FEHLER ') + e)
print(f'KoSIT: {len(errors)} Fehler, {len(warnings)} Warnungen')
sys.exit(1 if errors else 0)
PY

if [ "$status" -ne 0 ]; then
	echo "Offizielle KoSIT-Validierung fehlgeschlagen" >&2
	exit "$status"
fi
echo "Offizielle KoSIT-Validierung bestanden"
