# miko® GmbH – Website

Statische Website der miko® GmbH (Kottenheim, Eifel) – Full-Service-Anbieter für
Werbeartikel, Merchandising, Corporate Fashion und Arbeitskleidung.

**Live:** https://miko-online.com (ohne `www` – siehe Fallstricke)
**Stand dieser Datei:** 19.10.2026

---

## Was das ist

Handgeschriebenes HTML/CSS/JS. **Kein Build-System, kein npm, kein Framework.**
Dateien werden direkt bearbeitet und per SFTP hochgeladen. Einziger dynamischer
Teil ist das Kontaktformular in PHP.

| | |
|---|---|
| Seiten | 10 Hauptseiten + 2 Landingpages |
| CSS | eine Datei, `assets/css/style.css` (2.844 Zeilen) |
| JS | eine Datei, `assets/js/main.js` (367 Zeilen, Vanilla) |
| Backend | `contact.php` (358 Zeilen) + PHPMailer |
| Bilder | 288, alle mit Alt-Text |

## Verzeichnisse

```
*.html                  10 Hauptseiten
landingpages/           2 eigenständige Kampagnenseiten (eigenes CSS im <style>)
assets/css|js|img|video Stylesheet, Skript, 305 Bilder, 14 Videos
contact.php             Kontaktformular (SMTP via PHPMailer)
lib/PHPMailer/          Mailer-Bibliothek
data/                   Laufzeitdaten des Formulars (Tokens, Rate-Limit)
downloads/              7 Lieferanten-PDFs (328 MB, nur 4 davon verlinkt)
deploy/sftp.exp         Upload-Helfer (siehe Deployment)
config.smtp.php         Zugangsdaten – NICHT im Git, auf Server eigene Fassung
.env                    SFTP-Zugang – NICHT im Git, Rechte 600

_archiv/                643 MB Arbeitsmaterial – gehört NICHT zur Website
Logos Referenzen/       59 MB Vektor-Originale von Kundenlogos – NICHT zur Website
```

`_archiv/root/uebergabe_website_miko.md` enthält das Änderungsprotokoll eines
Praktikanten von März 2026 (Katalog-Update auf 2026).

---

## Was NICHT auf den Server gehört

Am 22.10.2026 lagen auf dem Live-Webspace **94 Kundenlogos in Druckformaten**
(`Logos Referenzen/`, .ai/.eps/.pdf/hochauflösende JPG von DHL, Telekom,
Aral und anderen) sowie die Testseite `_capstest.html` — öffentlich
abrufbar. Nicht verlinkt und nicht in der Sitemap, aber der
Verzeichnisname ist erratbar, und ein Verzeichnisschutz bestand nicht.
Entfernt; die Vorlagen liegen weiterhin hier im Git.

**Die Seite brauchte sie nie:** Die Referenzlogos zeigt sie aus
`assets/img/referenzen/` (116 webp-Dateien). `Logos Referenzen/` ist das
Archiv der gelieferten Originale und gehört nicht ins Web.

`deploy.sh` arbeitet deshalb mit einer **Freigabeliste**, nicht mit einer
Sperrliste: Hochgeladen wird nur, was dort ausdrücklich steht. Der erste
Entwurf hatte eine Sperrliste mit „Logos" darin — das Verzeichnis heißt
aber „Logos Referenzen", und genau diese Lücke hätte den Fehler wiederholt.

## Deployment

Per SFTP auf den Webspace. Zugangsdaten stehen in `.env` im Projektwurzelverzeichnis
(`Host`, `Port`, `User`, `Password`, `Folder=/htdocs`).

```bash
expect deploy/sftp.exp .env 'put index.html /htdocs/index.html'
expect deploy/sftp.exp .env 'ls -l "/htdocs/Logos Referenzen"'   # Leerzeichen → Quotes
```

