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

    // Caller-name lookups that found nothing return only their source label.
    // These are the exact shapes a production station records.
    expectCdrDisplayName('', CdrDisplayName::forNumber('ALL:', '74733003008'), 'A lookup label with no payload names nobody');
    expectCdrDisplayName('', CdrDisplayName::forNumber('1C:ALL:', '74733003008'), 'Stacked empty labels name nobody');
    expectCdrDisplayName('', CdrDisplayName::forNumber('SBIS:', '74733003008'), 'The rule is not tied to one provider label');
    expectCdrDisplayName('', CdrDisplayName::forNumber('TEL: ', '74733003008'), 'Trailing space does not hide an empty payload');

    // A label that did resolve a name keeps it: only the payload was missing above.
    expectCdrDisplayName('ALL:SP INVEST', CdrDisplayName::forNumber('ALL:SP INVEST', '79031557353'), 'A label with a payload is a real identity');
    expectCdrDisplayName('1C:Akvatar OOO', CdrDisplayName::forNumber('1C:Akvatar OOO', '74957654321'), 'A stacked label with a payload is kept');

    echo "CDR display name OK\n";
}
