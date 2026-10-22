# dte-chile

Librería propia de Inge Innova para generar, timbrar y firmar Documentos
Tributarios Electrónicos (DTE) del SII de Chile, en PHP puro y sin
dependencias de terceros.

## Por qué existe

Las librerías PHP disponibles para DTE (`libredte/libredte-lib-core`,
`hsd-cl/dte-cl`, etc.) están ancladas a PHP 7.3/7.4 + `ext-soap`, o dependen
transitivamente de esas mismas librerías viejas (caso `hsd-cl/dte-cl`, que
además exige Laravel). Ninguna es compatible con un backend en PHP 8.1 sin
framework como el de nuestros proyectos. Esta librería se construye desde
cero para llenar ese vacío, y queda pensada para copiarse tal cual a
cualquier otro proyecto PHP de la empresa — no depende de ningún namespace
`App\` ni de ningún framework.

## Estado actual (Fase 4 completa — pipeline de punta a punta probado contra el SII real)

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Generación del XML del DTE (Encabezado + Detalle), sin timbre ni firma | ✅ Hecho |
| 2 | TED (Timbre Electrónico) — firmado con la llave RSA propia del CAF | ✅ Hecho |
| 3 | Firma XMLDSig del DTE completo con el certificado digital de la empresa | ✅ Hecho |
| 4a | Autenticación SOAP ante el SII (semilla → token firmado) | ✅ Hecho — **token real obtenido** contra `maullin.sii.cl` |
| 4b | Envío del DTE (`EnvioDTE` + carga a `DTEUpload`) | ✅ Hecho — **subida real ejecutada** contra `maullin.sii.cl` |

Toda la cadena — generar, timbrar, firmar, autenticar y subir — se probó de
punta a punta contra el ambiente de certificación real del SII
(`maullin.sii.cl`) con un certificado digital acreditado (E-CertChile). El
SII respondió `STATUS=6` ("la empresa no está autorizada a enviar
archivos") — un rechazo limpio y esperado, no un error de la librería: el
RUT usado para la prueba no está inscrito ante el SII como emisor
electrónico (eso requiere completar la postulación al sistema de
facturación electrónica y el Set de Pruebas, un trámite del cliente, no de
esta librería). Antes de llegar a ese resultado se corrigieron dos bugs
reales encontrados en el proceso:

- **`TedSigner`** firmaba el TED con el string XML tal cual, sin eliminar el
  espacio en blanco entre tags — el instructivo oficial del SII (Anexo
  A.2.4) exige esa limpieza explícitamente, y **no** el C14N estándar de
  XMLDSig, a diferencia de la firma del DTE completo. Un CAF real (con
  indentación propia) habría producido una firma inválida sin este fix.
- **`SiiClient::uploadEnvioDte()`** recibía una página HTML de error
  genérica del SII en vez del XML `<RECEPCIONDTE>` con el detalle real,
  hasta agregar el header `User-Agent` con el string `PROG 1.0` — un truco
  legacy documentado en el manual OI2003_UPDTE_MDE que el SII sigue
  exigiendo.

La estructura del `EnvioDTE`, la `Carátula` y el propio `DTEUpload` se
verificaron además contra un ejemplo oficial completo publicado por el SII
(`sii.cl/servicios_online/docs/ejem_upload.txt`) y el XSD `EnvioDTE_v10.xsd`.

**Lo único que queda fuera del alcance de la librería**: la consulta de
estado de un envío ya aceptado (`QueryEstUp`, usando el `trackId`) — no
implementada todavía porque no hay forma de probarla sin un envío realmente
aceptado por el SII, lo cual depende de que el cliente complete su
habilitación como emisor electrónico.

## Clases

- **`DocumentType`** — códigos de tipo de documento del SII (33, 34, 39, 41,
  46, 52, 56, 61) y su etiqueta en español.
- **`Rut`** — normaliza un RUT a formato SII (`12345678-9`, sin puntos) y
  valida el dígito verificador (módulo 11).
- **`Certificate`** — envuelve un certificado digital `.pfx`/`.p12` ya en
  memoria (no toca disco): expone la llave privada, el certificado en PEM y
  el RUT del titular. No sabe nada de cómo el proyecto que la use almacena
  el certificado.
- **`Caf`** — envuelve un archivo CAF (Código de Autorización de Folios) del
  SII ya en memoria: tipo de documento, rango de folios autorizado, llave
  RSA propia del CAF (para firmar el TED) y el nodo `<CAF>` crudo que exige
  el TED.
- **`DteBuilder`** — arma el XML del DTE (`<DTE><Documento><Encabezado>...
  <Detalle>...`) a partir de un array con los datos del emisor, receptor e
  ítems. También expone `montoTotal()` (lee `<MntTotal>` ya calculado, para
  pasárselo a `TedSigner`) y `attachTed()` (incorpora el TED firmado más
  `<TmstFirma>` al `<Documento>`, dejándolo listo para la fase 3).
- **`TedSigner`** — arma y firma el TED (`<TED><DD>...</DD><FRMT>...
  </FRMT></TED>`) con la llave RSA propia del CAF (`RSASK`), validando antes
  que el folio esté dentro del rango autorizado y que el tipo de documento
  coincida con el del CAF. Esta firma es independiente y anterior a la firma
  XMLDSig del certificado de la empresa (fase 3) — son dos pares de llaves
  completamente distintos.
- **`DteSigner`** — firma XMLDSig (RSA-SHA1 + C14N no exclusivo, tal como
  exige el SII) el `<Documento>` completo con el certificado digital de la
  empresa, y agrega el `<Signature>` como hermano de `<Documento>` dentro
  del `<DTE>`. Re-serializa y re-parsea el documento antes de firmar, para
  que el C14N que firma sea exactamente el que recalculará cualquier
  verificador externo sobre los bytes finales.
- **`TokenRequestSigner`** — firma la solicitud de token (`<getToken><item>
  <Semilla>...</Semilla></item></getToken>`) con el certificado de la
  empresa: una firma XMLDSig "enveloped" (referencia el documento completo
  vía `URI=""`, no un nodo por ID) con un solo `Transform`
  (`enveloped-signature`), tal como exige el manual oficial del SII
  (OI2007_AUTAUTOM_MDE) — no incluir un segundo `Transform` de C14N ahí es
  importante, el SII la rechaza si se agrega.
- **`EnvioDteBuilder`** — envuelve uno o más `<DTE>` ya firmados (ver
  `DteSigner::sign()`) en un `<EnvioDTE>`, arma la `<Caratula>` (RUT emisor,
  RUT de quien envía, RUT receptor fijo del SII, datos de la resolución que
  autoriza al emisor, subtotales por tipo de documento) y firma el
  `<SetDTE>` completo reusando `DteSigner::signElement()`. Es el archivo que
  efectivamente se sube al SII.
- **`SiiClient`** — cliente para los servicios de autenticación (`getSeed()`,
  `getToken()`, `authenticate()`, vía SOAP armado a mano) y para la subida de
  documentos (`uploadEnvioDte()`, vía HTTP multipart corriente) del SII, sin
  depender de `ext-soap` (no disponible en este PHP y, de estarlo, es la
  misma dependencia que hizo inviables las librerías de terceros). Soporta
  ambiente de certificación (`SiiClient::CERTIFICACION`, por defecto) y
  producción (`SiiClient::PRODUCCION`).

## Uso

```php
use IngeInnova\DteChile\DteBuilder;

