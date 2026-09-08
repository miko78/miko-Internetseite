<?php
/**
 * SMTP-Konfiguration – VORLAGE
 *
 * 1. Diese Datei kopieren nach:  config.smtp.php
 * 2. Dort die echten Zugangsdaten eintragen.
 *
 * config.smtp.php ist über .gitignore vom Repository ausgeschlossen,
 * damit die Zugangsdaten NICHT in Git landen.
 */

return [
    // ── SMTP-Server (eigener Mailserver) ──
    'host'       => 'mail.miko-online.com',   // SMTP-Hostname
    'port'       => 587,                       // 587 = STARTTLS, 465 = SSL/SMTPS
    'encryption' => 'tls',                     // 'tls' (für 587) oder 'ssl' (für 465)
    'username'   => 'noreply@miko-online.com', // SMTP-Benutzername
    'password'   => 'HIER_PASSWORT_EINTRAGEN', // SMTP-Passwort

    // ── Absender / Empfänger ──
    'from_email' => 'noreply@miko-online.com', // Absenderadresse (sollte zum SMTP-Konto passen)
    'from_name'  => 'miko Website',            // Absendername
    'to_email'   => 'info@miko-online.com',    // Empfänger der Kontaktanfragen
    'to_name'    => 'miko GmbH',

    // ── Debug ──
    // 0 = aus (Produktion), 2 = ausführliches SMTP-Protokoll (nur zum Testen)
    'debug'      => 0,

    // ── Sicherheit / Spam- & Missbrauchsschutz ──
    'security' => [
        // Geheimer Schlüssel zum Signieren der Formular-Tokens (HMAC).
        // Eigene lange Zufallszeichenkette erzeugen mit:  openssl rand -hex 32
        'secret'           => 'HIER_EIGENES_ZUFALLS_SECRET_EINTRAGEN',

        // Zeitfenster: Wie lange nach Aufruf darf gesendet werden?
        'min_seconds'      => 3,      // frühestens nach X Sek. (Bots senden sofort)
        'max_seconds'      => 3600,   // spätestens nach X Sek. (Token-Ablauf = 1 Std.)

        // Rate-Limiting pro IP-Adresse
        'max_per_hour'     => 5,      // max. Absendungen je Stunde
        'max_per_day'      => 15,     // max. Absendungen je Tag

        // Inhalts-Heuristik
        'max_links'        => 3,      // max. Anzahl URLs in der Nachricht

        // Verzeichnis für Zähler/Token (muss beschreibbar sein)
        'data_dir'         => __DIR__ . '/data',
    ],
];
