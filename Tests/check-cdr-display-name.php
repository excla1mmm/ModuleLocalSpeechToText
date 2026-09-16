<?php

declare(strict_types=1);

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\Services\CdrDisplayName;

    require_once __DIR__ . '/../Lib/Services/CdrDisplayName.php';

    function expectCdrDisplayName(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
            );
        }
    }

    // The row shape a station actually stores: the phone book names the caller.
    expectCdrDisplayName(
        'ALL:SP INVEST',
        CdrDisplayName::forNumber('ALL:SP INVEST', '79031557353'),
        'A phone book name must be used as the display name'
    );
    expectCdrDisplayName(
        'Ефимова Юлия',
        CdrDisplayName::forNumber('Ефимова Юлия', '206'),
        'An employee name recorded by Asterisk must be used as the display name'
    );

    // Asterisk routinely sets CALLERID(name) to the number itself; repeating it
    // next to the number would be noise, not an identity.
    expectCdrDisplayName('', CdrDisplayName::forNumber('79990000000', '79990000000'), 'A name equal to its number is not an identity');
    expectCdrDisplayName('', CdrDisplayName::forNumber(' 79990000000 ', '79990000000'), 'Padding must not hide a name that repeats its number');
    expectCdrDisplayName('', CdrDisplayName::forNumber('79990000000', ' 79990000000 '), 'A padded number must still match its own name');

    // Nothing to show stays nothing to show.
    expectCdrDisplayName('', CdrDisplayName::forNumber('', '206'), 'An empty name resolves to no display name');
    expectCdrDisplayName('', CdrDisplayName::forNumber('   ', '206'), 'A blank name resolves to no display name');

    // CALLERID(name) on an inbound call is chosen by the remote side, so it is
    // untrusted display text.
    expectCdrDisplayName(
        'Acme Corp',
        CdrDisplayName::forNumber("  Acme\r\n\tCorp  ", '79991112233'),
        'Control characters and padding must collapse to single spaces'
    );
    $overlong = CdrDisplayName::forNumber(str_repeat('я', 200), '79991112233');
    expectCdrDisplayName(121, mb_strlen($overlong), 'An overlong name must be truncated to the cap plus an ellipsis');
    expectCdrDisplayName(true, str_ends_with($overlong, '…'), 'A truncated name must be marked as truncated');
    expectCdrDisplayName(
        120,
        mb_strlen(CdrDisplayName::forNumber(str_repeat('я', 120), '79991112233')),
        'A name exactly at the cap must not be truncated'
    );

    echo "CDR display name OK\n";
}
