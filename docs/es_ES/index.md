# Plugin Stellantis Connect

Este plugin conecta sus vehículos **Stellantis / antiguo Grupo PSA** (Peugeot, Citroën, DS, Opel, Vauxhall)
con Jeedom: obtención de la telemetría (batería, carga, autonomía, combustible, posición GPS,
kilometraje, puertas/aperturas, presión de neumáticos, mantenimiento…) y, una vez activado el control
remoto, **comandos remotos** (despertar, carga, preacondicionamiento, bloqueo, claxon, luces) — a
través de la API «connected car» utilizada por las aplicaciones móviles oficiales (MyPeugeot,
MyCitroën, MyDS, MyOpel, MyVauxhall).

> Los nombres y colores de las marcas (Peugeot, Citroën, DS, Opel, Vauxhall) se citan únicamente a
> título identificativo; este plugin no está afiliado ni respaldado por los fabricantes.

## Aviso — API no oficial y riesgos (condiciones de uso)

> ⚠️ **Leer antes de cualquier uso.**

Este plugin se basa en la API **de consumidor** de Stellantis — la misma que utilizan las
aplicaciones móviles — y no en una API oficial para desarrolladores: Stellantis no la proporciona a
particulares. Esta API ha sido objeto de **ingeniería inversa** por parte de la comunidad (en
particular el proyecto [`psa_car_controller`](https://github.com/flobz/psa_car_controller), del que
este plugin reutiliza algunos elementos bajo licencia GPL-3, y cuyo comportamiento observado sirve de
referencia).

Consecuencias que debe conocer antes de activar el plugin:

- Puede **dejar de funcionar sin previo aviso**, total o parcialmente, a raíz de un cambio decidido
  por Stellantis (sin garantía de continuidad ni de plazo de corrección).
- Su uso se realiza **bajo el propio riesgo del usuario**, incluidos los riesgos **legales y
  contractuales**: le corresponde a usted comprobar que este uso sigue siendo compatible con las
  condiciones de uso de su cuenta de marca.
- El plugin se proporciona **sin ninguna garantía**, de conformidad con la licencia **GPL-3** que lo
  rige.
- La extracción de sus credenciales (Client ID / Client Secret) — ya sea automática o manual — se
  realiza bajo su exclusiva responsabilidad.

## Configuración del plugin

Vaya a `Plugins → Gestión de plugins → Stellantis Connect → Configuración`. Los parámetros del
fieldset «Cuenta principal (control remoto)» son comunes a todos los vehículos de esta cuenta:

| Campo | Descripción |
|---|---|
| **Marca** | La marca de sus vehículos (Peugeot, Citroën, DS, Opel o Vauxhall). Determina el servidor de autenticación y el dominio utilizados — elija la marca correspondiente a la aplicación móvil de la que provienen sus credenciales. |
| **Client ID** | Identificador OAuth2 de la aplicación móvil. Se rellena automáticamente con **Extraer automáticamente**, o se introduce manualmente (véase «Obtener las credenciales» a continuación). |
| **Client Secret** | Secreto OAuth2 asociado, obtenido de la misma manera. Se **almacena cifrado** en Jeedom y nunca aparece en los registros. |
| **País** | Código de país de 2 letras (p. ej. `fr`), utilizado para construir la URL de redirección por defecto y para la extracción automática. |
| **URL de redirección** | `redirect_uri` OAuth2 de la aplicación móvil (p. ej. `mymap://oauth2redirect/fr`). Déjelo vacío para usar el valor por defecto de la marca. |

Mientras el Client ID y el Client Secret no estén rellenados, la página muestra un aviso «Plugin no
configurado» y las demás funciones del plugin permanecen inactivas.

## Obtener las credenciales (Client ID / Client Secret)

Las credenciales **no** son distribuidas por Stellantis: están incrustadas en el APK de la aplicación
móvil de cada marca (en un archivo interno `parameters.json`, bajo las claves `cvsClientId` y
`cvsSecret`), y dependen de la **marca** y del **país** de su cuenta. Hay dos métodos para
obtenerlas; son totalmente independientes entre sí.

### Método 1 (recomendado, con un clic): extracción automática en Jeedom

El propio plugin puede descargar la aplicación móvil de su marca y extraer las credenciales, sin
necesidad de instalar ninguna herramienta externa:

1. En la configuración del plugin, seleccione la **Marca** e introduzca el **País** (p. ej. `fr`).
2. Haga clic en el botón **Extraer automáticamente**.
3. Confirme el aviso mostrado («Esta API no es oficial. ¿Continuar?»): comienza la descarga de la
   aplicación (~100 MB), alojada en un repositorio comunitario de terceros.
4. Espere a que finalicen la descarga y la extracción. Si tiene éxito, los campos **Client ID** y
   **Client Secret** se rellenan automáticamente.

> ℹ️ **Dónde se ejecuta esto y cuándo preferir el otro método.** Esta extracción se realiza **en la
> propia caja Jeedom**: reutiliza el intérprete **Python 3** ya instalado para el demonio de control
> remoto, y descarga directamente en la caja el archivo de la aplicación móvil (**~100 MB**). En una
> **Raspberry Pi con tarjeta SD** (donde conviene ahorrar espacio y escrituras) — o **en caso de
> fallo** — prefiera el **Método 2** siguiente, que debe realizarse en un ordenador.

El campo avanzado **URL de la aplicación móvil (avanzado)** (`apk_url`) permite indicar otra URL de
archivo `.apk.bz2` si el repositorio comunitario por defecto no está disponible o se ha trasladado;
déjelo vacío en el caso general.

### Método 2 (alternativa): extracción manual en un ordenador

Este método obtiene **únicamente** el Client ID y el Client Secret, en una máquina de su elección con
**Python 3.11 o más reciente** (normalmente su PC); la conexión a su cuenta se realizará después en
Jeedom (sección «Conexión de la cuenta» siguiente), por lo que no es necesario iniciar sesión aquí:

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

> **Variante: asistente gráfico de `psa_car_controller`.** Si prefiere una interfaz web a la línea de
> comandos, `pip3 install psa-car-controller` y luego `psa-car-controller -l 0.0.0.0 --web-conf` abre
> un asistente (`http://<dirección-de-la-máquina>:5000`) que descarga el APK y extrae las credenciales
> automáticamente — pero le obliga a completar una conexión **OAuth** completa (el mismo
> procedimiento que «Conexión de la cuenta» a continuación) antes de escribir un archivo `config.json`
> cuyos valores `client_id`/`client_secret` tendría que copiar. Este asistente instala y ejecuta una
> segunda herramienta, y le hace iniciar sesión dos veces (una allí, otra en Jeedom): por ello, la
> línea de comandos anterior es preferible en el caso general.

## Conexión de la cuenta

Una vez guardada la configuración, conecte el plugin a su cuenta (sección «Conexión de la cuenta» de
la página de configuración). Esta conexión se realiza preferentemente desde un **ordenador con un
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
   > Si aparece un mensaje de **código rechazado (no válido, caducado o ya utilizado)** o de que se
   > **requiere una nueva conexión**, regenere la URL (paso 1) y pegue la nueva URL rápidamente. Un
   > mensaje que mencione el *realm* significa que la **marca seleccionada no corresponde a la
   > cuenta**.

El estado pasa a «Conectado a la cuenta». Puede comprobar en cualquier momento su correcto
funcionamiento mediante el botón **Probar la conexión** de la página del plugin (`Plugins → Objetos
conectados → Stellantis Connect`), que muestra el número de vehículos encontrados en la cuenta. A
partir de entonces, el plugin gestiona por sí solo la renovación del token de acceso; solo deberá
repetir este procedimiento si la conexión es revocada (un mensaje indica entonces que se requiere una
reconexión, en la página del plugin y en los mensajes de Jeedom), tras un cambio de marca o de
credenciales, o tras un vaciado completo de la caché de Jeedom.

## Control remoto — activación OTP

La simple conexión de su cuenta (sección anterior) solo es suficiente para la **lectura** de la
telemetría. Para desbloquear los **comandos remotos** (despertar, iniciar/detener la carga,
programación de la carga, preacondicionamiento, bloqueo/desbloqueo, claxon, luces), es necesaria una
activación adicional: se realiza en el fieldset **«Control remoto (activación OTP)»** de la página de
configuración.

> **Requisito previo**: la cuenta principal ya debe estar **conectada** (sección «Conexión de la
> cuenta» anterior) — el número de teléfono asociado a esta cuenta se utiliza para recibir el SMS de
> activación.

Procedimiento en 3 pasos:

1. Haga clic en **Enviar el SMS de activación** («1. Recibir el SMS») y confirme: se envía un SMS con
   un código al número asociado a su cuenta de marca.
2. Introduzca este código en el campo **Código recibido por SMS** («2. Código recibido por SMS»).
3. Introduzca su **Código PIN de la aplicación** («3. Código PIN de la aplicación» — el código de 4
   cifras que utiliza en la aplicación móvil de su marca), y luego haga clic en **Activar el control
   remoto**.

El estado mostrado pasa a «Activado».

> ⚠️ **Cuotas estrictas y definitivas por parte de Stellantis**: **6 códigos por 24 h** y **20
> activaciones por SMS por cuenta, de por vida** — estos contadores **nunca se reinician**. Utilice
> esta activación solo cuando esté dispuesto a llevarla a cabo hasta el final, y evite repetirla sin
> motivo.

El token remoto tiene una vida técnica muy corta (**~15 minutos**). El plugin lo **renueva de forma
automática y silenciosa en cada paso del cron**, mediante una simple renovación — **sin código OTP ni
SMS** — mientras esta cadena de renovación siga funcionando: normalmente **nunca debería tener que
intervenir usted mismo**.

Si esta renovación automática **falla de forma persistente** (token de renovación remoto no válido o
revocado), el estado pasa a «Caducado — renovación necesaria». Solo en ese caso, haga clic en el
botón **Renovar el token remoto**: reutiliza el dispositivo OTP ya registrado, **sin nuevo SMS**, pero
genera un nuevo código OTP y **por tanto consume 1 unidad de la cuota estricta de 6 códigos / 24 h**
mencionada anteriormente — use este botón solo cuando el estado lo indique realmente. Repita la
activación completa en 3 pasos únicamente si esta renovación también falla.

> El control remoto (OTP, comandos) solo está disponible en la **cuenta principal** (la primera
> cuenta configurada) — las cuentas secundarias (sección siguiente) permanecen de solo lectura.

## Cuentas secundarias (multi-marca, solo lectura)

Puede vincular hasta dos cuentas/marcas adicionales (secciones plegables «Cuenta secundaria 2»/«Cuenta
secundaria 3», visibles una vez configurada la cuenta principal): el mismo procedimiento de obtención
de credenciales y de conexión que el anterior, pero estas cuentas permanecen de **solo lectura**
(únicamente telemetría) — no hay activación OTP ni comando remoto disponible en ellas.

## Funciones disponibles

- **Telemetría**: batería/estado de carga, autonomía (eléctrica, combustible, total), posición GPS,
  kilometraje, estado de puertas/aperturas (puertas, maletero, capó…), presión de neumáticos (alerta),
  mantenimiento (fecha de revisión).
- **Comandos remotos** (cuenta principal, tras la activación OTP): despertar, iniciar/detener la
  carga y programación de horario, preacondicionamiento climático, bloqueo/desbloqueo, claxon, luces.
- **Panel de mapa «Mis vehículos»**: vista general de la posición de sus vehículos, accesible desde el
  menú de inicio de Jeedom.
- **Geofencing / zona del domicilio**: detección «en casa» / distancia al domicilio, a partir de una
  única zona del domicilio configurada para el hogar.
- **Alertas del vehículo**: notificación genérica de las alertas del fabricante (neumáticos, AdBlue,
  lavaparabrisas, testigos…) en forma de comandos utilizables en escenarios.
- **Estadísticas de carga**: detección de sesiones de carga, energía/duración/coste estimados.

## Límites y buenas prácticas

- **Actualidad de los datos**: la telemetría se obtiene mediante **consultas periódicas** (~5 minutos
  por defecto) — la API de Stellantis no ofrece notificaciones en tiempo real («push»). La
  información mostrada puede, por tanto, tener unos minutos de retraso.
- **Batería de 12 V**: despertar un vehículo (manualmente o mediante el despertar automático
  adaptativo, desactivado por defecto) consume la batería auxiliar de 12 V. Un despertar demasiado
  frecuente puede debilitarla; mantenga la cadencia por defecto salvo necesidad real.
- **Protección anti-bloqueo**: el plugin aplica deliberadamente cuotas y demoras (cooldowns) en las
  llamadas a la API y en los comandos, para limitar el riesgo de un bloqueo temporal de la cuenta por
  parte de Stellantis. No intente forzar actualizaciones repetidas más allá de lo que ofrece la
  interfaz.
- **Modo privacidad**: si el uso compartido de datos/localización se ha desactivado desde el vehículo
  (ajuste de privacidad de la aplicación móvil), el plugin pasa automáticamente a un modo de menos
  consultas para ese vehículo y señala la situación — **esto no es un fallo del plugin**.
- **Control remoto de una sola cuenta**: los comandos remotos solo funcionan en la cuenta principal;
  las cuentas secundarias permanecen de solo lectura (véase más arriba).
- **API no oficial**: como se ha indicado anteriormente, esta integración puede dejar de funcionar sin
  previo aviso en caso de un cambio por parte de Stellantis.
