<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Http;

/**
 * Anfrage an einen Endpunkt: Methode, Abfrageparameter, JSON-Rumpf, die id aus dem Pfad, die Token-Bezeichnung und der
 * Host der Anfrage. Alle Zugriffe prüfen die Eingaben und werfen bei Verstößen eine ApiException (400/422).
 */
final class ApiRequest
{
    public const VISIBILITY_ALL = 'all';
    public const VISIBILITY_VISIBLE = 'visible';
    public const VISIBILITY_HIDDEN = 'hidden';

    public const LIMIT_DEFAULT = 50;
    public const LIMIT_MAX = 200;

    /** @var array<string, mixed>|null */
    private ?array $parsedBody = null;

    /**
     * @param array<string, mixed> $query
     * @param list<string> $scopes Zusatzrechte des Tokens (TokenService::SCOPES)
     */
    public function __construct(
        public readonly string $method,
        public readonly array $query,
        private readonly string $rawBody,
        public readonly ?int $id = null,
        public readonly string $tokenName = '',
        public readonly string $host = '',
        public readonly array $scopes = [],
    ) {}

    /** Hat das Token das Zusatzrecht? */
    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** 403 `scope_<recht>`, wenn das Token das Zusatzrecht nicht hat; $what beschreibt die verweigerte Handlung. */
    public function require(string $scope, string $what): void
    {
        if (!$this->can($scope)) {
            throw ApiException::forbidden('scope_' . $scope, sprintf(
                '%s erfordert das Zusatzrecht „%s“ des Tokens. Ein Token mit diesem Recht erzeugt: vendor/bin/contao-console velundo:token create "<Bezeichnung>" --allow-%s',
                $what,
                $scope,
                str_replace('_', '-', $scope)
            ));
        }
    }

    public function has(string $name): bool
    {
        return isset($this->query[$name]) && $this->query[$name] !== '';
    }

    public function string(string $name, string $default = ''): string
    {
        $value = $this->query[$name] ?? $default;
        if (!is_scalar($value)) {
            throw ApiException::validation(sprintf('Parameter „%s“ muss ein einfacher Wert sein.', $name), ['parameter' => $name]);
        }
        return trim((string)$value);
    }

    public function int(string $name, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        if (!$this->has($name)) {
            return $default;
        }
        $raw = $this->query[$name];
        if (!is_scalar($raw) || preg_match('/^-?\d{1,18}$/', (string)$raw) !== 1) {
            throw ApiException::validation(sprintf('Parameter „%s“ muss eine ganze Zahl sein.', $name), ['parameter' => $name]);
        }
        $value = (int)$raw;
        if ($value < $min || $value > $max) {
            throw ApiException::validation(sprintf('Parameter „%s“ muss zwischen %d und %d liegen.', $name, $min, $max), ['parameter' => $name]);
        }
        return $value;
    }

    public function limit(int $default = self::LIMIT_DEFAULT, int $max = self::LIMIT_MAX): int
    {
        return $this->int('limit', $default, 1, $max);
    }

    public function offset(): int
    {
        return $this->int('offset', 0, 0, 1000000);
    }

    /** Sichtbarkeitsfilter: all (Standard), visible, hidden. */
    public function visibility(): string
    {
        $mode = $this->string('visibility', self::VISIBILITY_ALL);
        if (!in_array($mode, [self::VISIBILITY_ALL, self::VISIBILITY_VISIBLE, self::VISIBILITY_HIDDEN], true)) {
            throw ApiException::validation('Parameter „visibility“ muss all, visible oder hidden sein.', ['parameter' => 'visibility']);
        }
        return $mode;
    }

    /**
     * JSON-Rumpf als Feld-Liste; leerer Rumpf ergibt ein leeres Feld.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }
        if (trim($this->rawBody) === '') {
            return $this->parsedBody = [];
        }
        try {
            $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ApiException::badRequest('invalid_json', 'Der Anfragerumpf ist kein gültiges JSON.', ['reason' => $e->getMessage()]);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw ApiException::badRequest('invalid_json', 'Der Anfragerumpf muss ein JSON-Objekt sein.');
        }
        return $this->parsedBody = $decoded;
    }

    /**
     * Prüft, dass der Rumpf nur Felder der Positivliste enthält und (mit $requireAny) nicht leer ist.
     *
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    public function fields(array $allowed, bool $requireAny = true): array
    {
        $body = $this->body();
        $unknown = array_values(array_diff(array_map('strval', array_keys($body)), $allowed));
        if ($unknown !== []) {
            throw ApiException::validation(
                sprintf('Unbekannte Felder: %s.', implode(', ', $unknown)),
                ['unknown' => $unknown, 'allowed' => $allowed],
                'unknown_field'
            );
        }
        if ($requireAny && $body === []) {
            throw ApiException::validation('Der Anfragerumpf enthält keine Felder.', ['allowed' => $allowed], 'empty_body');
        }
        return $body;
    }
}
