<?php

namespace Recca0120\LaravelTracy\Tests;

use Closure;
use Exception;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Recca0120\LaravelTracy\DebuggerManager;
use Recca0120\LaravelTracy\Session;
use Tracy\Bar;
use Tracy\BlueScreen;
use Tracy\Debugger;

class DebuggerManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testInit()
    {
        DebuggerManager::init($config = [
            'showBar' => true,
            'editor' => 'foo',
            'maxDepth' => 10,
            'maxLength' => 100,
            'scream' => false,
            'showLocation' => false,
            'strictMode' => false,
            'currentTime' => 12345678,
            'editorMapping' => [],
        ]);

        $this->assertSame($config['editor'], Debugger::$editor);
        $this->assertSame($config['maxDepth'], Debugger::$maxDepth);
        $this->assertSame($config['maxLength'], Debugger::$maxLength);
        $this->assertSame($config['scream'], Debugger::$scream);
        $this->assertSame($config['showLocation'], Debugger::$showLocation);
        $this->assertSame($config['strictMode'], Debugger::$strictMode);
        $this->assertSame($config['currentTime'], Debugger::$time);

        if (isset(Debugger::$editorMapping) === true) {
            $this->assertSame($config['editorMapping'], Debugger::$editorMapping);
        }
    }

    public function testEnabled()
    {
        $config = ['enabled' => true];
        $bar = new Bar();
        $blueScreen = new BlueScreen();
        $session = new Session();
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session);

        $this->assertSame($config['enabled'], $debuggerManager->enabled());
    }

    public function testShowBar()
    {
        $config = ['showBar' => true];
        $bar = new Bar();
        $blueScreen = new BlueScreen();
        $session = new Session();
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session);

        $this->assertSame($config['showBar'], $debuggerManager->showBar());
    }

    public function testAccepts()
    {
        $config = ['accepts' => ['foo']];
        $bar = new Bar();
        $blueScreen = new BlueScreen();
        $session = new Session();
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session);

        $this->assertSame($config['accepts'], $debuggerManager->accepts());
    }

    public function testDispatchAssetsCss()
    {
        $config = [];
        $bar = m::spy(new Bar());
        $blueScreen = new BlueScreen();
        $session = new Session();
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session);

        $content = 'foo';
        $bar->expects('dispatchAssets')->andReturnUsing($this->echoContent($content));

        $this->assertSame([[
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => 'max-age=86400',
            'Content-Length' => strlen($content),
        ], $content], $debuggerManager->dispatchAssets('css'));
    }

    public function testDispatchAssetsJs()
    {
        $config = ['accepts' => ['foo']];
        $bar = m::spy(new Bar());
        $blueScreen = new BlueScreen();
        $session = new Session();
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session);

        $content = 'foo';
        $bar->expects('dispatchAssets')->andReturnUsing($this->echoContent($content));

        $this->assertSame([[
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'max-age=86400',
            'Content-Length' => strlen($content),
        ], $content], $debuggerManager->dispatchAssets('js'));
    }

    public function testDispatchAssetsContentId()
    {
        $config = ['accepts' => ['foo']];
        $bar = m::spy(new Bar());
        $blueScreen = new BlueScreen();
        $session = m::spy(new Session());
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session);

        $session->expects('isStarted')->andReturns(false);
        $session->expects('start');

        $content = 'foo';
        $bar->expects('dispatchAssets')->andReturnUsing($this->echoContent($content));

        $this->assertSame([[
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Content-Length' => strlen($content),
        ], $content], $debuggerManager->dispatchAssets(uniqid('', true)));
    }

    public function testShutdownHandlerAndSessionIsClose()
    {
        $config = [];
        $bar = m::spy(new Bar());
        $blueScreen = new BlueScreen();
        $session = m::spy(new Session());
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session);

        $session->expects('isStarted')->andReturns(false);

        $barRender = 'foo';
        $bar->expects('render')->andReturnUsing($this->echoContent($barRender));

        $content = '<html><head></head><body></body></html>';

        $this->assertSame('<html><head></head><body>'.$barRender.'</body></html>', $debuggerManager->shutdownHandler($content));
    }

    public function testShutdownHandler()
    {
        $config = [];
        $bar = m::spy(new Bar());
        $blueScreen = new BlueScreen();
        $session = m::spy(new Session());
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session);

        $session->expects('isStarted')->andReturns(true);

        $loader = '<script async></script>';
        $bar->expects('renderLoader')->andReturnUsing($this->echoContent($loader));

        $barRender = 'foo';
        $bar->expects('render')->andReturnUsing($this->echoContent($barRender));

        $content = '<html><head></head><body></body></html>';

        $this->assertSame('<html><head>'.$loader.'</head><body>'.$barRender.'</body></html>', $debuggerManager->shutdownHandler($content));
    }

    public function testShutdownHandlerAppendToHtml()
    {
        $config = ['appendTo' => 'html'];
        $bar = m::spy(new Bar());
        $blueScreen = new BlueScreen();
        $session = m::spy(new Session());
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session);

        $session->expects('isStarted')->andReturns(true);

        $loader = '<script async></script>';
        $bar->expects('renderLoader')->andReturnUsing($this->echoContent($loader));

        $barRender = 'foo';
        $bar->expects('render')->andReturnUsing($this->echoContent($barRender));

        $content = '<html><head></head><body></body></html>';

        $this->assertSame('<html><head>'.$loader.'</head><body></body>'.$barRender.'</html>', $debuggerManager->shutdownHandler($content));
    }

    public function testShutdownHandlerHasError()
    {
        $config = ['appendTo' => 'html'];
        $bar = new Bar();
        $blueScreen = m::spy(new BlueScreen());
        $session = new Session();
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session);

        $content = '';
        $error = [
            'message' => 'testing',
            'type' => E_ERROR,
            'file' => __FILE__,
            'line' => __LINE__,
        ];

        $blueScreen->expects('render')->with(m::on(function ($errorException) use ($error) {
            return $error['type'] === $errorException->getSeverity() &&
                $error['message'] === $errorException->getMessage() &&
                0 === $errorException->getCode() &&
                $error['file'] === $errorException->getFile() &&
                $error['line'] === $errorException->getLine();
        }))->andReturnUsing($this->echoContent($content));

        $this->assertSame($content, $debuggerManager->shutdownHandler($content, false, $error));
    }

    public function testExceptionHandler()
    {
        $config = ['accepts' => ['foo']];
        $bar = new Bar();
        $blueScreen = m::spy(new BlueScreen());
        $session = new Session();
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session);

        $exception = new Exception();
        $content = 'foo';
        $blueScreen->expects('render')->with($exception)->andReturnUsing($this->echoContent($content));

        $this->assertSame($content, $debuggerManager->exceptionHandler($exception));
    }

    public function testReplacePath()
    {
        $config = ['accepts' => ['foo']];
        $bar = m::spy(new Bar());
        $blueScreen = new BlueScreen();
        $session = m::spy(new Session());
        $debuggerManager = new DebuggerManager($config, $bar, $blueScreen, $session, 'foo');

        $session->expects('isStarted')->andReturns(true);

        $bar->expects('renderLoader')
            ->andReturnUsing($this->echoContent('<script src="?_tracy_bar=foo" async></script>'));

        $bar->expects('render')->andReturnUsing($this->echoContent('?_tracy_bar=foo'));

        $this->assertSame('<head><script src="foo?_tracy_bar=foo" async></script></head><body>foo?_tracy_bar=foo</body>', $debuggerManager->shutdownHandler('<head></head><body></body>'));
    }

    /**
     * @param $content
     * @return Closure
     */
    private function echoContent($content)
    {
        return static function () use ($content) {
            echo $content;

            return true;
        };
    }
}
