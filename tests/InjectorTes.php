<?php
namespace Chiron\Invoker\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Chiron\Container\Container;
use Chiron\Invoker\Injector5 as Injector;
use Chiron\Invoker\MissingRequiredArgumentException;
use Chiron\Invoker\Tests\Support\ColorInterface;
use Chiron\Invoker\Tests\Support\EngineInterface;
use Chiron\Invoker\Tests\Support\EngineMarkTwo;

/**
 * InjectorTest contains tests for \yii\di\Injector
 */
class InjectorTest extends TestCase
{
    public function testInvoke(): void
    {
        $container = new Container();

        $constainer->add(EngineInterface::class, EngineMarkTwo::class);

        $getEngineName = static function (EngineInterface $engine) {
            return $engine->getName();
        };

        $engineName = (new Injector($container))->invoke($getEngineName);

        $this->assertSame('Mark Two', $engineName);
    }

    public function testMissingRequiredParameter(): void
    {
        $container = new Container([
            EngineInterface::class => EngineMarkTwo::class,
        ]);

        $getEngineName = static function (EngineInterface $engine, $two) {
            return $engine->getName();
        };

        $injector = new Injector($container);

        $this->expectException(MissingRequiredArgumentException::class);
        $injector->invoke($getEngineName);
    }

    public function testNotFoundException(): void
    {
        $container = new Container([
            EngineInterface::class => EngineMarkTwo::class,
        ]);

        $getEngineName = static function (EngineInterface $engine, ColorInterface $color) {
            return $engine->getName();
        };

        $injector = new Injector($container);

        $this->expectException(NotFoundExceptionInterface::class);
        $injector->invoke($getEngineName);
    }
}
