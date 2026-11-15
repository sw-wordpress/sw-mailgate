# Verifizierte manuelle GitHub-Updates

Stand: 15. November 2026. Temporärer Vertriebsweg bis zu einem Store-Eintrag. Adaptiert aus der Referenz
[churchtools-suite@49dc66f](https://github.com/sw-wordpress/churchtools-suite/tree/49dc66f) (`docs/GITHUB-UPDATES*.md`); eigene
Namen, Repository-ID und Schlüssel. **Status: Code vorhanden, aber nicht produktiv aktiv.** Ohne öffentlichen Schlüssel im Plugin,
geschützte GitHub-Umgebung und einen ersten signierten Release bleibt der Provider gesperrt (kein Update wird angeboten).

## Verhalten

- Updates erscheinen in der nativen Pluginübersicht (Update URI `https://github.com/sw-wordpress/sw-mailgate`). Installiert wird nur über
  WordPress' Einzel-, AJAX- oder Sammelupdate; Automatik (`auto_update_plugin`) ist für dieses Plugin immer aus, auch für alte gecachte Angebote.
- Prüfung gegen GitHub nur in berechtigten interaktiven Backend-Sitzungen (`update_plugins`, Multisite: Netzwerkrechte, auch über
  `admin-ajax.php`): höchstens einmal je 24 h, nach dem `load`-Ereignis der Pluginübersicht; Fehler zählen als Versuch. Der Zeitstempel liegt in einer
  Option, Transient-Löschung hebt ihn nicht auf. Manuell über „Jetzt auf Updates prüfen“ (Zeile des Plugins in der Pluginübersicht) mit Nonce und mindestens 60 s Abstand.
  Kein GitHub-Abruf im Frontend, Cron, REST oder WP-CLI. Anzeigen lesen nur den lokalen Cache (höchstens 7 Tage gültig).
- Vertrauensanker sind ausschließlich öffentliche Ed25519-Schlüssel in `src/Update/Config/trusted-keys.php`
  (optional überschreibbar durch die Konstante `SW_MAILGATE_RELEASE_PUBLIC_KEYS`; keine gemeinsame globale Liste). Ohne Schlüssel, Signatur oder
  Sodium/ZipArchive: gesperrt, kein Hash-only-Fallback.
- Signiert werden die exakten Manifestbytes (`sw-plugin-release.json` + `.sig`). Bindung an Repository und numerische ID (`1432339791`), Release-ID/Tag,
  Asset-ID, Dateiname `sw-mailgate-<version>.zip`, Slug, Version, Größe, SHA-256; doppelte JSON-Schlüssel, Prereleases/Drafts, Rollback
  (höchste gesehene Version) und Systemvoraussetzungen werden abgelehnt.
- Vor der Übergabe an WordPress wird das Manifest erneut verifiziert und das ZIP geprüft: Hash, Größe, genau ein Wurzelordner `sw-mailgate/`,
  sichere Pfade, nur Dateien/Verzeichnisse (keine Symlinks), keine Duplikate (case-insensitiv), Entpacklimits, Plugin-Header = Manifest.
  Es gibt keine eigene Entpack-/Kopierinstallation; temporäre Dateien werden bereinigt.
- Lock: atomar (MySQL/MariaDB GET_LOCK und Compare-and-Delete; ohne Datenbank-Mutex gesperrt), Lease 120 s, abgelaufene Locks sind übernehmbar, ein alter Besitzer kann weder freigeben noch veröffentlichen.
- Deaktivieren entfernt zuerst eigene Angebote und die Auto-Update-Auswahl (Fremdes bleibt), Deinstallieren auch Zustand/Lock/Migrationsflag.
- Es gab vor dieser Umsetzung keinen Updater/Installer im Plugin; die einmalige Migration (`admin_init`) räumt nur eigene Angebote und die Auto-Update-Auswahl.

Dateien: `src/Update/{Manifest,Transport,Package,Store,Service,Provider}.php`, `Config/{source,descriptors,trusted-keys}.php`,
`assets/js/sw-mailgate-updates.js`, `bin/release-manifest.php`, `.github/workflows/main.yml` (Release) und `tests.yml` (PR-Tests).
Namen: Optionen `swmg_verified_github_updates_v1`/`…_lock_v1`/`swmg_verified_updates_migrated`, AJAX `swmg_check_github_updates`, Nonce `swmg_verified_updates`,
Filter `swmg_verified_release_public_keys`/`swmg_verified_update_descriptors`, Skript `swmg-verified-updates`, JS-Global `swmgVerifiedUpdates`, DOM `swmg-verified-updates-*`.

## Einmalige Betreiber-Einrichtung

Reihenfolge: (1) Schlüssel erzeugen und öffentlichen Teil ins Plugin übernehmen, (2) GitHub-Umgebung `plugin-releases` einrichten, (3) öffentlichen Schlüssel
**vorab** an bestehende Installationen verteilen, (4) erst dann taggen. Der erste Release darf seinen eigenen Schlüssel nicht legitimieren.

Die Befehle sind für Bash/Zsh auf dem lokalen Entwicklungs-/Release-Rechner (PHP mit Sodium), nicht für den WordPress-Webserver. Der Checkout liegt unter
`/var/www/html/wordpress_dev/wp-content/plugins/sw-mailgate`. Private Dateien liegen ausschließlich unter `$HOME/.local/share/sw-mailgate-release-keys`
(Rechte 0700/0600), außerhalb von Checkout und Webroot; separat verschlüsselt sichern, nie in Git, ZIPs oder synchronisierte Ordner. Die Blöcke wurden mit
Wegwerfschlüsseln geprüft; produktive Schlüssel erzeugt diese Dokumentation nicht.

**1. Einmalig Schlüssel erzeugen.** Verweigert einen vorhandenen Ordner (kein Überschreiben). Die Key-ID ist öffentlich; bei Rotation neue ID und neuen Ordner verwenden.

```bash
(
  set -eu
  umask 077
  export SWMG_RELEASE_KEYS_DIR="$HOME/.local/share/sw-mailgate-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
if (!function_exists('sodium_crypto_sign_keypair')) {
    throw new RuntimeException('PHP-Sodium fehlt.');
}
$dir = getenv('SWMG_RELEASE_KEYS_DIR');
if (!$dir || file_exists($dir) || is_link($dir)) {
    throw new RuntimeException('Schlüsselordner existiert bereits oder Pfad fehlt. Keine Dateien verändert.');
}
if (!mkdir($dir, 0700, true)) {
    throw new RuntimeException('Schlüsselordner konnte nicht angelegt werden.');
}
$id = 'sw-mailgate-2026-11';
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);
$files = [
    'signing-key.base64' => base64_encode($secret) . "\n",
    'public-keys.php' => "<?php\nreturn " . var_export([$id => base64_encode($public)], true) . ";\n",
    'key-id.txt' => $id . "\n",
];
foreach ($files as $name => $contents) {
    $handle = fopen($dir . '/' . $name, 'x');
    if (!$handle || fwrite($handle, $contents) !== strlen($contents)) {
        throw new RuntimeException('Schlüsseldatei konnte nicht vollständig geschrieben werden.');
    }
    fclose($handle);
}
sodium_memzero($secret);
sodium_memzero($pair);
echo "Schlüsseldateien erzeugt; privater Schlüssel wurde nicht ausgegeben.\n";
PHP
)
```

**2. Nur den öffentlichen Schlüssel ins Plugin übernehmen.** Erhält vorhandene öffentliche Schlüssel, lehnt eine abweichende Belegung derselben Key-ID ab; kein privater Schlüssel gelangt in eine Umgebungsvariable oder nach WordPress.

```bash
(
  set -eu
  export SWMG_PLUGIN_DIR='/var/www/html/wordpress_dev/wp-content/plugins/sw-mailgate'
  export SWMG_RELEASE_KEYS_DIR="$HOME/.local/share/sw-mailgate-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
$target = getenv('SWMG_PLUGIN_DIR') . '/src/Update/Config/trusted-keys.php';
$existing = require $target;
$incoming = require getenv('SWMG_RELEASE_KEYS_DIR') . '/public-keys.php';
if (!is_array($existing) || !is_array($incoming) || count($incoming) !== 1) {
    throw new RuntimeException('Ungültiger öffentlicher Schlüsselring.');
}
foreach ($incoming as $id => $key) {
    $decoded = is_string($key) ? base64_decode($key, true) : false;
    if (!is_string($decoded) || strlen($decoded) !== 32 || (isset($existing[$id]) && $existing[$id] !== $key)) {
        throw new RuntimeException('Ungültiger öffentlicher Schlüssel oder widersprüchliche Key-ID.');
    }
    $existing[$id] = $key;
}
$contents = "<?php\n/**\n * Public Ed25519 verification keys.\n *\n * @package SWMailgate\n */\n\nreturn " . var_export($existing, true) . ";\n";
if (file_put_contents($target, $contents, LOCK_EX) !== strlen($contents)) {
    throw new RuntimeException('Öffentlicher Schlüsselring konnte nicht geschrieben werden.');
}
echo "Öffentlicher Schlüsselring aktualisiert.\n";
PHP
  php -l "$SWMG_PLUGIN_DIR/src/Update/Config/trusted-keys.php"
  git -C "$SWMG_PLUGIN_DIR" diff -- src/Update/Config/trusted-keys.php
)
```

Die angezeigte Änderung darf ausschließlich den öffentlichen Schlüssel enthalten; nach Review regulär committen.

**3. GitHub vorbereiten und Secret hinterlegen.** Zuerst in den [Repository-Environments](https://github.com/sw-wordpress/sw-mailgate/settings/environments) die Umgebung `plugin-releases` anlegen. Unter **Required reviewers** eine Freigabe und unter **Deployment branches and tags** ausschließlich Release-Tags (`v*` und `[0-9]*`, keine Branches) zulassen. Diese Schutzregeln vor dem ersten Tag einrichten; GitHub legt unbekannte Umgebungen sonst ohne Schutz an. Erst danach folgenden Block mit einem für dieses Repository berechtigten GitHub-CLI-Konto ausführen. `gh auth status` prüft die Anmeldung; falls nötig zuvor `gh auth login` ausführen.

```bash
(
  set -eu
  SWMG_RELEASE_KEYS_DIR="$HOME/.local/share/sw-mailgate-release-keys"
  gh auth status
  gh secret set SW_PLUGIN_RELEASE_SIGNING_KEY \
    --repo sw-wordpress/sw-mailgate --env plugin-releases \
    < "$SWMG_RELEASE_KEYS_DIR/signing-key.base64"
  gh variable set SW_PLUGIN_RELEASE_KEY_ID \
    --repo sw-wordpress/sw-mailgate --env plugin-releases \
    --body "$(cat "$SWMG_RELEASE_KEYS_DIR/key-id.txt")"
  gh secret list --repo sw-wordpress/sw-mailgate --env plugin-releases
  gh variable list --repo sw-wordpress/sw-mailgate --env plugin-releases
)
```

Der private Schlüssel wird über Standardeingabe an GitHub übergeben und erscheint weder im Befehlsargument noch in der Terminalausgabe. Die Listen zeigen Secret-Namen beziehungsweise öffentliche Variablen. Referenzen: [GitHub CLI: Secret setzen](https://cli.github.com/manual/gh_secret_set), [Variable setzen](https://cli.github.com/manual/gh_variable_set), [PHP: Ed25519-Schlüsselpaar erzeugen](https://www.php.net/manual/en/function.sodium-crypto-sign-keypair.php).

Ein erneutes Setzen des Secrets ersetzt den GitHub-Wert. Deshalb vor Ausführung sicherstellen, dass dieser lokale Schlüssel zum gewünschten öffentlichen Schlüsselring passt; bei Rotation den Ablauf weiter unten verwenden. Die Befehle erzeugen keinen Release-Tag und veröffentlichen kein ZIP. Der eingerichtete Tag-Workflow übernimmt später Build, Signierung und Veröffentlichung.


## Vertrauensanker verteilen, Rotation, Store

- **Erstverteilung:** Das Plugin mit dem öffentlichen Schlüssel einmal vertrauenswürdig installieren (kontrollierter manueller ZIP-Upload bzw. lokale Konfiguration über
  `SW_MAILGATE_RELEASE_PUBLIC_KEYS`). Erst danach akzeptieren Installationen signierte Folgeupdates. Ein Release wird nie von seinem eigenen, erstmals remote geladenen Schlüssel legitimiert.
- **Rotation:** Neuen Schlüssel mit neuer Key-ID erzeugen (Block 1 mit neuer ID/neuem Ordner), mit Block 2 **zusätzlich** eintragen und ein Übergangsrelease bauen, das noch mit dem
  alten Schlüssel signiert ist und beide öffentlichen Schlüssel ausliefert. Nach dessen Installation Secret und Variable auf den neuen Schlüssel umstellen; den alten Eintrag erst im Folgerelease entfernen.
- **Release:** Tag `vX.Y.Z` (oder `X.Y.Z`, optional `-NN`) = Header `Version` = `SW_MAILGATE_VERSION`. Der Workflow baut das ZIP, lädt es in einen Draft, signiert die tatsächlichen
  Asset-IDs, lädt Manifest und Signatur hoch und veröffentlicht erst dann. Fehlen Secret/Variable, bricht er vor dem Build ab; veröffentlichte Releases werden nie überschrieben.
  Draft-Metadaten werden nach dem ZIP-Upload über die von `gh release view` ermittelte API-URL geladen; alle hochgeladenen Artefakte werden vor Veröffentlichung erneut heruntergeladen und durch die Laufzeitprüfer validiert; der REST-Endpunkt `/releases/tags/<tag>` liefert für Drafts HTTP 404.
  Alte Tags verwenden ihren damaligen Workflow; Workflowänderungen benötigen einen neuen Tag.
- **Wechsel zu WordPress.org:** Store-Builds müssen den temporären GitHub-Provider (`Update\Provider::init()`-Aufruf, `Update/`-Klassen, Skript) und den Header `Update URI` entfernen;
  anschließend liefert wordpress.org die Updates. Bestehende GitHub-Installationen erhalten dafür ein letztes signiertes Übergangsrelease.

## Tests und Grenzen

- `tests/wp/tests/VerifiedUpdatesTest.php` (Einzelsite und Multisite, nur privates WordPress/Test-DB, Wegwerfschlüssel, simuliertes HTTP, echte native Upgrader-Läufe),
  `npm run test:updates-ui` (Browser, Coverage des Skripts), `tests/release/verify-signed-build.php <dist>` (echtes Build-ZIP mit Wegwerfschlüssel, Vollständigkeit, Abbruch ohne Secret).
  Alle laufen ausschließlich in `tests.yml` auf Pull Requests; der Release-Workflow führt keine Testsuite aus.
- Ein kompromittierter Signierworkflow kann autorisierte Schadpakete erzeugen; Environment-Freigaben und Tag-Schutz gehören deshalb zur Einrichtung. Das Vorenthalten neuerer Releases vor dem ersten Clientkontakt ist durch den lokalen Rollback-Schutz allein nicht erkennbar.
- REST/WP-CLI und fehlende Sodium-/ZIP-Unterstützung werden zusätzlich in isolierten Prozessen geprüft. Die End-to-End-Veröffentlichung mit echtem GitHub und produktivem Schlüssel bleibt bis zur Betreiber-Einrichtung ungeprüft. Ein parallel registrierter zweiter SW-Provider wird als lokale Testkopie mit eigener Identität, Schlüsseln und Speicherung simuliert.

## Lokale Verifikation

```bash
composer install
composer install --working-dir=tests/wp
npm ci
node node_modules/playwright-core/cli.js install chromium
bash bin/test-db.sh start
WP_ROOT="$PWD/tests/wp/vendor/johnpbloch/wordpress-core" WP_DB_HOST=127.0.0.1:33119 WP_DB_USER=swmg WP_DB_PASSWORD=swmg XDEBUG_MODE=coverage composer test:coverage
npm run coverage:js
composer stan
composer cs
bash bin/build-zip.sh /tmp/swmg-dist
php tests/release/verify-signed-build.php /tmp/swmg-dist
python3 tests/release/key-instructions.py
```

Die Datenbank muss für die gesamte Testausführung laufen. Single-Site, Multisite und bestehende Mailtests laufen im Runner nacheinander.
Der Build erzeugt `sw-mailgate-0.8.0.zip`; ohne Runtime-Abhängigkeiten besteht `vendor-prefixed/` ausschließlich aus dem Composer-Loader.
Künftige Runtime-Abhängigkeiten lassen den Build abbrechen, bis deren Namespace-Isolation umgesetzt ist.
Ergebnisse und ausstehende Betreiberarbeiten: [Prüfprotokoll](github-updates-verification.md).
