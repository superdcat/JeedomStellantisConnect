# Plugin Stellantis Connect

Este plugin conecta sus vehículos **Stellantis / antiguo Grupo PSA** (Peugeot, Citroën, DS, Opel, Vauxhall)
con Jeedom: obtención de la telemetría (batería, carga, autonomía, combustible, posición GPS,
kilometraje…) a través de la API "connected car" utilizada por las aplicaciones móviles oficiales
(MyPeugeot, MyCitroën, MyDS, MyOpel, MyVauxhall).

> ⚠️ El plugin utiliza la API **de consumidor** de Stellantis, la misma que usan las aplicaciones móviles.
> Stellantis no proporciona acceso de desarrollador a particulares: debe obtener usted mismo las
> credenciales de la aplicación móvil de su marca (véase «Obtener las credenciales» a continuación).

## Configuración del plugin

Vaya a `Plugins → Gestión de plugins → Stellantis Connect → Configuración`. Los parámetros
son comunes a todos sus vehículos de una misma marca:

| Campo | Descripción |
|---|---|
| **Marca** | La marca de sus vehículos (Peugeot, Citroën, DS, Opel o Vauxhall). Determina el servidor de autenticación y el dominio utilizados — elija la marca correspondiente a la aplicación móvil de la que provienen sus credenciales. |
| **Client ID** | Identificador OAuth2 de la aplicación móvil, extraído del APK (véase más abajo). |
| **Client Secret** | Secreto OAuth2 asociado, extraído del APK. Se **almacena cifrado** en Jeedom y nunca aparece en los registros. |
| **País** | Código de país de 2 letras (p. ej. `fr`), utilizado para construir la URL de redirección por defecto. |
| **URL de redirección** | `redirect_uri` OAuth2 de la aplicación móvil (p. ej. `mymap://oauth2redirect/fr`). Déjelo vacío para usar el valor por defecto de la marca. Si su herramienta de extracción le proporcionó un valor, utilícelo. |

Mientras el Client ID y el Client Secret no estén rellenados, la página muestra un aviso
«Plugin no configurado» y las demás funciones del plugin permanecen inactivas.

## Obtener las credenciales (Client ID / Client Secret)

Las credenciales **no** son distribuidas por Stellantis: están incrustadas en la aplicación móvil de
cada marca. El método probado, procedente del proyecto de código abierto
[psa_car_controller](https://github.com/flobz/psa_car_controller), consiste en extraerlas del APK:

1. Descargue el APK de la aplicación móvil de **su marca** (por ejemplo, desde el repositorio
   [flobz/psa_apk](https://github.com/flobz/psa_apk), que archiva las versiones compatibles).
2. Ejecute el script `app_decoder.py` proporcionado por psa_car_controller sobre ese APK:
   ```
   python3 app_decoder.py <archivo.apk>
   ```
3. El script muestra, entre otras cosas, el `client_id` y el `client_secret` de la aplicación. Introdúzcalos
   tal cual en la configuración del plugin y seleccione la marca correspondiente al APK utilizado.

Esta extracción se realiza **fuera de Jeedom** (en su ordenador); el plugin no descarga ni
analiza ningún APK. Las credenciales no caducan, esta operación solo debe realizarse una vez.

## Conexión de la cuenta

Una vez guardada la configuración, conecte el plugin a su cuenta (sección «Conexión de la cuenta»
de la página de configuración):

1. Haga clic en **Generar URL de autorización** y luego abra el enlace mostrado en su navegador.
2. Inicie sesión con las credenciales de la aplicación móvil de su marca (correo electrónico +
   contraseña).
3. Tras iniciar sesión, el navegador intenta abrir la aplicación móvil y muestra una **página de
   error: esto es normal**. Copie la **URL completa** de esa página (barra de direcciones), que
   comienza con el esquema de la aplicación (p. ej. `mymap://oauth2redirect/fr?code=...`).
4. Pegue esta URL en el campo **Código de autorización** y haga clic en **Validar el código**.

El estado pasa a «Conectado a la cuenta». A partir de entonces, el plugin gestiona por sí solo la
renovación del token de acceso; solo deberá repetir este procedimiento si la conexión es revocada
(mensaje «se requiere reautenticación»), tras un cambio de marca o de credenciales, o tras un
vaciado completo de la caché de Jeedom.

## Próximos pasos

La detección de vehículos y la obtención de la telemetría se describen en las secciones
correspondientes de esta documentación a medida que se publiquen las versiones del plugin.
