<?php


namespace Maxbanton\Cwh\Handler;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;

class CloudWatch extends AbstractProcessingHandler
{
    private $initialized = false;
    private $client;
    private $logGroupName;
    private $logStreamName;
    private $uploadSequenceToken;
    private $retentionDays;

    /**
     * CloudWatchHandler constructor.
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
     * @param array $record
     */
    protected function write(array $record)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        $data = [
            'logGroupName' => $this->logGroupName,
            'logStreamName' => $this->logStreamName,
            'logEvents' => [
                [
                    'message' => $record['formatted'],
                    'timestamp' => round(microtime(true) * 1000),
                ]
            ]
        ];

        if ($this->uploadSequenceToken) {
            $data['sequenceToken'] = $this->uploadSequenceToken;
        }

        //TODO: put log events async
        // put log to AWS
        $response = $this->client->putLogEvents($data);

        // update sequence token
        $this->uploadSequenceToken = $response->get('nextSequenceToken');
    }

    private function initialize()
    {
        // fetch existing groups
        $existingGroups =
            $this
                ->client
                ->describeLogGroups([
                    'logGroupNamePrefix' => $this->logGroupName,
                    'limit' => 50,
                ])
                ->get('logGroups');

        // extract existing groups names
        $existingGroupsNames = array_map(function ($group) {
            return $group['logGroupName'];
        }, $existingGroups);

        // create group and set retention policy if not created yet
        if (!in_array($this->logGroupName, $existingGroupsNames, true)) {
            $this
                ->client
                ->createLogGroup([
                    'logGroupName' => $this->logGroupName,
                ]);
            $this
                ->client
                ->putRetentionPolicy([
                    'logGroupName' => $this->logGroupName,
                    'retentionInDays' => $this->retentionDays,
                ]);
        }

        // fetch existing streams
        $existingStreams =
            $this
                ->client
                ->describeLogStreams([
                    'logGroupName' => $this->logGroupName,
                    'logStreamNamePrefix' => $this->logStreamName,
                    'limit' => 50,
                ])
                ->get('logStreams');

        // extract existing streams names
        $existingStreamsNames = array_map(function ($stream) {

            // set sequence token
            if ($stream['logStreamName'] === $this->logStreamName && isset($stream['uploadSequenceToken'])) {
                $this->uploadSequenceToken = $stream['uploadSequenceToken'];
            }

            return $stream['logStreamName'];
        }, $existingStreams);

        // create stream if not created
        if (!in_array($this->logStreamName, $existingStreamsNames, true)) {
            $this
                ->client
                ->createLogStream([
                    'logGroupName' => $this->logGroupName,
                    'logStreamName' => $this->logStreamName
                ]);
        }

        $this->initialized = true;
    }
}
