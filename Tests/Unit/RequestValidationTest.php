<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Http\ApiRequest;
use Velundo\ContaoConnect\Http\ChangeSet;
use Velundo\ContaoConnect\Http\Validate;
use Velundo\ContaoConnect\Service\PlainText;

final class RequestValidationTest extends TestCase
{
    public function testQueryDefaultsAndBounds(): void
    {
        $request = new ApiRequest('GET', [], '');
        self::assertSame(50, $request->limit());
        self::assertSame(0, $request->offset());
        self::assertSame('all', $request->visibility());
        $request = new ApiRequest('GET', ['limit' => '200', 'offset' => '10', 'visibility' => 'hidden'], '');
        self::assertSame(200, $request->limit());
        self::assertSame(10, $request->offset());
        self::assertSame('hidden', $request->visibility());
    }

    public function testInvalidQueryIs422(): void
    {
        foreach ([
            fn() => (new ApiRequest('GET', ['limit' => '201'], ''))->limit(),
            fn() => (new ApiRequest('GET', ['limit' => 'x'], ''))->limit(),
            fn() => (new ApiRequest('GET', ['visibility' => 'maybe'], ''))->visibility(),
            fn() => (new ApiRequest('GET', ['q' => ['a']], ''))->string('q'),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Fall $i hätte 422 ergeben müssen");
            } catch (ApiException $e) {
                self::assertSame(422, $e->status, "Fall $i");
            }
        }
    }

    public function testBodyParsing(): void
    {
        foreach (['{"title": ', '[1,2]', '"text"'] as $raw) {
            try {
                (new ApiRequest('PATCH', [], $raw))->body();
                self::fail("$raw hätte 400 ergeben müssen");
            } catch (ApiException $e) {
                self::assertSame(400, $e->status);
                self::assertSame('invalid_json', $e->errorCode);
            }
        }
        try {
            (new ApiRequest('PATCH', [], '{"title":"x","deleted":1}'))->fields(['title', 'published']);
            self::fail('Unbekanntes Feld hätte 422 ergeben müssen');
        } catch (ApiException $e) {
            self::assertSame('unknown_field', $e->errorCode);
            self::assertSame(['deleted'], $e->details['unknown']);
        }
        try {
            (new ApiRequest('PATCH', [], '{}'))->fields(['title']);
            self::fail('Leerer Rumpf');
        } catch (ApiException $e) {
            self::assertSame('empty_body', $e->errorCode);
        }
    }

    public function testValidateTypes(): void
    {
        $body = ['title' => 'Hallo', 'published' => true, 'flag' => 1, 'count' => 5, 'when' => '2026-10-10T12:00:00+02:00', 'ids' => [3, 1, 3]];
        self::assertSame('Hallo', Validate::string($body, 'title'));
        self::assertNull(Validate::string($body, 'missing'));
        self::assertTrue(Validate::bool($body, 'published'));
        self::assertTrue(Validate::bool($body, 'flag'));
        self::assertSame(5, Validate::int($body, 'count', 0, 10));
        self::assertSame(1_791_626_400, Validate::datetime($body, 'when'));
        self::assertSame(1_791_626_400, Validate::datetime(['when' => '2026-10-10T10:00:00Z'], 'when'));
        self::assertNotNull(Validate::datetime(['when' => '2026-10-10'], 'when'));
        self::assertSame([3, 1], Validate::idList($body, 'ids'));
    }

    public function testValidateRejectsWrongTypes(): void
    {
        foreach ([
            fn() => Validate::string(['title' => 5], 'title'),
            fn() => Validate::bool(['published' => 'yes'], 'published'),
            fn() => Validate::int(['count' => 1.5], 'count'),
            fn() => Validate::int(['count' => 11], 'count', 0, 10),
            fn() => Validate::datetime(['when' => 'tomorrow'], 'when'),
            fn() => Validate::datetime(['when' => '10.10.2026 12:00'], 'when'),
            fn() => Validate::datetime(['when' => '2026-13-45'], 'when'),
            fn() => Validate::datetime(['when' => '2026-02-30'], 'when'),
            fn() => Validate::idList(['ids' => [1, 'a']], 'ids'),
            fn() => Validate::idList(['ids' => ['a' => 1]], 'ids'),
            fn() => Validate::idList(['ids' => range(1, 3)], 'ids', 2),
            fn() => Validate::string(['title' => str_repeat('x', 300)], 'title', 255),
            fn() => Validate::string(['title' => "\xFF"], 'title'),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Fall $i hätte 422 ergeben müssen");
            } catch (ApiException $e) {
                self::assertSame(422, $e->status, "Fall $i");
            }
        }
    }

    public function testPlainTextFieldsAreCheckedAndEncodedLikeTheBackend(): void
    {
        PlainText::useStorageOf('5.3.12');
        self::assertSame('Über uns &#40;Team&#41; &#35;1 &#60;b&#62; A & B &#61; &#34;x&#34; &#39;y&#39; &#92;', PlainText::encode('Über uns (Team) #1 <b> A & B = "x" \'y\' \\'));
        self::assertSame('a &#60;b> (c)', PlainText::encode('a <b> (c)', PlainText::ENCODE_LT));
        self::assertSame('Über uns (Team) #1 <b> A & B', PlainText::decode('Über uns &#40;Team&#41; &#35;1 &#60;b&#62; A & B'));
        self::assertSame("A\u{00A0}B & C", PlainText::decode('A[nbsp]B [&] C'));
        self::assertSame('Titel', PlainText::fromBody(['t' => '  Titel '], 't', 255));
        foreach ([['t' => "a\x01b"], ['t' => "a\nb"], ['t' => 'Hallo {{user::name}}'], ['t' => "a\u{202E}b"]] as $i => $body) {
            try {
                PlainText::fromBody($body, 't', 255);
                self::fail("Fall $i hätte 422 ergeben müssen");
            } catch (ApiException $e) {
                self::assertSame(422, $e->status, "Fall $i");
            }
        }
        self::assertSame("Zeile 1\nZeile 2", PlainText::fromBody(['t' => "Zeile 1\nZeile 2"], 't', 255, false, true));
        try {
            PlainText::fromBody(['t' => '  '], 't', 255, true);
            self::fail('leer');
        } catch (ApiException $e) {
            self::assertSame('empty_value', $e->errorCode);
        }
    }

    public function testChangeSet(): void
    {
        $changes = new ChangeSet();
        $changes->set('title', 'Neu', 'Alt', 'Neu&#40;');
        $changes->set('pageTitle', null, 'bleibt');
        $changes->flag('published', true, false);
        $changes->set('headline_unit', 'h3', 'h2', 'ser', 'headline');
        $changes->external('text', '<p>alt</p>');
        self::assertSame(['title' => 'Neu&#40;', 'published' => 1, 'headline' => 'ser'], $changes->data());
        self::assertSame(['title' => 'Alt', 'published' => false, 'headline_unit' => 'h2', 'text' => '<p>alt</p>'], $changes->before());
        self::assertSame('title, published, headline_unit, text', $changes->fieldList());
        $empty = new ChangeSet();
        try {
            $empty->requireAny(['title']);
            self::fail('empty_body erwartet');
        } catch (ApiException $e) {
            self::assertSame('empty_body', $e->errorCode);
        }
    }
}
