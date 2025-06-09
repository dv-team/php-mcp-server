<?php

namespace McpSrv\Common\Properties;

use McpSrv\Types\Tools\MCPToolProperties;
use McpSrv\Types\Tools\MCPToolProperty;

/**
 * @phpstan-type TObjectStruct array{
 *     type: 'object',
 *     description: string,
 *     required: bool,
 *     properties: array<string, array<string, mixed>>,
 *     minProperties?: int,
 *     maxProperties?: int,
 *     additionalProperties?: bool,
 *     requiredProperties?: string[]
 * }
 */
class MCPToolObject implements MCPToolProperty {
	/**
	 * @param string $name
	 * @param string $description
	 * @param bool $required
	 * @param MCPToolProperties $properties
	 * @param int|null $minProperties
	 * @param int|null $maxProperties
	 * @param bool|null $additionalProperties
	 * @param string[] $requiredProperties
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly MCPToolProperties $properties,
		public readonly bool $required = false,
		public readonly ?int $minProperties = null,
		public readonly ?int $maxProperties = null,
		public readonly ?bool $additionalProperties = null,
		public readonly ?array $requiredProperties = [],
	) {}
	
	public function getName(): string {
		return $this->name;
	}
	
	public function isRequired(): bool {
		return $this->required;
	}
	
	/**
	 * @return TObjectStruct
	 */
	public function jsonSerialize(): array {
		$result = [
			'type' => 'object',
			'description' => $this->description,
			'properties' => $this->properties->jsonSerialize(),
			'required' => $this->required,
		];
		
		if($this->minProperties !== null) {
			$result['minProperties'] = $this->minProperties;
		}
		
		if($this->maxProperties !== null) {
			$result['maxProperties'] = $this->maxProperties;
		}
		
		if($this->additionalProperties !== null) {
			$result['additionalProperties'] = $this->additionalProperties;
		}
		
		if($this->requiredProperties !== null && count($this->requiredProperties)) {
			$result['requiredProperties'] = $this->requiredProperties;
		}
		
		return $result;
	}
}
