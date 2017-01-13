<?php
namespace DreamFactory\Core\Script\Handlers\Events;

use DreamFactory\Core\Contracts\HttpStatusCodeInterface;
use DreamFactory\Core\Contracts\ServiceResponseInterface;
use DreamFactory\Core\Events\ApiEvent;
use DreamFactory\Core\Events\PostProcessApiEvent;
use DreamFactory\Core\Events\PreProcessApiEvent;
use DreamFactory\Core\Events\QueuedApiEvent;
use DreamFactory\Core\Script\Components\ScriptHandler;
use DreamFactory\Core\Script\Jobs\ApiEventScriptJob;
use DreamFactory\Core\Script\Models\EventScript;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\Session;
use Illuminate\Contracts\Events\Dispatcher;
use Log;

class ScriptableEventHandler
{
    use ScriptHandler;

    /**
     * Register the listeners for the subscriber.
     *
     * @param  Dispatcher $events
     */
    public function subscribe($events)
    {
        $events->listen(
            [
                PreProcessApiEvent::class,
                PostProcessApiEvent::class,
                QueuedApiEvent::class,
            ],
            static::class . '@handleApiEvent'
        );
    }

    /**
     * Handle events.
     *
     * @param ApiEvent $event
     *
     * @return boolean
     */
    public function handleApiEvent($event)
    {
        Log::debug('API event handled: ' . $event->name);

        if ($script = $this->getEventScript($event->name)) {
            if ($event instanceof PreProcessApiEvent) {
                $data = $event->makeData();

                if (null !== $result = $this->handleEventScript($script, $data)) {
                    if ($script->allow_event_modification) {
                        // request only
                        $event->request->mergeFromArray((array)array_get($result, 'request'));

                        // new feature to allow pre-process to circumvent process by returning response
                        if (!empty($response = array_get($result, 'response'))) {
                            if (is_array($response) && array_key_exists('content', $response)) {
                                $content = array_get($response, 'content');
                                $contentType = array_get($response, 'content_type');
                                $status = array_get($response, 'status_code', HttpStatusCodeInterface::HTTP_OK);

                                $event->response = ResponseFactory::create($content, $contentType, $status);
                            } else {
                                // otherwise assume raw content
                                $event->response = ResponseFactory::create($response);
                            }
                        }
                    }

                    return $this->handleEventScriptResult($script, $result);
                }
            } elseif ($event instanceof PostProcessApiEvent) {
                $data = $event->makeData();

                if (null !== $result = $this->handleEventScript($script, $data)) {
                    if ($script->allow_event_modification) {
                        if (empty($response = array_get($result, 'response', []))) {
                            // check for "return" results
                            // could be formatted array or raw content
                            if (is_array($result) && (isset($result['content']) || isset($result['status_code']))) {
                                $response = $result;
                            } else {
                                // otherwise must be raw content, assumes 200
                                $response = ['content' => $result, 'status_code' => HttpStatusCodeInterface::HTTP_OK];
                            }
                        }

                        // response only
                        if ($event->response instanceof ServiceResponseInterface) {
                            $event->response->mergeFromArray($response);
                        } else {
                            $event->response = $response;
                        }
                    }

                    return $this->handleEventScriptResult($script, $result);
                }
            } elseif ($event instanceof QueuedApiEvent) {
                $result = $event->dispatch(new ApiEventScriptJob($event->name, $event, $script->config));
                Log::debug('API event queued: ' . $event->name . PHP_EOL . $result);
            }
        }

        return true;
    }

    /**
     * @param string $name
     *
     * @return EventScript|null
     */
    public function getEventScript($name)
    {
        if (empty($model = EventScript::whereName($name)->whereIsActive(true)->first())) {
            return null;
        }

        $model->content = Session::translateLookups($model->content, true);
        if (!is_array($model->config)) {
            $model->config = [];
        }

        return $model;
    }
    
    /**
     * @param EventScript $script
     * @param array       $event
     *
     * @return array|null
     * @throws \DreamFactory\Core\Script\Exceptions\ScriptException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     * @throws \DreamFactory\Core\Exceptions\RestException
     * @throws \DreamFactory\Core\Exceptions\ServiceUnavailableException
     */
    public function handleEventScript($script, &$event)
    {
        $result = $this->handleScript($script->name, $script->content, $script->type, $script->config, $event);

        return $result;
    }

    /**
     * @param EventScript $script
     * @param             $result
     *
     * @return bool
     */
    protected function handleEventScriptResult(
        /** @noinspection PhpUnusedParameterInspection */
        $script,
        $result
    ) {
        if (array_get($result, 'stop_propagation', false)) {
            Log::info('  * Propagation stopped by script.');

            return false;
        }

        return true;
    }
}