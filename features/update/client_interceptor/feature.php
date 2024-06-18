<?php

declare(strict_types=1);

namespace Harness\Feature\Update\ClientInterceptor;

use Harness\Attribute\Check;
use Harness\Attribute\Client;
use Harness\Attribute\Stub;
use Temporal\Client\WorkflowStubInterface;
use Temporal\DataConverter\EncodedValues;
use Temporal\Interceptor\PipelineProvider;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Interceptor\Trait\WorkflowClientCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowClient\StartUpdateOutput;
use Temporal\Interceptor\WorkflowClient\UpdateInput;
use Temporal\Interceptor\WorkflowClientCallsInterceptor;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use Webmozart\Assert\Assert;

#[WorkflowInterface]
class FeatureWorkflow
{
    private bool $done = false;

    #[WorkflowMethod('Workflow')]
    public function run()
    {
        yield Workflow::await(fn(): bool => $this->done);
        return 'Hello, World!';
    }

    #[Workflow\UpdateMethod('my_update')]
    public function myUpdate(int $arg): int
    {
        $this->done = true;
        return $arg;
    }
}

class Interceptor implements WorkflowClientCallsInterceptor
{
    use WorkflowClientCallsInterceptorTrait;

    public function update(UpdateInput $input, callable $next): StartUpdateOutput
    {
        if ($input->updateName !== 'my_update') {
            return $next($input);
        }

        $rg = $input->arguments->getValue(0);

        return $next($input->with(arguments: EncodedValues::fromValues([$rg + 1])));
    }
}

class FeatureChecker
{
    public function pipelineProvider(): PipelineProvider
    {
        return new SimplePipelineProvider([new Interceptor()]);
    }

    #[Check]
    public static function check(
        #[Stub('Workflow')]
        #[Client(pipelineProvider: [FeatureChecker::class, 'pipelineProvider'])]
        WorkflowStubInterface $stub,
    ): void {
        $updated = $stub->update('my_update', 1)->getValue(0);
        Assert::same($updated, 2);
        $stub->getResult();
    }
}
