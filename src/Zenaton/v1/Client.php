<?php

namespace Zenaton;

use Zenaton\Exceptions\AgentException;
use Zenaton\Exceptions\AgentNotListeningException;
use Zenaton\Exceptions\AgentUpdateRequiredException;
use Zenaton\Exceptions\InvalidArgumentException;
use Zenaton\Interfaces\EventInterface;
use Zenaton\Interfaces\TaskInterface;
use Zenaton\Interfaces\WorkflowInterface;
use Zenaton\Services\Http;
use Zenaton\Services\Properties;
use Zenaton\Services\Serializer;
use Zenaton\Traits\SingletonTrait;
use Zenaton\Workflows\Version;

class Client
{
    use SingletonTrait;

    const ZENATON_API_URL = 'https://zenaton.com/api/v1';
    const ZENATON_WORKER_URL = 'http://localhost';
    const DEFAULT_WORKER_PORT = 4001;
    const WORKER_API_VERSION = 'v_newton';

    const MAX_ID_SIZE = 256;

    const APP_ENV = 'app_env';
    const APP_ID = 'app_id';
    const API_TOKEN = 'api_token';

    const ATTR_ID = 'custom_id';
    const ATTR_NAME = 'name';
    const ATTR_CANONICAL = 'canonical_name';
    const ATTR_DATA = 'data';
    const ATTR_PROG = 'programming_language';
    const ATTR_MODE = 'mode';
    const ATTR_MAX_PROCESSING_TIME = 'maxProcessingTime';

    const PROG = 'PHP';

    const EVENT_INPUT = 'event_input';
    const EVENT_NAME = 'event_name';

    const WORKFLOW_KILL = 'kill';
    const WORKFLOW_PAUSE = 'pause';
    const WORKFLOW_RUN = 'run';

    protected $appId;
    protected $apiToken;
    protected $appEnv;
    /** @var Http */
    protected $http;
    /** @var Serializer */
    protected $serializer;
    /** @var Properties */
    protected $properties;

    public static function init($appId, $apiToken, $appEnv)
    {
        Client::getInstance()
          ->setAppId($appId)
          ->setApiToken($apiToken)
          ->setAppEnv($appEnv);
    }

    public function construct()
    {
        $this->http = new Http();
        $this->serializer = new Serializer();
        $this->properties = new Properties();
    }

    public function setAppId($appId)
    {
        $this->appId = $appId;

        return $this;
    }

    public function setApiToken($apiToken)
    {
        $this->apiToken = $apiToken;

        return $this;
    }

    public function setAppEnv($appEnv)
    {
        $this->appEnv = $appEnv;

        return $this;
    }

    public function getWorkerUrl($ressources = '', $params = '')
    {
        $url = (getenv('ZENATON_WORKER_URL') ?: self::ZENATON_WORKER_URL)
            .':'.(getenv('ZENATON_WORKER_PORT') ?: self::DEFAULT_WORKER_PORT)
            .'/api/'.self::WORKER_API_VERSION
            .'/'.$ressources.'?';

        return $this->addAppEnv($url, $params);
    }

    public function getWebsiteUrl($ressources = '', $params = '')
    {
        $url = (getenv('ZENATON_API_URL') ?: self::ZENATON_API_URL)
            .'/'.$ressources.'?'
            .self::API_TOKEN.'='.$this->apiToken.'&';

        return $this->addAppEnv($url, $params);
    }

    /**
     * Start a single task.
     *
     * @throws AgentNotListeningException   If the agent is not listening to the application
     * @throws AgentUpdateRequiredException If the agent does not have the minimum required version
     * @throws AgentException               For any other error coming from the agent
     */
    public function startTask(TaskInterface $task)
    {
        $response = $this->http->post($this->getTaskWorkerUrl(), [
            self::ATTR_PROG => self::PROG,
            self::ATTR_NAME => get_class($task),
            self::ATTR_DATA => $this->serializer->encode($this->properties->getPropertiesFromObject($task)),
            self::ATTR_MAX_PROCESSING_TIME => method_exists($task, 'getMaxProcessingTime') ? $task->getMaxProcessingTime() : null,
        ]);

        if ($response->hasErrors()) {
            if (strpos($response->body->error, 'Your worker does not listen') !== false) {
                throw new AgentNotListeningException($this->appId, $this->appEnv);
            }

            if (strpos($response->body->error, 'Unknown version') !== false) {
                throw new AgentUpdateRequiredException('>=0.5.0');
            }

            throw new AgentException($response->body->error);
        }
    }

