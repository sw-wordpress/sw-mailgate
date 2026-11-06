# Google und Microsoft 365 einrichten

Unter **Mailgate → Verbindungen** eine Google-/Gmail- oder Microsoft-365-Verbindung
anlegen. **How do I create the OAuth client credentials?** öffnet die Hilfe mit
der exakten Redirect-URI dieser Installation. Ohne JavaScript lässt sich dieselbe
Anleitung aufklappen. WordPress muss für OAuth über HTTPS erreichbar sein.

Client-Secrets und Tokens werden im bestehenden Secret Store verschlüsselt.
Gespeicherte Secrets erscheinen nicht wieder im Formular; ein leeres Feld behält
den vorhandenen Wert. Die üblichen
SW_MAILGATE_<VERBINDUNGS-ID>_<FELD>-Konstanten werden unterstützt.

## Google / Gmail

1. In der [Google Cloud Console](https://console.cloud.google.com/) ein Projekt
   auswählen oder anlegen. Unter **APIs & Services → Library** die **Gmail API**
   aktivieren.
2. **Google Auth Platform → Branding** öffnen. App-Name, Support-E-Mail und
   Entwicklerkontakt eintragen und die Grundeinrichtung abschließen.
3. Unter **Audience** den Nutzerkreis festlegen. **Internal** passt nur zu einer
   entsprechenden Workspace-Organisation und deren eigenen Konten. Ansonsten
   **External** verwenden. In einer externen Test-App das sendende Konto unter
   **Test users** hinzufügen.
4. Unter **Data Access** ausschließlich den Sendescope hinzufügen:

       https://www.googleapis.com/auth/gmail.send

5. **Google Auth Platform → Clients → Create client → Web application** wählen.
   Unter **Authorized redirect URIs** die URI aus Mailgate vollständig übernehmen:
   HTTPS, Host, Unterverzeichnis und Query-String müssen exakt stimmen.
   JavaScript origins werden für diesen serverseitigen Flow nicht benötigt.
6. **Client ID** und **Client secret** aus dem erstellten OAuth-Client in
   Mailgate eintragen. Kein API-Key, Service-Account oder Mailbox-Passwort.
7. Verbindung speichern, **Connect mailbox** wählen, mit dem sendenden Konto
   anmelden und die Berechtigung bestätigen.
8. Als From-Adresse das Konto oder einen bereits in Gmail freigegebenen Alias
   verwenden. Verbindung testen, anschließend eine Testmail senden.

**gmail.send ist sensitive, nicht restricted.** Für externe Produktions-Apps kann
Google-Verifizierung nötig sein. Interne Workspace-Apps unterliegen weiterhin
den Organisationsrichtlinien. Im Status **Testing** laufen Refresh-Tokens einer
externen App für diesen Scope normalerweise nach sieben Tagen ab. Für
unbeaufsichtigten Produktivversand die passende Veröffentlichung/Freigabe
vornehmen und anschließend neu verbinden.

Bei **redirect_uri_mismatch** die registrierte URI Zeichen für Zeichen prüfen.
Nach einem Domainwechsel aktualisieren und neu verbinden. Bei widerrufenem Zugriff
oder abgelaufenem Test-Token **Reconnect mailbox** verwenden.

Quellen: [Consent konfigurieren](https://developers.google.com/workspace/guides/configure-oauth-consent),
[Gmail-Scopes](https://developers.google.com/workspace/gmail/api/auth/scopes),
[Web-OAuth](https://developers.google.com/identity/protocols/oauth2/web-server).

## Microsoft 365

### App und Client-Secret

1. Im [Entra Admin Center](https://entra.microsoft.com/) zu **Identity →
   Applications → App registrations → New registration** wechseln.
2. Namen vergeben und **Accounts in this organizational directory only** wählen.
   Unterstützt wird ein Work-/School-Tenant in der globalen Microsoft-Cloud.
3. Für **Delegated** die URI aus Mailgate mit Plattform **Web** registrieren.
   Keine SPA, impliziten Grants oder Public-Client-Flows aktivieren.
   Application-Modus benötigt keine Redirect-URI.
4. Aus **Overview** die **Application (client) ID** und die **Directory (tenant)
   ID** nach Mailgate übernehmen. Tenant ID muss eine UUID sein, keine URL,
   common oder organizations.
5. Unter **Certificates & secrets → Client secrets → New client secret**
   ein Secret anlegen und dessen **Value** sofort kopieren. Die **Secret ID**
   ist nicht der geheime Wert. Ablaufdatum vormerken und rechtzeitig rotieren.

### Delegated: angemeldetes Postfach

Unter **API permissions → Add a permission → Microsoft Graph → Delegated
permissions** die Berechtigung **Mail.Send** hinzufügen. Mailgate fordert
zusätzlich **offline_access** für die Erneuerung an. User.Read, Mail.Read oder
Mail.ReadWrite werden nicht benötigt.

Falls erforderlich, Administrator-Einwilligung einholen. In Mailgate **Delegated**
wählen, speichern und **Connect mailbox** ausführen. Mit dem tatsächlich sendenden
Postfachkonto anmelden. Gesendet wird über /me/sendMail; das Application-Postfachfeld
wird ignoriert. Shared-Mailbox-Delegation mit Mail.Send.Shared gehört nicht zu
diesem Modus. Verbindung testen und eine Testmail senden.

### Application: unbeaufsichtigter Versand

In Mailgate **Application** wählen und **Mailbox address** eintragen.
Access-Tokens werden über Client ID und Secret beschafft; kein Benutzerlogin
oder Refresh-Token ist nötig.

Ein Exchange-Administrator muss die tatsächlichen Postfachrechte einrichten,
vorzugsweise **RBAC for Applications** mit **Application Mail.Send** und einem
Resource Scope für die gewünschten Postfächer. Beispiel für ein einzelnes
Postfach; IDs/Adressen ersetzen und vorhandene Konfiguration berücksichtigen:

    Connect-ExchangeOnline
    New-ServicePrincipal -AppId CLIENT_ID -ObjectId ENTERPRISE_APP_OBJECT_ID -DisplayName "Mailgate"
    New-ManagementScope -Name "Mailgate mailbox" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'mailer@example.org'"
    New-ManagementRoleAssignment -Name "Mailgate Send" -App ENTERPRISE_APP_OBJECT_ID -Role "Application Mail.Send" -CustomResourceScope "Mailgate mailbox"
    Test-ServicePrincipalAuthorization -Identity ENTERPRISE_APP_OBJECT_ID -Resource mailer@example.org
    Test-ServicePrincipalAuthorization -Identity ENTERPRISE_APP_OBJECT_ID -Resource other@example.org

Die Object ID kommt aus **Enterprise applications**, vom Service Principal,
nicht aus App registrations. Ein vorhandener Exchange-Service-Principal muss
nicht erneut erstellt werden.

**Entra- und Exchange-Rechte sind additiv.** Ein unbeschränktes Application-Mail.Send
in Entra wird durch einen engen RBAC-Scope nicht eingeschränkt. Bei einer reinen
RBAC-Lösung die entsprechende unbeschränkte Entra-Zuweisung entfernen.
Der obige Test bewertet nur Exchange-RBAC, nicht separate Entra-Grants.
Deshalb Konfiguration und Zugriff auf ein erlaubtes sowie ein nicht erlaubtes
Postfach prüfen. Das Postfachfeld in WordPress ist keine Zugriffsbegrenzung.

Alternativ kann eine bestehende **Application Access Policy** genutzt werden:
Graph **Application Mail.Send** mit Admin Consent und einer Exchange-Policy
für die erlaubte mail-enabled Security Group. Die aktuelle Microsoft-Anleitung
beachten; RBAC und dieser ältere Ansatz wirken unterschiedlich. Änderungen
können verzögert wirksam werden.

Quellen: [Application RBAC](https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac),
[Client-Credentials](https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-client-creds-grant-flow),
[Graph sendMail](https://learn.microsoft.com/en-us/graph/api/user-sendmail).

## SMTP mit XOAUTH2

Für SMTP eine eigene Verbindung **Google SMTP / XOAUTH2** oder **Microsoft 365
SMTP / XOAUTH2** anlegen. API- und SMTP-Tokens sind getrennt; vorhandene
API-Verbindungen müssen nicht neu autorisiert werden. Die neuen Typen unterstützen
delegierten Zugriff auf das angemeldete Postfach. SMTP.SendAsApp und
Shared-Mailbox-Delegation werden nicht unterstützt.

Als **Mailbox login address** die primäre Postfachadresse eintragen, als From
das Postfach oder einen erlaubten Versandalias. Beim Verbinden mit genau diesem
Postfach anmelden. Ein anderer From-Absender benötigt die entsprechende
Alias-/Send-As-Freigabe. Nach Änderung der Login-Adresse speichern und neu verbinden.

### Google SMTP

1. In Google Cloud ein Projekt anlegen oder auswählen. **Google Auth Platform →
   Branding** mit App-Name, Support-Adresse und Entwicklerkontakt ausfüllen.
2. Unter **Audience** den internen Workspace-Nutzerkreis oder **External** wählen.
   Bei externen Test-Apps das sendende Postfach unter **Test users** aufnehmen.
3. Unter **Data Access** folgenden Scope hinzufügen, gegebenenfalls manuell:

       https://mail.google.com/

   **Dieser Scope ist restricted und umfasst Lesen, Senden, Ändern und endgültiges
   Löschen von Mail.** Die SMTP-Implementierung nutzt nur Versand, die Freigabe ist
   trotzdem umfassend. Die Gmail-API-Verbindung mit gmail.send ist die passende
   Wahl, wenn ausschließlich Senderechte gewünscht sind. Für externe
   Produktions-Apps können Restricted-Scope-Verifizierung und je nach Verarbeitung
   eine Sicherheitsprüfung nötig sein. Workspace-Zugriffsrichtlinien beachten.
   Externe Testing-Refresh-Tokens laufen in der Regel nach sieben Tagen ab.
4. Unter **Clients → Create client → Web application** einen Client erstellen.
   Die exakte Redirect-URI aus dem Mailgate-Dialog unter **Authorized redirect
   URIs** eintragen. JavaScript origins sind nicht nötig.
5. Client ID und Client secret nach Mailgate übernehmen. Keine App-Passwörter,
   API-Keys oder Service-Account-Dateien verwenden. Login-Postfach eintragen,
   speichern und über **Connect mailbox** die Vollzugriffsberechtigung bestätigen.
6. Verbindung testen und Testmail senden. Endpunkt ist fest `smtp.gmail.com:587`.
   Gmail-SMTP-Limits gelten; die Gmail-API-Media-Uploads werden nicht verwendet.

Quelle: [Googles SMTP-XOAUTH2-Protokoll und Scope](https://developers.google.com/workspace/gmail/imap/xoauth2-protocol).

### Microsoft 365 SMTP

1. Entra-App wie oben unter **App und Client-Secret** registrieren: Single Tenant,
   Plattform **Web**, exakte Redirect-URI, Client ID, Tenant-UUID und Secret **Value**.
2. Unter **API permissions → Add a permission → Office 365 Exchange Online →
   Delegated permissions** die Berechtigung **SMTP.Send** hinzufügen. Falls nicht
   sichtbar, unter **APIs my organization uses** nach Office 365 Exchange Online
   suchen; Resource-ID `00000002-0000-0ff1-ce00-000000000000`.
   **Microsoft Graph Mail.Send berechtigt nicht zum SMTP-Login.**
3. Der angeforderte Scope lautet:

       https://outlook.office.com/SMTP.Send offline_access

   Falls die Organisation es verlangt, Administrator-Consent erteilen.
4. Login-Postfach, Client ID, Tenant-UUID und Secret Value in Mailgate speichern.
   **Connect mailbox** wählen, mit diesem Postfach anmelden und Zugriff bestätigen.
5. Der Exchange-Administrator prüft SMTP AUTH für genau dieses Postfach. Im
   Microsoft-365-Admin-Center: **Users → Active users → Postfach → Mail → Manage
   email apps → Authenticated SMTP**. Alternativ in Exchange Online PowerShell:

       Get-TransportConfig | Format-List SmtpClientAuthenticationDisabled
       Get-CASMailbox -Identity mailer@example.org | Format-List SmtpClientAuthenticationDisabled

   Ein leerer Postfachwert erbt die Organisationsvorgabe. Wenn die Richtlinie es
   erlaubt, SMTP AUTH nur für das Zielpostfach freigeben:

       Set-CASMailbox -Identity mailer@example.org -SmtpClientAuthenticationDisabled $false

   **Security Defaults oder Organisationsrichtlinien können SMTP AUTH blockieren.**
   Dann den Graph-Provider nutzen; die Mandantensicherheit nicht eigens dafür
   abschwächen. Basic Authentication oder App-Passwörter sind für XOAUTH2 unnötig.
6. Verbindung testen und Testmail senden. Der feste Endpunkt ist
   `smtp.office365.com:587`; Exchange-SMTP-Limits gelten, nicht das Graph-4-MB-Limit.

Quellen: [Microsoft SMTP OAuth](https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth),
[SMTP-AUTH-Konfiguration](https://learn.microsoft.com/en-us/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission).

### SMTP-Sicherheit und Fehlerdiagnose

STARTTLS und Zertifikatsprüfung sind verpflichtend. Host, Port, Verschlüsselung und
Passwort sind bei diesen Verbindungstypen festgelegt; auch entsprechende
wp-config-Overrides ändern diese Transportvorgaben nicht. Client-Daten und
Login-Adresse lassen sich weiterhin über die üblichen Konstanten setzen.
Der Verbindungstest authentifiziert über TLS, ohne MAIL FROM/RCPT/DATA zu senden.
Erst die Testmail prüft From-/Send-As-Rechte und den tatsächlichen Versand.

Bei **535** die SMTP-Berechtigung, das angemeldete Konto, widerrufene Freigaben und
Microsofts SMTP-AUTH-Einstellungen prüfen. Bei **redirect_uri_mismatch** die
registrierte URI vergleichen. Abgelaufene Access-Tokens werden automatisch erneuert.
Abgelehnte SMTP-Versuche werden auf derselben Verbindung nicht automatisch
wiederholt; bei Authentifizierungsfehlern darf die Failover-Kette fortsetzen.
Teilzustellungen oder unbestätigte Annahme nach DATA werden nicht erneut versendet.

## Tests, Erneuerung und Grenzen

**Test connection** beschafft ein Token und sendet keine Mail. Erst **Send test
mail** prüft Postfach-/Send-As-Rechte. Graph 202 bedeutet Annahme zur Verarbeitung,
nicht erfolgte Zustellung.

Gmail verwendet über 5 MB den Media-Upload-Endpunkt und lehnt MIME über 35 MB
lokal ab. Graph ist auf den gesamten Base64-Request von 4 MB begrenzt; bereits
kleinere Binäranhänge können damit zu groß sein. Graph-Upload-Sessions für große
Anhänge sind hier nicht enthalten. SMTP/XOAUTH2 ist als eigener Verbindungstyp
mit den oben beschriebenen Berechtigungen verfügbar.

Nach Client-/Tenant-/Modus-Wechsel oder geändertem Application-Postfach ist die
passende neue Autorisierung erforderlich; nach Secret-Rotation im Delegated-Modus
neu verbinden. **Remove local OAuth credentials** entfernt lokale Tokens und
verwirft offene Anmeldungen. Die Freigabe beim Anbieter separat widerrufen.
Bei weiterhin gültigen Application-Client-Credentials kann erneut ein Token
beschafft werden.

Die vorhandenen Failover-/Timeout-Regeln gelten auch für diese APIs.
Logging bleibt ausschließlich über SW_MAILGATE_LOG_ENABLED in wp-config.php
aktivierbar; die OAuth-Einrichtung schaltet es nicht ein.
