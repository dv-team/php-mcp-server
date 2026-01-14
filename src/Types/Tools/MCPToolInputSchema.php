<?php

namespace McpSrv\Types\Tools;

class MCPToolInputSchema implements MCPToolInputSchemaInterface {
	/**
	 * @param string[] $required
	 * @param MCPToolProperties $properties
	 */
	public function __construct(
		public readonly MCPToolProperties $properties,
		public readonly array $required = [],
	) {}
	
	/**
	 * @return string[]
	 */
	public function getRequired(): array {
		$requiredProperties = [];
		foreach($this->properties as $property) {
			if($property->isRequired()) {
				$requiredProperties[] = $property->getName();
			}
		}
		return $requiredProperties;
	}
	
	/**
	 * @return object{
	 *     type: 'object',
	 *     properties: array<string, array<string, mixed>>|object,
	 *     required?: string[]
	 * }
	 */
	public function jsonSerialize(): object {
		$required = array_values(array_unique(array_merge($this->required, $this->getRequired())));
		
		$result = [
			'type' => 'object',
			'properties' => $this->properties->jsonSerialize(),
		];
		
		if(count($required)) {
			$result['required'] = $required;
		}
		
		return (object) $result;
	}
}
