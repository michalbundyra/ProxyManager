<?php

declare(strict_types=1);

namespace ProxyManagerTest\Functional;

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use ProxyManager\Factory\AccessInterceptorValueHolderFactory;
use ProxyManager\Generator\ClassGenerator;
use ProxyManager\Generator\Util\UniqueIdentifierGenerator;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use ProxyManager\Proxy\AccessInterceptorValueHolderInterface;
use ProxyManager\ProxyGenerator\AccessInterceptorValueHolderGenerator;
use ProxyManagerTestAsset\BaseClass;
use ProxyManagerTestAsset\BaseInterface;
use ProxyManagerTestAsset\ClassWithCounterConstructor;
use ProxyManagerTestAsset\ClassWithDynamicArgumentsMethod;
use ProxyManagerTestAsset\ClassWithMethodWithByRefVariadicFunction;
use ProxyManagerTestAsset\ClassWithMethodWithVariadicFunction;
use ProxyManagerTestAsset\ClassWithParentHint;
use ProxyManagerTestAsset\ClassWithPublicArrayProperty;
use ProxyManagerTestAsset\ClassWithPublicProperties;
use ProxyManagerTestAsset\ClassWithSelfHint;
use ProxyManagerTestAsset\EmptyClass;
use ProxyManagerTestAsset\OtherObjectAccessClass;
use ProxyManagerTestAsset\VoidCounter;
use ReflectionClass;
use stdClass;
use function array_values;
use function get_class;
use function random_int;
use function serialize;
use function ucfirst;
use function uniqid;
use function unserialize;

/**
 * Tests for {@see \ProxyManager\ProxyGenerator\LazyLoadingValueHolderGenerator} produced objects
 *
 * @group Functional
 * @coversNothing
 */
class AccessInterceptorValueHolderFunctionalTest extends TestCase
{
    /**
     * @dataProvider getProxyMethods
     *
     * @param mixed[] $params
     * @param mixed   $expectedValue
     */
    public function testMethodCalls(string $className, object $instance, string $method, array $params, $expectedValue) : void
    {
        $proxyName = $this->generateProxy($className);

        /** @var AccessInterceptorValueHolderInterface $proxy */
        $proxy    = $proxyName::staticProxyConstructor($instance);
        $callback = [$proxy, $method];

        self::assertInternalType('callable', $callback);
        self::assertSame($instance, $proxy->getWrappedValueHolderValue());
        self::assertSame($expectedValue, $callback(...array_values($params)));

        /** @var callable|\PHPUnit_Framework_MockObject_MockObject $listener */
        $listener = $this->getMockBuilder(stdClass::class)->setMethods(['__invoke'])->getMock();
        $listener
            ->expects(self::once())
            ->method('__invoke')
            ->with($proxy, $instance, $method, $params, false);

        $proxy->setMethodPrefixInterceptor(
            $method,
            function ($proxy, $instance, $method, $params, & $returnEarly) use ($listener) : void {
                $listener($proxy, $instance, $method, $params, $returnEarly);
            }
        );

        self::assertSame($expectedValue, $callback(...array_values($params)));

        $random = uniqid('', true);

        $proxy->setMethodPrefixInterceptor(
            $method,
            function ($proxy, $instance, string $method, $params, & $returnEarly) use ($random) : string {
                $returnEarly = true;

                return $random;
            }
        );

        self::assertSame($random, $callback(...array_values($params)));
    }

