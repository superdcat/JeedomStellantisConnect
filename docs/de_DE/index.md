# Plugin Stellantis Connect

Dieses Plugin verbindet Ihre Fahrzeuge von **Stellantis / ehemaliger PSA-Konzern** (Peugeot, Citroën, DS, Opel, Vauxhall)
mit Jeedom: Abruf der Telemetriedaten (Batterie, Ladevorgang, Reichweite, Kraftstoff, GPS-Position,
Kilometerstand …) über die "Connected Car"-API, die von den offiziellen mobilen Anwendungen verwendet wird
(MyPeugeot, MyCitroën, MyDS, MyOpel, MyVauxhall).

> ⚠️ Das Plugin nutzt die **Verbraucher-API** von Stellantis, dieselbe wie die mobilen Anwendungen.
> Stellantis stellt Privatpersonen keinen Entwicklerzugang zur Verfügung: Sie müssen die
> Anmeldedaten der mobilen App Ihrer Marke selbst beschaffen (siehe „Anmeldedaten beschaffen" unten).

> Markennamen und -farben (Peugeot, Citroën, DS, Opel, Vauxhall) werden ausschließlich zu
> Identifikationszwecken genannt; dieses Plugin steht in keiner Verbindung zu den Herstellern und
> wird nicht von ihnen unterstützt.

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

Die Anmeldedaten werden **nicht** von Stellantis herausgegeben: Sie sind in der APK der mobilen App
jeder Marke eingebettet (in einer internen Datei `parameters.json`, unter den Schlüsseln `cvsClientId`
und `cvsSecret`). Sie müssen sie daher **einmalig** auf einem Computer extrahieren — das Plugin selbst
lädt keine APK herunter und analysiert auch keine.

> Die Anmeldedaten hängen von der **Marke** und dem **Land** Ihres Kontos ab: Extrahieren Sie jene,
> die zur verwendeten App und zu Ihrem Land passen. Sie laufen nicht ab.

### Empfohlene Methode: direkte Extraktion (ohne Kontoanmeldung)

Diese Methode ruft **nur** die Client ID und das Client Secret ab; die Verbindung mit Ihrem Konto
erfolgt anschließend in Jeedom (nächster Abschnitt), eine Anmeldung ist hier also nicht nötig. Auf
einem Rechner mit **Python 3.11 oder neuer** (Ihr PC oder Ihr Jeedom, sofern dessen Python-Version
passt):

```bash
# 1. Extraktionswerkzeug installieren (bringt auch seine Abhängigkeit „androguard" mit)
pip3 install psa-car-controller

# 2. APK IHRER Marke herunterladen und entpacken
#    (Beispiel Peugeot; ersetzen durch mycitroen / myds / myopel / myvauxhall)
curl -L -o app.apk.bz2 https://github.com/flobz/psa_apk/raw/main/mypeugeot.apk.bz2
bunzip2 app.apk.bz2      # erzeugt die Datei app.apk

# 3. Anmeldedaten extrahieren (FR durch den Ländercode IHRES Kontos ersetzen)
python3 - <<'PY'
from psa_car_controller.psa.setup.apk_parser import ApkParser
p = ApkParser("app.apk", "FR")
p.retrieve_content_from_apk()
print("Client ID     =", p.client_id)
print("Client Secret =", p.client_secret)
PY
```

Übertragen Sie die beiden angezeigten Werte in die Plugin-Konfiguration und wählen Sie die Marke, die
zur verwendeten APK passt.

APK je Marke (Repository [flobz/psa_apk](https://github.com/flobz/psa_apk), das bekanntermaßen
funktionierende Versionen archiviert):

| Marke | Herunterzuladende Datei |
|---|---|
| Peugeot | `mypeugeot.apk.bz2` |
| Citroën | `mycitroen.apk.bz2` |
| DS | `myds.apk.bz2` |
| Opel | `myopel.apk.bz2` |
| Vauxhall | `myvauxhall.apk.bz2` |

### Alternative Methode: grafischer Assistent von psa_car_controller

Wenn Sie eine Weboberfläche der Befehlszeile vorziehen, lädt der Assistent von psa_car_controller die
APK herunter und extrahiert die Anmeldedaten automatisch — er zwingt Sie jedoch, **eine vollständige
OAuth-Anmeldung** zu durchlaufen (dieselbe wie im Abschnitt „Kontoverbindung" unten), bevor er die
Werte auf die Festplatte schreibt:

1. `pip3 install psa-car-controller`, dann `psa-car-controller -l 0.0.0.0 --web-conf` ausführen.
2. Öffnen Sie `http://<Adresse-des-Rechners>:5000` und geben Sie Marke, E-Mail, Kontopasswort und
   Ländercode ein.
3. Schließen Sie die Verbindungsprozedur ab (sie nutzt dieselbe Code-Abholung per F12 wie unten beschrieben).
4. Öffnen Sie die im Arbeitsverzeichnis erzeugte Datei `config.json`: Übertragen Sie deren Werte
   `client_id` und `client_secret` in das Plugin.

> Dieser Assistent installiert und betreibt ein zweites Werkzeug (und lässt Sie sich zweimal anmelden,
> einmal hier und einmal in Jeedom). Das Plugin **hängt anschließend nicht davon ab**, weshalb die
> direkte Methode oben vorzuziehen ist.

## Kontoverbindung

Sobald die Konfiguration gespeichert ist, verbinden Sie das Plugin mit Ihrem Konto (Abschnitt
„Kontoverbindung" der Konfigurationsseite). Diese Verbindung erfolgt am besten von einem **Computer
mit einem Browser, der über Entwicklertools verfügt**:

1. Klicken Sie auf **Autorisierungs-URL generieren** und öffnen Sie dann den angezeigten Link in Ihrem
   Browser.
2. Melden Sie sich mit den Anmeldedaten der mobilen App Ihrer Marke an (E-Mail + Passwort).
   > ⚠️ PSA-Konten begrenzen das **Passwort auf 16 Zeichen**: Ist Ihres länger, kann die Anmeldung auf
   > der Website der Marke fehlschlagen.
3. Nach der Anmeldung versucht der Browser, die mobile App zu öffnen (Adresse beginnend mit
   `mymap://…`, `mymacsdk://…` je nach Marke), und zeigt eine **Fehlerseite an: das ist normal**, der
   Browser kann diese Art von Adresse nicht öffnen.
   - **Einfacher Fall**: Die Adressleiste enthält die vollständige URL `…://oauth2redirect/…?code=…`.
     Kopieren Sie sie **vollständig**.
   - **Wenn die Adressleiste nichts Verwertbares zeigt**: Öffnen Sie die Entwicklertools (**F12**) →
     Tab **Netzwerk (Network)**, und lösen Sie dann die Weiterleitung aus. Suchen Sie die Zeile, deren
     Adresse mit dem Schema Ihrer Marke beginnt (`mymap://…`), und kopieren Sie den Wert des Parameters
     **`code`** (eine Zeichenfolge mit **36 Zeichen**).
4. Fügen Sie die vollständige URL (oder ersatzweise nur den `code`) in das Feld **Autorisierungscode**
   ein und klicken Sie **ohne Verzögerung** auf **Code bestätigen**: Der Code ist nur wenige Augenblicke
   gültig und nur einmal verwendbar.
   > Erscheint eine Meldung „Code ungültig, abgelaufen oder bereits verwendet" bzw.
   > „Re-Authentifizierung erforderlich", generieren Sie die URL neu (Schritt 1) und fügen Sie die neue
   > URL schnell ein. Eine Meldung mit dem Begriff *Realm* bedeutet, dass die **gewählte Marke nicht zum
   > Konto passt**.

Der Status wechselt zu „Mit Konto verbunden". Sie können die korrekte Funktion jederzeit über die
Schaltfläche **Verbindung testen** auf der Plugin-Seite (`Plugins → Vernetzte Objekte → Stellantis
Connect`) überprüfen, die die Anzahl der im Konto gefundenen Fahrzeuge anzeigt. Das Plugin verwaltet
anschließend selbstständig die Erneuerung des Zugriffstokens; Sie müssen dieses Vorgehen nur
wiederholen, wenn die Verbindung widerrufen wird (Meldung „Re-Authentifizierung erforderlich"),
nach einem Wechsel von Marke oder Anmeldedaten oder nach einer vollständigen Leerung des
Jeedom-Caches.

## Nächste Schritte

Die Fahrzeugerkennung und der Abruf der Telemetriedaten werden in den entsprechenden Abschnitten
dieser Dokumentation im Laufe der Plugin-Versionen beschrieben.
