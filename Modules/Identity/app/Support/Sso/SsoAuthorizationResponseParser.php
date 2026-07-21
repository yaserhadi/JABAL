<?php

namespace Modules\Identity\Support\Sso;

use Illuminate\Http\Request;
use Modules\Identity\Exceptions\SsoSecurityException;

/**
 * BK-082 WS4 / IH-7: single-source Authorization Response parser (never merge query + body).
 */
final class SsoAuthorizationResponseParser
{
    public const MODE_QUERY = 'query';

    public const MODE_FORM_POST = 'form_post';

    public const MAX_BODY_BYTES = 8192;

    /**
     * @return array{code: ?string, state: string, error: ?string, error_description: ?string}
     */
    public function parse(Request $request, string $responseMode): array
    {
        $mode = strtolower(trim($responseMode));

        if (! in_array($mode, [self::MODE_QUERY, self::MODE_FORM_POST], true)) {
            throw new SsoSecurityException('Unsupported OIDC response_mode.');
        }

        if ($mode === self::MODE_FORM_POST) {
            return $this->parseFormPost($request);
        }

        return $this->parseQuery($request);
    }

    /**
     * @return array{code: ?string, state: string, error: ?string, error_description: ?string}
     */
    protected function parseQuery(Request $request): array
    {
        if (! $request->isMethod('GET')) {
            throw new SsoSecurityException('Query response_mode requires GET.');
        }

        if ($request->getContent() !== '' && $request->getContent() !== false) {
            throw new SsoSecurityException('Query response_mode must not include a body.');
        }

        $raw = (string) $request->server->get('QUERY_STRING', '');
        $params = $this->parseRawUrlEncoded($raw);

        return $this->normalizeSecurityParams($params);
    }

    /**
     * @return array{code: ?string, state: string, error: ?string, error_description: ?string}
     */
    protected function parseFormPost(Request $request): array
    {
        if (! $request->isMethod('POST')) {
            throw new SsoSecurityException('form_post response_mode requires POST.');
        }

        $contentType = strtolower(trim((string) (
            $request->headers->get('Content-Type')
            ?: $request->server->get('CONTENT_TYPE')
            ?: $request->server->get('HTTP_CONTENT_TYPE')
            ?: ''
        )));
        $mediaType = trim(explode(';', $contentType)[0] ?? '');

        if ($mediaType !== 'application/x-www-form-urlencoded') {
            throw new SsoSecurityException('form_post requires application/x-www-form-urlencoded.');
        }

        $queryString = (string) ($request->getQueryString() ?? $request->server->get('QUERY_STRING', '') ?? '');
        if ($request->query->count() > 0 || $queryString !== '') {
            throw new SsoSecurityException('form_post must not include query parameters.');
        }

        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new SsoSecurityException('Authorization Response body exceeds size limit.');
        }

        $params = $this->parseRawUrlEncoded($raw);

        return $this->normalizeSecurityParams($params);
    }

    /**
     * @return array<string, string>
     */
    protected function parseRawUrlEncoded(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $seen = [];
        $out = [];

        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $name = rawurldecode(str_replace('+', ' ', $parts[0]));
            $value = isset($parts[1]) ? rawurldecode(str_replace('+', ' ', $parts[1])) : '';

            if ($name === '') {
                continue;
            }

            if (isset($seen[$name])) {
                throw new SsoSecurityException('Duplicate Authorization Response parameter.');
            }

            $seen[$name] = true;
            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $params
     * @return array{code: ?string, state: string, error: ?string, error_description: ?string}
     */
    protected function normalizeSecurityParams(array $params): array
    {
        foreach (['state', 'code', 'error', 'error_description'] as $key) {
            if (! array_key_exists($key, $params)) {
                continue;
            }

            if (! is_string($params[$key])) {
                throw new SsoSecurityException('Authorization Response parameters must be scalar strings.');
            }
        }

        $state = $params['state'] ?? '';
        if (! is_string($state) || $state === '') {
            throw new SsoSecurityException('Authorization Response requires state.');
        }

        $code = array_key_exists('code', $params) ? (string) $params['code'] : null;
        $error = array_key_exists('error', $params) ? (string) $params['error'] : null;
        $errorDescription = array_key_exists('error_description', $params)
            ? (string) $params['error_description']
            : null;

        if ($code !== null && $code === '') {
            $code = null;
        }

        if ($error !== null && $error === '') {
            $error = null;
        }

        if ($code !== null && $error !== null) {
            throw new SsoSecurityException('Authorization Response must not include both code and error.');
        }

        if ($code === null && $error === null) {
            throw new SsoSecurityException('Authorization Response requires code or error.');
        }

        return [
            'code' => $code,
            'state' => $state,
            'error' => $error,
            'error_description' => $errorDescription,
        ];
    }
}