    /**
     * Start a workflow instance.
     *
     * @param WorkflowInterface $flow Workflow to start
     */
    public function startWorkflow(WorkflowInterface $flow)
    {
        $canonical = null;
        // if $flow is a versionned workflow
        if ($flow instanceof Version) {
            // store canonical name
            $canonical = get_class($flow);
            // replace by true current implementation
            $flow = $flow->getCurrentImplementation();
        }

        // custom id management
        $customId = null;
        if (method_exists($flow, 'getId')) {
            $customId = $flow->getId();
            if (!is_string($customId) && !is_int($customId)) {
                throw new InvalidArgumentException('Provided Id must be a string or an integer');
            }
            // at the end, it's a string
            $customId = (string) $customId;
            // should be not more than 256 bytes;
            if (strlen($customId) > self::MAX_ID_SIZE) {
                throw new InvalidArgumentException('Provided Id must not exceed '.self::MAX_ID_SIZE.' bytes');
            }
        }

        // start workflow
        $this->http->post($this->getInstanceWorkerUrl(), [
            self::ATTR_PROG => self::PROG,
            self::ATTR_CANONICAL => $canonical,
            self::ATTR_NAME => get_class($flow),
            self::ATTR_DATA => $this->serializer->encode($this->properties->getPropertiesFromObject($flow)),
            self::ATTR_ID => $customId,
        ]);
    }

    /**
     * Kill a workflow instance.
     *
     * @param string $workflowName Workflow class name
     * @param string $customId     Provided custom id
     */
    public function killWorkflow($workflowName, $customId)
    {
        $this->updateInstance($workflowName, $customId, self::WORKFLOW_KILL);
    }

    /**
     * Pause a workflow instance.
     *
     * @param string $workflowName Workflow class name
     * @param string $customId     Provided custom id
     */
    public function pauseWorkflow($workflowName, $customId)
    {
        $this->updateInstance($workflowName, $customId, self::WORKFLOW_PAUSE);
    }

    /**
     * Resume a workflow instance.
     *
     * @param string $workflowName Workflow class name
     * @param string $customId     Provided custom id
     */
    public function resumeWorkflow($workflowName, $customId)
    {
        $this->updateInstance($workflowName, $customId, self::WORKFLOW_RUN);
    }

    /**
     * Find a workflow instance.
     *
     * @param string $workflowName Workflow class name
     * @param string $customId     Provided custom id
     *
     * @return null|WorkflowInterface
     */
    public function findWorkflow($workflowName, $customId)
    {
        $params =
            self::ATTR_ID.'='.$customId.'&'.
            self::ATTR_NAME.'='.$workflowName.'&'.
            self::ATTR_PROG.'='.self::PROG;

        // TODO : Have a better error handling by introducing an object between Client and Http that will
        // return domain exceptions and be able to work with multiple transport layers
        try {
            $response = $this->http->get($this->getInstanceWebsiteUrl($params));
        } catch (\Exception $e) {
            return null;
        }

        if ($response->hasErrors()) {
            return null;
        }

        return $this->properties->getObjectFromNameAndProperties($response->body->data->name, $this->serializer->decode($response->body->data->properties));
    }

    /**
     * Send an event to a workflow instance.
     *
     * @param string         $workflowName Workflow class name
     * @param string         $customId     Provided custom id
     * @param EventInterface $event        Event to send
     */
    public function sendEvent($workflowName, $customId, EventInterface $event)
    {
        $url = $this->getSendEventURL();

        $body = [
            self::ATTR_PROG => self::PROG,
            self::ATTR_NAME => $workflowName,
            self::ATTR_ID => $customId,
            self::EVENT_NAME => get_class($event),
            self::EVENT_INPUT => $this->serializer->encode($this->properties->getPropertiesFromObject($event)),
        ];

        $this->http->post($url, $body);
    }

    protected function updateInstance($workflowName, $customId, $mode)
    {
        $params = self::ATTR_ID.'='.$customId;

        return $this->http->put($this->getInstanceWorkerUrl($params), [
            self::ATTR_PROG => self::PROG,
            self::ATTR_NAME => $workflowName,
            self::ATTR_MODE => $mode,
        ]);
    }

    protected function getInstanceWebsiteUrl($params)
    {
        return $this->getWebsiteUrl('instances', $params);
    }

    protected function getInstanceWorkerUrl($params = '')
    {
        return $this->getWorkerUrl('instances', $params);
    }

    protected function getTaskWorkerUrl($params = '')
    {
        return $this->getWorkerUrl('tasks', $params);
    }

    protected function getSendEventURL()
    {
        return $this->getWorkerUrl('events');
    }

    protected function addAppEnv($url, $params = '')
    {
        // when called from worker, APP_ENV and APP_ID is not defined
        return $url
            .($this->appEnv ? self::APP_ENV.'='.$this->appEnv.'&' : '')
            .($this->appId ? self::APP_ID.'='.$this->appId.'&' : '')
            .($params ? $params.'&' : '');
    }
}
