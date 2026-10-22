<?php

declare(strict_types=1);

namespace Velundo\ContaoConnect\Service;

use Velundo\ContaoConnect\Service\Store\TokenStoreInterface;

/**
 * Zugangstokens: 32 zufällige Bytes als base64url, gespeichert nur als SHA-256-Hash (Tabelle tl_velundo_token).
 * Mehrere Tokens mit Bezeichnung, widerrufbar; Vergleich in konstanter Zeit über alle Einträge.
 * Anlegen und Widerrufen nur über die Konsole; eine Anfrage vermerkt höchstens einmal je Minute „zuletzt verwendet“.
 */
final class TokenService
{
    private const LAST_USED_INTERVAL = 60;

    /**
     * Zusatzrechte (ab 1.1.0): publish = Sichtbarschalten (published/invisible), live_edit = Titel und Texte sichtbarer
     * Datensätze ändern. Ohne Zusatzrecht ändert ein Token nur unveröffentlichte Inhalte und Metadaten.
     */
    public const SCOPES = ['publish', 'live_edit'];

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly TokenStoreInterface $store,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Erzeugt ein Token; der Klartext wird nur hier zurückgegeben.
     *
     * @param list<string> $scopes Zusatzrechte aus self::SCOPES
     * @return array{id: int, name: string, token: string, scopes: list<string>}
     */
    public function create(string $name, array $scopes = []): array
    {
        $name = trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Die Bezeichnung muss 1 bis 100 Zeichen lang sein.');
        }
        $scopes = self::normalizeScopes($scopes);
        $token = self::encode(random_bytes(32));
        $id = $this->store->insert($name, self::hash($token), ($this->clock)(), implode(',', $scopes));
        return ['id' => $id, 'name' => $name, 'token' => $token, 'scopes' => $scopes];
    }

    /**
     * Bekannte Zusatzrechte in fester Reihenfolge, ohne Doppelte; unbekannte → InvalidArgumentException.
     *
     * @param list<string> $scopes
     * @return list<string>
     */
    public static function normalizeScopes(array $scopes): array
    {
        $wanted = [];
        foreach ($scopes as $scope) {
            $scope = strtolower(trim((string)$scope));
            if ($scope === '') {
                continue;
            }
            if (!in_array($scope, self::SCOPES, true)) {
                throw new \InvalidArgumentException(sprintf('Unbekanntes Zusatzrecht „%s“ – erlaubt sind %s.', $scope, implode(', ', self::SCOPES)));
            }
            $wanted[$scope] = true;
        }
        return array_values(array_filter(self::SCOPES, static fn(string $s): bool => isset($wanted[$s])));
    }

    /**
     * Zusatzrechte eines gespeicherten Eintrags (kommagetrennt); Unbekanntes wird übergangen.
     *
     * @param array{scopes?: string} $row
     * @return list<string>
     */
    private static function scopesOf(array $row): array
    {
        $stored = array_map('trim', explode(',', (string)($row['scopes'] ?? '')));
        return array_values(array_filter(self::SCOPES, static fn(string $s): bool => in_array($s, $stored, true)));
    }

    /**
     * Alle Tokens ohne Hash (auch widerrufene).
     *
     * @return list<array{id: int, name: string, created: int, last_used: ?int, revoked: ?int, scopes: list<string>}>
     */
    public function list(): array
    {
        $result = [];
        foreach ($this->store->all() as $row) {
            $result[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'created' => $row['created'],
                'last_used' => $row['last_used'] > 0 ? $row['last_used'] : null,
                'revoked' => $row['revoked'] > 0 ? $row['revoked'] : null,
                'scopes' => self::scopesOf($row),
            ];
        }
        return $result;
    }

    public function revoke(int $id): bool
    {
        return $id > 0 && $this->store->revoke($id, ($this->clock)());
    }

    /**
     * Prüft ein Token aus dem Authorization-Kopf („Bearer …“); liefert id, Bezeichnung und Zusatzrechte oder null.
     *
     * @return array{id: int, name: string, scopes: list<string>}|null
     */
    public function authenticate(?string $authorizationHeader): ?array
    {
        $token = self::extractBearer($authorizationHeader);
        if ($token === null) {
            return null;
        }
        $hash = self::hash($token);
        $match = null;
        // Alle Einträge vergleichen, damit die Laufzeit nicht von der Position des Treffers abhängt
        foreach ($this->store->all() as $row) {
            $equal = hash_equals($row['token_hash'], $hash);
            if ($equal && $row['revoked'] === 0 && $match === null) {
                $match = $row;
            }
        }
        if ($match === null) {
            return null;
        }
        $now = ($this->clock)();
        if ($now - $match['last_used'] >= self::LAST_USED_INTERVAL) {
            $this->store->markUsed($match['id'], $now);
        }
        return ['id' => $match['id'], 'name' => $match['name'], 'scopes' => self::scopesOf($match)];
    }

    public static function extractBearer(?string $header): ?string
    {
        if ($header === null || preg_match('/^\s*Bearer\s+([A-Za-z0-9_-]{20,200})\s*$/', $header, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