**Vorgehen:** Immer nur die tatsächlich geänderten Dateien hochladen, nie das
ganze Verzeichnis synchronisieren. Vorher die Server-Version per `get` sichern
und gegen den vorigen Commit vergleichen – so fällt auf, wenn jemand anders
direkt auf dem Server etwas geändert hat.

**Nie mit hochladen:** `.env`, `config.smtp.php` (Server hat eine eigene, 2.097
statt 2.093 Bytes), `_archiv/`, `Logos Referenzen/`, `.DS_Store`, `CLAUDE.md`,
`deploy/`, `*.pdf`.

**Der Zugang endet bei `/htdocs`.** Serverkonfiguration ist nicht erreichbar.

---

## Git

Remote: `https://github.com/miko78/miko-Internetseite.git`, Branch `main`.
Lesezugriff funktioniert ohne Passwortabfrage (Zugangsdaten hinterlegt).

> **Push:** Seit dem 23.10.2026 auf ausdrücklichen Wunsch des Nutzers: **jeden
> Commit sofort nach GitHub pushen**, ohne Rückfrage. (Früher galt „nur auf Bitte“;
> am 16.10.2026 hatte der Berechtigungs-Classifier einen Push blockiert.)

Bewusst **nicht** versioniert (`.gitignore`): `.env`, `config.smtp.php`, `data/*`,
`_archiv/`, `Logos Referenzen/`, `_capstest.html`, `downloads/` (328 MB, ändert
sich einmal jährlich, liegt auf dem Server), `*.pdf`.

Folge: Ein frischer Clone ist **nicht allein deploybar** – die Katalog-PDFs fehlen.

---

## Fallstricke

Punkte, die beim ersten Mal Zeit gekostet haben.

**Der Server läuft unter Caddy, nicht Apache.** `.htaccess` wird dort komplett
ignoriert. Die Datei im Projektwurzelverzeichnis ist reine Vorsorge für einen
möglichen Umzug und trägt einen Warnhinweis im Kopf. Wer Header oder
Zugriffsregeln wirklich ändern will, muss ans Hosting-Panel. Der Schutz von
Punkt-Dateien (`/.env` → 403) kommt bereits aus der Caddy-Konfiguration.

**Kopfzeile und Fußzeile sind auf allen 10 Seiten dupliziert.** Es gibt kein
Include. Jede Navigationsänderung muss in allen Dateien gemacht werden – am
besten per Skript mit `assert count == 1` je Datei, damit kein stiller Fehltreffer
passiert. `sonderanfertigungen.html` wich historisch ab (hatte keinen
Webshop-Button); seit `737bb7d` sind alle identisch.

**Die Landingpages sind eigenständig.** Sie bringen ihr CSS im `<style>`-Block mit
und nutzen `style.css` nicht. Ihre Pfade sind relativ zu `/landingpages/`, daher
`../assets/…` und `../impressum.html`. Genau dort lag ein Fehler: Impressum und
Datenschutz zeigten auf `/landingpages/impressum.html` → 404 (behoben in `3afb190`).

**`border-bottom: none !important` im Mobilmenü.** Die Regel entfernt die graue
Trennlinie der Menüzeilen – und dabei auch den eigenen Rahmen von Buttons mit
Umriss. Deshalb bekommt im Shop-Paar jeder Button eine eigene, vollständige
`border`-Angabe statt einer pauschalen Löschung. Im Stylesheet steht ein
Kommentar an der Stelle.

**Headless-Chrome misst falsch.** `--window-size` wirkt bei `--dump-dom` nicht;
Chrome layoutet dann mit 500 px und beschneidet den Screenshot auf die
angeforderte Breite. Das sieht aus wie ein abgeschnittener Button, ist aber ein
Messfehler. Für echte Mobilbreiten eine Wrapper-Seite mit
`<iframe width="390">` rendern.

---

## Konventionen

**Markenfarbe** `#E3003A` (`--red`). Alle Farben als CSS-Variablen in `:root`.

