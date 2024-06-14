<?php

declare(strict_types=1);

namespace Harness\Feature\Schedule\Trigger;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Harness\Attribute\Check;
use Harness\Runtime\Feature;
use Harness\Runtime\State;
use Temporal\Client\Schedule\Action\StartWorkflowAction;
use Temporal\Client\Schedule\Schedule;
use Temporal\Client\Schedule\ScheduleOptions;
use Temporal\Client\Schedule\Spec\ScheduleSpec;
use Temporal\Client\Schedule\Spec\ScheduleState;
use Temporal\Client\ScheduleClientInterface;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
class FeatureWorkflow
{
    #[WorkflowMethod('Workflow')]
    public function run(string $arg)
    {
        return $arg;
    }
}

class FeatureChecker
{
    #[Check]
    public static function check(
        ScheduleClientInterface $client,
        Feature $feature,
        State $runtime,
    ): void {
        $handle = $client->createSchedule(
            schedule: Schedule::new()
                ->withAction(StartWorkflowAction::new('Workflow')
                    ->withTaskQueue($feature->taskQueue)
                    ->withInput(['arg1']))
                ->withSpec(ScheduleSpec::new()->withIntervalList(CarbonInterval::minute(1)))
                ->withState(ScheduleState::new()->withPaused(true)),
            options: ScheduleOptions::new()->withNamespace($runtime->namespace),
        );

        try {
            $handle->trigger();
            // We have to wait before triggering again. See
            // https://github.com/temporalio/temporal/issues/3614
            \sleep(2);

            $handle->trigger();

            // Wait for completion
            $deadline = CarbonImmutable::now()->addSeconds(10);
            while ($handle->describe()->info->numActions < 2) {
                CarbonImmutable::now() < $deadline or throw new \Exception('Workflow did not complete');
                \usleep(100_000);
            }
        } finally {
            $handle->delete();
        }
    }
}
