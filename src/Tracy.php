<?php

namespace Recca0120\LaravelTracy;

use ErrorException;
use Exception;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tracy\Debugger;
use Tracy\Helpers;
use Tracy\IBarPanel;

class Tracy
{
    /**
     * $config.
     *
     * @var array
     */
    protected $config;

    /**
     * $app.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * $request.
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * $ajax.
     *
     * @var bool
     */
    protected $ajax = false;

    /**
     * $panels.
     *
     * @var array
     */
    public $panels = [];

    /**
     * __construct.
     *
     * @method __construct
     *
     * @param array $config
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @param \Illuminate\Http\Request                     $request
     */
    public function __construct($config = [], ApplicationContract $app = null, Request $request = null)
    {
        $this->config = $config;
        $this->app = $app;
        $this->request = is_null($request) === true ? Request::capture() : $request;
        $this->ajax = $this->request->ajax();
        $this->request->user();
    }

    /**
     * initialize.
     *
     * @method initialize
     *
     * @return bool
     */
    public function initialize()
    {
        if ($this->isRunningInConsole() === true || array_get($this->config, 'enabled', true) === false) {
            return false;
        }

        if ($this->request->has('_tracy_bar') === true) {
            if (Debugger::getBar()->dispatchAssets() === true) {
                exit;
            }

            if (Debugger::dispatch() === true) {
                exit;
            }

            $this->closeSession();
        }

        Debugger::$editor = array_get($this->config, 'editor', Debugger::$editor);
        Debugger::$maxDepth = array_get($this->config, 'maxDepth', Debugger::$maxDepth);
        Debugger::$maxLength = array_get($this->config, 'maxLength', Debugger::$maxLength);
        Debugger::$scream = array_get($this->config, 'scream', true);
        Debugger::$showLocation = array_get($this->config, 'showLocation', true);
        Debugger::$strictMode = array_get($this->config, 'strictMode', true);
        Debugger::$time = array_get($_SERVER, 'REQUEST_TIME_FLOAT', microtime(true));
        $panels = array_get($this->config, 'panels', []);
        if (isset($panels['user']) === true) {
            $panels['auth'] = $panels['user'];
            unset($panels['user']);
        }
        foreach ($panels as $name => $enabled) {
            if ($enabled === false) {
                continue;
            }

            $class = '\\'.__NAMESPACE__.'\Panels\\'.Str::studly($name).'Panel';
            $panel = new $class();

            if ($this->ajax === true && $panel->supportAjax === false) {
                continue;
            }

            $this->addPanel($panel, $name);
        }

        return true;
    }

    /**
     * isRunningInConsole.
     *
     * @method isRunningInConsole
     *
     * @return bool
     */
    protected function isRunningInConsole()
    {
        return is_null($this->app) === false && $this->app->runningInConsole() === true;
    }

