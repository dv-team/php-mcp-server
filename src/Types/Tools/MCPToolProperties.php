<?php

namespace McpSrv\Types\Tools;

use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * @implements IteratorAggregate<MCPToolProperty>
 */
class MCPToolProperties implements JsonSerializable, IteratorAggregate {
	/** @var array<string, MCPToolProperty> */
	public readonly array $properties;
	
	public function __construct(
		MCPToolProperty ...$properties
	) {
		$props = [];
		foreach($properties as $property) {
			$props[$property->getName()] = $property;
		}
		$this->properties = $props;
	}
	
	public function getByName(string $name): MCPToolProperty {
		if(!array_key_exists($name, $this->properties)) {
			throw new InvalidArgumentException("Property with name '$name' not found in tool input schema.");
		}
		return $this->properties[$name];
	}
	
	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function jsonSerialize(): array {
		$result = [];
		foreach($this->properties as $property) {
			$result[$property->getName()] = $property->jsonSerialize();
		}
		return $result;
	}
	
	public function getIterator(): Traversable {
		yield from $this->properties;
	}
}
