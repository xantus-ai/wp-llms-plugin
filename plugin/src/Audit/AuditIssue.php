<?php
declare(strict_types=1);

namespace WPSearch\Audit;

final class AuditIssue {
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    public function __construct(
        public readonly int $post_id,
        public readonly string $rule,
        public readonly string $severity,
        public readonly string $message,
        public readonly ?string $suggested_fix = null
    ) {}

    public function to_row(): array {
        return [
            'post_id' => $this->post_id,
            'rule' => $this->rule,
            'severity' => $this->severity,
            'message' => $this->message,
            'suggested_fix' => $this->suggested_fix,
        ];
    }
}