    /**
     * @dataProvider getProxyMethods
     *
     * @param mixed[] $params
     * @param mixed   $expectedValue
     */
    public function testMethodCallsWithSuffixListener(
        string $className,
        object $instance,
        string $method,
        array $params,
        $expectedValue
    ) : void {
        $proxyName = $this->generateProxy($className);

        /** @var AccessInterceptorValueHolderInterface $proxy */
        $proxy    = $proxyName::staticProxyConstructor($instance);
        $callback = [$proxy, $method];

        self::assertInternalType('callable', $callback);

        /** @var callable|\PHPUnit_Framework_MockObject_MockObject $listener */
        $listener = $this->getMockBuilder(stdClass::class)->setMethods(['__invoke'])->getMock();
        $listener
            ->expects(self::once())
            ->method('__invoke')
            ->with($proxy, $instance, $method, $params, $expectedValue, false);

        $proxy->setMethodSuffixInterceptor(
            $method,
            function ($proxy, $instance, $method, $params, $returnValue, & $returnEarly) use ($listener) : void {
                $listener($proxy, $instance, $method, $params, $returnValue, $returnEarly);
            }
        );

        self::assertSame($expectedValue, $callback(...array_values($params)));

        $random = uniqid('', true);

        $proxy->setMethodSuffixInterceptor(
            $method,
            function ($proxy, $instance, string $method, $params, $returnValue, & $returnEarly) use ($random) : string {
                $returnEarly = true;

                return $random;
            }
        );

        self::assertSame($random, $callback(...array_values($params)));
    }

    /**
     * @dataProvider getProxyMethods
     *
     * @param mixed[] $params
     * @param mixed   $expectedValue
     */
    public function testMethodCallsAfterUnSerialization(
        string $className,
        object $instance,
        string $method,
        array $params,
        $expectedValue
    ) : void {
        $proxyName = $this->generateProxy($className);
        /** @var AccessInterceptorValueHolderInterface $proxy */
        $proxy    = unserialize(serialize($proxyName::staticProxyConstructor($instance)));
        $callback = [$proxy, $method];

        self::assertInternalType('callable', $callback);
        self::assertSame($expectedValue, $callback(...array_values($params)));
        self::assertEquals($instance, $proxy->getWrappedValueHolderValue());
    }

    /**
     * @dataProvider getProxyMethods
     *
     * @param mixed[] $params
     * @param mixed   $expectedValue
     */
    public function testMethodCallsAfterCloning(
        string $className,
        object $instance,
        string $method,
        array $params,
        $expectedValue
    ) : void {
        $proxyName = $this->generateProxy($className);

        /** @var AccessInterceptorValueHolderInterface $proxy */
        $proxy    = $proxyName::staticProxyConstructor($instance);
        $cloned   = clone $proxy;
        $callback = [$cloned, $method];

        self::assertInternalType('callable', $callback);
        self::assertNotSame($proxy->getWrappedValueHolderValue(), $cloned->getWrappedValueHolderValue());
        self::assertSame($expectedValue, $callback(...array_values($params)));
        self::assertEquals($instance, $cloned->getWrappedValueHolderValue());
    }

    /**
     * @dataProvider getPropertyAccessProxies
     *
     * @param mixed $propertyValue
     */
    public function testPropertyReadAccess(
        object $instance,
        AccessInterceptorValueHolderInterface $proxy,
        string $publicProperty,
        $propertyValue
    ) : void {
        self::assertSame($propertyValue, $proxy->$publicProperty);
        self::assertEquals($instance, $proxy->getWrappedValueHolderValue());
    }

    /**
     * @dataProvider getPropertyAccessProxies
     *
     */
    public function testPropertyWriteAccess(
        object $instance,
        AccessInterceptorValueHolderInterface $proxy,
        string $publicProperty
    ) : void {
        $newValue               = uniqid();
        $proxy->$publicProperty = $newValue;

        self::assertSame($newValue, $proxy->$publicProperty);
        self::assertSame($newValue, $proxy->getWrappedValueHolderValue()->$publicProperty);
    }

    /**
     * @dataProvider getPropertyAccessProxies
     *
     */
    public function testPropertyExistence(
        object $instance,
        AccessInterceptorValueHolderInterface $proxy,
        string $publicProperty
    ) : void {
        self::assertSame(isset($instance->$publicProperty), isset($proxy->$publicProperty));
        self::assertEquals($instance, $proxy->getWrappedValueHolderValue());

        $proxy->getWrappedValueHolderValue()->$publicProperty = null;
        self::assertFalse(isset($proxy->$publicProperty));
    }

