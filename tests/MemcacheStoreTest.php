<?php

class MemcacheStoreTest extends PHPUnit_Framework_TestCase {

	public function testGetReturnsNullWhenNotFound()
	{
		$memcache = $this->getMock('Memcache', array('get'));
		$memcache->expects($this->once())->method('get')->with($this->equalTo('foobar'))->will($this->returnValue(null));
		$store = new Illuminate\Cache\MemcacheStore($memcache, 'foo');
		$this->assertNull($store->get('bar'));
	}


	public function testMemcacheValueIsReturned()
	{
		$memcache = $this->getMock('Memcache', array('get'));
		$memcache->expects($this->once())->method('get')->will($this->returnValue('bar'));
		$store = new Illuminate\Cache\MemcacheStore($memcache);
		$this->assertEquals('bar', $store->get('foo'));
	}


	public function testSetMethodProperlyCallsMemcache()
	{
		$memcache = $this->getMock('Memcache', array('set'));
		$memcache->expects($this->once())->method('set')->with($this->equalTo('foo'), $this->equalTo('bar'), $this->equalTo(0), $this->equalTo(60));
		$store = new Illuminate\Cache\MemcacheStore($memcache);
		$store->put('foo', 'bar', 1);
	}


	public function testForgetMethodProperlyCallsMemcache()
	{
		$memcache = $this->getMock('Memcache', array('delete'));
		$memcache->expects($this->once())->method('delete')->with($this->equalTo('foo'));
		$store = new Illuminate\Cache\MemcacheStore($memcache);
		$store->forget('foo');
	}

}