# Plugin Stellantis Connect

Dieses Plugin verbindet Ihre Fahrzeuge von **Stellantis / ehemaliger PSA-Konzern** (Peugeot, Citroën, DS, Opel, Vauxhall)
mit Jeedom: Abruf der Telemetriedaten (Batterie, Ladevorgang, Reichweite, Kraftstoff, GPS-Position,
Kilometerstand …) über die "Connected Car"-API, die von den offiziellen mobilen Anwendungen verwendet wird
(MyPeugeot, MyCitroën, MyDS, MyOpel, MyVauxhall).

> ⚠️ Das Plugin nutzt die **Verbraucher-API** von Stellantis, dieselbe wie die mobilen Anwendungen.
> Stellantis stellt Privatpersonen keinen Entwicklerzugang zur Verfügung: Sie müssen die
> Anmeldedaten der mobilen App Ihrer Marke selbst beschaffen (siehe „Anmeldedaten beschaffen" unten).

## Plugin-Konfiguration

Gehen Sie zu `Plugins → Plugin-Verwaltung → Stellantis Connect → Konfiguration`. Die Einstellungen
gelten für alle Ihre Fahrzeuge derselben Marke:

| Feld | Beschreibung |
|---|---|
| **Marke** | Die Marke Ihrer Fahrzeuge (Peugeot, Citroën, DS, Opel oder Vauxhall). Sie bestimmt den verwendeten Authentifizierungsserver und die Domain — wählen Sie die Marke, die zur mobilen App passt, aus der Ihre Anmeldedaten stammen. |
| **Client ID** | OAuth2-Kennung der mobilen App, aus der APK extrahiert (siehe unten). |
| **Client Secret** | Zugehöriges OAuth2-Secret, aus der APK extrahiert. Es wird in Jeedom **verschlüsselt gespeichert** und erscheint niemals in den Logs. |
| **Land** | Zweistelliger Ländercode (z. B. `fr`), der zur Erstellung der Standard-Weiterleitungs-URL verwendet wird. |
| **Weiterleitungs-URL** | OAuth2 `redirect_uri` der mobilen App (z. B. `mymap://oauth2redirect/fr`). Leer lassen, um den Standardwert der Marke zu verwenden. Wenn Ihr Extraktionstool Ihnen einen Wert geliefert hat, verwenden Sie diesen. |

Solange Client-ID und Client-Secret nicht ausgefüllt sind, zeigt die Seite ein Banner
„Plugin nicht konfiguriert" an, und die übrigen Funktionen des Plugins bleiben inaktiv.

## Anmeldedaten beschaffen (Client ID / Client Secret)

Die Anmeldedaten werden **nicht** von Stellantis herausgegeben: Sie sind in der mobilen App jeder
Marke eingebettet. Die bewährte Methode aus dem Open-Source-Projekt
[psa_car_controller](https://github.com/flobz/psa_car_controller) besteht darin, sie aus der APK zu extrahieren:

1. Laden Sie die APK der mobilen App **Ihrer Marke** herunter (zum Beispiel aus dem Repository
   [flobz/psa_apk](https://github.com/flobz/psa_apk), das kompatible Versionen archiviert).
2. Führen Sie das von psa_car_controller bereitgestellte Skript `app_decoder.py` auf dieser APK aus:
   ```
   python3 app_decoder.py <datei.apk>
   ```
3. Das Skript zeigt unter anderem die `client_id` und das `client_secret` der Anwendung an. Übernehmen Sie
   diese unverändert in die Plugin-Konfiguration und wählen Sie die Marke, die zur verwendeten APK passt.

Diese Extraktion erfolgt **außerhalb von Jeedom** (auf Ihrem Computer); das Plugin lädt keine APK herunter
und analysiert auch keine. Die Anmeldedaten laufen nicht ab, dieser Vorgang muss nur einmal durchgeführt werden.

## Nächste Schritte

Sobald das Plugin konfiguriert ist, verbinden Sie Ihr Konto (Authentifizierungsschaltfläche) und starten
dann die Fahrzeugerkennung — diese Schritte werden in den entsprechenden Abschnitten dieser
Dokumentation im Laufe der Plugin-Versionen beschrieben.