    /**
     * @dataProvider getPropertyAccessProxies
     *
     */
    public function testPropertyUnset(
        object $instance,
        AccessInterceptorValueHolderInterface $proxy,
        string $publicProperty
    ) : void {
        $instance = $proxy->getWrappedValueHolderValue() ?: $instance;
        unset($proxy->$publicProperty);

        self::assertFalse(isset($instance->$publicProperty));
        self::assertFalse(isset($proxy->$publicProperty));
    }

    /**
     * Verifies that accessing a public property containing an array behaves like in a normal context
     */
    public function testCanWriteToArrayKeysInPublicProperty() : void
    {
        $instance  = new ClassWithPublicArrayProperty();
        $className = get_class($instance);
        $proxyName = $this->generateProxy($className);
        /** @var ClassWithPublicArrayProperty $proxy */
        $proxy = $proxyName::staticProxyConstructor($instance);

        $proxy->arrayProperty['foo'] = 'bar';

        self::assertSame('bar', $proxy->arrayProperty['foo']);

        $proxy->arrayProperty = ['tab' => 'taz'];

        self::assertSame(['tab' => 'taz'], $proxy->arrayProperty);
    }

    /**
     * Verifies that public properties retrieved via `__get` don't get modified in the object state
     */
    public function testWillNotModifyRetrievedPublicProperties() : void
    {
        $instance  = new ClassWithPublicProperties();
        $className = get_class($instance);
        $proxyName = $this->generateProxy($className);
        /** @var ClassWithPublicProperties $proxy */
        $proxy    = $proxyName::staticProxyConstructor($instance);
        $variable = $proxy->property0;

        self::assertSame('property0', $variable);

        $variable = 'foo';

        self::assertSame('property0', $proxy->property0);
        self::assertSame('foo', $variable);
    }

    /**
     * Verifies that public properties references retrieved via `__get` modify in the object state
     */
    public function testWillModifyByRefRetrievedPublicProperties() : void
    {
        $instance  = new ClassWithPublicProperties();
        $className = get_class($instance);
        $proxyName = $this->generateProxy($className);
        /** @var ClassWithPublicProperties $proxy */
        $proxy    = $proxyName::staticProxyConstructor($instance);
        $variable = &$proxy->property0;

        self::assertSame('property0', $variable);

        $variable = 'foo';

        self::assertSame('foo', $proxy->property0);
        self::assertSame('foo', $variable);
    }

    /**
     * @group 115
     * @group 175
     */
    public function testWillBehaveLikeObjectWithNormalConstructor() : void
    {
        $instance = new ClassWithCounterConstructor(10);

        self::assertSame(10, $instance->amount, 'Verifying that test asset works as expected');
        self::assertSame(10, $instance->getAmount(), 'Verifying that test asset works as expected');
        $instance->__construct(3);
        self::assertSame(13, $instance->amount, 'Verifying that test asset works as expected');
        self::assertSame(13, $instance->getAmount(), 'Verifying that test asset works as expected');

        $proxyName = $this->generateProxy(get_class($instance));

        /** @var ClassWithCounterConstructor $proxy */
        $proxy = new $proxyName(15);

        self::assertSame(15, $proxy->amount, 'Verifying that the proxy constructor works as expected');
        self::assertSame(15, $proxy->getAmount(), 'Verifying that the proxy constructor works as expected');
        $proxy->__construct(5);
        self::assertSame(20, $proxy->amount, 'Verifying that the proxy constructor works as expected');
        self::assertSame(20, $proxy->getAmount(), 'Verifying that the proxy constructor works as expected');
    }