**Buttons:** `.btn-red` gefüllt (primär), `.btn-outline` roter Umriss auf hellem
Grund, `.btn-outline-white` weißer Umriss auf dunklem Grund. In der Kopfzeile
zusätzlich `.btn-shop-ghost` – Maße bewusst identisch zu `.nav-links a.btn-red`
(8px/20px + 2px Rahmen), damit beide Pillen exakt gleich hoch sind. Nicht
auseinanderziehen.

**Externe Links** immer mit `target="_blank" rel="noopener"`. Interne nie.

**Canonicals, `og:url`, Sitemap** immer ohne `www` (siehe SEO-Stand).

**Shops in der Kopfzeile:** `<li class="nav-shops">` fasst Hakro-Shop (Umriss) und
Webshop (gefüllt) als Paar zusammen. Mobil bilden sie eine Reihe zu zwei gleich
breiten Hälften (`align-items: stretch`).

---

## Kontaktformular

`contact.php` ist sicherheitstechnisch solide und sollte nicht vereinfacht werden:
Honeypots, HMAC-signierte Einmal-Tokens mit Zeitfenster (min. 3 s, max. 1 h),
Rate-Limiting pro IP (gehasht gespeichert, DSGVO-freundlich) über gelockte
JSON-Dateien in `data/`, UTF-8-Prüfung, Schutz gegen Header-Injection und eine
Link-Heuristik gegen Spam.

Konfiguration über `config.smtp.php` (Vorlage: `config.smtp.example.php`).

---

## SEO: Stand und offene Punkte

Vollständiges Audit mit 11 Befunden und Vier-Phasen-Plan:
https://claude.ai/code/artifact/383d557f-7a43-452c-8fe2-11e018725d5f
(PDF-Fassung: `SEO-Fahrplan-miko-online.pdf`, nicht im Git)

### Erledigt und live

| Befund | Was |
|---|---|
| **B2** | Canonicals, `og:url`, JSON-LD und Sitemap auf die Domain ohne `www` umgestellt. Vorher waren 10 von 10 Sitemap-URLs 301-Weiterleitungen, jetzt liefern alle 11 direkt 200. |
| **B4** | Landingpages sind keine Waisen mehr – Canonical, Open-Graph, Sitemap, interne Verlinkung. |
| **B5** | Kannibalisierung gelöst: `caps.html` ist die kanonische Caps-Seite, `landingpages/caps.html` verweist per Canonical darauf und steht nicht in der Sitemap. Sie bleibt für Besucher und Anzeigen erreichbar. |
| **B7** | Titel auf 40–54 Zeichen (vorher 21–79), Descriptions auf 146–157 Zeichen. |
| **B9** | Header-Logo ohne `loading="lazy"`, dafür `fetchpriority="high"`; Hero-Poster werden auf 5 Seiten vorgeladen. Footer-Logo bleibt bewusst lazy. |

### Offen – blockiert durch fehlende Zugänge

- **B1 Messbarkeit.** Kein Analytics, keine Search-Console-Verifizierung. Ohne
  Daten ist jede weitere Optimierung Blindflug. Braucht das Google-Konto des
  Nutzers. **Höchste Priorität.**
- **Google Business Profile** – für einen regionalen Anbieter der stärkste
  einzelne Hebel. Ebenfalls nur vom Nutzer einzurichten.
- **B3 Komprimierung und Caching.** Der Server sendet weder `content-encoding`
  noch `cache-control`. HTML geht mit 33,7 KB unkomprimiert raus (komprimiert
  wären es ~7 KB), `style.css` mit 62 KB. Caddy-Konfiguration, nicht erreichbar.
  Ein fertiger Konfigurationsblock wurde angeboten, aber noch nicht geschrieben.

### Offen – blockiert durch fehlendes Fachwissen

