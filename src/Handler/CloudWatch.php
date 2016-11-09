<?php

namespace Maxbanton\Cwh\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;

class CloudWatch extends AbstractProcessingHandler
{
    const BATCH_SIZE = 50;

    private $initialized = false;
    private $client;
    private $logGroupName;
    private $logStreamName;
    private $uploadSequenceToken;
    private $retentionDays;

    /**
     * CloudWatchHandler constructor.
     *
     * @param CloudWatchLogsClient $client
     * @param string $logGroupName
     * @param string $logStreamName
     * @param int $retentionDays
     * @param int $level
     * @param bool $bubble
     */
    public function __construct(
        CloudWatchLogsClient $client,
        $logGroupName,
        $logStreamName,
        $retentionDays = 7,
        $level = Logger::DEBUG,
        $bubble = true
    ) {

        $this->client = $client;
        $this->logGroupName = $logGroupName;
        $this->logStreamName = $logStreamName;
        $this->retentionDays = $retentionDays;

        parent::__construct($level, $bubble);
    }

    /**
     * {@inheritdoc}
     */
    protected function write(array $record)
    {
        $this->flushMessages([$record]);
    }

    /**
     * {@inheritdoc}
     */
    public function handleBatch(array $records)
    {
        foreach (array_chunk($records, self::BATCH_SIZE) as $chunk) {
            $this->handleChunk($chunk);
        }
    }

    protected function handleChunk(array $records)
    {
        $messages = [];
        foreach ($records as $record) {
            if ($record['level'] < $this->level) {
                continue;
            }

            // @FIXME: Correct way to handle logs ?
            $record = $this->processRecord($record);
            $record['formatted'] = $this->getFormatter()->format($record);

            $messages[] = $record;
        }

        if (!empty($messages)) {
            $this->flushMessages($messages);
        }
    }

    protected function flushMessages($messages)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        foreach ($messages as $message) {
            $events[] = [
                'message' => $message['formatted'],
                'timestamp' => round(microtime(true) * 1000),
            ];
        }

        $data = [
            'logGroupName' => $this->logGroupName,
            'logStreamName' => $this->logStreamName,
            'logEvents' => $events
        ];

        if ($this->uploadSequenceToken) {
            $data['sequenceToken'] = $this->uploadSequenceToken;
        }

        // put log to AWS
        $response = $this->client->putLogEvents($data);

        // update sequence token
        $this->uploadSequenceToken = $response->get('nextSequenceToken');
    }

    private function initialize()
    {
        // fetch existing groups
        $existingGroups = $this
            ->client
            ->describeLogGroups(
                [
                    'logGroupNamePrefix' => $this->logGroupName
                ]
            )
            ->get('logGroups');

        // extract existing groups names
        $existingGroupsNames = array_map(
            function ($group) {
                return $group['logGroupName'];
            },
            $existingGroups
        );

        // create group and set retention policy if not created yet
        if (!in_array($this->logGroupName, $existingGroupsNames, true)) {
            $this
                ->client
                ->createLogGroup(
                    [
                        'logGroupName' => $this->logGroupName,
                    ]
                );
            $this
                ->client
                ->putRetentionPolicy(
                    [
                        'logGroupName' => $this->logGroupName,
                        'retentionInDays' => $this->retentionDays,
                    ]
                );
        }

        // fetch existing streams
        $existingStreams = $this
            ->client
            ->describeLogStreams(
                [
                    'logGroupName' => $this->logGroupName,
                    'logStreamNamePrefix' => $this->logStreamName,
                ]
            )
            ->get('logStreams');

        // extract existing streams names
        $existingStreamsNames = array_map(
            function ($stream) {

                // set sequence token
                if ($stream['logStreamName'] === $this->logStreamName && isset($stream['uploadSequenceToken'])) {
                    $this->uploadSequenceToken = $stream['uploadSequenceToken'];
                }

                return $stream['logStreamName'];
            },
            $existingStreams
        );

        // create stream if not created
        if (!in_array($this->logStreamName, $existingStreamsNames, true)) {
            $this
                ->client
                ->createLogStream(
                    [
                        'logGroupName' => $this->logGroupName,
                        'logStreamName' => $this->logStreamName
                    ]
                );
        }

        $this->initialized = true;
    }
}
