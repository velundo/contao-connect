<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\ContaoConnect\Http\ApiException;
use Velundo\ContaoConnect\Service\Contao\HtmlWhitelistInterface;
use Velundo\ContaoConnect\Service\HtmlSanitizer;
use Velundo\ContaoConnect\Service\PlainText;
use Velundo\ContaoConnect\Service\Settings;
use Velundo\ContaoConnect\Service\TextField;

final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    public function testRemovesScriptsAndEventHandlers(): void
    {
        $clean = $this->sanitizer->sanitize('<p onclick="alert(1)">Hallo <b>Welt</b><script>alert(2)</script></p><SCRIPT src=x.js></SCRIPT><img src="a.png" ONERROR=alert(3)>');
        self::assertStringNotContainsString('<script', strtolower($clean));
        self::assertStringNotContainsString('alert', $clean);
        self::assertStringNotContainsString('onerror', strtolower($clean));
        self::assertStringContainsString('<b>Welt</b>', $clean);
    }

    public function testRemovesDangerousUrlsAndElements(): void
    {
        $clean = $this->sanitizer->sanitize('<a href="javascript:alert(1)">x</a><a href=" JaVaScRiPt:void(0)">y</a><a href="data:text/html;base64,xx">z</a><a href="https://example.de/">ok</a>'
            . '<iframe src="https://example.de"></iframe><style>p{color:red}</style><svg><script>1</script></svg><form action="/x"><input name="a"></form><!--[if IE]><script>1</script><![endif]-->'
            . '<p style="background:url(javascript:alert(1))">s</p>');
        self::assertStringNotContainsString('javascript', strtolower($clean));
        self::assertStringNotContainsString('data:text/html', $clean);
        self::assertStringContainsString('href="https://example.de/"', $clean);
        foreach (['<iframe', '<style', '<svg', '<form', '<input', '<!--', 'style='] as $needle) {
            self::assertStringNotContainsString($needle, $clean);
        }
    }

    public function testKeepsRegularMarkupAndRunningText(): void
    {
        $html = '<h2>Überschrift</h2><p>Absatz mit <a href="/seite">Link</a> und <strong>fett</strong>. Die online=Version ist da.</p><ul><li>eins</li></ul>';
        self::assertSame($html, $this->sanitizer->sanitize($html));
        self::assertSame('<p>online=Version</p><a href="/a">online=1</a>', $this->sanitizer->preClean('<p onmouseover="x()">online=Version</p><a href="/a" onclick=alert(1)>online=1</a>'));
    }

    public function testInsertTagsOnlyAsLinks(): void
    {
        $ok = '<p><a href="{{link_url::12}}">Seite</a> {{news_url::neue-website|absolute}} {{email::info@example.de}} {{br}}</p>';
        self::assertSame($ok, $this->sanitizer->sanitize($ok));
        foreach (['<p>{{user::name}}</p>', '<p>{{env::host}}</p>', '<p>{{file::x.php}}</p>', '<p>{{link_url::12}} {{insert_module::3}}</p>', '<p>{{link_url::a b}}</p>', '<p>{{</p>'] as $bad) {
            try {
                $this->sanitizer->sanitize($bad);
                self::fail("$bad hätte abgelehnt werden müssen");
            } catch (ApiException $e) {
                self::assertSame('insert_tag_not_allowed', $e->errorCode, $bad);
            }
        }
    }

    /** Ein Insert-Tag, das erst die Bereinigung zusammensetzt (Kommentar oder verbotenes Element zwischen den Klammern), wird am Endergebnis erkannt. */
    public function testInsertTagsAssembledByCleaningAreRejected(): void
    {
        $whitelist = new class implements HtmlWhitelistInterface {
            public function clean(string $html): string
            {
                return strip_tags($html, '<p><a>');
            }
        };
        foreach ([new HtmlSanitizer(), new HtmlSanitizer($whitelist)] as $sanitizer) {
            foreach (['<p>{<!-- -->{insert_module::3}}</p>', '<p>{<script>x</script>{env::host}}</p>', '<p>{<!-- a -->{insert_article::5}}</p>'] as $bad) {
                try {
                    $sanitizer->sanitize($bad);
                    self::fail("$bad hätte abgelehnt werden müssen");
                } catch (ApiException $e) {
                    self::assertSame('insert_tag_not_allowed', $e->errorCode, $bad);
                }
            }
        }
        // Erst die Positivliste von Contao setzt das Tag zusammen („<x>“ bleibt in der Grobreinigung stehen)
        try {
            (new HtmlSanitizer($whitelist))->sanitize('<p>{<x>{insert_module::3}}</p>');
            self::fail('Insert-Tag nach der Positivliste hätte abgelehnt werden müssen');
        } catch (ApiException $e) {
            self::assertSame('insert_tag_not_allowed', $e->errorCode);
        }
        self::assertSame('<p>{{link_url::12}}</p>', (new HtmlSanitizer($whitelist))->sanitize('<p>{{link_url::12}}</p>'));
    }

    /** „/“ als Attribut-Trenner und angefangene Tags ohne „>“ entgehen der Bereinigung nicht. */
    public function testSlashSeparatedAttributesAndUnterminatedTags(): void
    {
        self::assertSame('<img src="x">', $this->sanitizer->preClean('<img/onerror=alert(1) src="x">'));
        // Ein unquotierter Wert reicht bis zum nächsten Leerzeichen – „alert(1)/src="x"“ ist der Wert von onerror
        self::assertSame('<img>', $this->sanitizer->preClean('<img/onerror=alert(1)/src="x">'));
        self::assertSame('<a>x</a>', $this->sanitizer->preClean('<a/href=javascript:alert(1)>x</a>'));
        self::assertSame('<p>&lt;img src=x onerror=alert(1)</p>', $this->sanitizer->preClean('<p><img src=x onerror=alert(1)</p>'));
        self::assertSame('Text &lt;img src=x onerror=alert(1)', $this->sanitizer->preClean('Text <img src=x onerror=alert(1)'));
        self::assertSame('<p>a < b</p>', $this->sanitizer->preClean('<p>a < b</p>'));
    }

    public function testWhitelistRunsAfterPreClean(): void
    {
        $whitelist = new class implements HtmlWhitelistInterface {
            public string $seen = '';

            public function clean(string $html): string
            {
                $this->seen = $html;
                return strip_tags($html, '<p>');
            }
        };
        $clean = (new HtmlSanitizer($whitelist))->sanitize('<p>a<script>x</script><b>b</b></p>');
        self::assertSame('<p>a<b>b</b></p>', $whitelist->seen);
        self::assertSame('<p>ab</p>', $clean);
    }

    public function testTextFieldSizeAndType(): void
    {
        $field = new TextField(new Settings(['max_text_kb' => 1]), $this->sanitizer);
        self::assertNull($field->fromBody([], 'text'));
        self::assertSame('<p>x</p>', $field->fromBody(['text' => ' <p>x</p> '], 'text'));
        foreach ([['text' => 5], ['text' => str_repeat('x', 1100)], ['text' => "\xFF"]] as $i => $body) {
            try {
                $field->fromBody($body, 'text');
                self::fail("Fall $i");
            } catch (ApiException $e) {
                self::assertSame(422, $e->status);
            }
        }
    }

    public function testToText(): void
    {
        self::assertSame('Hallo Welt – zwei', PlainText::fromHtml("<p>Hallo</p><p>Welt &ndash;\n zwei</p>"));
        $long = PlainText::fromHtml('<p>' . str_repeat('a', 3000) . '</p>', 2000);
        self::assertSame(2000, mb_strlen($long));
        self::assertSame('…', mb_substr($long, -1));
        self::assertSame('Text ? Ende', PlainText::fromHtml("<p>Text \xFF Ende</p>"));
        self::assertSame('a b', PlainText::fromHtml('<p>a[nbsp]b</p><script>x</script>'));
    }
}