$builder = new DteBuilder();

$doc = $builder->buildFacturaElectronica([
    'folio' => 1,
    'fechaEmision' => '2026-09-08',
    'emisor' => [
        'rut' => '76.123.456-0',
        'razonSocial' => 'Inge Innova SpA',
        'giro' => 'Servicios de Ingeniería',
        'direccion' => 'Av. Siempre Viva 123',
        'comuna' => 'Providencia',
        'ciudad' => 'Santiago',
    ],
    'receptor' => [
        'rut' => '77.111.222-6',
        'razonSocial' => 'Cliente de Prueba Ltda',
        'giro' => 'Comercio',
        'direccion' => 'Calle Falsa 456',
        'comuna' => 'Las Condes',
    ],
    'items' => [
        ['nombre' => 'Consultoría técnica', 'cantidad' => 10, 'precioUnitario' => 25000],
    ],
]);

echo $doc->saveXML(); // XML en ISO-8859-1, tal como lo exige el SII
```

```php
use IngeInnova\DteChile\Certificate;
use IngeInnova\DteChile\Caf;

$cert = Certificate::fromPkcs12(file_get_contents('empresa.pfx'), $password);
$caf = Caf::fromXml(file_get_contents('CAF_33_1_100.xml'));

$cert->subjectRut();  // RUT del titular del certificado
$caf->containsFolio(1); // true si el folio 1 está dentro del rango autorizado
```

```php
use IngeInnova\DteChile\TedSigner;

// $doc y $caf, siguiendo los dos ejemplos anteriores.
$montoTotal = $builder->montoTotal($doc);

$tedSigner = new TedSigner();
$tedDoc = $tedSigner->sign($caf, 33, [
    'folio' => 1,
    'fechaEmision' => '2026-09-08',
    'emisor' => ['rut' => '76.123.456-0'],
    'receptor' => ['rut' => '77.111.222-6', 'razonSocial' => 'Cliente de Prueba Ltda'],
    'items' => [['nombre' => 'Consultoría técnica']],
    'montoTotal' => $montoTotal,
]);

$builder->attachTed($doc, $tedDoc); // agrega <TED> y <TmstFirma> al <Documento>
```

```php
use IngeInnova\DteChile\DteSigner;

$certificate = Certificate::fromPkcs12(file_get_contents('empresa.pfx'), $password);

$signedDoc = (new DteSigner())->sign($doc, $certificate);
echo $signedDoc->saveXML(); // DTE completo, firmado
```

```php
use IngeInnova\DteChile\EnvioDteBuilder;
use IngeInnova\DteChile\SiiClient;

$envioDoc = (new EnvioDteBuilder())->build(
    [$signedDoc], // uno o más DTE ya firmados (mismo emisor)
    [
        'rutEmisor' => '76.123.456-0',
        'rutEnvia' => '76.123.456-0', // RUT del firmante autorizado (el del certificado)
        'fechaResolucion' => '2026-01-01', // fecha de la resolución SII que autorizó al emisor
        'numeroResolucion' => 0, // 0 si la autorización fue por internet
    ],
    $certificate
);

$client = new SiiClient(SiiClient::CERTIFICACION); // o SiiClient::PRODUCCION
$token = $client->authenticate($certificate); // getSeed() + getToken() en un paso

$result = $client->uploadEnvioDte($envioDoc, $token, rutSender: '76.123.456-0', rutCompany: '76.123.456-0');
$result['trackId']; // número de seguimiento asignado por el SII
```

## Requisitos

- PHP >= 8.1
- `ext-openssl`
- `ext-dom` (incluye `ext-simplexml`, usado por `Caf`)
- `ext-curl` (usado por `SiiClient` para hablar con los WS del SII sin depender de `ext-soap`)

## Convención de errores

Todas las clases lanzan `InvalidArgumentException` ante datos de entrada
inválidos (RUT mal formado, certificado que no abre con la contraseña dada,
XML que no tiene forma de CAF real) con mensajes en español ya listos para
mostrar al usuario final — y `RuntimeException` ante fallas internas que no
deberían depender de la entrada del usuario.
