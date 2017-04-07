<?php

namespace Tests\Clue\React\HttpProxy;

use Clue\React\HttpProxy\ProxyConnector;
use React\Promise\Promise;
use React\SocketClient\ConnectionInterface;

class ProxyConnectorTest extends AbstractTestCase
{
    private $connector;

    public function setUp()
    {
        $this->connector = $this->getMockBuilder('React\SocketClient\ConnectorInterface')->getMock();
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidProxy()
    {
        new ProxyConnector('///', $this->connector);
    }

    public function testCreatesConnectionToHttpPort()
    {
        $promise = new Promise(function () { });
        $this->connector->expects($this->once())->method('connect')->with('proxy.example.com:80?hostname=google.com')->willReturn($promise);

        $proxy = new ProxyConnector('proxy.example.com', $this->connector);

        $proxy->connect('google.com:80');
    }

    public function testCreatesConnectionToHttpPortAndObeysExplicitHostname()
    {
        $promise = new Promise(function () { });
        $this->connector->expects($this->once())->method('connect')->with('proxy.example.com:80?hostname=www.google.com')->willReturn($promise);

        $proxy = new ProxyConnector('proxy.example.com', $this->connector);

        $proxy->connect('google.com:80?hostname=www.google.com');
    }

    public function testCreatesConnectionToHttpsPort()
    {
        $promise = new Promise(function () { });
        $this->connector->expects($this->once())->method('connect')->with('proxy.example.com:443?hostname=google.com')->willReturn($promise);

        $proxy = new ProxyConnector('https://proxy.example.com', $this->connector);

        $proxy->connect('google.com:80');
    }

    public function testCancelPromiseWillCancelPendingConnection()
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());
        $this->connector->expects($this->once())->method('connect')->willReturn($promise);

        $proxy = new ProxyConnector('proxy.example.com', $this->connector);

        $promise = $proxy->connect('google.com:80');

        $this->assertInstanceOf('React\Promise\CancellablePromiseInterface', $promise);

        $promise->cancel();
    }

    public function testWillWriteToOpenConnection()
    {
        $stream = $this->getMockBuilder('React\SocketClient\StreamConnection')->disableOriginalConstructor()->setMethods(array('close', 'write'))->getMock();
        $stream->expects($this->once())->method('write');

        $promise = \React\Promise\resolve($stream);
        $this->connector->expects($this->once())->method('connect')->willReturn($promise);

        $proxy = new ProxyConnector('proxy.example.com', $this->connector);

        $proxy->connect('google.com:80');
    }

    public function testRejectsAndClosesIfStreamWritesNonHttp()
    {
        $stream = $this->getMockBuilder('React\SocketClient\StreamConnection')->disableOriginalConstructor()->setMethods(array('close', 'write'))->getMock();

        $promise = \React\Promise\resolve($stream);
        $this->connector->expects($this->once())->method('connect')->willReturn($promise);

        $proxy = new ProxyConnector('proxy.example.com', $this->connector);

        $promise = $proxy->connect('google.com:80');

        $stream->expects($this->once())->method('close');
        $stream->emit('data', array("invalid\r\n\r\n"));

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testRejectsAndClosesIfStreamWritesTooMuchData()
    {
        $stream = $this->getMockBuilder('React\SocketClient\StreamConnection')->disableOriginalConstructor()->setMethods(array('close', 'write'))->getMock();

        $promise = \React\Promise\resolve($stream);
        $this->connector->expects($this->once())->method('connect')->willReturn($promise);

        $proxy = new ProxyConnector('proxy.example.com', $this->connector);

        $promise = $proxy->connect('google.com:80');

        $stream->expects($this->once())->method('close');
        $stream->emit('data', array(str_repeat('*', 100000)));

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testRejectsAndClosesIfStreamReturnsNonSuccess()
    {
        $stream = $this->getMockBuilder('React\SocketClient\StreamConnection')->disableOriginalConstructor()->setMethods(array('close', 'write'))->getMock();

        $promise = \React\Promise\resolve($stream);
        $this->connector->expects($this->once())->method('connect')->willReturn($promise);

        $proxy = new ProxyConnector('proxy.example.com', $this->connector);

        $promise = $proxy->connect('google.com:80');

        $stream->expects($this->once())->method('close');
        $stream->emit('data', array("HTTP/1.1 403 Not allowed\r\n\r\n"));

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testResolvesIfStreamReturnsSuccess()
    {
        $stream = $this->getMockBuilder('React\SocketClient\StreamConnection')->disableOriginalConstructor()->setMethods(array('close', 'write'))->getMock();

        $promise = \React\Promise\resolve($stream);
        $this->connector->expects($this->once())->method('connect')->willReturn($promise);

        $proxy = new ProxyConnector('proxy.example.com', $this->connector);

        $promise = $proxy->connect('google.com:80');

        $promise->then($this->expectCallableOnce('React\Stream\Stream'));
        $never = $this->expectCallableNever();
        $promise->then(function (ConnectionInterface $stream) use ($never) {
            $stream->on('data', $never);
        });

        $stream->emit('data', array("HTTP/1.1 200 OK\r\n\r\n"));
    }

    public function testResolvesIfStreamReturnsSuccessAndEmitsExcessiveData()
    {
        $stream = $this->getMockBuilder('React\SocketClient\StreamConnection')->disableOriginalConstructor()->setMethods(array('close', 'write'))->getMock();

        $promise = \React\Promise\resolve($stream);
        $this->connector->expects($this->once())->method('connect')->willReturn($promise);

        $proxy = new ProxyConnector('proxy.example.com', $this->connector);

        $promise = $proxy->connect('google.com:80');

        $once = $this->expectCallableOnceWith('hello!');
        $promise->then(function (ConnectionInterface $stream) use ($once) {
            $stream->on('data', $once);
        });

        $stream->emit('data', array("HTTP/1.1 200 OK\r\n\r\nhello!"));
    }

    public function testCancelPromiseWillCloseOpenConnectionAndReject()
    {
        $stream = $this->getMockBuilder('React\SocketClient\StreamConnection')->disableOriginalConstructor()->setMethods(array('close', 'write'))->getMock();
        $stream->expects($this->once())->method('close');

        $promise = \React\Promise\resolve($stream);
        $this->connector->expects($this->once())->method('connect')->willReturn($promise);

        $proxy = new ProxyConnector('proxy.example.com', $this->connector);

        $promise = $proxy->connect('google.com:80');

        $this->assertInstanceOf('React\Promise\CancellablePromiseInterface', $promise);

        $promise->cancel();

        $promise->then(null, $this->expectCallableOnce());
    }
}
