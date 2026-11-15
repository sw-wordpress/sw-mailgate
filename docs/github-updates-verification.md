# Prüfprotokoll – 15. November 2026

Umgebung: PHP 8.3.16, private WordPress-Kopie 6.9.11, eigene MariaDB auf Port 33119. Keine produktiven Plugin-Dateien ersetzt; HTTP vollständig simuliert. Schlüssel ausschließlich kurzlebige Testschlüssel.

| Prüfung | Ergebnis |
| --- | --- |
| `composer test:coverage` mit isolierter WordPress/DB | Bestanden; 46 instrumentierte Prozesse plus Erweiterungsregressionen |
| Updater Single-Site | 107 Tests, 357 Assertions |
| Updater Multisite | 107 Tests, 363 Assertions |
| PHP-Zeilenabdeckung gesamter bestehender Produktivbereich | 3829/4162 = **92,00 %**; Grenze >80 % |
| `npm run coverage:js` einschließlich Chromium | Bestanden; 76/76 = **100 %**; Grenze >80 % |
| `composer stan` | Level 8, keine Baseline, keine Fehler |
| `composer cs` | Bestanden; zwei bestehende Warnungen (`$return`, `$default`) |
| Echter ZIP-Build und `tests/release/verify-signed-build.php` | Vollständigkeit, Wegwerfsignatur, Laufzeitprüfer, Release-Artefaktprüfer und Abbruch ohne Secret bestanden |
| Drei dokumentierte Schlüsselblöcke, Bash und Zsh | Dateirechte 0700/0600, Wiederholungs-/Konfliktschutz, Erhalt öffentlicher Schlüssel, stdin; GitHub CLI lokal simuliert |

Abgedeckt: Signaturfehler/Byteänderungen, Identitäts-/Assetbindungen, doppelte JSON-Schlüssel, manipulierte und unsichere ZIPs, Redirects/Limits, Nonces/Capabilities, Frontend/Cron/REST/CLI, persistente Sperrfrist nach Fehler und Cache-Löschung, Rollback, Locks/Eigentümerwechsel, Deaktivierung, native Einzel-/AJAX-/Sammelupdates und Multisite einschließlich Netzwerk-AJAX. Zwei unabhängig registrierte SW-Provider mit getrennten Schlüsseln und Zuständen laufen als lokale Testkopien gleichzeitig; eine vollständige Kombination aller installierten SW-Plugins ist damit nicht geprüft.

PR-Workflow führt Suiten sequenziell pro Datenbank aus. Release-Workflow führt keine Testsuite aus: Er ergänzt den öffentlichen Build-Schlüssel, erstellt den Draft, signiert tatsächliche Asset-IDs, lädt alle Artefakte erneut zur Laufzeitvalidierung herunter und veröffentlicht erst danach. Eine Veröffentlichung mit produktivem Schlüssel wurde nicht durchgeführt.

**Betreiberprüfung:** Der Schlüsselring enthält den öffentlichen Schlüssel `sw-mailgate-2026-11`. Zugehörigen privaten Schlüssel und GitHub-Secret/Key-ID, kontrollierte Verteilung an bestehende Installationen sowie `plugin-releases` mit Review-/Tag-Schutz vor dem ersten signierten Release prüfen. Diese Einrichtung und eine Veröffentlichung mit Produktionsschlüssel wurden hier nicht verifiziert. Kopierbare Befehle, Rotation und Store-Wechsel: [Betriebsanleitung](GITHUB-UPDATES-BETRIEB.md).
