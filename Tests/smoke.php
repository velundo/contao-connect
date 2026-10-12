<?php

declare(strict_types=1);

/*
 * Prüfstand ohne Contao: lädt die Klassen des Bundles, bei Bedarf Ersatz für PHPUnit, PSR-Log und Doctrine DBAL
 * (PDO/SQLite) und führt alle Prüfungen in Tests/Unit aus – auch die Endpunkte gegen eine SQLite-Datenbank.
 *
 *   php Tests/smoke.php            (lokal, ohne Vendor)
 *   vendor/bin/phpunit Tests/Unit  (in einer Installation mit PHPUnit; die Endpunktprüfungen brauchen pdo_sqlite)
 */

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Velundo\\ContaoConnect\\Tests\\' => '/Tests/', 'Velundo\\ContaoConnect\\' => '/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});

foreach ([$root . '/vendor/autoload.php', dirname($root, 2) . '/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}
if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    require_once __DIR__ . '/Support/TestCaseShim.php';
}
if (!interface_exists(\Psr\Log\LoggerInterface::class)) {
    require_once __DIR__ . '/Support/PsrLogShim.php';
}
if (!class_exists(\Doctrine\DBAL\Connection::class)) {
    require_once __DIR__ . '/Support/DbalShim.php';
}
require_once __DIR__ . '/Support/TestSite.php';
date_default_timezone_set('Europe/Berlin');

$files = glob($root . '/Tests/Unit/*Test.php') ?: [];
sort($files);
$passed = 0;
$failed = 0;
foreach ($files as $file) {
    require_once $file;
    $class = 'Velundo\\ContaoConnect\\Tests\\Unit\\' . basename($file, '.php');
    if (!class_exists($class)) {
        echo "FEHLT  Klasse $class in $file\n";
        $failed++;
        continue;
    }
    $reflection = new ReflectionClass($class);
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }
        $name = $reflection->getShortName() . '::' . $method->getName();
        try {
            $instance = $reflection->hasMethod('runShim') ? $reflection->newInstance() : $reflection->newInstance($method->getName());
            if (method_exists($instance, 'runShim')) {
                $instance->runShim($method->getName());
            } else {
                $setUp = $reflection->getMethod('setUp');
                $setUp->invoke($instance);
                $instance->{$method->getName()}();
            }
            $passed++;
        } catch (\Throwable $e) {
            $failed++;
            echo "FEHLER $name: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        }
    }
}
echo sprintf("%d Prüfungen bestanden, %d fehlgeschlagen (PHP %s)\n", $passed, $failed, PHP_VERSION);
exit($failed === 0 ? 0 : 1);
