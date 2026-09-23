<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\ChatMatcher;

$cases = [
    ['ID numérico', ChatMatcher::identifiers(-1001234567890), '-1001234567890', true],
    ['username com @', ChatMatcher::identifiers(-1001234567890, ['Chat' => ['username' => 'NoticiasOficiais']]), '@noticiasoficiais', true],
    ['username sem @', ChatMatcher::identifiers(-1001234567890, ['Chat' => ['username' => 'NoticiasOficiais']]), 'NoticiasOficiais', true],
    ['nome público do canal', ChatMatcher::identifiers(-1001234567890, ['Chat' => ['title' => ' Notícias Oficiais ']]), 'notícias oficiais', true],
    ['URL pública t.me', ChatMatcher::identifiers(-1001234567890, ['Chat' => ['username' => 'NoticiasOficiais']]), 'https://t.me/NoticiasOficiais', true],
    ['case insensitive', ChatMatcher::identifiers(-1001234567890, ['Chat' => ['title' => 'Alertas VIP']]), 'ALERTAS VIP', true],
    ['chat diferente', ChatMatcher::identifiers(-1001234567890, ['Chat' => ['username' => 'NoticiasOficiais']]), '@outrochat', false],
    ['gatilho não faz parte do matcher', ChatMatcher::identifiers(-1001234567890, ['Chat' => ['username' => 'NoticiasOficiais']]), '-100987654321', false],
];

$failed = 0;
foreach ($cases as [$name, $identifiers, $wanted, $expected]) {
    $actual = ChatMatcher::matches($identifiers, $wanted);
    $ok = $actual === $expected;
    printf("%s %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (!$ok) $failed++;
}

exit($failed === 0 ? 0 : 1);
