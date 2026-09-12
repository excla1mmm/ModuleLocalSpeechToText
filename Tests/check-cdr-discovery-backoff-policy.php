<?php

declare(strict_types=1);

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\Services\CdrDiscoveryBackoffPolicy;

    require_once __DIR__ . '/../Lib/Services/CdrDiscoveryBackoffPolicy.php';

    function expectBackoffPolicy(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
            );
        }
    }

    expectBackoffPolicy(15, CdrDiscoveryBackoffPolicy::initialDelaySeconds(), 'Initial delay must be the 15 second base');
    expectBackoffPolicy(15, CdrDiscoveryBackoffPolicy::BASE_DELAY_SECONDS, 'Base delay constant must be 15 seconds');

    // Full idle escalation sequence: 15 -> 30 -> 60 -> 120 -> 180 -> 180 (holds).
    $delay = CdrDiscoveryBackoffPolicy::initialDelaySeconds();
    $sequence = [$delay];
    for ($i = 0; $i < 6; $i++) {
        $delay = CdrDiscoveryBackoffPolicy::delayAfterIdle($delay);
        $sequence[] = $delay;
    }
    expectBackoffPolicy([15, 30, 60, 120, 180, 180, 180], $sequence, 'Idle backoff must follow 15/30/60/120/180 and hold at 180');

    expectBackoffPolicy(15, CdrDiscoveryBackoffPolicy::delayAfterActivity(), 'Activity must always reset the delay to 15 seconds');
    expectBackoffPolicy(
        15,
        CdrDiscoveryBackoffPolicy::delayAfterActivity(),
        'Activity reset must be independent of the current delay'
    );

    // Reset from deep backoff back to 15 on activity.
    $deepDelay = 180;
    for ($i = 0; $i < 4; $i++) {
        $deepDelay = CdrDiscoveryBackoffPolicy::delayAfterIdle($deepDelay);
    }
    expectBackoffPolicy(180, $deepDelay, 'Repeated idle at the ceiling must stay at 180');
    expectBackoffPolicy(15, CdrDiscoveryBackoffPolicy::delayAfterActivity(), 'Activity after reaching the ceiling must reset to 15');

    // Unknown/corrupt current delay values must fail safe to the base interval.
    expectBackoffPolicy(15, CdrDiscoveryBackoffPolicy::delayAfterIdle(0), 'Unknown current delay must fail safe to 15 seconds');
    expectBackoffPolicy(15, CdrDiscoveryBackoffPolicy::delayAfterIdle(-5), 'Negative current delay must fail safe to 15 seconds');
    expectBackoffPolicy(15, CdrDiscoveryBackoffPolicy::delayAfterIdle(999), 'Out-of-sequence current delay must fail safe to 15 seconds');

    echo "CDR discovery backoff policy OK\n";
}
