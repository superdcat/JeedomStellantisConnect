# Stellantis Connect plugin

This plugin connects your **Stellantis / former PSA Group** vehicles (Peugeot, Citroën, DS, Opel, Vauxhall)
to Jeedom: retrieving telemetry (battery, charging, range, fuel, GPS position,
mileage…) via the "connected car" API used by the official mobile applications
(MyPeugeot, MyCitroën, MyDS, MyOpel, MyVauxhall).

> ⚠️ The plugin uses Stellantis' **consumer** API, the same one used by the mobile applications.
> Stellantis does not provide developer access to individuals: you must retrieve the
> credentials of your brand's mobile application yourself (see "Getting the credentials" below).

## Plugin configuration

Go to `Plugins → Plugin management → Stellantis Connect → Configuration`. The settings
are shared by all your vehicles of the same brand:

| Field | Description |
|---|---|
| **Brand** | The brand of your vehicles (Peugeot, Citroën, DS, Opel or Vauxhall). It determines the authentication server and domain used — choose the brand matching the mobile application your credentials come from. |
| **Client ID** | OAuth2 identifier of the mobile application, extracted from the APK (see below). |
| **Client Secret** | Associated OAuth2 secret, extracted from the APK. It is **stored encrypted** in Jeedom and never appears in the logs. |
| **Country** | 2-letter country code (e.g. `fr`), used to build the default redirect URL. |
| **Redirect URL** | OAuth2 `redirect_uri` of the mobile application (e.g. `mymap://oauth2redirect/fr`). Leave empty to use the brand's default value. If your extraction tool gave you a value, use it. |

As long as the Client ID and Client Secret are not filled in, the page displays a
"Plugin not configured" banner and the other plugin functions remain inactive.

## Getting the credentials (Client ID / Client Secret)

The credentials are **not** distributed by Stellantis: they are embedded in each brand's
mobile application. The proven method, from the open source project
[psa_car_controller](https://github.com/flobz/psa_car_controller), consists in extracting them from the APK:

1. Download the mobile application APK for **your brand** (for example from the repository
   [flobz/psa_apk](https://github.com/flobz/psa_apk), which archives compatible versions).
2. Run the `app_decoder.py` script provided by psa_car_controller on this APK:
   ```
   python3 app_decoder.py <file.apk>
   ```
3. The script displays, among other things, the application's `client_id` and `client_secret`. Enter them
   as-is in the plugin configuration, and select the brand matching the APK used.

This extraction happens **outside of Jeedom** (on your computer); the plugin does not download or
analyze any APK. The credentials do not expire, this operation only needs to be done once.

## Connecting your account

Once the configuration is saved, connect the plugin to your account (the "Account connection"
section of the configuration page):

1. Click **Generate authorization URL**, then open the displayed link in your browser.
2. Log in with the credentials of your brand's mobile application (email + password).
3. After logging in, the browser tries to open the mobile application and displays an **error
   page: this is normal**. Copy the **full URL** of this page (address bar), which starts with the
   application's scheme (e.g. `mymap://oauth2redirect/fr?code=...`).
4. Paste this URL into the **Authorization code** field and click **Validate code**.

The status changes to "Connected to account". You can check that it works at any time using the
**Test connection** button on the plugin page (`Plugins → Connected devices → Stellantis
Connect`), which displays the number of vehicles found on the account. The plugin then manages the
access token refresh on its own; you will only need to repeat this procedure if the connection is
revoked (message "re-authentication required"), after a change of brand or credentials, or after a
full Jeedom cache clear.

## Next steps

Vehicle discovery and telemetry retrieval are described in the corresponding sections of this
documentation as the plugin versions are released.
