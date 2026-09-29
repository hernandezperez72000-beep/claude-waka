<?php
declare(strict_types=1);

/** Arranque y marcador de las pruebas. */

require_once dirname(__DIR__) . '/app/nucleo/arranque.php';
require_once __DIR__ . '/banco.php';

$GLOBALS['__ok'] = 0;
$GLOBALS['__mal'] = [];
$GLOBALS['__grupo'] = '';

function grupo(string $t): void
{
    $GLOBALS['__grupo'] = $t;
    echo "\n\033[1m── $t\033[0m\n";
}

function ok(string $que, bool $bien, string $detalle = ''): void
{
    if ($bien) {
        $GLOBALS['__ok']++;
        echo "  \033[32m✓\033[0m $que\n";
    } else {
        $GLOBALS['__mal'][] = $GLOBALS['__grupo'] . ' · ' . $que . ($detalle ? "\n      $detalle" : '');
        echo "  \033[31m✗ $que\033[0m" . ($detalle ? "\n      $detalle" : '') . "\n";
    }
}

function es(string $que, $esperado, $real): void
{
    $bien = $esperado === $real;
    ok($que, $bien, $bien ? '' : 'esperado: ' . var_export($esperado, true)
                            . ' · real: ' . var_export($real, true));
}

function marcador(): int
{
    $ok  = $GLOBALS['__ok'];
    $mal = $GLOBALS['__mal'];
    echo "\n" . str_repeat('─', 60) . "\n";
    if (!$mal) {
        echo "\033[32m✓ $ok pruebas, todas pasan.\033[0m\n";
        return 0;
    }
    echo "\033[31m✗ " . count($mal) . " de " . ($ok + count($mal)) . " fallan:\033[0m\n";
    foreach ($mal as $m) echo "  · $m\n";
    return 1;
}
