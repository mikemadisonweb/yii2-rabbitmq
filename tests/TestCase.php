<?php

namespace mikemadisonweb\rabbitmq\tests;

use mikemadisonweb\rabbitmq\DependencyInjection;
use Yii;
use yii\helpers\ArrayHelper;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public static $params;

    /**
     * Clean up after test case.
     */
    public static function tearDownAfterClass()
    {
        parent::tearDownAfterClass();
        $logger = Yii::getLogger();
        $logger->flush();
    }

    /**
     * Clean up after test.
     * By default the application created with [[mockApplication]] will be destroyed.
     */
    protected function tearDown()
    {
        parent::tearDown();
        $this->destroyApplication();
    }

    /**
     * Populates Yii::$app with a new application
     * The application will be destroyed on tearDown() automatically.
     * @param array $config The application configuration, if needed
     * @param string $appClass name of the application class to create
     */
    protected function mockApplication($config = [], $appClass = '\yii\console\Application')
    {
        new $appClass(ArrayHelper::merge([
            'id' => 'testapp',
            'basePath' => __DIR__,
            'vendorPath' => $this->getVendorPath(),
        ], $config));
    }

    protected function getVendorPath()
    {
        $vendor = dirname(__DIR__, 2) . '/vendor';
        if (!is_dir($vendor)) {
            $vendor = dirname(__DIR__, 4);
        }
        return $vendor;
    }

    /**
     * Destroys application in Yii::$app by setting it to null.
     */
    protected function destroyApplication()
    {
        if (\Yii::$app && \Yii::$app->has('session', true)) {
            \Yii::$app->session->close();
        }
        \Yii::$app = null;
    }

    /**
     * Invokes a inaccessible method.
     * @param $object
     * @param $method
     * @param array $args
     * @param bool $revoke whether to make method inaccessible after execution
     * @return mixed
     * @since 2.0.11
     */
    protected function invokeMethod($object, $method, $args = [], $revoke = true)
    {
        $reflection = new \ReflectionObject($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        $result = $method->invokeArgs($object, $args);
        if ($revoke) {
            $method->setAccessible(false);
        }
        return $result;
    }

    /**
     * Sets an inaccessible object property to a designated value.
     * @param $object
     * @param $propertyName
     * @param $value
     * @param bool $revoke whether to make property inaccessible after setting
     * @since 2.0.11
     */
    protected function setInaccessibleProperty($object, $propertyName, $value, $revoke = true)
    {
        $class = new \ReflectionClass($object);
        while (!$class->hasProperty($propertyName)) {
            $class = $class->getParentClass();
        }
        $property = $class->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
        if ($revoke) {
            $property->setAccessible(false);
        }
    }

    /**
     * Gets an inaccessible object property.
     * @param $object
     * @param $propertyName
     * @param bool $revoke whether to make property inaccessible after getting
     * @return mixed
     */
    protected function getInaccessibleProperty($object, $propertyName, $revoke = true)
    {
        $class = new \ReflectionClass($object);
        while (!$class->hasProperty($propertyName)) {
            $class = $class->getParentClass();
        }
        $property = $class->getProperty($propertyName);
        $property->setAccessible(true);
        $result = $property->getValue($object);
        if ($revoke) {
            $property->setAccessible(false);
        }
        return $result;
    }

    /**
     * Load extension to test app instance
     * @param array $config
     * @throws \mikemadisonweb\rabbitmq\exceptions\InvalidConfigException
     */
    protected function loadExtension(array $config)
    {
        $this->mockApplication($config);
        $di = new DependencyInjection();
        $di->bootstrap(\Yii::$app);
    }
}