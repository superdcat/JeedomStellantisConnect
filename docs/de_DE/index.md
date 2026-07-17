# Plugin Stellantis Connect

Dieses Plugin verbindet Ihre Fahrzeuge von **Stellantis / ehemaliger PSA-Konzern** (Peugeot, Citroën, DS, Opel, Vauxhall)
mit Jeedom: Abruf der Telemetriedaten (Batterie, Ladevorgang, Reichweite, Kraftstoff, GPS-Position,
Kilometerstand, Türen/Öffnungen, Reifendruck, Wartung …) und, sobald die Fernsteuerung aktiviert ist,
**Fernbefehle** (Aufwecken, Laden, Vorklimatisierung, Verriegeln, Hupen, Licht) — über die "Connected
Car"-API, die von den offiziellen mobilen Anwendungen verwendet wird (MyPeugeot, MyCitroën, MyDS,
MyOpel, MyVauxhall).

> Markennamen und -farben (Peugeot, Citroën, DS, Opel, Vauxhall) werden ausschließlich zu
> Identifikationszwecken genannt; dieses Plugin steht in keiner Verbindung zu den Herstellern und
> wird nicht von ihnen unterstützt.

## Warnung — Inoffizielle API & Risiken (AGB)

> ⚠️ **Vor jeder Nutzung lesen.**

