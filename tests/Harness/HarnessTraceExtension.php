<?php

namespace Harness;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Finished as TestFinishedEvent;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\Skipped as TestSkippedEvent;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished as ExecutionFinishedEvent;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit 11 事件系统扩展（rails TraceReporter 的 PHPUnit 移植）。
 *
 * 仅当 TRACE_MAP=1（suite trace_env 注入）时激活：普通跑测试不写 .state/trace.json
 * （不脏工作树）。注册于 phpunit.xml <extensions>。
 */
final class HarnessTraceExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (getenv('TRACE_MAP') !== '1') {
            return;
        }

        TraceCollector::activate((string) getcwd());

        $facade->registerSubscriber(new class implements FinishedSubscriber
        {
            public function notify(TestFinishedEvent $event): void
            {
                $test = $event->test();

                if ($test instanceof TestMethod) {
                    TraceCollector::record($test->className(), $test->methodName(), false);
                }
            }
        });

        $facade->registerSubscriber(new class implements SkippedSubscriber
        {
            public function notify(TestSkippedEvent $event): void
            {
                $test = $event->test();

                if ($test instanceof TestMethod) {
                    TraceCollector::record($test->className(), $test->methodName(), true);
                }
            }
        });

        $facade->registerSubscriber(new class implements ExecutionFinishedSubscriber
        {
            public function notify(ExecutionFinishedEvent $event): void
            {
                TraceCollector::flush();
            }
        });
    }
}
