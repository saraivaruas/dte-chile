<?php

declare(strict_types=1);

namespace IngeInnova\DteChile;

use DOMDocument;
use RuntimeException;

/**
 * Fase 4 del pipeline de DTE: autenticación ante el SII (semilla → token
 * firmado). El SII no expone un WSDL utilizable por php-ext-soap para
 * estos servicios en un PHP moderno (son servicios RPC/encoded viejos), así
 * que acá se arma el sobre SOAP a mano sobre HTTP — sin depender de
 * ext-soap, que es justamente la dependencia que hizo inviables las
 * librerías de terceros (ver README).
 */
final class SiiClient
{
    public const CERTIFICACION = 'certificacion';
    public const PRODUCCION = 'produccion';

    private const ENDPOINTS = [
        self::CERTIFICACION => [
            'semilla' => 'https://maullin.sii.cl/DTEWS/CrSeed.jws',
            'token' => 'https://maullin.sii.cl/DTEWS/GetTokenFromSeed.jws',
            'upload' => 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload',
        ],
        self::PRODUCCION => [
            'semilla' => 'https://palena.sii.cl/DTEWS/CrSeed.jws',
            'token' => 'https://palena.sii.cl/DTEWS/GetTokenFromSeed.jws',
            'upload' => 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload',
        ],
    ];

    /** Códigos de STATUS que devuelve DTEUpload — manual OI2003_UPDTE_MDE, punto 2.1. */
    private const UPLOAD_STATUS_LABELS = [
        '0' => 'Upload OK.',
        '1' => 'El remitente no tiene permiso para enviar.',
        '2' => 'Error en el tamaño del archivo (muy grande o muy chico).',
        '3' => 'Archivo cortado (tamaño distinto al informado).',
        '5' => 'No está autenticado (token inválido o vencido).',
        '6' => 'La empresa no está autorizada a enviar archivos.',
        '7' => 'Esquema XML inválido.',
        '8' => 'Firma del documento inválida.',
        '9' => 'Sistema bloqueado.',
    ];

    private readonly TokenRequestSigner $tokenSigner;

    public function __construct(
        private readonly string $environment = self::CERTIFICACION,
        ?TokenRequestSigner $tokenSigner = null,
    ) {
        if (!isset(self::ENDPOINTS[$this->environment])) {
            throw new RuntimeException("Ambiente SII desconocido: \"{$this->environment}\" (use SiiClient::CERTIFICACION o SiiClient::PRODUCCION).");
        }
        $this->tokenSigner = $tokenSigner ?? new TokenRequestSigner();
    }

    /** Semilla (número de una sola vez) que hay que firmar dentro de los próximos ~2 minutos para canjearla por un token. */
    public function getSeed(): string
    {
        $response = $this->soapCall(
            self::ENDPOINTS[$this->environment]['semilla'],
            $this->buildNoArgEnvelope('getSeed')
        );

        $inner = $this->extractSoapReturn($response, 'getSeedReturn');

        return $this->readSiiValue($inner, 'SEMILLA');
    }

    /** Canjea una semilla ya firmada por el token de sesión que autentica el resto de las llamadas al SII. */
    public function getToken(string $semilla, Certificate $certificate): string
    {
        $signedRequest = $this->tokenSigner->sign($semilla, $certificate);
        $signedXml = $signedRequest->saveXML();
        if ($signedXml === false) {
            throw new RuntimeException('No fue posible serializar la solicitud de token firmada.');
        }

        $response = $this->soapCall(
            self::ENDPOINTS[$this->environment]['token'],
            $this->buildStringArgEnvelope('getToken', 'pszXml', $signedXml)
        );

        $inner = $this->extractSoapReturn($response, 'getTokenReturn');

        return $this->readSiiValue($inner, 'TOKEN');
    }

    /** Atajo: obtiene la semilla y la canjea por token en un solo paso. */
    public function authenticate(Certificate $certificate): string
    {
        return $this->getToken($this->getSeed(), $certificate);
    }

