<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Api;

use Psr\Log\LoggerInterface;
use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\Result;
use Velundo\ContaoConnect\Service\LogService;
use Velundo\ContaoConnect\Service\RateLimiter;
use Velundo\ContaoConnect\Service\Router;
use Velundo\ContaoConnect\Service\Settings;
use Velundo\ContaoConnect\Service\Contao\SystemInfoInterface;
use Velundo\ContaoConnect\Service\PlainText;
use Velundo\ContaoConnect\Service\TokenService;

/**
 * Ablauf einer Anfrage ohne Symfony-Abhängigkeit (prüfbar ohne Contao): Token → HTTPS → Ratenbegrenzung → Route →
 * Bereich. Liefert Status, Nutzdaten und zusätzliche Köpfe; der Controller macht daraus die JSON-Antwort.
 * Jeder Aufruf mit gültigem Token landet im Protokoll (außer 429).
 */
final class ApiKernel
{
    /** @param array<string, HandlerInterface> $handlers */
    public function __construct(
        private readonly Router $router,
        private readonly TokenService $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly Settings $settings,
        private readonly LogService $log,
        private readonly LoggerInterface $logger,
        private readonly array $handlers,
        private readonly ?SystemInfoInterface $system = null,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, data: array<string, mixed>, headers: array<string, string>}
     */
    public function handle(string $method, string $apiPath, array $query, string $body, ?string $authorization, ?string $velundoToken, bool $secure, string $host = ''): array
    {
        $tokenName = null;
        $status = 500;
        try {
            // Speicherform der Klartextfelder: Contao 5 kodiert, Contao 6 roh (PlainText)
            if ($this->system !== null) {
                PlainText::useStorageOf($this->system->contaoVersion());
            }
            $result = $this->dispatch($method, $apiPath, $query, $body, $authorization, $velundoToken, $secure, $host, $tokenName);
            $status = $result->status;
            return ['status' => $result->status, 'data' => $result->data, 'headers' => $result->headers];
        } catch (ApiException $e) {
            $status = $e->status;
            $headers = [];
            if ($e->status === 401) {
                $headers['WWW-Authenticate'] = 'Bearer realm="velundo"';
            }
            if ($e->status === 429) {
                $headers['Retry-After'] = (string)(int)($e->details['retry_after'] ?? 60);
            }
            return ['status' => $e->status, 'data' => $e->toArray(), 'headers' => $headers];
        } catch (\Throwable $e) {
            $this->logger->error('velundo: ' . get_class($e) . ': ' . $e->getMessage(), ['exception' => $e]);
            $details = $this->settings->isDevelopment() ? ['exception' => get_class($e), 'message' => $e->getMessage(), 'file' => basename($e->getFile()) . ':' . $e->getLine()] : [];
            return ['status' => 500, 'data' => (new ApiException(500, 'internal_error', 'Interner Fehler in der Schnittstelle.', $details))->toArray(), 'headers' => []];
        } finally {
            // Nur abgelehnte Schreibversuche als Aufrufzeile – erfolgreiche Änderungen protokolliert der Endpunkt selbst
            // (Tabelle, ID, Felder), Lesezugriffe gar nicht (Livetest: sonst flutet jede Abfrage das Contao-Protokoll)
            if ($tokenName !== null && $method !== 'GET' && $status >= 400 && $status !== 429) {
                $this->log->log(sprintf('%s /%s → %d', strtoupper($method), $apiPath, $status), $tokenName);
            }
        }
    }

    /** @param array<string, mixed> $query */
    private function dispatch(string $method, string $apiPath, array $query, string $body, ?string $authorization, ?string $velundoToken, bool $secure, string $host, ?string &$tokenName): Result
    {
        // Erst das Token: ohne gültiges Token antwortet die Schnittstelle immer 401 (Vertrag), auch über http
        $token = $this->tokens->authenticate(self::authorizationHeader($authorization, $velundoToken));
        if ($token === null && trim((string)$velundoToken) !== '' && trim((string)$authorization) !== '') {
            // Authorization trägt etwas anderes (z. B. HTTP-Basic-Schutz vor der Website) – dann zählt X-Velundo-Token
            $token = $this->tokens->authenticate('Bearer ' . trim((string)$velundoToken));
        }
        if ($token === null) {
            throw ApiException::unauthorized();
        }
        $tokenName = $token['name'];
        if ($this->settings->requireHttps() && !$this->settings->isDevelopment() && !$secure) {
            throw ApiException::forbidden('https_required', 'Die Schnittstelle ist nur über HTTPS erreichbar.');
        }
        $rate = $this->rateLimiter->hit($token['id']);
        if (!$rate['allowed']) {
            throw ApiException::rateLimited($rate['retry_after']);
        }
        $route = $this->router->match($method, $apiPath);
        $request = new ApiRequest(strtoupper($method), $query, $body, $route['id'], $token['name'], $host);
        $handler = $this->handlers[$route['area']] ?? throw ApiException::notFound();
        return $handler->handle($route['action'], $request);
    }

    /**
     * Authorization-Kopf; fehlt er (Apache mit PHP-FPM gibt ihn ohne Anpassung nicht weiter), gilt ersatzweise
     * „X-Velundo-Token: <token>“ – eigene Köpfe erreichen PHP immer. Die App sendet beide.
     */
    public static function authorizationHeader(?string $authorization, ?string $velundoToken): ?string
    {
        if ($authorization !== null && trim($authorization) !== '') {
            return $authorization;
        }
        $token = trim((string)$velundoToken);
        return $token !== '' ? 'Bearer ' . $token : null;
    }
}
