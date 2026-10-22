#!/bin/bash
set -euo pipefail

# ============================================================
#  miko® Website — Deployment
#
#  Verwendung:
#    ./deploy.sh vorschau              auf den eigenen Server (mo.lhaw.rocks)
#    ./deploy.sh live                  auf den Webspace (miko-online.com)
#    ./deploy.sh vorschau --trockenlauf   nur anzeigen, nichts übertragen
#    ./deploy.sh live --alles          alle Dateien statt nur der geänderten
#
#  Zwei Ziele, weil es zwei Server gibt:
#
#    vorschau  mo.lhaw.rocks  →  eigener Server, rsync über SSH.
#              Dort gehören die Dateien root und sind für den Webserver nur
#              lesbar (siehe Abschnitt „Rechte"). Das Skript stellt das nach
#              jedem Hochladen wieder her — rsync setzt Eigentümer sonst
#              zurück, und dann könnte www-data wieder PHP-Dateien ablegen.
#
#    live      miko-online.com →  Webspace beim Hoster, nur SFTP.
#              Kein rsync, kein chown. Übertragen wird deshalb nur, was sich
#              seit dem letzten Lauf geändert hat (Marke: .deploy-stand-live).
# ============================================================

ZIEL="${1:-}"
MODUS="${2:-}"

SSH_ZIEL="miko-webserver"
VORSCHAU_PFAD="/var/www/miko"
VORSCHAU_URL="https://mo.lhaw.rocks"
LIVE_URL="https://miko-online.com"
MARKE=".deploy-stand-live"

cd "$(dirname "$0")"

# ── Was darf auf den Webserver — Freigabeliste ──────────────────
#
# Bewusst eine Freigabeliste, keine Sperrliste. Beim ersten Entwurf stand
# hier eine Sperrliste mit „Logos" darin — das Verzeichnis heißt aber
# „Logos Referenzen", und damit wären 94 Kundenlogos in Druckformaten
# (.ai, .eps, hochauflösende JPG) auf einen öffentlich erreichbaren Server
# gewandert. Eine Sperrliste schweigt bei dem, was man vergisst; eine
# Freigabeliste schweigt bei dem, was man nicht gemeint hat. Auf einem
# öffentlichen Webserver ist das zweite Schweigen das richtige.
#
# Neue Datei auf oberster Ebene? Dann gehört sie hier eingetragen — sonst
# bleibt sie liegen, und das Skript sagt es beim Trockenlauf.
ERLAUBT=(
    '/*.html'                 # die zehn Hauptseiten
    '/robots.txt'
    '/sitemap.xml'
    '/.htaccess'
    '/.user.ini'              # PHP-Einstellungen je Verzeichnis
    '/contact.php'            # das Kontaktformular
    '/config.smtp.example.php'  # Vorlage, ohne Zugangsdaten
    '/assets/***'             # CSS, JS, Bilder, Schriften
    '/downloads/***'
    '/landingpages/***'
    '/lib/***'                # PHPMailer
)

# Ausdrücklich NICHT, obwohl es dem Muster entspräche. Doppelter Boden:
# Diese Namen würden von keiner Freigabe erfasst, stehen aber hier, damit
# die Prüfung unten anschlägt, falls jemand die Liste erweitert.
NIEMALS=(
    '.env'                    # SFTP-Zugangsdaten
    '.git'
    '.claude'
    'CLAUDE.md'
    'config.smtp.php'         # echte Zugangsdaten; Server hat eigene Fassung
    'data'                    # Zustand des Formulars, gehört dem Server
    '_archiv'
    '_capstest.html'
    'Logos Referenzen'        # Kundenlogos in Druckformaten
    'SEO-Fahrplan'
    'deploy'
)

AUSSCHLUSS=()
# Zuerst die Ausnahmen von der Freigabe: Bei rsync gewinnt die erste
# passende Regel. '_capstest.html' entspraeche dem Muster /*.html, ist aber
# eine Testseite und hat auf dem Server nichts verloren.
AUSSCHLUSS+=( --exclude='/_*' )
for e in "${ERLAUBT[@]}"; do AUSSCHLUSS+=( --include="$e" ); done
# Kein --include='*/': Damit wuerde rsync jedes Verzeichnis durchlaufen und
# auf dem Server anlegen — auch .git/ und .claude/, wenn auch leer. Die
# Muster mit *** nehmen ihr Verzeichnis bereits mit.
AUSSCHLUSS+=( --exclude='*' )
# macOS legt neben jeder Datei ein ._-Gegenstück an, sobald sie auf ein
# fremdes Dateisystem wandert. Im Bildverzeichnis des HAKRO-Shops lagen
# davon 6.833 Stück. Hier gar nicht erst hochladen.
AUSSCHLUSS=( --exclude='._*' --exclude='.DS_Store' "${AUSSCHLUSS[@]}" )

