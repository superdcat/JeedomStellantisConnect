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

Las credenciales **no** son distribuidas por Stellantis: están incrustadas en el APK de la aplicación
móvil de cada marca (en un archivo interno `parameters.json`, bajo las claves `cvsClientId` y
`cvsSecret`). Por tanto, debe extraerlas **una vez**, en un ordenador — el plugin, por su parte, no
descarga ni analiza ningún APK.

> Las credenciales dependen de la **marca** y del **país** de su cuenta: extraiga las que corresponden
> a la aplicación que usa y a su país. No caducan.

### Método recomendado: extracción directa (sin conexión a la cuenta)

Este método obtiene **únicamente** el Client ID y el Client Secret; la conexión a su cuenta se hará
después en Jeedom (sección siguiente), por lo que no es necesario iniciar sesión aquí. En una máquina
con **Python 3.11 o más reciente** (su PC, o su Jeedom si su versión de Python es adecuada):

```bash
# 1. Instalar la herramienta de extracción (incluye también su dependencia «androguard»)
pip3 install psa-car-controller

# 2. Descargar y descomprimir el APK de SU marca
#    (ejemplo Peugeot; sustituya por mycitroen / myds / myopel / myvauxhall)
curl -L -o app.apk.bz2 https://github.com/flobz/psa_apk/raw/main/mypeugeot.apk.bz2
bunzip2 app.apk.bz2      # produce el archivo app.apk

# 3. Extraer las credenciales (sustituya FR por el código de país de SU cuenta)
python3 - <<'PY'
from psa_car_controller.psa.setup.apk_parser import ApkParser
p = ApkParser("app.apk", "FR")
p.retrieve_content_from_apk()
print("Client ID     =", p.client_id)
print("Client Secret =", p.client_secret)
PY
```

Copie los dos valores mostrados en la configuración del plugin y seleccione la marca correspondiente al
APK utilizado.

APK por marca (repositorio [flobz/psa_apk](https://github.com/flobz/psa_apk), que archiva versiones
que se sabe que funcionan):

| Marca | Archivo a descargar |
|---|---|
| Peugeot | `mypeugeot.apk.bz2` |
| Citroën | `mycitroen.apk.bz2` |
| DS | `myds.apk.bz2` |
| Opel | `myopel.apk.bz2` |
| Vauxhall | `myvauxhall.apk.bz2` |

### Otro método: asistente gráfico de psa_car_controller

Si prefiere una interfaz web a la línea de comandos, el asistente de psa_car_controller descarga el APK
y extrae las credenciales automáticamente — pero también le obliga a **completar una conexión OAuth
entera** (la misma que el paso «Conexión de la cuenta» de más abajo) antes de escribir los valores en
el disco:

1. `pip3 install psa-car-controller` y luego ejecute `psa-car-controller -l 0.0.0.0 --web-conf`.
2. Abra `http://<dirección-de-la-máquina>:5000` e introduzca la marca, el correo electrónico, la
   contraseña de la cuenta y el código de país.
3. Complete el procedimiento de conexión (utiliza la misma recuperación del código mediante F12
   descrita más abajo).
4. Abra el archivo `config.json` creado en el directorio de trabajo: copie sus valores `client_id` y
   `client_secret` en el plugin.

> Este asistente instala y ejecuta una segunda herramienta (y le hace iniciar sesión dos veces, una
> aquí y otra en Jeedom). El plugin **no depende de ella** después, por lo que el método directo de
> arriba es preferible.

## Conexión de la cuenta

Una vez guardada la configuración, conecte el plugin a su cuenta (sección «Conexión de la cuenta»
de la página de configuración). Esta conexión se realiza preferentemente desde un **ordenador con un
navegador que disponga de herramientas de desarrollo**:

1. Haga clic en **Generar URL de autorización** y luego abra el enlace mostrado en su navegador.
2. Inicie sesión con las credenciales de la aplicación móvil de su marca (correo electrónico +
   contraseña).
   > ⚠️ Las cuentas PSA limitan la **contraseña a 16 caracteres**: si la suya es más larga, el inicio
   > de sesión puede fallar en el sitio web de la marca.
3. Tras iniciar sesión, el navegador intenta abrir la aplicación móvil (dirección que empieza por
   `mymap://…`, `mymacsdk://…` según la marca) y muestra una **página de error: esto es normal**, el
   navegador no sabe abrir este tipo de dirección.
   - **Caso sencillo**: la barra de direcciones contiene la URL completa `…://oauth2redirect/…?code=…`.
     Cópiela **entera**.
   - **Si la barra de direcciones no muestra nada aprovechable**: abra las herramientas de desarrollo
     (**F12**) → pestaña **Red (Network)** y luego provoque la redirección. Localice la línea cuya
     dirección empieza por el esquema de su marca (`mymap://…`) y copie el valor del parámetro
     **`code`** (una cadena de **36 caracteres**).
4. Pegue la URL completa (o, en su defecto, solo el `code`) en el campo **Código de autorización** y
   haga clic en **Validar el código** **sin esperar**: el código solo es válido unos instantes y es de
   un solo uso.
   > Si aparece un mensaje «código no válido, caducado o ya utilizado» o «se requiere reautenticación»,
   > regenere la URL (paso 1) y pegue la nueva URL rápidamente. Un mensaje que mencione el *realm*
   > significa que la **marca seleccionada no corresponde a la cuenta**.

El estado pasa a «Conectado a la cuenta». Puede comprobar en cualquier momento su correcto
funcionamiento mediante el botón **Probar la conexión** de la página del plugin (`Plugins → Objetos
conectados → Stellantis Connect`), que muestra el número de vehículos encontrados en la cuenta. A
partir de entonces, el plugin gestiona por sí solo la renovación del token de acceso; solo deberá
repetir este procedimiento si la conexión es revocada (mensaje «se requiere reautenticación»), tras
un cambio de marca o de credenciales, o tras un vaciado completo de la caché de Jeedom.

## Próximos pasos

La detección de vehículos y la obtención de la telemetría se describen en las secciones
correspondientes de esta documentación a medida que se publiquen las versiones del plugin.
