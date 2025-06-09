<?php

namespace McpSrv\Types\Prompts\PromptResult;

use JsonSerializable;
use McpSrv\Common\PromptResult\RoleEnum;

class PromptResultStringMessage implements PromptResultMessage, JsonSerializable {
	public function __construct(
		public readonly RoleEnum $role,
		public readonly string $content,
	) {}
	
	/**
	 * @return array{
	 *     role: string,
	 *     content: array{
	 *         type: string,
	 *         text: string
	 *     }
	 * }
	 */
	public function jsonSerialize(): array {
		return [
			'role' => $this->role->value,
			'content' => [
				'type' => 'text',
				'text' => $this->content,
			],
		];
	}
}