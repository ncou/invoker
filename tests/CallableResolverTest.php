<?php

namespace Chiron\Invoker\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Chiron\Container\Container;
use Chiron\Invoker\Injector5 as Invoker;
use Chiron\Invoker\CallableResolver;
use Chiron\Invoker\Exception\NotCallableException;
use Chiron\Invoker\MissingRequiredArgumentException;
use Chiron\Invoker\Tests\Support\ColorInterface;
use Chiron\Invoker\Tests\Support\EngineInterface;
use Chiron\Invoker\Tests\Support\EngineMarkTwo;
use Chiron\Invoker\Tests\Support\CallableSpy;


class CallableResolverTest extends TestCase
{
    /**
     * @var CallableResolver
     */
    private $resolver;
    /**
     * @var ArrayContainer
     */
    private $container;

    public function setUp()
    {
        $this->container = new Container;
        $this->resolver = new CallableResolver($this->container);
    }
    /**
     * @test
     */
    public function resolves_function()
    {
        $result = $this->resolver->resolve('strlen');
        $this->assertSame(strlen('Hello world!'), $result('Hello world!'));
    }
    /**
     * @test
     */
    public function resolves_namespaced_function()
    {
        $result = $this->resolver->resolve(__NAMESPACE__ . '\foo');
        $this->assertEquals('bar', $result());
    }
    /**
     * @test
     */
    public function resolves_callable_from_container()
    {
        $callable = function () {};
        $this->container->add('thing-to-call', function() use ($callable) { return $callable;});
        $this->assertSame($callable, $this->resolver->resolve('thing-to-call'));
    }
    /**
     * @test
     */
    public function resolves_invokable_class()
    {
        $callable = new CallableSpy;
        $this->container->add('Chiron\Invoker\Tests\Support\CallableSpy', function() use ($callable) { return $callable;});
        $this->assertSame($callable, $this->resolver->resolve('Chiron\Invoker\Tests\Support\CallableSpy'));
    }
    /**
     * @test
     */
    public function resolve_array_method_call()
    {
        $fixture = new InvokerTestFixture;
        $this->container->add('Chiron\Invoker\Tests\InvokerTestFixture', $fixture);
        $result = $this->resolver->resolve(array('Chiron\Invoker\Tests\InvokerTestFixture', 'foo'));
        $result();
        $this->assertTrue($fixture->wasCalled);
    }
    /**
     * @test
     */
    public function resolve_string_method_call()
    {
        $fixture = new InvokerTestFixture;
        $this->container->add('Chiron\Invoker\Tests\InvokerTestFixture', $fixture);
        $result = $this->resolver->resolve('Chiron\Invoker\Tests\InvokerTestFixture::foo');
        $result();
        $this->assertTrue($fixture->wasCalled);
    }
    /**
     * @test
     */
    public function resolves_array_method_call_with_service()
    {
        $fixture = new InvokerTestFixture;
        $this->container->add('thing-to-call', $fixture);
        $result = $this->resolver->resolve(array('thing-to-call', 'foo'));
        $result();
        $this->assertTrue($fixture->wasCalled);
    }
    /**
     * @test
     */
    public function resolves_string_method_call_with_service()
    {
        $fixture = new InvokerTestFixture;
        $this->container->add('thing-to-call', $fixture);
        $result = $this->resolver->resolve('thing-to-call::foo');
        $result();
        $this->assertTrue($fixture->wasCalled);
    }
    /**
     * @test
     */
    public function resolves_string_method_call_with_service_simpledot()
    {
        $fixture = new InvokerTestFixture;
        $this->container->add('thing-to-call', $fixture);
        $result = $this->resolver->resolve('thing-to-call:foo');
        $result();
        $this->assertTrue($fixture->wasCalled);
    }
    /**
     * @test
     * @expectedException \Chiron\Invoker\Exception\NotCallableException
     * @expectedExceptionMessage 'foo' is neither a callable nor a valid container entry
     */
    public function throws_resolving_non_callable_from_container()
    {
        $resolver = new CallableResolver(new Container);
        $resolver->resolve('foo');
    }
    /**
     * @test
     * @expectedException \Chiron\Invoker\Exception\NotCallableException
     * @expectedExceptionMessage Instance of stdClass is not a callable.
     */
    public function handles_objects_correctly_in_exception_message()
    {
        $resolver = new CallableResolver(new Container);
        $resolver->resolve(new \stdClass);
    }
    /**
     * @test
     * @expectedException \Chiron\Invoker\Exception\NotCallableException
     * @expectedExceptionMessage stdClass::test() is not a callable.
     */
    public function handles_method_calls_correctly_in_exception_message()
    {
        $resolver = new CallableResolver(new Container);
        $resolver->resolve(array(new \stdClass, 'test'));
    }
}
function foo()
{
    return 'bar';
}
