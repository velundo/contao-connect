<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Service\Router;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testApiPathDetection(): void
    {
        self::assertSame('v1/pages/12', Router::apiPath('/_velundo/v1/pages/12'));
        self::assertSame('v1/pages', Router::apiPath('/_velundo/v1/pages/'));
        self::assertSame('v1/info', Router::apiPath('/sub/_velundo/v1/info'));
        self::assertSame('', Router::apiPath('/_velundo/'));
        self::assertNull(Router::apiPath('/'));
        self::assertNull(Router::apiPath('/seite/_velundox/v1'));
        self::assertNull(Router::apiPath('/foo_velundo/v1/info'));
    }

    public function testAllContractRoutes(): void
    {
        $cases = [
            ['GET', 'v1/info', 'info', 'get', null],
            ['GET', 'v1/system', 'system', 'get', null],
            ['GET', 'v1/pages', 'pages', 'list', null],
            ['GET', 'v1/pages/12', 'pages', 'get', 12],
            ['PATCH', 'v1/pages/12', 'pages', 'update', 12],
            ['GET', 'v1/articles', 'articles', 'list', null],
            ['GET', 'v1/content', 'content', 'list', null],
            ['POST', 'v1/content', 'content', 'create', null],
            ['GET', 'v1/content/7', 'content', 'get', 7],
            ['PATCH', 'v1/content/7', 'content', 'update', 7],
            ['GET', 'v1/news_archives', 'news_archives', 'list', null],
            ['GET', 'v1/news', 'news', 'list', null],
            ['POST', 'v1/news', 'news', 'create', null],
            ['GET', 'v1/news/3', 'news', 'get', 3],
            ['PATCH', 'v1/news/3', 'news', 'update', 3],
            ['GET', 'v1/seo/overview', 'seo', 'overview', null],
            ['GET', 'v1/search', 'search', 'get', null],
            ['POST', 'v1/cache/clear', 'cache', 'clear', null],
            ['get', 'v1/info', 'info', 'get', null],
        ];
        foreach ($cases as [$method, $path, $area, $action, $id]) {
            $route = $this->router->match($method, $path);
            self::assertSame($area, $route['area'], "$method $path");
            self::assertSame($action, $route['action'], "$method $path");
            self::assertSame($id, $route['id'], "$method $path");
        }
        self::assertCount(18, Router::ROUTES);
    }

    public function testUnknownPathIs404(): void
    {
        foreach (['v1/unknown', 'v1/pages/abc', 'v1/pages/0', 'v1/pages/12/extra', 'v1', 'v1/pages/-1', 'v1/pages/01', 'v1/pages/99999999999'] as $path) {
            try {
                $this->router->match('GET', $path);
                self::fail("Pfad $path hätte 404 ergeben müssen");
            } catch (ApiException $e) {
                self::assertSame(404, $e->status, $path);
                self::assertSame('not_found', $e->errorCode, $path);
            }
        }
    }

    public function testWrongMethodIs404WithAllowedList(): void
    {
        try {
            $this->router->match('DELETE', 'v1/pages/12');
            self::fail('DELETE hätte abgelehnt werden müssen');
        } catch (ApiException $e) {
            self::assertSame(404, $e->status);
            self::assertSame('method_not_allowed', $e->errorCode);
            self::assertSame(['GET', 'PATCH'], $e->details['allowed']);
        }
    }

    public function testWrongVersionIs404(): void
    {
        $this->expectException(ApiException::class);
        $this->router->match('GET', 'v2/info');
    }

    public function testErrorShape(): void
    {
        $array = ApiException::validation('Fehler', ['field' => 'x'], 'invalid_type')->toArray();
        self::assertSame('invalid_type', $array['error']['code']);
        self::assertSame(['field' => 'x'], $array['error']['details']);
        self::assertSame('{"error":{"code":"unauthorized","message":"Kein gültiges Zugangstoken.","details":{}}}', json_encode(ApiException::unauthorized()->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
