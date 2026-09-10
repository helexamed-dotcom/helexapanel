<?php
declare(strict_types=1);

namespace HeleXa\Core;

final class Validator
{
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    public function required(string $field, string $label): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->errors[$field] = $label . ' الزامی است.';
        }
        return $this;
    }

    public function length(string $field, string $label, int $min, int $max): self
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = (string) ($this->data[$field] ?? '');
        $len   = mb_strlen($value, 'UTF-8');
        if ($len < $min || $len > $max) {
            $this->errors[$field] = sprintf(
                '%s باید بین %s تا %s کاراکتر باشد.',
                $label,
                \HeleXa\Services\Jalali::digits((string) $min),
                \HeleXa\Services\Jalali::digits((string) $max)
            );
        }
        return $this;
    }

    public function pattern(string $field, string $label, string $regex, string $message): self
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = (string) ($this->data[$field] ?? '');
        if ($value !== '' && preg_match($regex, $value) !== 1) {
            $this->errors[$field] = $message !== '' ? $message : ($label . ' معتبر نیست.');
        }
        return $this;
    }

    public function username(string $field, string $label): self
    {
        return $this->pattern($field, $label, '/^[a-zA-Z0-9._-]{3,64}$/', 'نام کاربری فقط می‌تواند شامل حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط باشد.');
    }

    public function mobile(string $field, string $label): self
    {
        if (($this->data[$field] ?? '') === '') {
            return $this;
        }
        return $this->pattern($field, $label, '/^09\d{9}$/', 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.');
    }

    /**
     * Password policy: at least eight characters, English letters and digits
     * only, and at least one of each.
     *
     * The character check is done on the raw string rather than with mb_*
     * functions on purpose: a Persian digit or a non-breaking space typed by
     * accident must be rejected, not silently normalised into something that
     * the user cannot reproduce on another keyboard.
     */
    public function password(string $field, string $label, int $min = 8): self
    {
        $value = (string) ($this->data[$field] ?? '');
        $min   = max(8, $min);

        if (strlen($value) !== mb_strlen($value, 'UTF-8')) {
            $this->errors[$field] = $label . ' فقط می‌تواند شامل حروف انگلیسی و اعداد باشد.';
            return $this;
        }
        if (mb_strlen($value, 'UTF-8') < $min) {
            // Persian digits: a Latin "8" inside a Persian sentence reads as a
            // stray character to the people who will actually see this message.
            $this->errors[$field] = sprintf(
                '%s باید حداقل %s کاراکتر باشد.',
                $label,
                \HeleXa\Services\Jalali::digits((string) $min)
            );
            return $this;
        }
        if (preg_match('/^[A-Za-z0-9]+$/', $value) !== 1) {
            $this->errors[$field] = $label . ' فقط می‌تواند شامل حروف انگلیسی و اعداد باشد؛ فاصله و کاراکتر خاص مجاز نیست.';
            return $this;
        }
        if (preg_match('/[A-Za-z]/', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
            $this->errors[$field] = $label . ' باید ترکیبی از حروف و عدد باشد.';
        }

        return $this;
    }

    public function matches(string $field, string $otherField, string $message): self
    {
        if (($this->data[$field] ?? null) !== ($this->data[$otherField] ?? null)) {
            $this->errors[$field] = $message;
        }
        return $this;
    }

    public function in(string $field, string $label, array $allowed): self
    {
        if (!in_array($this->data[$field] ?? null, $allowed, true)) {
            $this->errors[$field] = $label . ' معتبر نیست.';
        }
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors === [] ? null : (string) reset($this->errors);
    }
}
