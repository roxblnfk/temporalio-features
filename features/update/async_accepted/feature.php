<?php

declare(strict_types=1);

namespace Harness\Feature\Update\AsyncAccepted;

use Harness\Attribute\Check;
use Harness\Attribute\Stub;
use Ramsey\Uuid\Uuid;
use Temporal\Client\Update\LifecycleStage;
use Temporal\Client\Update\UpdateHandle;
use Temporal\Client\Update\UpdateOptions;
use Temporal\Client\WorkflowStubInterface;
use Temporal\Exception\Client\TimeoutException;
use Temporal\Exception\Client\WorkflowUpdateException;
use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use Webmozart\Assert\Assert;

#[WorkflowInterface]
class FeatureWorkflow
{
    private bool $done = false;
    private bool $blocked = true;

    #[WorkflowMethod('Workflow')]
    public function run()
    {
        yield Workflow::await(fn(): bool => $this->done);
        return 'Hello, World!';
    }

    #[Workflow\SignalMethod('finish')]
    public function finish()
    {
        $this->done = true;
    }

    #[Workflow\SignalMethod('unblock')]
    public function unblock()
    {
        $this->blocked = false;
    }

    #[Workflow\UpdateMethod('my_update')]
    public function myUpdate(bool $block)
    {
        if ($block) {
            yield Workflow::await(fn(): bool => !$this->blocked);
            $this->blocked = true;
            return 123;
        }

        throw new ApplicationFailure('Dying on purpose', 'my_update', true);
    }
}

class FeatureChecker
{
    #[Check]
    public function check(
        #[Stub('Workflow')] WorkflowStubInterface $stub,
    ): void {
        $updateId = Uuid::uuid4()->toString();
        # Issue async update
        $handle = $stub->startUpdate(
            UpdateOptions::new('my_update', LifecycleStage::StageAccepted)
                ->withUpdateId($updateId),
            true,
        );

        $this->assertHandleIsBlocked($handle);
        // Create a separate handle to the same update
        $otherHandle = $stub->getUpdateHandle($updateId);
        $this->assertHandleIsBlocked($otherHandle);

        # Unblock last update
        $stub->signal('unblock');
        Assert::same($handle->getResult(), 123);
        Assert::same($otherHandle->getResult(), 123);

        # issue an async update that should throw
        $updateId = Uuid::uuid4()->toString();
        try {
            $stub->startUpdate(
                UpdateOptions::new('my_update', LifecycleStage::StageCompleted)
                    ->withUpdateId($updateId),
                false,
            );
            throw new \RuntimeException('Expected ApplicationFailure.');
        } catch (WorkflowUpdateException $e) {
            Assert::contains($e->getPrevious()->getMessage(), 'Dying on purpose');
            Assert::same($e->getUpdateId(), $updateId);
            Assert::same($e->getUpdateName(), 'my_update');
        }
    }

    private function assertHandleIsBlocked(UpdateHandle $handle): void
    {
        try {
            // Check there is no result
            $handle->getEncodedValues(1.5);
            throw new \RuntimeException('Expected Timeout Exception.');
        } catch (TimeoutException) {
            // Expected
        }
    }
}
