<?php

namespace Zenaton;

use Zenaton\Exceptions\InvalidArgumentException;
use Zenaton\Interfaces\BoxInterface;
use Zenaton\Interfaces\TaskInterface;
use Zenaton\Interfaces\WorkflowInterface;
use Zenaton\Traits\SingletonTrait;
use Zenaton\Parallel\Collection;

class Helpers
{
    use SingletonTrait;

    protected $worker;

    public function construct()
    {
        // zenaton execution
        if (class_exists('Zenaton\Worker\Helpers')) {
            $this->worker = \Zenaton\Worker\Helpers::getInstance();
        }
    }

    public function parallel()
    {
        return new Collection(func_get_args());
    }

    public function execute($boxes)
    {
        return $this->doExecute($boxes, true);
    }

    public function dispatch($boxes)
    {
        $this->doExecute($boxes, false);
    }

    protected function doExecute($boxes, $isSync)
    {
        // check arguments'type
        $this->checkArgumentsType($boxes);

        // local execution
        if (is_null($this->worker)) {
            $outputs = [];
            foreach ($boxes as $box) {
                $outputs[] = $box->handle();
            }
            if ($isSync) {
                // sync executions return results
                return (count($boxes) > 1) ? $outputs : $outputs[0];
            } else {
                // async executions return no results
                return;
            }
        }

        // zenaton execution
        return $this->worker->doExecute($boxes, $isSync);
    }

    protected function checkArgumentsType($boxes)
    {
        $error = new InvalidArgumentException(
            'arguments MUST be one or many objects implementing '.TaskInterface::class.
            ' or '.WorkflowInterface::class
        );

        // check there is at least one argument
        if (count($boxes) == 0) {
            throw $error;
        }

        // check each arguments'type
        $check = function ($arg) use ($error) {
            if ( ! is_object($arg) || ! $arg instanceof BoxInterface) {
                throw $error;
            }
        };

        array_map($check, $boxes);
    }
}
