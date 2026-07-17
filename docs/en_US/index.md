# Stellantis Connect plugin

This plugin connects your **Stellantis / former PSA Group** vehicles (Peugeot, Citroën, DS, Opel,
Vauxhall) to Jeedom: telemetry retrieval (battery, charging, range, fuel, GPS position, mileage,
doors/openings, tyre pressure, servicing…) and, once remote control is activated, **remote commands**
(wake-up, charging, preconditioning, locking, horn, lights) — via the "connected car" API used by the
official mobile applications (MyPeugeot, MyCitroën, MyDS, MyOpel, MyVauxhall).

> Brand names and colors (Peugeot, Citroën, DS, Opel, Vauxhall) are mentioned for identification
> purposes only; this plugin is neither affiliated with nor endorsed by the manufacturers.

## Warning — Unofficial API & risks (ToS)

> ⚠️ **Read this before any use.**

This plugin relies on Stellantis' **consumer** API — the same one used by the mobile applications —
rather than an official developer API: Stellantis does not provide one to individuals. This API has
been **reverse-engineered** by the community (notably the
[`psa_car_controller`](https://github.com/flobz/psa_car_controller) project, from which this plugin
reuses some elements under the GPL-3 license, and whose observed behavior serves as a reference).

Consequences to be aware of before activating the plugin:

- It may **stop working without notice**, in whole or in part, following a change decided by
  Stellantis (no guarantee of continuity or of a fix timeline).
- Its use is **at the user's own risk**, including **legal and contractual** risks: it is up to you
  to verify that this use remains compatible with the terms of service (ToS) of your brand account.
- The plugin is provided **with no warranty whatsoever**, in accordance with the **GPL-3** license
  that governs it.
- Extracting your credentials (Client ID / Client Secret) — whether automatically or manually — is
  done at your own sole responsibility.

## Plugin configuration

Go to `Plugins → Plugin management → Stellantis Connect → Configuration`. The settings in the
"Primary account (remote control)" fieldset are shared by all vehicles on this account:

| Field | Description |
|---|---|
| **Brand** | The brand of your vehicles (Peugeot, Citroën, DS, Opel or Vauxhall). It determines the authentication server and domain used — choose the brand matching the mobile application your credentials come from. |
| **Client ID** | OAuth2 identifier of the mobile application. Filled in automatically by **Extract automatically**, or entered manually (see "Getting the credentials" below). |
| **Client Secret** | Associated OAuth2 secret, obtained the same way. It is **stored encrypted** in Jeedom and never appears in the logs. |
| **Country** | 2-letter country code (e.g. `fr`), used to build the default redirect URL and for automatic extraction. |
| **Redirect URL** | OAuth2 `redirect_uri` of the mobile application (e.g. `mymap://oauth2redirect/fr`). Leave empty to use the brand's default value. |

As long as the Client ID and Client Secret are not filled in, the page displays a "Plugin not
configured" banner and the other plugin functions remain inactive.

## Getting the credentials (Client ID / Client Secret)

The credentials are **not** distributed by Stellantis: they are embedded in each brand's mobile
application APK (inside an internal `parameters.json` file, under the keys `cvsClientId` and
`cvsSecret`), and depend on the **brand** and **country** of your account. Two methods let you
retrieve them; they are entirely independent of each other.

### Method 1 (recommended, one click): automatic extraction in Jeedom

The plugin can download your brand's mobile application and extract the credentials itself, with no
external tool to install:

1. In the plugin configuration, select the **Brand** and enter the **Country** (e.g. `fr`).
2. Click the **Extract automatically** button.
3. Confirm the warning shown ("This API is not official. Continue?"): the download of the
   application (~100 MB) starts, hosted on a third-party community repository.
4. Wait for the download and extraction to complete. On success, the **Client ID** and
   **Client Secret** fields are filled in automatically.

> ℹ️ **Where this runs, and when to prefer the other method.** This extraction happens **on the
> Jeedom box itself**: it reuses the **Python 3** interpreter already installed for the remote
> control daemon, and downloads the mobile application archive (**~100 MB**) directly onto the box.
> On a **Raspberry Pi with an SD card** (where it is better to spare storage space and write cycles)
> — or **if it fails** — prefer **Method 2** below, carried out on a computer.

The advanced field **Mobile app URL (advanced)** (`apk_url`) lets you specify a different
`.apk.bz2` archive URL if the default community repository is unavailable or has moved; leave it
empty in the general case.

### Method 2 (fallback): manual extraction on a computer

This method retrieves **only** the Client ID and Client Secret, on a machine of your choice with
**Python 3.11 or newer** (typically your PC); the connection to your account will then happen in
Jeedom (the "Connecting your account" section below), so there is no need to log in here:

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

Copy the two displayed values into the plugin configuration, and select the brand matching the APK
used.

APK per brand (repository [flobz/psa_apk](https://github.com/flobz/psa_apk), which archives versions
known to work):

| Brand | File to download |
|---|---|
| Peugeot | `mypeugeot.apk.bz2` |
| Citroën | `mycitroen.apk.bz2` |
| DS | `myds.apk.bz2` |
| Opel | `myopel.apk.bz2` |
| Vauxhall | `myvauxhall.apk.bz2` |

> **Alternative: `psa_car_controller` graphical wizard.** If you prefer a web interface over the
> command line, `pip3 install psa-car-controller` then `psa-car-controller -l 0.0.0.0 --web-conf`
> opens a wizard (`http://<machine-address>:5000`) that downloads the APK and extracts the
> credentials automatically — but it makes you go all the way through a full **OAuth login** (the
> same procedure as "Connecting your account" below) before writing a `config.json` file whose
> `client_id`/`client_secret` values you would then copy. This wizard installs and runs a second
> tool, and makes you log in twice (once there, once in Jeedom): the command line above is therefore
> preferable in the general case.

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
   > If a message reports a **rejected code (invalid, expired or already used)** or that a **new
   > connection is required**, regenerate the URL (step 1) and paste the new URL quickly. A message
   > mentioning the *realm* means the **selected brand does not match the account**.

The status changes to "Connected to account". You can check that it works at any time using the
**Test connection** button on the plugin page (`Plugins → Connected devices → Stellantis
Connect`), which displays the number of vehicles found on the account. The plugin then manages the
access token refresh on its own; you will only need to repeat this procedure if the connection is
revoked (a message then reports that a reconnection is required, on the plugin page and in Jeedom
messages), after a change of brand or credentials, or after a full Jeedom cache clear.

## Remote control — OTP activation

Simply connecting your account (previous section) is only enough for **reading** telemetry. To
unlock **remote commands** (wake-up, starting/stopping charging, charge scheduling,
preconditioning, locking/unlocking, horn, lights), an additional activation is required: it is done
in the **"Remote control (OTP activation)"** fieldset of the configuration page.

> **Prerequisite**: the primary account must already be **connected** (the "Account connection"
> section above) — the phone number associated with this account is used to receive the activation
> SMS.

3-step procedure:

1. Click **Send activation SMS** ("1. Receive the SMS") and confirm: an SMS containing a code is
   sent to the phone number associated with your brand account.
2. Enter this code in the **Code received by SMS** field ("2. Code received by SMS").
3. Enter your **App PIN code** ("3. App PIN code" — the 4-digit code you use in your brand's mobile
   application), then click **Activate remote control**.

The displayed status changes to "Activated".

> ⚠️ **Hard, permanent quotas on Stellantis' side**: **6 codes per 24 h** and **20 SMS activations
> per account, for life** — these counters are **never reset to zero**. Only use this activation
> once you are ready to go through with it, and avoid repeating it without reason.

The remote token has a very short technical lifetime (**~15 minutes**). The plugin **renews it
automatically and silently on every cron pass**, through a simple refresh — **without any OTP code
or SMS** — as long as this renewal chain keeps working: under normal circumstances you should
**never have to intervene yourself**.

If this automatic renewal **fails persistently** (invalid or revoked remote refresh token), the
status changes to "Expired — renewal required". Only in this case, click the **Renew the remote
token** button: it reuses the OTP device already registered, **without a new SMS**, but generates a
new OTP code and therefore **consumes 1 unit of the strict 6 codes / 24 h quota** mentioned above —
only use this button when the status actually indicates it. Only redo the full 3-step activation if
this renewal also fails.

> Remote control (OTP, commands) is only available on the **primary account** (the first account
> configured) — secondary accounts (next section) remain read-only.

## Secondary accounts (multi-brand, read-only)

You can link up to two additional accounts/brands (collapsible sections "Secondary account 2" /
"Secondary account 3", visible once the primary account is configured): the same procedure for
obtaining credentials and connecting as above applies, but these accounts remain **read-only**
(telemetry only) — no OTP activation or remote command is available on them.

## Available features

- **Telemetry**: battery/charging state, range (electric, fuel, total), GPS position, mileage,
  doors/openings state (doors, trunk, hood…), tyre pressure (alert), servicing (service due date).
- **Remote commands** (primary account, after OTP activation): wake-up, starting/stopping charging
  and schedule programming, climate preconditioning, locking/unlocking, horn, lights.
- **"My vehicles" map panel**: overview of your vehicles' position, accessible from Jeedom's home
  menu.
- **Geofencing / home zone**: "at home" detection / distance to home, based on a single home zone
  configured for the household.
- **Vehicle alerts**: generic reporting of manufacturer alerts (tyres, AdBlue, windshield washer
  fluid, warning lights…) as commands usable in scenarios.
- **Charging statistics**: charging session detection, estimated energy/duration/cost.

## Limits & best practices

- **Data freshness**: telemetry is obtained through **periodic polling** (~5 minutes by default) —
  the Stellantis API does not offer real-time ("push") notifications. Displayed information may
  therefore be a few minutes old.
- **12 V battery**: waking up a vehicle (manually or via the adaptive automatic wake-up, disabled by
  default) draws on the 12 V auxiliary battery. Waking it up too often may weaken it; keep the
  default rate unless you have a real need.
- **Anti-ban**: the plugin deliberately applies quotas and delays (cooldowns) on API calls and
  commands, to limit the risk of a temporary account block on Stellantis' side. Do not try to force
  repeated refreshes beyond what the interface offers.
- **Privacy mode**: if data/location sharing is turned off on the vehicle's side (a privacy setting
  of the mobile application), the plugin automatically switches to a lower-polling mode for that
  vehicle and reports the situation — **this is not a plugin malfunction**.
- **Single-account remote control**: remote commands only work on the primary account; secondary
  accounts remain read-only (see above).
- **Unofficial API**: as mentioned above, this integration may stop working without notice in case
  of a change on Stellantis' side.