# ── Letzte Reißleine ────────────────────────────────────────────
#
# Die Freigabeliste sollte das schon verhindern. Diese Prüfung läuft
# trotzdem, weil der Preis eines Fehlers hier sehr hoch ist: .env enthält
# die SFTP-Zugangsdaten zum Webspace.
pruefe_sperrliste() {
    local liste="$1" treffer=0
    for v in "${NIEMALS[@]}"; do
        if grep -qF "$v" "$liste" 2>/dev/null; then
            echo "    ABBRUCH: '$v' wäre übertragen worden" >&2
            treffer=1
        fi
    done
    [ "$treffer" -eq 0 ] || {
        echo "==> Nichts übertragen. Freigabeliste im Skript prüfen." >&2
        exit 1
    }
}

# ── Rechte auf dem Vorschau-Server ──────────────────────────────
#
# Alles root:root und nur lesbar. Zwei Ausnahmen, beide nötig:
#   data/            muss für www-data schreibbar sein, sonst bricht das
#                    Kontaktformular. Caddy sperrt /data/* mit 404, dort
#                    abgelegtes PHP ist also nicht aufrufbar.
#   config.smtp.php  muss für PHP lesbar sein, für sonst niemanden.
rechte_herstellen() {
    ssh "$SSH_ZIEL" "set -e
        cd $VORSCHAU_PFAD
        chown -R root:root .
        find . -type d -exec chmod 755 {} +
        find . -type f -exec chmod 644 {} +
        chown -R www-data:www-data data && chmod 775 data
        if [ -f config.smtp.php ]; then
            chown root:www-data config.smtp.php && chmod 640 config.smtp.php
        fi"
}

pruefe_rechte() {
    ssh "$SSH_ZIEL" "
        sudo -u www-data test -w $VORSCHAU_PFAD      && echo '    FEHLER: Wurzel beschreibbar' || echo '    ok  Wurzel nicht beschreibbar'
        sudo -u www-data test -w $VORSCHAU_PFAD/lib  && echo '    FEHLER: lib beschreibbar'    || echo '    ok  lib nicht beschreibbar'
        sudo -u www-data test -w $VORSCHAU_PFAD/data && echo '    ok  data beschreibbar'       || echo '    FEHLER: data nicht beschreibbar — Formular bricht'"
}

# ── Rauchprobe ──────────────────────────────────────────────────
#
# Prüft nicht nur, dass die Seite antwortet, sondern dass PHP arbeitet und
# die gesperrten Pfade gesperrt bleiben. Eine Seite, die 200 liefert, aber
# die SMTP-Zugangsdaten ausliefert, wäre kein geglücktes Deployment.
rauchprobe() {
    local basis="$1" fehler=0
    pruefe() {
        local pfad="$1" erwartet="$2" name="$3"
        local code
        code=$(curl -s -o /dev/null -m 20 -w '%{http_code}' "$basis$pfad")
        if [ "$code" = "$erwartet" ]; then
            echo "    ok  $name ($code)"
        else
            echo "    FEHLER $name: $code, erwartet $erwartet" >&2
            fehler=1
        fi
    }
    pruefe "/"                        200 "Startseite"
    pruefe "/config.smtp.php"         404 "SMTP-Zugangsdaten gesperrt"
    pruefe "/lib/PHPMailer/SMTP.php"  404 "PHPMailer gesperrt"

    # Das Kontaktformular ist der einzige dynamische Teil. Antwortet der
    # Token-Endpunkt nicht mit einem signierten Token, läuft PHP nicht —
    # dann käme bei jeder Anfrage eines Besuchers ein Fehler.
    local token
    token=$(curl -s -m 20 "$basis/contact.php?action=token" || true)
    if echo "$token" | grep -q '"success":true'; then
        echo "    ok  Kontaktformular liefert Token"
    else
        echo "    FEHLER Kontaktformular: $(echo "$token" | head -c 120)" >&2
        fehler=1
    fi
    return $fehler
}

# ── Ziel: Vorschau ──────────────────────────────────────────────
deploy_vorschau() {
    echo "==> Vorschau: $VORSCHAU_URL ($SSH_ZIEL:$VORSCHAU_PFAD)"

    echo "==> Was würde sich ändern"
    local liste; liste=$(mktemp); trap 'rm -f "$liste"' RETURN
    rsync -rn --itemize-changes --delete "${AUSSCHLUSS[@]}" \
        ./ "$SSH_ZIEL:$VORSCHAU_PFAD/" | tee "$liste" | sed 's/^/    /' | head -30
    [ -s "$liste" ] || echo "    (keine Änderungen)"
    pruefe_sperrliste "$liste"

    if [ "$MODUS" = "--trockenlauf" ]; then
        echo "==> Trockenlauf — nichts übertragen"
        return 0
    fi

    echo "==> Übertrage"
    # --delete räumt entfernte Dateien auch auf dem Server weg. Die
    # Ausschlüsse schützen data/ und config.smtp.php davor.
    rsync -rz --delete "${AUSSCHLUSS[@]}" ./ "$SSH_ZIEL:$VORSCHAU_PFAD/"

    echo "==> Rechte wiederherstellen"
    rechte_herstellen
    pruefe_rechte

    echo "==> Rauchprobe"
    rauchprobe "$VORSCHAU_URL"
}

