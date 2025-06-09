<?php

namespace McpSrv\Common\Properties;

use McpSrv\Types\Tools\MCPToolProperty;

/**
 * @phpstan-type TBooleanStruct array{
 *     type: 'boolean',
 *     description: string,
 *     required: bool,
 *     default?: bool
 * }
 */
readonly class MCPToolBoolean implements MCPToolProperty {
	public function __construct(
		public string $name,
		public string $description,
		public bool $required = false,
		public ?bool $default = null
	) {}
	
	public function getName(): string {
		return $this->name;
	}
	
	public function isRequired(): bool {
		return $this->required;
	}
	
	/**
	 * @return TBooleanStruct
	 */
	public function jsonSerialize(): array {
		$result = [
			'type' => 'boolean',
			'description' => $this->description,
			'required' => $this->required,
		];
		
		if($this->default !== null) {
			$result['default'] = $this->default;
		}
		
		return $result;
	}
}
