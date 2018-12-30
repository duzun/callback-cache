<?php
namespace duzun\CallbackCache\Tests;

use PHPUnit\Framework\TestCase;
use duzun\CallbackCache\PHP as CCPHP;

class CCPHPTest extends TestCase {

    private static $_producer;
    private static $_dataProducerCounter = 0;

	public static function setupBeforeClass() {
        parent::setUpBeforeClass();

        CCPHP::$base_dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
        self::$_producer = new CCPHP([self::class, 'produceData'], 1e3, './', 1e2);
        self::$_producer->clear(1);
	}

    public static function tearDownAfterClass() {
        // remove old stuff, but keep newly generated cache items for inspection
        self::$_producer->clear(60);

        self::$_producer = NULL;

        parent::tearDownAfterClass();
    }

	public static function produceData($x) {
	    ++self::$_dataProducerCounter;
	    return [$x, $x *= $x, $x *= $x];
	}

	public function test_invoke() {
	    $producer = self::$_producer;
        $counter = self::$_dataProducerCounter;

        $producer->delete(1);
        $d1 = $producer->get(1);
        $this->assertNull($d1);

        $e1 = [1,1,1];

        // Generate
        $d1 = $producer(1);
        $this->assertEquals($e1, $d1);

        // Get from cache
        $d1 = $producer(1);
        $this->assertEquals($e1, $d1);
        $this->assertEquals(++$counter, self::$_dataProducerCounter);

        $e2 = [2, 2*2, 2*2*2*2];

	    // Generate new value for a new argument
        $d2 = $producer(2);
	    $this->assertEquals($e2, $d2);
        $this->assertEquals(++$counter, self::$_dataProducerCounter);

        // Get from cache
        $d2 = $producer(2);
        $this->assertEquals($e2, $d2);
        $this->assertEquals($counter, self::$_dataProducerCounter);

        // Generate same value as for 2, just because we have an extra argument
        $d2 = $producer(2, [3.0, '4']); // cache filename would be a hash, because not all arguments are scalar values
        $this->assertEquals($e2, $d2);
        $this->assertEquals(++$counter, self::$_dataProducerCounter);

        $d2 = $producer->getItem(2);
        $this->assertEquals($e2, $d2);
        $this->assertEquals($counter, self::$_dataProducerCounter);

        return [
            [[1], $e1],
            [[2], $e2],
            [[2, [3.0, '4']], $e2],
        ];
	}

    /**
     * @depends test_invoke
     */
    public function test_refreshItem($cases) {
        $producer = self::$_producer;
        $counter = self::$_dataProducerCounter;

        foreach($cases as $v) {
            list($args, $e) = $v;
            $d = $producer->refreshItem(...$args);
            $this->assertEquals($e, $d);
            $this->assertEquals(++$counter, self::$_dataProducerCounter);
        }
    }

    /**
     * @depends test_refresh
     */
    public function test_delete() {
        $producer = self::$_producer;
        $counter = self::$_dataProducerCounter;

        $d1 = $producer->get(1);
        $this->assertNotNull($d1);

        $producer->delete(1);
        $d1 = $producer->get(1);
        $this->assertNull($d1);

        $d2 = $producer->get(2);
        $this->assertNotNull($d2);

        $producer->delete(2);
        $d2 = $producer->getItem(2);
        $this->assertNull($d2);

        $this->assertEquals($counter, self::$_dataProducerCounter);
    }



}
