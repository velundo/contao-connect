<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

use Velundo\ContaoConnect\Http\ApiException;

/**
 * Ordnet Methode und Pfad (nach /_velundo/) einem Bereich und einer Aktion zu.
 */
final class Router
{
    public const PREFIX = '/_velundo/';
    public const VERSION = 'v1';

    /**
     * Routentabelle: Methode, Muster (Segmente; {id} = Zahl), Bereich, Aktion.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public const ROUTES = [
        ['GET', 'info', 'info', 'get'],
        ['GET', 'system', 'system', 'get'],
        ['GET', 'pages', 'pages', 'list'],
        ['GET', 'pages/{id}', 'pages', 'get'],
        ['PATCH', 'pages/{id}', 'pages', 'update'],
        ['GET', 'articles', 'articles', 'list'],
        ['GET', 'content', 'content', 'list'],
        ['POST', 'content', 'content', 'create'],
        ['GET', 'content/{id}', 'content', 'get'],
        ['PATCH', 'content/{id}', 'content', 'update'],
        ['GET', 'news_archives', 'news_archives', 'list'],
        ['GET', 'news', 'news', 'list'],
        ['POST', 'news', 'news', 'create'],
        ['GET', 'news/{id}', 'news', 'get'],
        ['PATCH', 'news/{id}', 'news', 'update'],
        ['GET', 'seo/overview', 'seo', 'overview'],
        ['GET', 'search', 'search', 'get'],
        ['POST', 'cache/clear', 'cache', 'clear'],
    ];

    /**
     * Teil des Pfads hinter /_velundo/ oder null, wenn die Anfrage nicht an die Schnittstelle geht
     * (am Anfang oder hinter einem Unterverzeichnis, z. B. /sub/_velundo/…).
     */
    public static function apiPath(string $path): ?string
    {
        $pos = strpos($path, self::PREFIX);
        if ($pos === false) {
            return null;
        }
        return trim(substr($path, $pos + strlen(self::PREFIX)), '/');
    }

    /**
     * @return array{area: string, action: string, id: ?int}
     */
    public function match(string $method, string $apiPath): array
    {
        $segments = $apiPath === '' ? [] : explode('/', $apiPath);
        if (($segments[0] ?? '') !== self::VERSION) {
            throw ApiException::notFound('Unbekannte Schnittstellenversion – erwartet wird /_velundo/v1/.');
        }
        array_shift($segments);
        $method = strtoupper($method);
        $allowed = [];
        foreach (self::ROUTES as [$routeMethod, $pattern, $area, $action]) {
            $id = self::matchPattern($pattern, $segments);
            if ($id === false) {
                continue;
            }
            if ($routeMethod === $method) {
                return ['area' => $area, 'action' => $action, 'id' => $id];
            }
            $allowed[] = $routeMethod;
        }
        if ($allowed !== []) {
            throw ApiException::notFound(
                sprintf('Methode %s ist für diesen Endpunkt nicht vorgesehen.', $method),
                'method_not_allowed',
                ['allowed' => array_values(array_unique($allowed))]
            );
        }
        throw ApiException::notFound();
    }

    /**
     * @param list<string> $segments
     * @return int|null|false id (int), null ohne id, false bei Nichttreffer
     */
    private static function matchPattern(string $pattern, array $segments): int|null|false
    {
        $parts = explode('/', $pattern);
        if (count($parts) !== count($segments)) {
            return false;
        }
        $id = null;
        foreach ($parts as $i => $part) {
            if ($part === '{id}') {
                if (preg_match('/^[1-9]\d{0,9}$/', $segments[$i]) !== 1 || (int)$segments[$i] > 4294967295) {
                    return false;
                }
                $id = (int)$segments[$i];
            } elseif ($part !== $segments[$i]) {
                return false;
            }
        }
        return $id;
    }
}