- **B6 Dünne Inhalte** auf den Geldseiten: `werbeartikel.html` 258 Wörter,
  `corporate-fashion.html` 269, `sonderanfertigungen.html` 233,
  `referenzen.html` 64 bei 80 Kundenlogos. Ziel 700–1.000 Wörter.
  Dafür nötig: Mindestmengen je Produktgruppe, Veredelungsverfahren,
  Lieferzeiten, 2–3 Kundenprojekte zum Zitieren.
- **B8 Lokaler Bezug.** Eifel, Andernach und Mendig kommen im Text nirgends vor.
  Für das LocalBusiness-Schema fehlen die Öffnungszeiten.

### Offen – kann jederzeit gemacht werden

- **B8 Schema.** Nur `Organization` auf der Startseite, sonst nichts.
  `LocalBusiness` sowie `Service`/`Product` je Sortimentsseite fehlen.
- **B9 Videos.** `arbeitskleidung-hero.mp4` 5,4 MB, Startseite 2,4 MB, insgesamt
  sechs Autoplay-Videos. `ffmpeg` ist lokal nicht installiert.
- **B10** Keine Breadcrumbs, kein Ratgeber-Bereich.
- **B11** Katalog-PDFs ohne HTML-Gegenstück.
- Die 1.639 Wörter der Caps-Landingpage sind durch den Canonical vorerst totes
  Kapital – Substanz nach `caps.html` übernehmen.

### Wichtig zu wissen

**Google Analytics bringt einen Cookie-Banner mit.** Die Seite hat heute kein
Tracking und deshalb zu Recht keinen Banner. Datenschutzfreundliche Alternativen
(Plausible, selbst gehostetes Matomo) gehen ohne Einwilligung. Die Search Console
ist davon nicht betroffen, sie arbeitet serverseitig.

**Das Audit beruht auf Code und Live-Seite, nicht auf Marktdaten.** Suchvolumina
und Wettbewerbsstärke sind unbekannt. Die Titelvorschläge waren fachlich
plausible Annahmen, keine belegten Zahlen.

---

## Weitere offene Punkte

**PHP2Shell (CVE-2026-109618/-109619), Stand 21.10.2026.** Der Server läuft mit
PHP 8.3.6 (PHP-FPM, vermutlich Ubuntu 24.04). Ob der Hoster das korrigierte Paket
`8.3.6-0ubuntu0.24.04.13` eingespielt hat, lässt sich von `/htdocs` aus nicht
feststellen (`phpinfo` ist gesperrt). Notmaßnahme: `.user.ini` schaltet
`session.upload_progress.enabled` ab (live geprüft, wirkt). Das ersetzt das Update
nicht – Bestätigung vom Hoster steht aus. `.user.ini` gehört zum Upload, ist
per Caddy von außen gesperrt (403). Zum Prüfen von PHP-Einstellungen ein
Wegwerf-Skript mit Zufallsnamen hochladen, abrufen, sofort wieder löschen.

**Kundenlogos im Document Root.** `/htdocs/Logos Referenzen/` (93 Dateien, 59 MB)
ist öffentlich abrufbar – druckfähige Vektor-Originale fremder Marken, etwa
`https://miko-online.com/Logos%20Referenzen/DEG Logo_Solo_HKS-Black_RZ.ai`.
Ein Verzeichnis-Listing gibt es nicht, man muss die Dateinamen kennen.
Der Abgleich lokal ↔ Server ist vollständig (93/93, gleiche Größen), ein Löschen
auf dem Server wäre also gefahrlos. **Lokal behalten** – die Vektor-Originale
lassen sich aus den `.webp` der Website nicht zurückgewinnen.
Ebenfalls öffentlich und nirgends verlinkt: `_capstest.html`.

**Ungenutzte PDFs.** `Atlantis_catalogue_2026.pdf`, `CGInt_Katalog_2026.pdf` und
`KingcapCatalogus-DU-mail.pdf` sind zusammen 79 MB, auf die keine Seite verweist.
