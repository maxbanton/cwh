<?php

namespace Maxbanton\Cwh\Test\Handler;


use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\CloudWatchLogs\Exception\CloudWatchLogsException;
use Aws\Result;
use Maxbanton\Cwh\Handler\CloudWatch;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger;
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
                        'createLogGroup',
                        'putRetentionPolicy',
                        'createLogStream',
                        'putLogEvents',
                    ]
                )
                ->disableOriginalConstructor()
                ->getMock();
    }

    private function resourceAlreadyExistsException(): CloudWatchLogsException
    {
        $exception = $this->getMockBuilder(CloudWatchLogsException::class)
            ->disableOriginalConstructor()
            ->getMock();
        $exception->method('getAwsErrorCode')->willReturn('ResourceAlreadyExistsException');

        return $exception;
    }

    public function testInitializeWithCreateGroupDisabled()
    {
        $this->clientMock->expects($this->never())->method('createLogGroup');
        $this->clientMock->expects($this->never())->method('putRetentionPolicy');

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName,
            ]);

        $handler = new CloudWatch($this->clientMock, $this->groupName, $this->streamName, 14, 10000, [], Logger::DEBUG, true, false);

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeWithExistingLogGroup()
    {
        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with(['logGroupName' => $this->groupName])
            ->willThrowException($this->resourceAlreadyExistsException());

        // Existing groups must NOT have their retention policy reapplied — that would
        // silently overwrite externally-managed retention (Terraform, manual setup).
        $this
            ->clientMock
            ->expects($this->never())
            ->method('putRetentionPolicy');

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName,
            ])
            ->willThrowException($this->resourceAlreadyExistsException());

        $handler = $this->getCUT();

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeWithTags()
    {
        $tags = [
            'applicationName' => 'dummyApplicationName',
            'applicationEnvironment' => 'dummyApplicationEnvironment'
        ];

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with([
                'logGroupName' => $this->groupName,
                'tags' => $tags
            ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName,
            ]);

        $handler = new CloudWatch($this->clientMock, $this->groupName, $this->streamName, 14, 10000, $tags);

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeWithEmptyTags()
    {
        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with(['logGroupName' => $this->groupName]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName,
            ]);

        $handler = new CloudWatch($this->clientMock, $this->groupName, $this->streamName);

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeWithMissingGroupAndStream()
    {
        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->with(['logGroupName' => $this->groupName]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('putRetentionPolicy')
            ->with([
                'logGroupName' => $this->groupName,
                'retentionInDays' => 14,
            ]);

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->with([
                'logGroupName' => $this->groupName,
                'logStreamName' => $this->streamName,
            ]);

        $handler = $this->getCUT();

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testInitializeSkipsRetentionWhenNull()
    {
        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogGroup');

        $this
            ->clientMock
            ->expects($this->never())
            ->method('putRetentionPolicy');

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogStream');

        $handler = new CloudWatch($this->clientMock, $this->groupName, $this->streamName, null);

        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testCreateLogGroupNonExistsExceptionBubbles()
    {
        // e.g. 'User is not authorized to perform logs:CreateLogGroup'
        $exception = $this->getMockBuilder(CloudWatchLogsException::class)
            ->disableOriginalConstructor()
            ->getMock();
        $exception->method('getAwsErrorCode')->willReturn('AccessDeniedException');

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogGroup')
            ->will($this->throwException($exception));

        $this->expectException(CloudWatchLogsException::class);

        $handler = $this->getCUT();
        $reflection = new \ReflectionClass($handler);
        $reflectionMethod = $reflection->getMethod('initialize');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($handler);
    }

    public function testCreateLogStreamNonExistsExceptionBubbles()
    {
        $exception = $this->getMockBuilder(CloudWatchLogsException::class)
            ->disableOriginalConstructor()
            ->getMock();
        $exception->method('getAwsErrorCode')->willReturn('AccessDeniedException');

        $this
            ->clientMock
            ->expects($this->once())
            ->method('createLogStream')
            ->will($this->throwException($exception));

        $this->expectException(CloudWatchLogsException::class);

        $handler = new CloudWatch($this->clientMock, $this->groupName, $this->streamName, 14, 10000, [], Logger::DEBUG, true, false);
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

        $handler->handle($this->getRecord(Logger::DEBUG));

        $handler->close();
    }

    public function testFlushSendsBufferedRecords()
    {
        $this->prepareMocks();

        $this
            ->clientMock
            ->expects($this->once())
            ->method('putLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = $this->getCUT(1000);

        $handler->handle($this->getRecord(Logger::DEBUG));
        $handler->flush();
    }

    public function testResetFlushesBuffer()
    {
        $this->prepareMocks();

        $this
            ->clientMock
            ->expects($this->once())
            ->method('putLogEvents')
            ->willReturn($this->awsResultMock);

        $handler = $this->getCUT(1000);

        $handler->handle($this->getRecord(Logger::DEBUG));
        $handler->reset();
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
        $this
            ->clientMock
            ->method('createLogGroup')
            ->willThrowException($this->resourceAlreadyExistsException());

        $this
            ->clientMock
            ->method('createLogStream')
            ->willThrowException($this->resourceAlreadyExistsException());

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
            $record = $this->getRecord(Logger::INFO, 'record' . $i);
            $record['datetime'] = \DateTime::createFromFormat('U', time() + $i);
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
            $record = $this->getRecord(Logger::INFO, 'record' . $i);
            $record['datetime'] = \DateTime::createFromFormat('U', time() + $i * 5 * 60 * 60);

            $handler->handle($record);
        }

        $handler->close();
    }

    private function getCUT($batchSize = 1000)
    {
        return new CloudWatch($this->clientMock, $this->groupName, $this->streamName, 14, $batchSize);
    }

    /**
     * @param int $level
     * @param string $message
     * @param array $context
     * @return array
     */
    private function getRecord($level = Logger::WARNING, $message = 'test', $context = [])
    {
        return [
            'message' => $message,
            'context' => $context,
            'level' => $level,
            'level_name' => Logger::getLevelName($level),
            'channel' => 'test',
            'datetime' => \DateTime::createFromFormat('U.u', sprintf('%.6F', microtime(true))),
            'extra' => [],
        ];
    }

    /**
     * @return array
     */
    private function getMultipleRecords()
    {
        return [
            $this->getRecord(Logger::DEBUG, 'debug message 1'),
            $this->getRecord(Logger::DEBUG, 'debug message 2'),
            $this->getRecord(Logger::INFO, 'information'),
            $this->getRecord(Logger::WARNING, 'warning'),
            $this->getRecord(Logger::ERROR, 'error'),
        ];
    }
}