    public function testWillForwardVariadicArguments() : void
    {
        $factory      = new AccessInterceptorValueHolderFactory();
        $targetObject = new ClassWithMethodWithVariadicFunction();

        /** @var ClassWithMethodWithVariadicFunction $object */
        $object = $factory->createProxy(
            $targetObject,
            [
                function () : string {
                    return 'Foo Baz';
                },
            ]
        );

        self::assertNull($object->bar);
        self::assertNull($object->baz);

        $object->foo('Ocramius', 'Malukenho', 'Danizord');
        self::assertSame('Ocramius', $object->bar);
        self::assertSame(['Malukenho', 'Danizord'], self::getObjectAttribute($object, 'baz'));
    }

    /**
     * @group 265
     */
    public function testWillForwardVariadicByRefArguments() : void
    {
        $factory      = new AccessInterceptorValueHolderFactory();
        $targetObject = new ClassWithMethodWithByRefVariadicFunction();

        /** @var ClassWithMethodWithByRefVariadicFunction $object */
        $object = $factory->createProxy(
            $targetObject,
            [
                function () : string {
                    return 'Foo Baz';
                },
            ]
        );

        $arguments = ['Ocramius', 'Malukenho', 'Danizord'];

        self::assertSame(
            ['Ocramius', 'changed', 'Danizord'],
            (new ClassWithMethodWithByRefVariadicFunction())->tuz(...$arguments),
            'Verifying that the implementation of the test asset is correct before proceeding'
        );
        self::assertSame(['Ocramius', 'changed', 'Danizord'], $object->tuz(...$arguments));
        self::assertSame(['Ocramius', 'changed', 'Danizord'], $arguments, 'By-ref arguments were changed');
    }

    /**
     * This test documents a known limitation: `func_get_args()` (and similars) don't work in proxied APIs.
     * If you manage to make this test pass, then please do send a patch
     *
     * @group 265
     */
    public function testWillNotForwardDynamicArguments() : void
    {
        $proxyName = $this->generateProxy(ClassWithDynamicArgumentsMethod::class);

        /** @var ClassWithDynamicArgumentsMethod $object */
        $object = $proxyName::staticProxyConstructor(new ClassWithDynamicArgumentsMethod());

        self::assertSame(['a', 'b'], (new ClassWithDynamicArgumentsMethod())->dynamicArgumentsMethod('a', 'b'));

        $this->expectException(ExpectationFailedException::class);

        self::assertSame(['a', 'b'], $object->dynamicArgumentsMethod('a', 'b'));
    }

    /**
     * Generates a proxy for the given class name, and retrieves its class name
     */
    private function generateProxy(string $parentClassName) : string
    {
        $generatedClassName = __NAMESPACE__ . '\\' . UniqueIdentifierGenerator::getIdentifier('Foo');
        $generator          = new AccessInterceptorValueHolderGenerator();
        $generatedClass     = new ClassGenerator($generatedClassName);
        $strategy           = new EvaluatingGeneratorStrategy();

        $generator->generate(new ReflectionClass($parentClassName), $generatedClass);
        $strategy->generate($generatedClass);

        return $generatedClassName;
    }

    /**
     * Generates a list of object | invoked method | parameters | expected result
     *
     * @return string[][]|object[][]|mixed[][]
     */
    public function getProxyMethods() : array
    {
        $selfHintParam = new ClassWithSelfHint();
        $empty         = new EmptyClass();

        return [
            [
                BaseClass::class,
                new BaseClass(),
                'publicMethod',
                [],
                'publicMethodDefault',
            ],
            [
                BaseClass::class,
                new BaseClass(),
                'publicTypeHintedMethod',
                ['param' => new stdClass()],
                'publicTypeHintedMethodDefault',
            ],
            [
                BaseClass::class,
                new BaseClass(),
                'publicByReferenceMethod',
                [],
                'publicByReferenceMethodDefault',
            ],
            [
                BaseInterface::class,
                new BaseClass(),
                'publicMethod',
                [],
                'publicMethodDefault',
            ],
            [
                ClassWithSelfHint::class,
                new ClassWithSelfHint(),
                'selfHintMethod',
                ['parameter' => $selfHintParam],
                $selfHintParam,
            ],
            [
                ClassWithParentHint::class,
                new ClassWithParentHint(),
                'parentHintMethod',
                ['parameter' => $empty],
                $empty,
            ],
        ];
    }

