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
        public string $number = '';
        public string $callerid = '';
        public ?Users $Users = null;

        /** @param array<string, mixed> $parameters */
        public static function findFirst(array $parameters = []): ?Extensions
        {
            return self::$byNumber[(string)($parameters['bind']['number'] ?? '')] ?? null;
        }

        /** The staff roster the identity guard reads. @return array<int, Extensions> */
        public static function find(array $parameters = []): array
        {
            return array_values(self::$byNumber);
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
    $employee->number = '206';
    $employee->Users = new Users();
    $employee->Users->username = 'Ефимова Юлия';
    $calleridOnly = new Extensions();
    $calleridOnly->number = '207';
    $calleridOnly->callerid = 'Отдел продаж';
    Extensions::$byNumber = ['206' => $employee, '207' => $calleridOnly];

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
    expectPartyName(
        '207 (Отдел продаж)',
        $service->numberWithEmployeeName('207', 'spoofed'),
        'An extension without a user must fall back to its caller id, not the CDR name'
    );

    // Asterisk puts the dialling extension's number on the customer's leg of
    // an outgoing call, and that employee's own name on a callback leg. Either
    // would label the customer with the operator.
    expectPartyName(
        '79263977535',
        $service->numberWithEmployeeName('79263977535', '206'),
        "An extension number on the customer's leg never labels the customer"
    );
    expectPartyName(
        '79611359896',
        $service->numberWithEmployeeName('79611359896', 'Ефимова Юлия'),
        "An employee's own name on an external leg never labels the customer"
    );
    expectPartyName(
        '79611359896',
        $service->numberWithEmployeeName('79611359896', 'ефимова юлия'),
        'The staff check ignores letter case'
    );
    expectPartyName(
        '74957654321',
        $service->numberWithEmployeeName('74957654321', 'Отдел продаж'),
        'An extension caller id is a staff identity too'
    );

    // A genuine external identity is untouched.
    expectPartyName(
        '78612059222 (Yug-Agro)',
        $service->numberWithEmployeeName('78612059222', 'Yug-Agro'),
        'A genuine external identity still labels the customer'
    );

    echo "Call party name resolution OK\n";
}
