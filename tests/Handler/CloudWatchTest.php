<?php

namespace Maxbanton\Cwh\Test\Handler;


use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Result;
use Maxbanton\Cwh\Handler\CloudWatch;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class CloudWatchTest extends TestCase
{

    /**
     * @var MockObject | CloudWatchLogsClient
     */
    private $clientMock;

    /**
     * @var MockObject | Result
     */
    private $awsResultMock;

    /**
     * @var string
     */
    private $groupName = 'group';

    /**
     * @var string
     */
    private $streamName = 'stream';

    protected function setUp(): void
    {
        $this->clientMock =
            $this
                ->getMockBuilder(CloudWatchLogsClient::class)
                ->addMethods(
                    [
                        'describeLogStreams',
                        'createLogStream',
                        'putLogEvents'
                    ]
                )
                ->disableOriginalConstructor()
                ->getMock();
    }

    public function testInitializeWithExistingStream()
    {
        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName,
                    'uploadSequenceToken' => '49559307804604887372466686181995921714853186581450198322'
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this
            ->clientMock
            ->expects($this->never())
            ->method('createLogStream');

        $handler = $this->getCUT();

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeWithMissingStream()
    {
        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName . 'bar',
                    'uploadSequenceToken' => '49559307804604887372466686181995921714853186581450198324'
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName
            ]);

        $handler = $this->getCUT();

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testLimitExceeded()
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CloudWatch($this->clientMock, 'a', 'b', 14, 10001));
    }

    public function testSendsOnClose()
    {
        $this->prepareMocks();

        $this
            ->clientMock
            ->expects($this->once())
            ->method('putLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = $this->getCUT(1);

        $handler->handle($this->getRecord(Level::Debug));

        $handler->close();
    }

    public function testSendsBatches()
    {
        $this->prepareMocks();

        $this
            ->clientMock
            ->expects($this->exactly(2))
            ->method('putLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = $this->getCUT(3);

        foreach ($this->getMultipleRecords() as $record) {
            $handler->handle($record);
        }

        $handler->close();
    }

    public function testFormatter()
    {
        $handler = $this->getCUT();

        $formatter = $handler->getFormatter();

        $expected = new LineFormatter("%channel%: %level_name%: %message% %context% %extra%", null, false, true);

        $this->assertEquals($expected, $formatter);
    }

    private function prepareMocks()
    {
        $logStreamResult = new Result([
            'logStreams' => [
                [
                    'logStreamName' => $this->streamName,
                    'uploadSequenceToken' => '49559307804604887372466686181995921714853186581450198322'
                ]
            ]
        ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this->awsResultMock =
            $this
                ->getMockBuilder(Result::class)
                ->onlyMethods(['get'])
                ->disableOriginalConstructor()
                ->getMock();
    }

    public function testSortsEntriesChronologically()
    {
        $this->prepareMocks();

        $this
            ->clientMock
            ->expects($this->once())
            ->method('putLogEvents')
            ->willReturnCallback(function (array $data) {
                $this->assertStringContainsString('record1', $data['logEvents'][0]['message']);
                $this->assertStringContainsString('record2', $data['logEvents'][1]['message']);
                $this->assertStringContainsString('record3', $data['logEvents'][2]['message']);
                $this->assertStringContainsString('record4', $data['logEvents'][3]['message']);

                return $this->awsResultMock;
            });

        $handler = $this->getCUT(4);

        // created with chronological timestamps:
        $records = [];

        for ($i = 1; $i <= 4; ++$i) {
            $datetime = \DateTimeImmutable::createFromFormat('U', (string)(time() + $i));
            $record = new LogRecord(
                $datetime,
                'test',
                Level::Info,
                'record' . $i,
                [],
                []
            );
            $records[] = $record;
        }

        // but submitted in a different order:
        $handler->handle($records[2]);
        $handler->handle($records[0]);
        $handler->handle($records[3]);
        $handler->handle($records[1]);

        $handler->close();
    }

    public function testSendsBatchesSpanning24HoursOrLess()
    {
        $this->prepareMocks();

        $this
            ->clientMock
                ->expects($this->exactly(3))
                ->method('putLogEvents')
                ->willReturnCallback(function (array $data) {
                    /** @var int|null */
                    $earliestTime = null;

                    /** @var int|null */
                    $latestTime = null;

                    foreach ($data['logEvents'] as $logEvent) {
                        $logTimestamp = $logEvent['timestamp'];

                        if (!$earliestTime || $logTimestamp < $earliestTime) {
                            $earliestTime = $logTimestamp;
                        }

                        if (!$latestTime || $logTimestamp > $latestTime) {
                            $latestTime = $logTimestamp;
                        }
                    }

                    $this->assertNotNull($earliestTime);
                    $this->assertNotNull($latestTime);
                    $this->assertGreaterThanOrEqual($earliestTime, $latestTime);
                    $this->assertLessThanOrEqual(24 * 60 * 60 * 1000, $latestTime - $earliestTime);

                    return $this->awsResultMock;
                });

        $handler = $this->getCUT();

        // write 15 log entries spanning 3 days
        for ($i = 1; $i <= 15; ++$i) {
            $datetime = \DateTimeImmutable::createFromFormat('U', (string)(time() + $i * 5 * 60 * 60));
            $record = new LogRecord(
                $datetime,
                'test',
                Level::Info,
                'record' . $i,
                [],
                []
            );

            $handler->handle($record);
        }

        $handler->close();
    }

    private function getCUT($batchSize = 1000)
    {
        return new CloudWatch($this->clientMock, $this->groupName, $this->streamName, 14, $batchSize);
    }

    /**
     * @param Level $level
     * @param string $message
     * @param array $context
     * @return LogRecord
     */
    private function getRecord(Level $level = Level::Warning, string $message = 'test', array $context = []): LogRecord
    {
        return new LogRecord(
            \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true))),
            'test',
            $level,
            $message,
            $context,
            []
        );
    }

    /**
     * @return LogRecord[]
     */
    private function getMultipleRecords(): array
    {
        return [
            $this->getRecord(Level::Debug, 'debug message 1'),
            $this->getRecord(Level::Debug, 'debug message 2'),
            $this->getRecord(Level::Info, 'information'),
            $this->getRecord(Level::Warning, 'warning'),
            $this->getRecord(Level::Error, 'error'),
        ];
    }
}
