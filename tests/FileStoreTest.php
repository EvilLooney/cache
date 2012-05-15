<?php

use Illuminate\Cache\FileStore;

class FileStoreTest extends PHPUnit_Framework_TestCase {

	public function testNullIsReturnedIfFileDoesntExist()
	{
		$files = $this->mockFilesystem();
		$files->expects($this->once())->method('exists')->will($this->returnValue(false));
		$store = new FileStore($files, __DIR__);
		$value = $store->get('foo');
		$this->assertNull($value);
	}


	public function testExpiredItemsReturnNull()
	{
		$files = $this->mockFilesystem();
		$files->expects($this->once())->method('exists')->will($this->returnValue(true));
		$contents = '0000000000';
		$files->expects($this->once())->method('get')->will($this->returnValue($contents));
		$store = $this->getMock('Illuminate\Cache\FileStore', array('removeItem'), array($files, __DIR__));
		$store->expects($this->once())->method('removeItem');
		$value = $store->get('foo');
		$this->assertNull($value);
	}


	public function testValidItemReturnsContents()
	{
		$files = $this->mockFilesystem();
		$files->expects($this->once())->method('exists')->will($this->returnValue(true));
		$contents = '9999999999Hello World';
		$files->expects($this->once())->method('get')->will($this->returnValue($contents));
		$store = new FileStore($files, __DIR__);
		$this->assertEquals('Hello World', $store->get('foo'));
	}


	public function testStoreItemProperlyStoresValues()
	{
		$files = $this->mockFilesystem();
		$store = $this->getMock('Illuminate\Cache\FileStore', array('expiration'), array($files, __DIR__));
		$store->expects($this->once())->method('expiration')->with($this->equalTo(10))->will($this->returnValue(1111111111));
		$contents = '1111111111'.serialize('Hello World');
		$files->expects($this->once())->method('put')->with($this->equalTo(__DIR__.'/foo'), $this->equalTo($contents));
		$store->put('foo', 'Hello World', 10);
	}


	protected function mockFilesystem()
	{
		return $this->getMock('Illuminate\Filesystem');
	}

}