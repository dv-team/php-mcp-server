<?php

namespace McpSrv\Common\Properties;

use McpSrv\Types\Tools\MCPToolProperty;

/**
 * @phpstan-type TStringStruct object{
 *     type: 'string',
 *     description: string,
 *     minLength?: int,
 *     pattern?: string,
 *     default?: string
 * }
 */
class MCPToolString implements MCPToolProperty {
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly bool $required = false,
		public readonly ?int $minLength = null,
		public readonly ?string $pattern = null,
		public readonly ?string $default = null
	) {}
	
	public function getName(): string {
		return $this->name;
	}
	
	public function isRequired(): bool {
		return $this->required;
	}
	
	/**
	 * @return TStringStruct
	 */
	public function jsonSerialize(): object {
		$result = [
			'type' => 'string',
			'description' => $this->description,
		];
		
		if($this->minLength !== null) {
			$result['minLength'] = $this->minLength;
		}
		
		if($this->pattern !== null) {
			$result['pattern'] = $this->pattern;
		}
		
		if($this->default !== null) {
			$result['default'] = $this->default;
		}
		
		return (object) $result;
	}
}
