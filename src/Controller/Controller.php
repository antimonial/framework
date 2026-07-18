<?php

declare(strict_types=1);

namespace Antimonial\Controller;

use Antimonial\Database\DB;
use Antimonial\Http\Request;
use Antimonial\Http\Response;
use Antimonial\Http\UploadedFile;
use Antimonial\Http\ValidationException;
use Antimonial\View\View;

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
     * @param  string  $path  View path relative to app/Views (e.g. 'users/index')
     * @param  array<string, mixed>  $data  Variables available in the view
     * @param  string|null  $layout  Optional layout to wrap the view in
     *
     * @see View::renderWithLayout()
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
     * @param  mixed  $data  Data to encode (arrays, objects, etc.)
     * @param  int  $status  HTTP status code
     *
     * @see Response::json()
     */
    protected function json(mixed $data, int $status = 200): Response
    {
        return (new Response)->json($data, $status);
    }

    /**
     * Create a redirect response.
     *
     * @example return $this->redirect('/users/42');
     *
     * @param  string  $url  The URL to redirect to
     * @param  int  $status  HTTP status (301, 302, etc.)
     *
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
     * @param  Request  $request  The current request
     * @return Response A redirect response
     */
    protected function back(Request $request): Response
    {
        /** @var string $referer */
        $referer = $request->header('referer', '/') ?? '/';

        return redirect($referer);
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
     * @param  array<string, string>  $rules  Field name -> pipe-separated rules
     * @return array<string, mixed> Validated data
     *
     * @throws ValidationException If validation fails
     *
     * @see ValidationException::errors()
     */
    protected function validate(Request $request, array $rules): array
    {
        $data = $request->all();
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = array_map('trim', explode('|', $ruleString));
            $value = $data[$field] ?? '';
            $fieldErrors = [];

            // A field carrying any file rule is validated against the
            // UploadedFile object, never through the string-based path.
            $hasFileRule = $this->hasFileRule($ruleList);

            // HTML inputs with array notation (e.g. name="tags[]") submit an
            // array. Only `required` and an explicit `array` rule may inspect
            // it; any other rule must yield a normal validation error rather
            // than throwing a TypeError from strlen()/preg_match() on an array.
            if (is_array($value)) {
                $fieldErrors = array_merge($fieldErrors, $this->applyArrayRules($ruleList, $field, $value));

                if (! empty($fieldErrors)) {
                    $errors[$field] = $fieldErrors;
                }

                continue;
            }

            /** @var string $value Non-array values are treated as strings here. */
            $value = is_string($value) ? $value : '';

            foreach ($ruleList as $rule) {
                if ($hasFileRule) {
                    $error = $this->applyFileRule($rule, $field, $request);
                } else {
                    $error = $this->applyRule($rule, $field, $value, $data);
                }

                if ($error !== null) {
                    $fieldErrors[] = $error;
                }
            }

            if (! empty($fieldErrors)) {
                $errors[$field] = $fieldErrors;
            }
        }

        if (! empty($errors)) {
            throw new ValidationException($errors);
        }

        $validated = [];
        foreach ($rules as $field => $unused) {
            $validated[$field] = $data[$field] ?? null;
        }

        return $validated;
    }

    /**
     * Whether any of the rules for a field is a file rule.
     *
     * @param  string[]  $ruleList
     */
    private function hasFileRule(array $ruleList): bool
    {
        foreach ($ruleList as $rule) {
            $name = explode(':', $rule)[0];
            if (in_array($name, ['file', 'image', 'mimes', 'max_size'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply the rules that are valid for an array-valued field.
     *
     * Only `required` (fail when the array is empty) and an explicit
     * `array` rule are evaluated against the list. Every other rule name
     * applied to an array field produces a normal validation error
     * ("must be a single value, not a list") instead of a PHP-level
     * TypeError from strlen()/preg_match() on an array.
     *
     * @param  string[]  $ruleList  The pipe-split rules for the field
     * @param  string  $field  Field being validated
     * @param  mixed[]  $value  The submitted array value
     * @return string[] Error messages (empty if all rules pass)
     */
    private function applyArrayRules(array $ruleList, string $field, array $value): array
    {
        $errors = [];

        foreach ($ruleList as $rule) {
            $name = explode(':', $rule)[0];

            if ($name === 'required') {
                if ($value === []) {
                    $errors[] = 'The '.$field.' field is required.';
                }

                continue;
            }

            if ($name === 'array') {
                continue;
            }

            $errors[] = 'The '.$field.' field must be a single value, not a list.';
        }

        return $errors;
    }

    /**
     * Apply a single validation rule.
     *
     * @param  string  $rule  Rule name with optional ":param" suffix
     * @param  string  $field  Field being validated
     * @param  string  $value  Field value
     * @param  array<string, mixed>  $allData  All submitted data
     * @return string|null Error message or null if valid
     */
    private function applyRule(string $rule, string $field, string $value, array $allData): ?string
    {
        // Handle parameterized rules (e.g. 'min:3')
        $params = explode(':', $rule);
        $ruleName = $params[0];
        $paramValue = $params[1] ?? null;
        $paramStr = (string) $paramValue;

        $isNum = is_numeric($value);

        return match ($ruleName) {
            'required' => ($value === '')
                ? 'The '.$field.' field is required.'
                : null,

            'email' => ($value !== '' && ! filter_var($value, FILTER_VALIDATE_EMAIL))
                ? 'The '.$field.' field must be a valid email address.'
                : null,

            'min' => ($value !== '' && ! $this->passesLength($isNum, $value, $paramValue, false))
                ? 'The '.$field.' field must be at least '.$paramStr.'.'
                : null,

            'max' => ($value !== '' && ! $this->passesLength($isNum, $value, $paramValue, true))
                ? 'The '.$field.' field must not exceed '.$paramStr.'.'
                : null,

            'in' => ($value !== '' && ! in_array($value, explode(',', $paramStr), true))
                ? 'The '.$field.' field must be one of: '.$paramStr.'.'
                : null,

            'numeric' => ($value !== '' && ! is_numeric($value))
                ? 'The '.$field.' field must be numeric.'
                : null,

            'alpha' => ($value !== '' && ! preg_match('/^[a-zA-Z]+$/', $value))
                ? 'The '.$field.' field must contain only letters.'
                : null,

            'alpha_num' => ($value !== '' && ! preg_match('/^[a-zA-Z0-9]+$/', $value))
                ? 'The '.$field.' field must contain only letters and numbers.'
                : null,

            'unique' => ($value === '')
                ? null
                : (! $this->isUnique($paramStr, $field, $value)
                    ? 'The '.$field.' has already been taken.'
                    : null),

            'exists' => ($value === '')
                ? null
                : (! $this->recordExists($paramStr, $field, $value)
                    ? 'The selected '.$field.' is invalid.'
                    : null),

            default => null,
        };
    }

    /**
     * Whether a value is unique in the database.
     *
     * Rule format: unique:table,column (column defaults to the field name).
     *
     * @param  string  $paramStr  The "table,column" parameter string
     * @param  string  $field  The field being validated
     * @param  string  $value  The value to check
     */
    private function isUnique(string $paramStr, string $field, string $value): bool
    {
        [$table, $column] = $this->parseDbRule($paramStr, $field);

        return ! DB::table($table)->where($column, $value)->exists();
    }

    /**
     * Whether a record exists in the database for the value.
     *
     * Rule format: exists:table,column (column defaults to the field name).
     *
     * @param  string  $paramStr  The "table,column" parameter string
     * @param  string  $field  The field being validated
     * @param  string  $value  The value to check
     */
    private function recordExists(string $paramStr, string $field, string $value): bool
    {
        [$table, $column] = $this->parseDbRule($paramStr, $field);

        return DB::table($table)->where($column, $value)->exists();
    }

    /**
     * Parse a "table,column" DB-rule parameter.
     *
     * @return array{0: string, 1: string} [table, column]
     */
    private function parseDbRule(string $paramStr, string $field): array
    {
        /** @var string[] $parts */
        $parts = explode(',', $paramStr);
        $table = $parts[0] ?? '';
        $column = $parts[1] ?? $field;

        return [$table, $column];
    }

    /**
     * Apply a single file validation rule.
     *
     * File rules operate on the UploadedFile for the field (resolved via
     * $request->file()), not on the string data array. A missing file is
     * treated as "no error" here — absence is enforced by the `required`
     * rule, consistent with the project's rule-composition convention.
     *
     * @param  string  $rule  Rule name with optional ":param" suffix
     * @param  string  $field  Field being validated
     * @param  Request  $request  The current request
     * @return string|null Error message or null if valid
     */
    private function applyFileRule(string $rule, string $field, Request $request): ?string
    {
        $params = explode(':', $rule);
        $ruleName = $params[0];
        $paramStr = (string) ($params[1] ?? '');

        $file = $request->file($field);

        // No file present: let `required` handle absence.
        if ($file === null) {
            return null;
        }

        return match ($ruleName) {
            'file' => $file->isValid()
                ? null
                : 'The '.$field.' file is invalid: '.$file->errorMessage(),

            'image' => (! $file->isValid())
                ? 'The '.$field.' file is invalid: '.$file->errorMessage()
                : (str_starts_with($file->mimeType(), 'image/')
                    ? null
                    : 'The '.$field.' must be an image.'),

            'mimes' => (! $file->isValid())
                ? 'The '.$field.' file is invalid: '.$file->errorMessage()
                : (in_array(strtolower($file->clientExtension()), explode(',', $paramStr), true)
                    ? null
                    : 'The '.$field.' must be a file of type: '.$paramStr.'.'),

            'max_size' => (! $file->isValid())
                ? 'The '.$field.' file is invalid: '.$file->errorMessage()
                : (($file->size() / 1024) <= (float) $paramStr
                    ? null
                    : 'The '.$field.' must not be larger than '.$paramStr.' kilobytes.'),

            default => null,
        };
    }

    /**
     * Check a min/max length-or-numeric rule.
     *
     * Numeric values are compared numerically; everything else is
     * compared by string length.
     *
     * @param  bool  $isNum  Whether the value parses as a number
     * @param  string  $value  The field value
     * @param  string|null  $limit  The numeric/length limit from the rule
     * @param  bool  $upper  true for max (greater-than), false for min (less-than)
     */
    private function passesLength(bool $isNum, string $value, ?string $limit, bool $upper): bool
    {
        $limit = (float) $limit;

        return $isNum
            ? ($upper ? (float) $value <= $limit : (float) $value >= $limit)
            : ($upper ? strlen($value) <= (int) $limit : strlen($value) >= (int) $limit);
    }
}
