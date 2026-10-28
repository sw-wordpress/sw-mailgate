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

## Tests, Erneuerung und Grenzen

**Test connection** beschafft ein Token und sendet keine Mail. Erst **Send test
mail** prüft Postfach-/Send-As-Rechte. Graph 202 bedeutet Annahme zur Verarbeitung,
nicht erfolgte Zustellung.

Gmail verwendet über 5 MB den Media-Upload-Endpunkt und lehnt MIME über 35 MB
lokal ab. Graph ist auf den gesamten Base64-Request von 4 MB begrenzt; bereits
kleinere Binäranhänge können damit zu groß sein. Graph-Upload-Sessions für große
Anhänge und SMTP/XOAUTH2 sind hier nicht enthalten.

Nach Client-/Tenant-/Modus-Wechsel oder geändertem Application-Postfach ist die
passende neue Autorisierung erforderlich; nach Secret-Rotation im Delegated-Modus
neu verbinden. **Remove local OAuth credentials** entfernt lokale Tokens und
verwirft offene Anmeldungen. Die Freigabe beim Anbieter separat widerrufen.
Bei weiterhin gültigen Application-Client-Credentials kann erneut ein Token
beschafft werden.

Die vorhandenen Failover-/Timeout-Regeln gelten auch für diese APIs.
Logging bleibt ausschließlich über SW_MAILGATE_LOG_ENABLED in wp-config.php
aktivierbar; die OAuth-Einrichtung schaltet es nicht ein.
