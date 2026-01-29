<?php

namespace McpSrv\Common\Properties;

use InvalidArgumentException;
use McpSrv\Types\Tools\MCPToolProperty;

/**
 * @phpstan-type TEnumStruct object{
 *     type: 'array',
 *     description: string,
 *     items: TItem,
 *     minItems?: int,
 *     maxItems?: int,
 *     uniqueItems?: bool,
 *     default?: scalar[]
 * }
 *
 * @phpstan-type TItem array{
 *     enum: scalar[],
 *     type: 'boolean' | 'integer' | 'number' | 'string'
 * }
 */
readonly class MCPToolEnum implements MCPToolProperty {
	/**
	 * @param scalar[] $options
	 * @param scalar[]|null $default
	 */
	public function __construct(
		public string $name,
		public string $description,
		public array $options,
		public bool $required = false,
		public ?int $minItems = null,
		public ?int $maxItems = null,
		public ?bool $uniqueItems = null,
		public ?array $default = null
	) {
		if(!count($this->options)) {
			throw new InvalidArgumentException('At least one option must be provided');
		}
		
		if($this->default !== null) {
			foreach($this->default as $value) {
				if(!in_array($value, $this->options, true)) {
					throw new InvalidArgumentException('Default values must be part of the provided options');
				}
			}
		}
	}
	
	public function getName(): string {
		return $this->name;
	}
	
	public function isRequired(): bool {
		return $this->required;
	}
	
	/**
	 * @return TEnumStruct
	 */
	public function jsonSerialize(): object {
		$optionTypes = array_map('get_debug_type', $this->options);
		$uniqueTypes = array_values(array_unique($optionTypes));
		
		foreach($uniqueTypes as $optionType) {
			if(!in_array($optionType, ['bool', 'int', 'float', 'string'], true)) {
				throw new InvalidArgumentException('Enum options must be scalar types');
			}
		}
		
		if(count($uniqueTypes) !== 1) {
			throw new InvalidArgumentException('Enum options must share the same scalar type');
		}
		
		$type = match($uniqueTypes[0]) {
			'bool' => 'boolean',
			'int' => 'integer',
			'float' => 'number',
			'string' => 'string',
		};
		
		$result = [
			'type' => 'array',
			'description' => $this->description,
			'items' => [
				'enum' => $this->options,
				'type' => $type,
			],
		];
		
		if($this->minItems !== null) {
			$result['minItems'] = $this->minItems;
		}
		
		if($this->maxItems !== null) {
			$result['maxItems'] = $this->maxItems;
		}
		
		if($this->uniqueItems !== null) {
			$result['uniqueItems'] = $this->uniqueItems;
		}
		
		if($this->default !== null) {
			$result['default'] = $this->default;
		}
		
		return (object) $result;
	}
}