    /**
     * Generates proxies and instances with a public property to feed to the property accessor methods
     *
     * @return string[][]|object[][]|AccessInterceptorValueHolderInterface[][]
     */
    public function getPropertyAccessProxies() : array
    {
        $instance1  = new BaseClass();
        $proxyName1 = $this->generateProxy(get_class($instance1));
        $instance2  = new BaseClass();
        $proxyName2 = $this->generateProxy(get_class($instance2));

        return [
            [
                $instance1,
                $proxyName1::staticProxyConstructor($instance1),
                'publicProperty',
                'publicPropertyDefault',
            ],
            [
                $instance2,
                unserialize(serialize($proxyName2::staticProxyConstructor($instance2))),
                'publicProperty',
                'publicPropertyDefault',
            ],
        ];
    }

    /**
     * @group        276
     *
     * @dataProvider getMethodsThatAccessPropertiesOnOtherObjectsInTheSameScope
     *
     */
    public function testWillInterceptAccessToPropertiesViaFriendClassAccess(
        object $callerObject,
        object $realInstance,
        string $method,
        string $expectedValue,
        string $propertyName
    ) : void {
        $proxyName = $this->generateProxy(get_class($realInstance));
        /** @var AccessInterceptorValueHolderInterface $proxy */
        $proxy = $proxyName::staticProxyConstructor($realInstance);

        /** @var callable|\PHPUnit_Framework_MockObject_MockObject $listener */
        $listener = $this->getMockBuilder(stdClass::class)->setMethods(['__invoke'])->getMock();

        $listener
            ->expects(self::once())
            ->method('__invoke')
            ->with($proxy, $realInstance, '__get', ['name' => $propertyName]);

        $proxy->setMethodPrefixInterceptor(
            '__get',
            function ($proxy, $instance, $method, $params, & $returnEarly) use ($listener) : void {
                $listener($proxy, $instance, $method, $params, $returnEarly);
            }
        );

        /** @var callable $accessor */
        $accessor = [$callerObject, $method];

        self::assertSame($expectedValue, $accessor($proxy));
    }

    /**
     * @group        276
     *
     * @dataProvider getMethodsThatAccessPropertiesOnOtherObjectsInTheSameScope
     *
     */
    public function testWillInterceptAccessToPropertiesViaFriendClassAccessEvenIfDeSerialized(
        object $callerObject,
        object $realInstance,
        string $method,
        string $expectedValue,
        string $propertyName
    ) : void {
        $proxyName = $this->generateProxy(get_class($realInstance));
        /** @var AccessInterceptorValueHolderInterface $proxy */
        $proxy = unserialize(serialize($proxyName::staticProxyConstructor($realInstance)));

        /** @var callable|\PHPUnit_Framework_MockObject_MockObject $listener */
        $listener = $this->getMockBuilder(stdClass::class)->setMethods(['__invoke'])->getMock();

        $listener
            ->expects(self::once())
            ->method('__invoke')
            ->with($proxy, $realInstance, '__get', ['name' => $propertyName]);

        $proxy->setMethodPrefixInterceptor(
            '__get',
            function ($proxy, $instance, $method, $params, & $returnEarly) use ($listener) : void {
                $listener($proxy, $instance, $method, $params, $returnEarly);
            }
        );

        /** @var callable $accessor */
        $accessor = [$callerObject, $method];

        self::assertSame($expectedValue, $accessor($proxy));
    }