    /**
     * renderBlueScreen.
     *
     * @method renderBlueScreen
     *
     * @param  \Exception $exception
     *
     * @return string
     */
    public function renderBlueScreen(Exception $exception)
    {
        $error = error_get_last();
        if (in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR, E_USER_ERROR], true)) {
            $exception = Helpers::fixStack(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
        }

        ob_start();
        Helpers::improveException($exception);
        Debugger::getBlueScreen()->render($exception);
        $content = ob_get_clean();

        return $content;
    }

    /**
     * renderResponse.
     *
     * @method renderResponse
     *
     * @param \Symfony\Component\HttpFoundation\Response $response
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function renderResponse(Response $response)
    {
        if ($this->denyRenderResponse($response) === true) {
            return $response;
        }

        $response->setContent($this->appendDebugbar($response->getContent(), $response->getStatusCode()));

        return $response;
    }

    /**
     * acceptRenderResponse.
     *
     * @method acceptRenderResponse
     *
     * @param \Symfony\Component\HttpFoundation\Response $response $response
     *
     * @return bool
     */
    protected function denyRenderResponse($response)
    {
        if ($this->ajax === true) {
            return false;
        }

        if ($response instanceof BinaryFileResponse) {
            return true;
        }

        if ($response instanceof StreamedResponse) {
            return true;
        }

        if ($response->isRedirection() === true) {
            return true;
        }

        $contentType = $response->headers->get('Content-type');

        if (empty($contentType) === true && $response->getStatusCode() >= 400) {
            return false;
        }

        $accepts = array_get($this->config, 'accepts', []);
        if (count($accepts) === 0) {
            return false;
        }

        $contentType = strtolower($contentType);
        foreach ($accepts as $accept) {
            if (strpos($contentType, $accept) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * appendDebugbar.
     *
     * @method appendDebugbar
     *
     * @param string $content
     * @param int    $statusCode
     *
     * @return string
     */
    public function appendDebugbar($content, $statusCode = 200)
    {
        if (array_get($this->config, 'showBar', true) === false) {
            return $content;
        }

        $htmlValidatorPanel = $this->getPanel('html-validator');
        if (is_null($htmlValidatorPanel) === false && $statusCode === 200) {
            $htmlValidatorPanel->setHtml($content);
        }

        $barPanels = $this->renderPanel();
        $pos = strripos($content, '</body>');
        if ($pos !== false) {
            $content = substr($content, 0, $pos).$barPanels.substr($content, $pos);
        } else {
            $content .= $barPanels;
        }

        return $content;
    }

    /**
     * addPanel description.
     *
     * @method addPanel
     *
     * @param \Tracy\IBarPanel  $panel
     * @param string            $id
     *
     * @return $this
     */
    public function addPanel(IBarPanel $panel, $id)
    {
        $panel->setLaravel($this->app);
        $this->panels[$id] = $panel;

        return $this;
    }

    /**
     * getPanel.
     *
     * @method getPanel
     *
     * @param string  $id
     *
     * @return \Tracy\IBarPanel
     */
    public function getPanel($id)
    {
        return array_get($this->panels, $id);
    }

    /**
     * getPanels.
     *
     * @method getPanels
     *
     * @return array
     */
    public function getPanels()
    {
        return $this->panels;
    }

    /**
     * renderPanel.
     *
     * @method renderPanel
     *
     * @return string
     */
    public function renderPanel()
    {
        $this->startSession();
        $bar = Debugger::getBar();
        $this->setupPanels($bar);

        ob_start();
        $bar->render();
        $content = ob_get_clean();
        $this->closeSession();

        return $content;
    }

    /**
     * setupPanels.
     *
     * @method setupPanels
     *
     * @param \Tracy\Bar $bar
     *
     * @return static
     */
    protected function setupPanels($bar)
    {
        foreach ($this->getPanels() as $panel) {
            $bar->addPanel($panel);
        }

        return $this;
    }

    /**
     * startBuffering.
     *
     * @method startBuffering
     *
     * @return $this
     */
    public function startBuffering()
    {
        ob_start();

        return $this;
    }

    /**
     * stopBuffering.
     *
     * @method stopBuffering
     *
     * @return $this
     */
    public function stopBuffering()
    {
        if (ob_get_level()) {
            ob_end_flush();
        }

        return $this;
    }

    /**
     * startSession.
     *
     * @method startSession
     */
    public function startSession()
    {
        try {
            @Debugger::dispatch();
        } catch (LogicException $e) {
        }

        return $this;
    }

    /**
     * closeSession.
     *
     * @method closeSession
     */
    private function closeSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $this;
    }

    /**
     * instance.
     *
     * @method instance
     *
     * @param  array$config
     * @return static
     */
    public static function instance($config = [])
    {
        static $instance;

        if (is_null($instance) === false) {
            return $instance;
        }

        $config = array_merge([
            'enabled'      => true,
            'showBar'      => true,
            'editor'       => 'subl://open?url=file://%file&line=%line',
            'maxDepth'     => 4,
            'maxLength'    => 1000,
            'scream'       => true,
            'showLocation' => true,
            'strictMode'   => true,
            'panels'       => [
                'routing'  => false,
                'database' => true,
                'view'     => false,
                'event'    => false,
                'session'  => true,
                'request'  => true,
                'auth'     => true,
                'terminal' => false,
            ],
        ], $config);
        Debugger::enable();
        $bar = Debugger::getBar();
        $tracy = new static($config);
        $tracy->initialize();
        $tracy->setupPanels($bar);

        return $instance = $tracy;
    }
}