    /**
     * Sube un <EnvioDTE> ya armado y firmado (ver EnvioDteBuilder::build())
     * al SII — formato y endpoint confirmados contra el ejemplo oficial del
     * SII (sii.cl/servicios_online/docs/ejem_upload.txt) y el manual
     * OI2003_UPDTE_MDE. No es un servicio SOAP: es un POST HTTP
     * multipart/form-data corriente, autenticado con el token vía cookie.
     *
     * @return array{trackId: string, status: string} El trackId sirve para consultar el estado de la validación más adelante (todavía no implementado en esta librería).
     * @throws RuntimeException Si el SII rechaza el envío (status distinto de "0") o la respuesta no se puede interpretar.
     */
    public function uploadEnvioDte(DOMDocument $signedEnvioDte, string $token, string $rutSender, string $rutCompany): array
    {
        $xml = $signedEnvioDte->saveXML();
        if ($xml === false) {
            throw new RuntimeException('No fue posible serializar el EnvioDTE antes de subirlo.');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'enviodte_');
        if ($tmpPath === false || file_put_contents($tmpPath, $xml) === false) {
            throw new RuntimeException('No fue posible preparar el archivo temporal del EnvioDTE.');
        }

        try {
            [$senderNumber, $senderDv] = $this->splitRut($rutSender);
            [$companyNumber, $companyDv] = $this->splitRut($rutCompany);

            $ch = curl_init(self::ENDPOINTS[$this->environment]['upload']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'rutSender' => $senderNumber,
                    'dvSender' => $senderDv,
                    'rutCompany' => $companyNumber,
                    'dvCompany' => $companyDv,
                    'archivo' => new \CURLFile($tmpPath, 'text/xml', 'EnvioDTE.xml'),
                ],
                CURLOPT_HTTPHEADER => ['Cookie: TOKEN=' . $token],
                // Sin este User-Agent el SII responde con una página HTML de
                // error genérica en vez del XML <RECEPCIONDTE> — es un truco
                // legacy documentado en el propio manual del SII
                // (OI2003_UPDTE_MDE, figura 2.3: "el parámetro PROG 1.0
                // permitirá que el formato de salida sea en un XML").
                CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.0; YComp 5.0.2.4)',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 60,
            ]);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            @unlink($tmpPath);
        }

        if ($response === false) {
            throw new RuntimeException("No fue posible conectar con el SII para subir el EnvioDTE: {$error}");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("El SII respondió con un error HTTP {$httpCode} al subir el EnvioDTE.");
        }

        return $this->parseUploadResponse($response);
    }

    /** @return array{trackId: string, status: string} */
    private function parseUploadResponse(string $responseXml): array
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($responseXml)) {
            throw new RuntimeException("La respuesta del SII al subir el EnvioDTE no es un XML válido: " . mb_substr($responseXml, 0, 300));
        }

        $statusNodes = $doc->getElementsByTagName('STATUS');
        $status = $statusNodes->length > 0 ? $statusNodes->item(0)->textContent : null;

        if ($status !== '0') {
            $label = self::UPLOAD_STATUS_LABELS[$status] ?? 'Error desconocido.';
            throw new RuntimeException("El SII rechazó el envío del DTE (STATUS={$status}): {$label}");
        }

        $trackIdNodes = $doc->getElementsByTagName('TRACKID');
        if ($trackIdNodes->length === 0) {
            throw new RuntimeException('El SII aceptó el envío pero no devolvió un TRACKID.');
        }

        return ['trackId' => $trackIdNodes->item(0)->textContent, 'status' => $status];
    }

    /** @return array{0: string, 1: string} [RUT sin dígito verificador, dígito verificador] */
    private function splitRut(string $rut): array
    {
        $parts = explode('-', Rut::toDteFormat($rut));

        return [$parts[0], $parts[1]];
    }

    private function soapCall(string $url, string $envelopeXml): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelopeXml,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new RuntimeException("No fue posible conectar con el SII ({$url}): {$error}");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("El SII respondió con un error HTTP {$httpCode} ({$url}).");
        }

        return $response;
    }

    private function buildNoArgEnvelope(string $operation): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $envelope = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Envelope');
        $doc->appendChild($envelope);
        $body = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Body');
        $envelope->appendChild($body);
        $op = $doc->createElement($operation);
        $op->setAttribute('xmlns', 'http://DefaultNamespace');
        $body->appendChild($op);

        return (string) $doc->saveXML();
    }

    private function buildStringArgEnvelope(string $operation, string $paramName, string $paramValue): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $envelope = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Envelope');
        $doc->appendChild($envelope);
        $body = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soapenv:Body');
        $envelope->appendChild($body);
        $op = $doc->createElement($operation);
        $op->setAttribute('xmlns', 'http://DefaultNamespace');
        $body->appendChild($op);
        $param = $doc->createElement($paramName, $paramValue);
        $op->appendChild($param);

        return (string) $doc->saveXML();
    }

    /** Extrae el texto (ya des-escapado) del elemento de retorno RPC/encoded dentro del sobre SOAP de respuesta. */
    private function extractSoapReturn(string $soapResponseXml, string $returnElementLocalName): string
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($soapResponseXml)) {
            throw new RuntimeException("La respuesta del SII no es un XML válido: " . mb_substr($soapResponseXml, 0, 300));
        }

        $nodes = $doc->getElementsByTagName($returnElementLocalName);
        if ($nodes->length === 0) {
            $fault = $doc->getElementsByTagName('faultstring');
            if ($fault->length > 0) {
                throw new RuntimeException("El SII rechazó la solicitud: {$fault->item(0)->textContent}");
            }
            throw new RuntimeException("La respuesta del SII no tiene el elemento esperado <{$returnElementLocalName}>.");
        }

        return $nodes->item(0)->textContent;
    }

    /**
     * Las respuestas del SII (semilla, token) vienen envueltas en
     * <SII:RESPUESTA><SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR>
     * <SII:RESP_BODY><TAG>valor</TAG></SII:RESP_BODY></SII:RESPUESTA> — con
     * ESTADO "00" significando éxito y cualquier otro código un rechazo.
     */
    private function readSiiValue(string $innerXml, string $valueTagName): string
    {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($innerXml)) {
            throw new RuntimeException("El SII devolvió una respuesta que no se pudo interpretar: " . mb_substr($innerXml, 0, 300));
        }

        $estadoNodes = $doc->getElementsByTagName('ESTADO');
        $estado = $estadoNodes->length > 0 ? $estadoNodes->item(0)->textContent : null;

        $valueNodes = $doc->getElementsByTagName($valueTagName);
        if ($valueNodes->length > 0 && ($estado === null || $estado === '00')) {
            return $valueNodes->item(0)->textContent;
        }

        $glosaNodes = $doc->getElementsByTagName('GLOSA');
        $glosa = $glosaNodes->length > 0 ? $glosaNodes->item(0)->textContent : 'sin detalle';

        throw new RuntimeException("El SII rechazó la solicitud (ESTADO=" . ($estado ?? 'desconocido') . "): {$glosa}");
    }
}
