<?php

namespace McpSrv\Types\Tools;

use JsonSerializable;

interface MCPToolProperty extends JsonSerializable {
	public function getName(): string;
	public function isRequired(): bool;
	
	/**
	 * @return object{type: string}
	 */
	public function jsonSerialize(): object;
}