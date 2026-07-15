# Stellantis Connect plugin

This plugin connects your **Stellantis / former PSA Group** vehicles (Peugeot, Citroën, DS, Opel, Vauxhall)
to Jeedom: retrieving telemetry (battery, charging, range, fuel, GPS position,
mileage…) via the "connected car" API used by the official mobile applications
(MyPeugeot, MyCitroën, MyDS, MyOpel, MyVauxhall).

> ⚠️ The plugin uses Stellantis' **consumer** API, the same one used by the mobile applications.
> Stellantis does not provide developer access to individuals: you must retrieve the
> credentials of your brand's mobile application yourself (see "Getting the credentials" below).

> Brand names and colors (Peugeot, Citroën, DS, Opel, Vauxhall) are mentioned for identification
> purposes only; this plugin is neither affiliated with nor endorsed by the manufacturers.

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

The credentials are **not** distributed by Stellantis: they are embedded in each brand's mobile
application APK (inside an internal `parameters.json` file, under the keys `cvsClientId` and
`cvsSecret`). You therefore need to extract them **once**, on a computer — the plugin itself never
downloads or analyzes any APK.

> The credentials depend on the **brand** and the **country** of your account: extract the ones
> matching the application you use and your country. They do not expire.

### Recommended method: direct extraction (no account login)

This method retrieves **only** the Client ID and Client Secret; the connection to your account then
happens inside Jeedom (next section), so there is no need to log in here. On a machine with
**Python 3.11 or newer** (your PC, or your Jeedom if its Python version is suitable):

```bash
# 1. Install the extraction tool (it also brings its "androguard" dependency)
pip3 install psa-car-controller

# 2. Download and decompress the APK for YOUR brand
#    (Peugeot example; replace with mycitroen / myds / myopel / myvauxhall)
curl -L -o app.apk.bz2 https://github.com/flobz/psa_apk/raw/main/mypeugeot.apk.bz2
bunzip2 app.apk.bz2      # produces the file app.apk

# 3. Extract the credentials (replace FR with YOUR account's country code)
python3 - <<'PY'
from psa_car_controller.psa.setup.apk_parser import ApkParser
p = ApkParser("app.apk", "FR")
p.retrieve_content_from_apk()
print("Client ID     =", p.client_id)
print("Client Secret =", p.client_secret)
PY
```

Copy the two displayed values into the plugin configuration, and select the brand matching the APK used.

APK per brand (repository [flobz/psa_apk](https://github.com/flobz/psa_apk), which archives versions
known to work):

| Brand | File to download |
|---|---|
| Peugeot | `mypeugeot.apk.bz2` |
| Citroën | `mycitroen.apk.bz2` |
| DS | `myds.apk.bz2` |
| Opel | `myopel.apk.bz2` |
| Vauxhall | `myvauxhall.apk.bz2` |

### Alternative method: psa_car_controller graphical wizard

If you prefer a web interface over the command line, the psa_car_controller wizard downloads the APK
and extracts the credentials automatically — but it also makes you **go all the way through an OAuth
login** (the same as the "Connecting your account" step below) before it writes the values to disk:

1. `pip3 install psa-car-controller`, then run `psa-car-controller -l 0.0.0.0 --web-conf`.
2. Open `http://<machine-address>:5000` and fill in the brand, email, account password and country code.
3. Complete the connection procedure (it uses the same F12 code retrieval described below).
4. Open the `config.json` file created in the working directory: copy its `client_id` and
   `client_secret` values into the plugin.

> This wizard installs and runs a second tool (and makes you log in twice, once here and once in
> Jeedom). The plugin **does not depend on it** afterwards, which is why the direct method above is
> preferable.

## Connecting your account

Once the configuration is saved, connect the plugin to your account (the "Account connection"
section of the configuration page). This connection is best done from a **computer with a browser
that has developer tools**:

1. Click **Generate authorization URL**, then open the displayed link in your browser.
2. Log in with the credentials of your brand's mobile application (email + password).
   > ⚠️ PSA accounts limit the **password to 16 characters**: if yours is longer, the login may fail
   > on the brand's website.
3. After logging in, the browser tries to open the mobile application (address starting with
   `mymap://…`, `mymacsdk://…` depending on the brand) and displays an **error page: this is normal**,
   the browser cannot open this kind of address.
   - **Simple case**: the address bar contains the full URL `…://oauth2redirect/…?code=…`. Copy it
     **entirely**.
   - **If the address bar shows nothing usable**: open the developer tools (**F12**) → **Network**
     tab, then trigger the redirection. Find the line whose address starts with your brand's scheme
     (`mymap://…`) and copy the value of the **`code`** parameter (a string of **36 characters**).
4. Paste the full URL (or, failing that, the `code` alone) into the **Authorization code** field, then
   click **Validate code** **without delay**: the code is valid for only a few moments and is
   single-use.
   > If a "code invalid, expired or already used" or "re-authentication required" message appears,
   > regenerate the URL (step 1) and paste the new URL quickly. A message mentioning the *realm* means
   > the **selected brand does not match the account**.

The status changes to "Connected to account". You can check that it works at any time using the
**Test connection** button on the plugin page (`Plugins → Connected devices → Stellantis
Connect`), which displays the number of vehicles found on the account. The plugin then manages the
access token refresh on its own; you will only need to repeat this procedure if the connection is
revoked (message "re-authentication required"), after a change of brand or credentials, or after a
full Jeedom cache clear.

## Next steps

Vehicle discovery and telemetry retrieval are described in the corresponding sections of this
documentation as the plugin versions are released.
