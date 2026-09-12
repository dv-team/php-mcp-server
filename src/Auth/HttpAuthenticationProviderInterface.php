<?php

declare(strict_types=1);

namespace McpSrv\Auth;

use McpSrv\Common\HttpRequest;

/** Implemented by the application; the library does not interpret credentials. */
interface HttpAuthenticationProviderInterface {
	/**
	 * Return the application's identity/context for this request.
	 * @throws HttpAuthenticationException When access is denied.
	 */
	public function authenticate(HttpRequest $request): object;
}
