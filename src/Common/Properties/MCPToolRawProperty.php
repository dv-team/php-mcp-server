<?php

namespace McpSrv\Common\Properties;

use InvalidArgumentException;
use McpSrv\Types\Tools\MCPToolProperty;

/**
 * @phpstan-type TRawPropertySchema object
 */
readonly class MCPToolRawProperty implements MCPToolProperty {
	private object $schema;

	/**
	 * @param TRawPropertySchema|array<mixed> $schema
	 */
	public function __construct(
		private string $name,
		object|array $schema,
		private bool $required = false,
	) {
		$this->schema = (object) $schema;
		if(!property_exists($this->schema, 'type') || !is_string($this->schema->type)) {
			throw new InvalidArgumentException('Property schema must include a string "type" key');
		}
	}

	public function getName(): string {
		return $this->name;
	}

	public function isRequired(): bool {
		return $this->required;
	}

	/**
	 * @return TRawPropertySchema
	 */
	public function jsonSerialize(): object {
		$schema = (array) $this->schema;
		if(array_key_exists('required', $schema) && !is_array($schema['required'])) {
			unset($schema['required']);
		}

		return (object) $schema;
	}
}
