<?php

namespace Maxbanton\Cwh\Test\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Result;
use Maxbanton\Cwh\Handler\CloudWatch;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CloudWatchTest extends TestCase
{
    /** @var MockObject&CloudWatchLogsClient */
    private $clientMock;

    /** @var MockObject&Result */
    private $awsResultMock;

    private string $groupName = 'group';

    private string $streamName = 'stream';

    protected function setUp(): void
    {
        $this->clientMock = $this
            ->getMockBuilder(CloudWatchLogsClient::class)
            ->addMethods([
                'describeLogGroups',
                'createLogGroup',
                'putRetentionPolicy',
                'describeLogStreams',
                'createLogStream',
                'putLogEvents',
            ])
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testInitializeWithCreateGroupDisabled(): void
    {
        $this->clientMock
            ->expects($this->never())
            ->method('describeLogGroups');

        $this->clientMock
            ->expects($this->never())
            ->method('createLogGroup');

        $logStreamResult = new Result([
            'logStreams' => [
                ['logStreamName' => $this->streamName . 'foo'],
            ],
        ]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName,
            ]);

        $handler = new CloudWatch(
            $this->clientMock,
            $this->groupName,
            $this->streamName,
            14,
            10000,
            [],
            Level::Debug,
            true,
            false,
        );

        $this->invokeInitialize($handler);
    }

    public function testInitializeWithCreateStreamDisabled(): void
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName]]]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $this->clientMock
            ->expects($this->never())
            ->method('createLogGroup');

        $this->clientMock
            ->expects($this->never())
            ->method('describeLogStreams');

        $this->clientMock
            ->expects($this->never())
            ->method('createLogStream');

        $handler = new CloudWatch(
            $this->clientMock,
            $this->groupName,
            $this->streamName,
            14,
            10000,
            [],
            Level::Debug,
            true,
            true,
            false,
        );

        $this->invokeInitialize($handler);
    }

    public function testInitializeWithBothCreateFlagsDisabled(): void
    {
        $this->clientMock->expects($this->never())->method('describeLogGroups');
        $this->clientMock->expects($this->never())->method('createLogGroup');
        $this->clientMock->expects($this->never())->method('describeLogStreams');
        $this->clientMock->expects($this->never())->method('createLogStream');

        $handler = new CloudWatch(
            $this->clientMock,
            $this->groupName,
            $this->streamName,
            14,
            10000,
            [],
            Level::Debug,
            true,
            false,
            false,
        );

        $this->invokeInitialize($handler);
    }

    public function testInitializeWithExistingLogGroup(): void
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName]]]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $this->clientMock
            ->expects($this->never())
            ->method('createLogGroup');

        $this->clientMock
            ->expects($this->never())
            ->method('putRetentionPolicy');

        $logStreamResult = new Result([
            'logStreams' => [
                ['logStreamName' => $this->streamName],
            ],
        ]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this->clientMock
            ->expects($this->never())
            ->method('createLogStream');

        $this->invokeInitialize($this->getCUT());
    }

    public function testInitializeWithTags(): void
    {
        $tags = [
            'applicationName' => 'dummyApplicationName',
            'applicationEnvironment' => 'dummyApplicationEnvironment',
        ];

        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName . 'foo']]]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $this->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with([
                'logGroupName' => $this->groupName,
                'tags' => $tags,
            ]);

        $logStreamResult = new Result([
            'logStreams' => [
                ['logStreamName' => $this->streamName . 'foo'],
            ],
        ]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName,
            ]);

        $handler = new CloudWatch($this->clientMock, $this->groupName, $this->streamName, 14, 10000, $tags);

        $this->invokeInitialize($handler);
    }

    public function testInitializeWithEmptyTags(): void
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName . 'foo']]]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $this->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with(['logGroupName' => $this->groupName]); // empty tags array NOT included

        $logStreamResult = new Result([
            'logStreams' => [
                ['logStreamName' => $this->streamName . 'foo'],
            ],
        ]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName,
            ]);

        $handler = new CloudWatch($this->clientMock, $this->groupName, $this->streamName);

        $this->invokeInitialize($handler);
    }

    public function testInitializeWithMissingGroupAndStream(): void
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName . 'foo']]]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $this->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with(['logGroupName' => $this->groupName]);

        $this->clientMock
            ->expects($this->once())
            ->method('putRetentionPolicy')
            ->with([
                'logGroupName' => $this->groupName,
                'retentionInDays' => 14,
            ]);

        $logStreamResult = new Result([
            'logStreams' => [
                ['logStreamName' => $this->streamName . 'foo'],
            ],
        ]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName,
            ]);

        $this->invokeInitialize($this->getCUT());
    }

    public function testInitializeSkipsRetentionWhenNull(): void
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName . 'foo']]]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $this->clientMock
            ->expects($this->once())
            ->method('createLogGroup');

        $this->clientMock
            ->expects($this->never())
            ->method('putRetentionPolicy');

        $logStreamResult = new Result([
            'logStreams' => [
                ['logStreamName' => $this->streamName . 'foo'],
            ],
        ]);

        $this->clientMock
            ->expects($this->once())
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this->clientMock
            ->expects($this->once())
            ->method('createLogStream');

        $handler = new CloudWatch($this->clientMock, $this->groupName, $this->streamName, null);

        $this->invokeInitialize($handler);
    }

    public function testExceptionFromDescribeLogGroups(): void
    {
        // e.g. 'User is not authorized to perform logs:DescribeLogGroups'
        /** @var CloudWatchLogsException&MockObject $awsException */
        $awsException = $this->getMockBuilder(CloudWatchLogsException::class)
            ->disableOriginalConstructor()
            ->getMock();

        // if this fails ...
        $this->clientMock
            ->expects($this->atLeastOnce())
            ->method('describeLogGroups')
            ->will($this->throwException($awsException));

        // ... this should not be called:
        $this->clientMock
            ->expects($this->never())
            ->method('describeLogStreams');

        $this->expectException(CloudWatchLogsException::class);

        $handler = $this->getCUT(0);
        $handler->handle($this->getRecord(Level::Info));
    }

    public function testLimitExceeded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CloudWatch($this->clientMock, 'a', 'b', 14, 10001);
    }

    public function testSendsOnClose(): void
    {
        $this->prepareMocks();

        $this->clientMock
            ->expects($this->once())
            ->method('putLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = $this->getCUT(1);

        $handler->handle($this->getRecord(Level::Debug));

        $handler->close();
    }

    public function testFlushSendsBufferedRecords(): void
    {
        $this->prepareMocks();

        $this->clientMock
            ->expects($this->once())
            ->method('putLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = $this->getCUT(1000);

        $handler->handle($this->getRecord(Level::Debug));
        $handler->flush();
    }

    public function testResetFlushesBuffer(): void
    {
        $this->prepareMocks();

        $this->clientMock
            ->expects($this->once())
            ->method('putLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = $this->getCUT(1000);

        $handler->handle($this->getRecord(Level::Debug));
        $handler->reset();
    }

    public function testSendsBatches(): void
    {
        $this->prepareMocks();

        $this->clientMock
            ->expects($this->exactly(2))
            ->method('putLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = $this->getCUT(3);

        foreach ($this->getMultipleRecords() as $record) {
            $handler->handle($record);
        }

        $handler->close();
    }

    public function testFormatter(): void
    {
        $handler = $this->getCUT();

        $formatter = $handler->getFormatter();

        $expected = new LineFormatter("%channel%: %level_name%: %message% %context% %extra%", null, false, true);

        $this->assertEquals($expected, $formatter);
    }

    public function testSortsEntriesChronologically(): void
    {
        $this->prepareMocks();

        $this->clientMock
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
            $records[] = $this->getRecord(Level::Info, 'record' . $i)
                ->with(datetime: \DateTimeImmutable::createFromFormat('U', (string) (time() + $i)));
        }

        // but submitted in a different order:
        $handler->handle($records[2]);
        $handler->handle($records[0]);
        $handler->handle($records[3]);
        $handler->handle($records[1]);

        $handler->close();
    }

    public function testSendsBatchesSpanning24HoursOrLess(): void
    {
        $this->prepareMocks();

        $this->clientMock
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
            $record = $this->getRecord(Level::Info, 'record' . $i)
                ->with(datetime: \DateTimeImmutable::createFromFormat('U', (string) (time() + $i * 5 * 60 * 60)));

            $handler->handle($record);
        }

        $handler->close();
    }

    private function prepareMocks(): void
    {
        $logGroupsResult = new Result(['logGroups' => [['logGroupName' => $this->groupName]]]);

        $this->clientMock
            ->method('describeLogGroups')
            ->with(['logGroupNamePrefix' => $this->groupName])
            ->willReturn($logGroupsResult);

        $logStreamResult = new Result([
            'logStreams' => [
                ['logStreamName' => $this->streamName],
            ],
        ]);

        $this->clientMock
            ->method('describeLogStreams')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamNamePrefix' => $this->streamName,
            ])
            ->willReturn($logStreamResult);

        $this->awsResultMock = $this
            ->getMockBuilder(Result::class)
            ->onlyMethods(['get'])
            ->disableOriginalConstructor()
            ->getMock();
    }

    private function getCUT(int $batchSize = 1000): CloudWatch
    {
        return new CloudWatch($this->clientMock, $this->groupName, $this->streamName, 14, $batchSize);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function getRecord(
        Level $level = Level::Warning,
        string $message = 'test',
        array $context = [],
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: $level,
            message: $message,
            context: $context,
            extra: [],
        );
    }

    /**
     * @return list<LogRecord>
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

    private function invokeInitialize(CloudWatch $handler): void
    {
        (new \ReflectionClass($handler))->getMethod('initialize')->invoke($handler);
    }
}