Dieses Plugin basiert auf der **Verbraucher-API** von Stellantis — derselben, die von den mobilen
Anwendungen verwendet wird — und nicht auf einer offiziellen Entwickler-API: Stellantis stellt
Privatpersonen keine solche zur Verfügung. Diese API wurde von der Community
**zurückentwickelt (reverse-engineered)** (insbesondere durch das Projekt
[`psa_car_controller`](https://github.com/flobz/psa_car_controller), von dem dieses Plugin einige
Elemente unter der GPL-3-Lizenz wiederverwendet und dessen beobachtetes Verhalten als Referenz dient).

Folgen, die Sie kennen sollten, bevor Sie das Plugin aktivieren:

- Sie kann infolge einer von Stellantis beschlossenen Änderung **ganz oder teilweise ohne
  Vorankündigung aufhören zu funktionieren** (keine Garantie für Kontinuität oder eine Frist zur
  Behebung).
- Ihre Nutzung erfolgt **auf eigenes Risiko des Nutzers**, einschließlich **rechtlicher und
  vertraglicher** Risiken: Es liegt an Ihnen zu prüfen, ob diese Nutzung mit den
  Nutzungsbedingungen (AGB) Ihres Markenkontos vereinbar ist.
- Das Plugin wird **ohne jegliche Garantie** bereitgestellt, gemäß der **GPL-3**-Lizenz, unter der
  es steht.
- Die Extraktion Ihrer Zugangsdaten (Client-ID / Client-Secret) — ob automatisch oder manuell —
  erfolgt in Ihrer alleinigen Verantwortung.

## Plugin-Konfiguration

Gehen Sie zu `Plugins → Plugin-Verwaltung → Stellantis Connect → Konfiguration`. Die Einstellungen im
Fieldset „Hauptkonto (Fernsteuerung)" gelten für alle Fahrzeuge dieses Kontos:

| Feld | Beschreibung |
|---|---|
| **Marke** | Die Marke Ihrer Fahrzeuge (Peugeot, Citroën, DS, Opel oder Vauxhall). Sie bestimmt den verwendeten Authentifizierungsserver und die Domain — wählen Sie die Marke, die zur mobilen App passt, aus der Ihre Anmeldedaten stammen. |
| **Client-ID** | OAuth2-Kennung der mobilen App. Wird automatisch durch **Automatisch extrahieren** ausgefüllt oder manuell eingegeben (siehe „Anmeldedaten beschaffen" unten). |
| **Client-Secret** | Zugehöriges OAuth2-Secret, auf dieselbe Weise beschafft. Es wird in Jeedom **verschlüsselt gespeichert** und erscheint niemals in den Logs. |
| **Land** | Zweistelliger Ländercode (z. B. `fr`), der zur Erstellung der Standard-Weiterleitungs-URL und für die automatische Extraktion verwendet wird. |
| **Weiterleitungs-URL** | OAuth2 `redirect_uri` der mobilen App (z. B. `mymap://oauth2redirect/fr`). Leer lassen, um den Standardwert der Marke zu verwenden. |

Solange Client-ID und Client-Secret nicht ausgefüllt sind, zeigt die Seite ein Banner
„Plugin nicht konfiguriert" an, und die übrigen Funktionen des Plugins bleiben inaktiv.

## Anmeldedaten beschaffen (Client ID / Client Secret)

Die Anmeldedaten werden **nicht** von Stellantis herausgegeben: Sie sind in der APK der mobilen App
jeder Marke eingebettet (in einer internen Datei `parameters.json`, unter den Schlüsseln `cvsClientId`
und `cvsSecret`) und hängen von der **Marke** und dem **Land** Ihres Kontos ab. Zwei Methoden
ermöglichen es, sie zu beschaffen; sie sind vollständig unabhängig voneinander.

### Methode 1 (empfohlen, mit einem Klick): automatische Extraktion in Jeedom

Das Plugin kann die mobile App Ihrer Marke selbst herunterladen und daraus die Anmeldedaten
extrahieren, ohne dass ein externes Tool installiert werden muss:

1. Wählen Sie in der Plugin-Konfiguration die **Marke** aus und geben Sie das **Land** ein (z. B. `fr`).
2. Klicken Sie auf die Schaltfläche **Automatisch extrahieren**.
3. Bestätigen Sie die angezeigte Warnung („Diese API ist nicht offiziell. Fortfahren?"): Der
   Download der Anwendung (~100 MB) beginnt, gehostet auf einem Community-Repository eines
   Drittanbieters.
4. Warten Sie, bis der Download und die Extraktion abgeschlossen sind. Bei Erfolg werden die Felder
   **Client-ID** und **Client-Secret** automatisch ausgefüllt.

> ℹ️ **Wo dies ausgeführt wird und wann die andere Methode vorzuziehen ist.** Diese Extraktion findet
> **direkt auf der Jeedom-Box** statt: Sie nutzt den bereits für den Fernsteuerungs-Daemon
> installierten **Python-3**-Interpreter und lädt das Archiv der mobilen App (**~100 MB**) direkt auf
> die Box herunter. Bei einem **Raspberry Pi mit SD-Karte** (bei dem Speicherplatz und Schreibzugriffe
> geschont werden sollten) — oder **bei einem Fehlschlag** — bevorzugen Sie die untenstehende
> **Methode 2**, die auf einem Computer durchzuführen ist.

Das erweiterte Feld **URL der mobilen App (erweitert)** (`apk_url`) ermöglicht die Angabe einer
anderen `.apk.bz2`-Archiv-URL, falls das Standard-Community-Repository nicht verfügbar ist oder
verschoben wurde; lassen Sie es im Normalfall leer.

### Methode 2 (Rückfalloption): manuelle Extraktion auf einem Computer

Diese Methode ruft **ausschließlich** die Client-ID und das Client-Secret ab, auf einem Rechner Ihrer
Wahl mit **Python 3.11 oder neuer** (typischerweise Ihr PC); die Verbindung mit Ihrem Konto erfolgt
anschließend in Jeedom (Abschnitt „Kontoverbindung" weiter unten), eine Anmeldung ist hier also nicht
nötig:

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

Wenn Sie eine Weboberfläche der Befehlszeile vorziehen, öffnet `pip3 install psa-car-controller` und
anschließend `psa-car-controller -l 0.0.0.0 --web-conf` einen Assistenten
(`http://<Adresse-des-Rechners>:5000`), der die APK herunterlädt und die Anmeldedaten automatisch
extrahiert — er zwingt Sie jedoch, eine vollständige **OAuth-Anmeldung** zu durchlaufen (dieselbe wie
im Abschnitt „Kontoverbindung" unten), bevor er eine Datei `config.json` schreibt, aus der Sie die
Werte `client_id`/`client_secret` übertragen müssten. Dieser Assistent installiert und betreibt ein
zweites Werkzeug und lässt Sie sich zweimal anmelden (einmal dort, einmal in Jeedom): Die obige
Befehlszeile ist daher im Normalfall vorzuziehen.

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
   > Erscheint eine Meldung über einen **abgelehnten Code (ungültig, abgelaufen oder bereits
   > verwendet)** oder dass eine **neue Verbindung erforderlich** ist, generieren Sie die URL neu
   > (Schritt 1) und fügen Sie die neue URL schnell ein. Eine Meldung mit dem Begriff *Realm* bedeutet,
   > dass die **gewählte Marke nicht zum Konto passt**.

Der Status wechselt zu „Mit Konto verbunden". Sie können die korrekte Funktion jederzeit über die
Schaltfläche **Verbindung testen** auf der Plugin-Seite (`Plugins → Vernetzte Objekte → Stellantis
Connect`) überprüfen, die die Anzahl der im Konto gefundenen Fahrzeuge anzeigt. Das Plugin verwaltet
anschließend selbstständig die Erneuerung des Zugriffstokens; Sie müssen dieses Vorgehen nur
wiederholen, wenn die Verbindung widerrufen wird (eine Meldung zeigt dann an, dass eine erneute
Verbindung erforderlich ist, auf der Plugin-Seite und in den Jeedom-Meldungen), nach einem Wechsel von
Marke oder Anmeldedaten oder nach einer vollständigen Leerung des Jeedom-Caches.

## Fernsteuerung — OTP-Aktivierung

Die einfache Verbindung Ihres Kontos (vorheriger Abschnitt) genügt nur für das **Lesen** der
Telemetriedaten. Um die **Fernbefehle** freizuschalten (Aufwecken, Starten/Stoppen des Ladevorgangs,
Ladeprogrammierung, Vorklimatisierung, Ver-/Entriegeln, Hupen, Licht), ist eine zusätzliche
Aktivierung erforderlich: Sie erfolgt im Fieldset **„Fernsteuerung (OTP-Aktivierung)"** der
Konfigurationsseite.

> **Voraussetzung**: Das Hauptkonto muss bereits **verbunden** sein (Abschnitt „Kontoverbindung"
> oben) — die diesem Konto zugeordnete Telefonnummer wird verwendet, um die Aktivierungs-SMS zu
> empfangen.

Verfahren in 3 Schritten:

1. Klicken Sie auf **Aktivierungs-SMS senden** („1. SMS empfangen") und bestätigen Sie: Eine SMS mit
   einem Code wird an die mit Ihrem Markenkonto verknüpfte Nummer gesendet.
2. Geben Sie diesen Code in das Feld **Per SMS erhaltener Code** ein („2. Per SMS erhaltener Code").
3. Geben Sie Ihren **PIN-Code der App** ein („3. PIN-Code der App" — der 4-stellige Code, den Sie in
   der mobilen App Ihrer Marke verwenden), und klicken Sie dann auf **Fernsteuerung aktivieren**.

Der angezeigte Status wechselt zu „Aktiviert".

> ⚠️ **Feste und endgültige Kontingente auf Stellantis-Seite**: **6 Codes pro 24 Std.** und **20
> SMS-Aktivierungen pro Konto, auf Lebenszeit** — diese Zähler werden **nie zurückgesetzt**. Nutzen
> Sie diese Aktivierung erst, wenn Sie bereit sind, sie bis zum Ende durchzuführen, und vermeiden Sie
> unnötige Wiederholungen.

Das Remote-Token hat eine sehr kurze technische Lebensdauer (**~15 Minuten**). Das Plugin **erneuert
es automatisch und unbemerkt bei jedem Cron-Durchlauf**, durch eine einfache Aktualisierung — **ohne
OTP-Code oder SMS** — solange diese Erneuerungskette funktioniert: Normalerweise müssen Sie
**niemals selbst eingreifen**.

Wenn diese automatische Erneuerung **dauerhaft fehlschlägt** (ungültiges oder widerrufenes
Remote-Refresh-Token), wechselt der Status zu „Abgelaufen — Erneuerung erforderlich". Nur in diesem
Fall klicken Sie auf die Schaltfläche **Remote-Token erneuern**: Sie verwendet das bereits
registrierte OTP-Gerät erneut, **ohne neue SMS**, generiert jedoch einen neuen OTP-Code und
**verbraucht somit 1 Einheit des oben genannten strengen Kontingents von 6 Codes / 24 Std.** —
verwenden Sie diese Schaltfläche nur, wenn der Status dies tatsächlich anzeigt. Führen Sie die
vollständige 3-Schritte-Aktivierung nur erneut durch, wenn auch diese Erneuerung fehlschlägt.

> Die Fernsteuerung (OTP, Befehle) ist nur beim **Hauptkonto** (dem zuerst konfigurierten Konto)
> verfügbar — Zweitkonten (nächster Abschnitt) bleiben schreibgeschützt.

## Zweitkonten (Mehrmarken, nur Lesezugriff)

Sie können bis zu zwei zusätzliche Konten/Marken verknüpfen (einklappbare Abschnitte „Zweitkonto 2"/
„Zweitkonto 3", sichtbar sobald das Hauptkonto konfiguriert ist): Es gilt dasselbe Verfahren zur
Beschaffung der Anmeldedaten und zur Verbindung wie oben, doch diese Konten bleiben **schreibgeschützt**
(nur Telemetrie) — keine OTP-Aktivierung oder Fernbefehl ist dort verfügbar.

## Verfügbare Funktionen

- **Telemetrie**: Batterie/Ladezustand, Reichweite (elektrisch, Kraftstoff, gesamt), GPS-Position,
  Kilometerstand, Status der Türen/Öffnungen (Türen, Kofferraum, Motorhaube…), Reifendruck (Warnung),
  Wartung (Inspektionstermin).
- **Fernbefehle** (Hauptkonto, nach OTP-Aktivierung): Aufwecken, Starten/Stoppen des Ladevorgangs und
  Zeitplanprogrammierung, Klima-Vorklimatisierung, Ver-/Entriegeln, Hupen, Licht.
- **Kartenpanel „Meine Fahrzeuge"**: Überblick über die Position Ihrer Fahrzeuge, zugänglich über das
  Startmenü von Jeedom.
- **Geofencing / Zuhause-Zone**: Erkennung „zu Hause" / Entfernung zum Zuhause, basierend auf einer
  einzigen, für den Haushalt konfigurierten Zuhause-Zone.
- **Fahrzeugwarnungen**: generische Meldung von Herstellerwarnungen (Reifen, AdBlue, Scheibenwaschwasser,
  Kontrollleuchten…) als in Szenarien nutzbare Befehle.
- **Ladestatistiken**: Erkennung von Ladevorgängen, geschätzte Energie/Dauer/Kosten.

## Grenzen & bewährte Praktiken

- **Datenaktualität**: Die Telemetriedaten werden durch **periodische Abfrage** (standardmäßig
  ~5 Minuten) abgerufen — die Stellantis-API bietet keine Echtzeitbenachrichtigung („Push"). Die
  angezeigten Informationen können daher einige Minuten alt sein.
- **12-V-Batterie**: Das Aufwecken eines Fahrzeugs (manuell oder über das standardmäßig deaktivierte
  adaptive automatische Aufwecken) beansprucht die 12-V-Bordbatterie. Zu häufiges Aufwecken kann sie
  schwächen; belassen Sie die Standardhäufigkeit, sofern kein echter Bedarf besteht.
- **Schutz vor Sperrung**: Das Plugin wendet bewusst Kontingente und Verzögerungen (Cooldowns) auf
  API-Aufrufe und Befehle an, um das Risiko einer vorübergehenden Kontosperrung auf Stellantis-Seite
  zu begrenzen. Versuchen Sie nicht, wiederholte Aktualisierungen über das von der Oberfläche
  angebotene Maß hinaus zu erzwingen.
- **Privatsphäre-Modus**: Wenn die Daten-/Standortfreigabe fahrzeugseitig deaktiviert ist
  (Datenschutzeinstellung der mobilen App), schaltet das Plugin für dieses Fahrzeug automatisch in
  einen Modus mit selteneren Abfragen und meldet die Situation — **dies ist keine Fehlfunktion des
  Plugins**.
- **Fernsteuerung nur für ein Konto**: Fernbefehle funktionieren nur beim Hauptkonto; Zweitkonten
  bleiben schreibgeschützt (siehe oben).
- **Inoffizielle API**: Wie oben erwähnt, kann diese Integration bei einer Änderung auf
  Stellantis-Seite ohne Vorankündigung aufhören zu funktionieren.
