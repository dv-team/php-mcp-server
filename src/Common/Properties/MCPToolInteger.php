<?php

namespace McpSrv\Common\Properties;

use McpSrv\Types\Tools\MCPToolProperty;

/**
 * @phpstan-type TIntegerStruct object{
 *     type: 'integer',
 *     description: string,
 *     required: bool,
 *     minimum?: int,
 *     maximum?: int,
 *     multipleOf?: int,
 *     default?: int
 * }
 */
class MCPToolInteger implements MCPToolProperty {
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly ?int $minimum = null,
		public readonly ?int $maximum = null,
		public readonly ?int $multipleOf = null,
		public readonly bool $required = false,
		public readonly ?int $default = null
	) {}
	
	public function getName(): string {
		return $this->name;
	}
	
	public function isRequired(): bool {
		return $this->required;
	}
	
	/**
	 * @return TIntegerStruct
	 */
	public function jsonSerialize(): object {
		$result = [
			'type' => 'integer',
			'description' => $this->description,
			'required' => $this->required,
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
