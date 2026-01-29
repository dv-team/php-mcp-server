<?php

namespace McpSrv\Common\Properties;

use McpSrv\Types\Tools\MCPToolProperty;

/**
 * @phpstan-type TFloatStruct object{
 *     type: 'number',
 *     description: string,
 *     minimum?: float,
 *     maximum?: float,
 *     multipleOf?: float,
 *     default?: float
 * }
 */
class MCPToolFloat implements MCPToolProperty {
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly ?float $minimum = null,
		public readonly ?float $maximum = null,
		public readonly ?float $multipleOf = null,
		public readonly bool $required = false,
		public readonly ?float $default = null
	) {}
	
	public function getName(): string {
		return $this->name;
	}
	
	public function isRequired(): bool {
		return $this->required;
	}
	
	/**
	 * @return TFloatStruct
	 */
	public function jsonSerialize(): object {
		$result = [
			'type' => 'number',
			'description' => $this->description,
		];
		
		if($this->minimum !== null) {
			$result['minimum'] = $this->minimum;
		}
		
		if($this->maximum !== null) {
			$result['maximum'] = $this->maximum;
		}
		
		if($this->multipleOf !== null) {
			$result['multipleOf'] = $this->multipleOf;
		}
		
		if($this->default !== null) {
			$result['default'] = $this->default;
		}
		
		return (object) $result;
	}
}