# ── Ziel: Live ──────────────────────────────────────────────────
deploy_live() {
    echo "==> Live: $LIVE_URL (SFTP)"
    [ -f .env ] || { echo "FEHLER: .env mit den SFTP-Zugangsdaten fehlt" >&2; exit 1; }
    # sed statt grep -oP: Das BSD-grep auf macOS kennt kein -P, und dieses
    # Skript laeuft auf einem Mac. Mit leerem $ordner liefe der Upload ins
    # Nichts — aufgefallen beim Trockenlauf.
    local ordner; ordner=$(sed -n 's/^Folder=//p' .env | head -1)
    [ -n "$ordner" ] || ordner='/htdocs'

    # Nur Geändertes übertragen: SFTP kennt keinen Abgleich wie rsync, und
    # 454 Dateien bei jedem Lauf hochzuladen dauert zu lange. Die Marke
    # trägt den Zeitpunkt des letzten erfolgreichen Laufs.
    # Dieselbe Freigabeliste wie bei der Vorschau — über rsync im
    # Trockenlauf ermittelt, damit es nur EINE Quelle dafür gibt und die
    # beiden Ziele nicht auseinanderlaufen.
    local dateien; dateien=$(mktemp); trap 'rm -f "$dateien"' RETURN
    local neuer=()
    if [ "$MODUS" != "--alles" ] && [ -f "$MARKE" ]; then
        echo "    (nur Dateien neuer als $MARKE — --alles überträgt alles)"
    fi
    rsync -rn --out-format='%n' "${AUSSCHLUSS[@]}" ./ /dev/null 2>/dev/null \
        | grep -v '/$' | sort > "$dateien" || true
    if [ "$MODUS" != "--alles" ] && [ -f "$MARKE" ]; then
        while read -r f; do
            [ -n "$f" ] && [ "$f" -nt "$MARKE" ] && neuer+=( "$f" )
        done < "$dateien"
        printf '%s\n' "${neuer[@]+"${neuer[@]}"}" | sed '/^$/d' | sort > "$dateien"
    fi
    local anzahl; anzahl=$(wc -l < "$dateien" | tr -d ' ')
    echo "==> $anzahl Datei(en) zu übertragen"
    [ "$anzahl" -gt 0 ] || { echo "    nichts zu tun"; return 0; }
    sed 's/^/    /' "$dateien" | head -20
    [ "$anzahl" -gt 20 ] && echo "    … und $((anzahl - 20)) weitere"
    pruefe_sperrliste "$dateien"

    if [ "$MODUS" = "--trockenlauf" ]; then
        echo "==> Trockenlauf — nichts übertragen"
        return 0
    fi

    # Verzeichnisse zuerst anlegen; sftp legt sie nicht automatisch an.
    local befehle=()
    while read -r d; do
        [ "$d" = "." ] || befehle+=( "-mkdir $ordner/$d" )
    done < <(cut -d/ -f1 --complement --output-delimiter=/ /dev/null 2>/dev/null; sed 's|/[^/]*$||' "$dateien" | sort -u)

    while read -r f; do
        befehle+=( "put \"$f\" \"$ordner/$f\"" )
    done < "$dateien"

    echo "==> Übertrage per SFTP"
    expect deploy/sftp.exp .env "${befehle[@]}"

    touch "$MARKE"
    echo "==> Rauchprobe"
    rauchprobe "$LIVE_URL" || {
        echo >&2
        echo "==> Die Dateien sind übertragen — die Rauchprobe betrifft den" >&2
        echo "    Zustand des Servers, nicht das Hochladen." >&2
        echo "    Läuft PHP für diese Domain beim Hoster überhaupt? Am" >&2
        echo "    22.10.2026 antwortete $LIVE_URL/contact.php mit 503" >&2
        echo "    'PHP nicht verfügbar für diese Domain'. Solange das so" >&2
        echo "    ist, zeigt die Seite den Ausweichhinweis mit Telefonnummer" >&2
        echo "    und E-Mail-Adresse (siehe assets/js/main.js)." >&2
        exit 1
    }
}

case "$ZIEL" in
    vorschau) deploy_vorschau ;;
    live)     deploy_live ;;
    *)
        cat >&2 <<'HILFE'
Verwendung: ./deploy.sh <ziel> [option]

  Ziele:
    vorschau    eigener Server, mo.lhaw.rocks (rsync, mit Rechte-Reparatur)
    live        Webspace des Hosters, miko-online.com (SFTP)

  Optionen:
    --trockenlauf   nur anzeigen, was passieren würde
    --alles         (nur live) alle Dateien statt nur der geänderten

  Beispiele:
    ./deploy.sh vorschau --trockenlauf
    ./deploy.sh vorschau
    ./deploy.sh live
HILFE
        exit 1 ;;
esac

echo "==> Fertig"
