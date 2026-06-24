<?php

declare(strict_types=1);

namespace Antimonial\Controller;

use Antimonial\Core\ValidationException;
use Antimonial\Http\Request;
use Antimonial\Http\Response;

/**
 * Base controller class.
 *
 * Provides convenience methods for common controller operations:
 * rendering views, creating JSON responses, redirects, and
 * inline request validation.
 *
 * Controllers have NO injected dependencies. Import what you need
 * explicitly — this is by design, not a limitation.
 *
 * @see Request
 * @see Response
 * @see ValidationException
 */
class Controller
{
    /**
     * Render a view and return it as a Response.
     *
     * @example return $this->view('users/index', ['users' => $users], 'layouts/main');
     *
     * @param string      $path   View path relative to app/Views (e.g. 'users/index')
     * @param array       $data   Variables available in the view
     * @param string|null $layout Optional layout to wrap the view in
     * @return Response
     * @see \Antimonial\View\View::renderWithLayout()
     */
    protected function view(string $path, array $data = [], ?string $layout = null): Response
    {
        return view($path, $data, $layout);
    }

    /**
     * Create a JSON response.
     *
     * @example return $this->json(['users' => $users]);
     *
     * @param mixed $data   Data to encode (arrays, objects, etc.)
     * @param int   $status HTTP status code
     * @return Response
     * @see Response::json()
     */
    protected function json(mixed $data, int $status = 200): Response
    {
        return (new Response())->json($data, $status);
    }

    /**
     * Create a redirect response.
     *
     * @example return $this->redirect('/users/42');
     *
     * @param string $url    The URL to redirect to
     * @param int    $status HTTP status (301, 302, etc.)
     * @return Response
     * @see redirect()
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return redirect($url, $status);
    }

    /**
     * Redirect back to the previous page (HTTP_REFERER).
     *
     * Falls back to '/' if no Referer header is present.
     *
     * @param Request $request
     * @return Response
     */
    protected function back(Request $request): Response
    {
        $referer = $request->header('referer', '/');
        return redirect((string) $referer);
    }

    /**
     * Validate request data against a set of rules.
     *
     * Returns the validated (and filtered) data on success.
     * Throws ValidationException on failure.
     *
     * Supported rules:
     *  - required        Field must be present and non-empty
     *  - email           Must be a valid email address
     *  - min:N           Minimum length (strings) or value (numbers)
     *  - max:N           Maximum length (strings) or value (numbers)
     *  - in:v1,v2,...    Must be one of the listed values
     *  - numeric         Must be numeric
     *  - alpha           Must contain only letters
     *  - alpha_num       Must contain only letters and numbers
     *
     * @example
     *   $data = $this->validate($request, [
     *       'name'  => 'required|min:2',
     *       'email' => 'required|email',
     *   ]);
     *
     * @param Request               $request
     * @param array<string, string> $rules  Field name -> pipe-separated rules
     * @return array<string, mixed> Validated data
     * @throws ValidationException If validation fails
     * @see ValidationException::errors()
     */
    protected function validate(Request $request, array $rules): array
    {
        $data = $request->all();
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = array_map('trim', explode('|', $ruleString));
            $value = $data[$field] ?? null;
            $fieldErrors = [];

            foreach ($ruleList as $rule) {
                $error = $this->applyRule($rule, $field, $value, $data);
                if ($error !== null) {
                    $fieldErrors[] = $error;
                }
            }

            if (!empty($fieldErrors)) {
                $errors[$field] = $fieldErrors;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $data;
    }

    /**
     * Apply a single validation rule.
     *
     * @param string               $rule
     * @param string               $field
     * @param mixed                $value
     * @param array<string, mixed> $allData
     * @return string|null Error message or null if valid
     */
    private function applyRule(string $rule, string $field, mixed $value, array $allData): ?string
    {
        // Handle parameterized rules (e.g. 'min:3')
        $params = explode(':', $rule);
        $ruleName = $params[0];
        $paramValue = $params[1] ?? null;

        return match ($ruleName) {
            'required' => ($value === null || $value === '')
                ? "The {$field} field is required."
                : null,

            'email' => ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL))
                ? "The {$field} field must be a valid email address."
                : null,

            'min' => ($value !== null && $value !== '' && strlen((string) $value) < (int) $paramValue)
                ? "The {$field} field must be at least {$paramValue} characters."
                : null,

            'max' => ($value !== null && $value !== '' && strlen((string) $value) > (int) $paramValue)
                ? "The {$field} field must not exceed {$paramValue} characters."
                : null,

            'in' => ($value !== null && $value !== '' && !in_array((string) $value, explode(',', $paramValue), true))
                ? "The {$field} field must be one of: {$paramValue}."
                : null,

            'numeric' => ($value !== null && $value !== '' && !is_numeric($value))
                ? "The {$field} field must be numeric."
                : null,

            'alpha' => ($value !== null && $value !== '' && !preg_match('/^[a-zA-Z]+$/', (string) $value))
                ? "The {$field} field must contain only letters."
                : null,

            'alpha_num' => ($value !== null && $value !== '' && !preg_match('/^[a-zA-Z0-9]+$/', (string) $value))
                ? "The {$field} field must contain only letters and numbers."
                : null,

            default => null,
        };
    }
}
