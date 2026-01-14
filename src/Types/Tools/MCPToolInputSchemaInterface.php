<?php

namespace McpSrv\Types\Tools;

use JsonSerializable;

interface MCPToolInputSchemaInterface extends JsonSerializable {
	public function jsonSerialize(): object;
}