# Verifizierte manuelle Updates

Lokale Portierung von [ChurchTools Suite @49dc66f](https://github.com/sw-wordpress/churchtools-suite/tree/49dc66f), mit der nativen Pluginzeile aus sw-backupit. Kein Code wird zur Laufzeit aus diesen Plugins nachgeladen.

Quelle: `sw-wordpress/sw-mailgate` (GitHub-ID **1432339791**), Slug `sw-mailgate`, Hauptdatei `sw-mailgate.php`, Basename `sw-mailgate/sw-mailgate.php`, Version `SW_MAILGATE_VERSION`.

`src/Update/` trennt WordPress-Adapter, Prüfpolitik, persistenten Zustand, HTTPS-Transport, Manifest- und ZIP-Prüfung. Alle Namen und Vertrauensanker sind pluginspezifisch. Es gibt keinen bisherigen GitHub-Updater oder eigenen Plugininstaller zu migrieren; die Migration bereinigt ausschließlich eigene WordPress-Angebote und Auto-Update-Auswahlen. Mail-Cronjobs bleiben erhalten.

Prüfschaltfläche und Status erscheinen ausschließlich in der nativen Pluginverwaltung. Diese liest lokalen Zustand; erst nach dem Seitenaufbau darf ein authentifizierter AJAX-Aufruf prüfen. Installationen bleiben manuelle native WordPress-Aktionen.

Sicherheitsvertrag, drei kopierbare Schlüsselblöcke, geschützte Release-Umgebung, Vertrauensstart, Rotation und Store-Wechsel: [Betriebsanleitung](GITHUB-UPDATES-BETRIEB.md). **Ohne eingerichtete Vertrauensanker und signierten Release keine aktive Updateversorgung.**