    /**
     * @group        276
     *
     * @dataProvider getMethodsThatAccessPropertiesOnOtherObjectsInTheSameScope
     *
     */
    public function testWillInterceptAccessToPropertiesViaFriendClassAccessEvenIfCloned(
        object $callerObject,
        object $realInstance,
        string $method,
        string $expectedValue,
        string $propertyName
    ) : void {
        $proxyName = $this->generateProxy(get_class($realInstance));
        /** @var AccessInterceptorValueHolderInterface $proxy */
        $proxy = clone $proxyName::staticProxyConstructor($realInstance);

        /** @var callable|\PHPUnit_Framework_MockObject_MockObject $listener */
        $listener = $this->getMockBuilder(stdClass::class)->setMethods(['__invoke'])->getMock();

        $listener
            ->expects(self::once())
            ->method('__invoke')
            ->with($proxy, $realInstance, '__get', ['name' => $propertyName]);

        $proxy->setMethodPrefixInterceptor(
            '__get',
            function ($proxy, $instance, $method, $params, & $returnEarly) use ($listener) : void {
                $listener($proxy, $instance, $method, $params, $returnEarly);
            }
        );

        /** @var callable $accessor */
        $accessor = [$callerObject, $method];

        self::assertSame($expectedValue, $accessor($proxy));
    }

    public function getMethodsThatAccessPropertiesOnOtherObjectsInTheSameScope() : \Generator
    {
        $proxyClass = $this->generateProxy(OtherObjectAccessClass::class);

        foreach ((new \ReflectionClass(OtherObjectAccessClass::class))->getProperties() as $property) {
            $property->setAccessible(true);

            $propertyName  = $property->getName();
            $realInstance  = new OtherObjectAccessClass();
            $expectedValue = uniqid('', true);

            $property->setValue($realInstance, $expectedValue);

            // callee is an actual object
            yield OtherObjectAccessClass::class . '#$' . $propertyName => [
                new OtherObjectAccessClass(),
                $realInstance,
                'get' . ucfirst($propertyName),
                $expectedValue,
                $propertyName,
            ];

            $realInstance  = new OtherObjectAccessClass();
            $expectedValue = uniqid('', true);

            $property->setValue($realInstance, $expectedValue);

            // callee is a proxy (not to be lazy-loaded!)
            yield '(proxy) ' . OtherObjectAccessClass::class . '#$' . $propertyName => [
                $proxyClass::staticProxyConstructor(new OtherObjectAccessClass()),
                $realInstance,
                'get' . ucfirst($propertyName),
                $expectedValue,
                $propertyName,
            ];
        }
    }

    /**
     * @group 327
     */
    public function testWillInterceptAndReturnEarlyOnVoidMethod() : void
    {
        $skip      = random_int(100, 200);
        $addMore   = random_int(201, 300);
        $increment = random_int(301, 400);

        $proxyName = $this->generateProxy(VoidCounter::class);

        /** @var VoidCounter $object */
        $object = $proxyName::staticProxyConstructor(
            new VoidCounter(),
            [
                'increment' => function (
                    VoidCounter $proxy,
                    VoidCounter $instance,
                    string $method,
                    array $params,
                    ?bool & $returnEarly
                ) use ($skip) : void {
                    if ($skip !== $params['amount']) {
                        return;
                    }

                    $returnEarly = true;
                },
            ],
            [
                'increment' => function (
                    VoidCounter $proxy,
                    VoidCounter $instance,
                    string $method,
                    array $params,
                    ?bool & $returnEarly
                ) use ($addMore) : void {
                    if ($addMore !== $params['amount']) {
                        return;
                    }

                    $instance->counter += 1;
                },
            ]
        );

        $object->increment($skip);
        self::assertSame(0, $object->counter);

        $object->increment($increment);
        self::assertSame($increment, $object->counter);

        $object->increment($addMore);
        self::assertSame($increment + $addMore + 1, $object->counter);
    }
}
