<?php

namespace McpSrv\Common\Resources;

class ResourceRequest {
	public function __construct(
		public string $uri,
		public object $parameters,
		public object $arguments,
	) {}
}