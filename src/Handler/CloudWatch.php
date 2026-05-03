<?php

namespace Maxbanton\Cwh\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

class CloudWatch extends AbstractProcessingHandler
{
    /**
     * Event size limit (https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/cloudwatch_limits_cwl.html)
     */
    final public const EVENT_SIZE_LIMIT = 1048550; // 1048576 (1 MB) - reserved 26 byte AWS overhead

    /**
     * The batch of log events in a single PutLogEvents request cannot span more than 24 hours.
     */
    final public const TIMESPAN_LIMIT = 86400000;

    /**
     * Data amount limit (http://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html)
     */
    private const DATA_AMOUNT_LIMIT = 1048576;

    private bool $initialized = false;

    /** @var array<int, array{message: string, timestamp: int|float}> */
    private array $buffer = [];

    private int $currentDataAmount = 0;

    private int|null $earliestTimestamp = null;

    /**
     * @param CloudWatchLogsClient $client
     *
     * Log group names must be unique within a region for an AWS account.
     * Log group names can be between 1 and 512 characters long.
     * Log group names consist of the following characters: a-z, A-Z, 0-9, '_' (underscore), '-' (hyphen),
     * '/' (forward slash), and '.' (period).
     * @param string $group
     *
     * Log stream names must be unique within the log group.
     * Log stream names can be between 1 and 512 characters long.
     * The ':' (colon) and '*' (asterisk) characters are not allowed.
     * @param string $stream
     *
     * @param int|null $retention Days to retain logs. Pass null for indefinite retention.
     * @param int $batchSize
     * @param array<string, string> $tags
     * @param int|string|Level $level
     * @param bool $bubble
     * @param bool $createGroup Whether to create the log group if it does not exist.
     * @param bool $createStream Whether to verify/create the log stream. Set to false to skip
     *                          DescribeLogStreams + CreateLogStream when the stream is provisioned
     *                          out of band (e.g. via Terraform). Drops the matching IAM permissions.
     */
    public function __construct(
        private readonly CloudWatchLogsClient $client,
        private readonly string $group,
        private readonly string $stream,
        private readonly ?int $retention = 14,
        private readonly int $batchSize = 10000,
        private readonly array $tags = [],
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly bool $createGroup = true,
        private readonly bool $createStream = true,
    ) {
        if ($this->batchSize > 10000) {
            throw new \InvalidArgumentException('Batch size can not be greater than 10000');
        }

        parent::__construct(Logger::toMonologLevel($level), $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $records = $this->formatRecords($record);

        foreach ($records as $entry) {
            if ($this->willMessageSizeExceedLimit($entry) || $this->willMessageTimestampExceedLimit($entry)) {
                $this->flushBuffer();
            }

            $this->addToBuffer($entry);

            if (count($this->buffer) >= $this->batchSize) {
                $this->flushBuffer();
            }
        }
    }

    /**
     * @param array{message: string, timestamp: int|float} $record
     */
    private function addToBuffer(array $record): void
    {
        $this->currentDataAmount += $this->getMessageSize($record);

        $timestamp = (int) $record['timestamp'];

        if (!$this->earliestTimestamp || $timestamp < $this->earliestTimestamp) {
            $this->earliestTimestamp = $timestamp;
        }

        $this->buffer[] = $record;
    }

    private function flushBuffer(): void
    {
        if (!empty($this->buffer)) {
            if (false === $this->initialized) {
                $this->initialize();
            }

            $this->send($this->buffer);

            // clear buffer
            $this->buffer = [];

            // clear the earliest timestamp
            $this->earliestTimestamp = null;

            // clear data amount
            $this->currentDataAmount = 0;
        }
    }

    /**
     * http://docs.aws.amazon.com/AmazonCloudWatchLogs/latest/APIReference/API_PutLogEvents.html
     *
     * @param array{message: string, timestamp: int|float} $record
     */
    private function getMessageSize(array $record): int
    {
        return strlen($record['message']) + 26;
    }

    /**
     * Determine whether the specified record's message size in addition to the
     * size of the current queued messages will exceed AWS CloudWatch's limit.
     *
     * @param array{message: string, timestamp: int|float} $record
     */
    protected function willMessageSizeExceedLimit(array $record): bool
    {
        return $this->currentDataAmount + $this->getMessageSize($record) >= self::DATA_AMOUNT_LIMIT;
    }

    /**
     * Determine whether the specified record's timestamp exceeds the 24 hour timespan limit
     * for all batched messages written in a single call to PutLogEvents.
     *
     * @param array{message: string, timestamp: int|float} $record
     */
    protected function willMessageTimestampExceedLimit(array $record): bool
    {
        return $this->earliestTimestamp !== null
            && $record['timestamp'] - $this->earliestTimestamp > self::TIMESPAN_LIMIT;
    }

    /**
     * Each log event can not be bigger than 1 MB.
     * https://docs.aws.amazon.com/AmazonCloudWatch/latest/logs/cloudwatch_limits_cwl.html
     *
     * @return array<int, array{message: string, timestamp: int|float}>
     */
    private function formatRecords(LogRecord $entry): array
    {
        $entries = str_split($entry->formatted, self::EVENT_SIZE_LIMIT);
        $timestamp = (int) ($entry->datetime->format('U.u') * 1000);
        $records = [];

        foreach ($entries as $chunk) {
            $records[] = [
                'message' => $chunk,
                'timestamp' => $timestamp,
            ];
        }

        return $records;
    }

    /**
     * The batch of events must satisfy the following constraints:
     *  - The maximum batch size is 1,048,576 bytes, and this size is calculated as the sum of all event messages in
     * UTF-8, plus 26 bytes for each log event.
     *  - None of the log events in the batch can be more than 2 hours in the future.
     *  - None of the log events in the batch can be older than 14 days or the retention period of the log group.
     *  - The log events in the batch must be in chronological ordered by their timestamp (the time the event occurred,
     * expressed as the number of milliseconds since Jan 1, 1970 00:00:00 UTC).
     *  - The maximum number of log events in a batch is 10,000.
     *  - A batch of log events in a single request cannot span more than 24 hours. Otherwise, the operation fails.
     *
     * @param array<int, array{message: string, timestamp: int|float}> $entries
     *
     * @throws \Aws\CloudWatchLogs\Exception\CloudWatchLogsException Thrown by putLogEvents on AWS-side errors
     *                                                               (e.g. IAM denial, persistent throttling).
     */
    private function send(array $entries): void
    {
        // AWS expects to receive entries in chronological order...
        usort($entries, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);

        $this->client->putLogEvents([
            'logGroupName' => $this->group,
            'logStreamName' => $this->stream,
            'logEvents' => $entries,
        ]);
    }

    private function initializeGroup(): void
    {
        $existingGroups = $this
            ->client
            ->describeLogGroups(['logGroupNamePrefix' => $this->group])
            ->get('logGroups');

        $existingGroupsNames = array_column($existingGroups, 'logGroupName');

        if (!in_array($this->group, $existingGroupsNames, true)) {
            $createLogGroupArguments = ['logGroupName' => $this->group];

            if (!empty($this->tags)) {
                $createLogGroupArguments['tags'] = $this->tags;
            }

            $this->client->createLogGroup($createLogGroupArguments);

            if ($this->retention !== null) {
                $this->client->putRetentionPolicy([
                    'logGroupName' => $this->group,
                    'retentionInDays' => $this->retention,
                ]);
            }
        }
    }

    private function initialize(): void
    {
        if ($this->createGroup) {
            $this->initializeGroup();
        }

        if ($this->createStream) {
            $this->initializeStream();
        }

        $this->initialized = true;
    }

    private function initializeStream(): void
    {
        $existingStreams = $this
            ->client
            ->describeLogStreams([
                'logGroupName' => $this->group,
                'logStreamNamePrefix' => $this->stream,
            ])
            ->get('logStreams');

        $existingStreamsNames = array_column($existingStreams, 'logStreamName');

        if (!in_array($this->stream, $existingStreamsNames, true)) {
            $this->client->createLogStream([
                'logGroupName' => $this->group,
                'logStreamName' => $this->stream,
            ]);
        }
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter("%channel%: %level_name%: %message% %context% %extra%", null, false, true);
    }

    public function close(): void
    {
        $this->flushBuffer();
    }

    /**
     * Flush buffered records to CloudWatch immediately.
     *
     * Useful for long-lived workers (Laravel queues, Symfony messenger,
     * PHP-FPM with persistent state) that cannot rely on close() being called.
     */
    public function flush(): void
    {
        $this->flushBuffer();
    }

    public function reset(): void
    {
        $this->flush();
        parent::reset();
    }
}
