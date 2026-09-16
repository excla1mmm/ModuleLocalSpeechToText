<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    class Users
    {
        public string $username = '';
    }

    class Extensions
    {
        /** @var array<string, Extensions> */
        public static array $byNumber = [];
        public string $callerid = '';
        public ?Users $Users = null;

        /** @param array<string, mixed> $parameters */
        public static function findFirst(array $parameters = []): ?Extensions
        {
            return self::$byNumber[(string)($parameters['bind']['number'] ?? '')] ?? null;
        }
    }
}

namespace {
    use MikoPBX\Common\Models\Extensions;
    use MikoPBX\Common\Models\Users;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallRecordingService;

    require_once __DIR__ . '/../Lib/Services/CdrDisplayName.php';
    require_once __DIR__ . '/../Lib/Services/CallRecordingService.php';

    function expectPartyName(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
            );
        }
    }

    $employee = new Extensions();
    $employee->Users = new Users();
    $employee->Users->username = 'Ефимова Юлия';
    Extensions::$byNumber = ['206' => $employee];

    $service = new CallRecordingService(null, null, null, null);

    // An external counterparty is named from the CDR row, which is what the
    // client column and the call-history modal previously lacked.
    expectPartyName(
        '79031557353 (ALL:SP INVEST)',
        $service->numberWithEmployeeName('79031557353', 'ALL:SP INVEST'),
        'An external number must be labelled with its CDR display name'
    );

    // A known extension keeps the PBX name: CALLERID(name) on an inbound call
    // is chosen by the remote side and must never relabel an internal number.
    expectPartyName(
        '206 (Ефимова Юлия)',
        $service->numberWithEmployeeName('206', 'spoofed'),
        'A known extension must keep its own name over a CDR display name'
    );
    expectPartyName(
        '206 (Ефимова Юлия)',
        $service->numberWithEmployeeName('206'),
        'A known extension is still named without any CDR display name'
    );

    // Without a usable name the bare number is still printed, unchanged from
    // the behaviour before CDR names were consulted at all.
    expectPartyName(
        '79031557353',
        $service->numberWithEmployeeName('79031557353'),
        'An unnamed external number stays a bare number'
    );
    expectPartyName(
        '79990000000',
        $service->numberWithEmployeeName('79990000000', '79990000000'),
        'A CDR name repeating its own number never becomes a label'
    );
    expectPartyName(
        '79031557353',
        $service->numberWithEmployeeName('79031557353', "  \r\n  "),
        'A CDR name of only control characters never becomes a label'
    );
    expectPartyName('', $service->numberWithEmployeeName('', 'ALL:SP INVEST'), 'An empty number has no label');

    // The employee name falls back to the extension caller id.
    $calleridOnly = new Extensions();
    $calleridOnly->callerid = 'Отдел продаж';
    Extensions::$byNumber['207'] = $calleridOnly;
    expectPartyName(
        '207 (Отдел продаж)',
        $service->numberWithEmployeeName('207', 'spoofed'),
        'An extension without a user must fall back to its caller id, not the CDR name'
    );

    echo "Call party name resolution OK\n";
}
