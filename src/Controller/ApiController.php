<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Velundo\ContaoConnect\Api\ApiKernel;
use Velundo\ContaoConnect\Service\Router;

/**
 * Einstieg der Schnittstelle unter /_velundo/{path} (Route in config/routes.yaml, ohne Contao-Bereich: keine
 * Firewall, keine Session, keine CSRF-Prüfung). Übersetzt die Symfony-Anfrage für den ApiKernel und dessen Ergebnis in
 * eine JSON-Antwort.
 */
final class ApiController
{
    // JSON_INVALID_UTF8_SUBSTITUTE: ungültige Bytes aus der Datenbank brechen die Antwort nicht
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(private readonly ApiKernel $kernel) {}

    public function __invoke(Request $request): Response
    {
        $result = $this->kernel->handle(
            $request->getMethod(),
            Router::apiPath($request->getPathInfo()) ?? '',
            $request->query->all(),
            (string)$request->getContent(),
            // Symfony liest Authorization auch aus HTTP_AUTHORIZATION/REDIRECT_HTTP_AUTHORIZATION (CGI/FastCGI)
            $request->headers->get('Authorization'),
            $request->headers->get('X-Velundo-Token'),
            $request->isSecure(),
            $request->getHost(),
            // Absenderadresse nur für die Bremse gegen Fehlversuche (nicht gespeichert, nur gehasht gezählt)
            (string)$request->getClientIp()
        );
        $response = new JsonResponse(null, $result['status']);
        $response->setEncodingOptions(self::JSON_FLAGS);
        $response->setData($result['data']);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('X-Velundo-Api', '1');
        $response->headers->set('Cache-Control', 'no-store');
        foreach ($result['headers'] as $name => $value) {
            $response->headers->set($name, $value);
        }
        return $response;
    }
}
