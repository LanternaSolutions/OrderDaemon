<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\Diagnostics;

/**
 * Diagnostic Result - Container for Diagnostic Test Results
 *
 * This class encapsulates the results of a diagnostic test, including
 * success/failure status, messages, detailed information, and recommendations.
 *
 * @package OrderDaemon\CompletionManager\Diagnostics
 */
class DiagnosticResult
{
    /**
     * The name of the diagnostic test
     *
     * @var string
     */
    private string $name;

    /**
     * Whether the diagnostic test was successful
     *
     * @var bool
     */
    private bool $successful;

    /**
     * The main message describing the result
     *
     * @var string
     */
    private string $message;

    /**
     * Detailed information about the test result
     *
     * @var array
     */
    private array $details;

    /**
     * Recommendations for fixing any issues found
     *
     * @var array
     */
    private array $recommendations;

    /**
     * Additional metadata about the test execution
     *
     * @var array
     */
    private array $metadata;

    /**
     * Create a new diagnostic result
     *
     * @param string $name The name of the diagnostic test
     * @param bool $successful Whether the test was successful
     * @param string $message The main result message
     * @param array $details Detailed information about the result
     * @param array $recommendations Recommendations for fixing issues
     * @param array $metadata Additional metadata
     */
    public function __construct(
        string $name,
        bool $successful,
        string $message,
        array $details = [],
        array $recommendations = [],
        array $metadata = []
    ) {
        $this->name = $name;
        $this->successful = $successful;
        $this->message = $message;
        $this->details = $details;
        $this->recommendations = $recommendations;
        $this->metadata = array_merge([
            'timestamp' => current_time('mysql'),
            'execution_time' => null
        ], $metadata);
    }

    /**
     * Get the diagnostic test name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Check if the diagnostic test was successful
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * Get the main result message
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get detailed information about the result
     *
     * @return array
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Get recommendations for fixing any issues
     *
     * @return array
     */
    public function getRecommendations(): array
    {
        return $this->recommendations;
    }

    /**
     * Get metadata about the test execution
     *
     * @return array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Add a detail item to the result
     *
     * @param string $key The detail key
     * @param mixed $value The detail value
     * @return void
     */
    public function addDetail(string $key, $value): void
    {
        $this->details[$key] = $value;
    }

    /**
     * Add a recommendation to the result
     *
     * @param string $recommendation The recommendation text
     * @return void
     */
    public function addRecommendation(string $recommendation): void
    {
        $this->recommendations[] = $recommendation;
    }

    /**
     * Add metadata to the result
     *
     * @param string $key The metadata key
     * @param mixed $value The metadata value
     * @return void
     */
    public function addMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * Get the severity level of the result
     *
     * @return string (success, warning, error, critical)
     */
    public function getSeverity(): string
    {
        // Check if this is explicitly marked as a warning (successful but with concerns)
        if ($this->successful && $this->isWarning()) {
            return 'warning';
        }
        
        // Check if successful and has recommendations (also indicates warning)
        if ($this->successful && !empty($this->recommendations)) {
            return 'warning';
        }
        
        if ($this->successful) {
            return 'success';
        }

        // For failed tests, determine severity based on keywords in the message
        $message_lower = strtolower($this->message);
        
        if (strpos($message_lower, 'critical') !== false || 
            strpos($message_lower, 'fatal') !== false ||
            strpos($message_lower, 'failed to') !== false) {
            return 'critical';
        }

        if (strpos($message_lower, 'error') !== false ||
            strpos($message_lower, 'missing') !== false ||
            strpos($message_lower, 'invalid') !== false) {
            return 'error';
        }

        return 'warning';
    }

    /**
     * Convert the result to an array for serialization
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'successful' => $this->successful,
            'message' => $this->message,
            'details' => $this->details,
            'recommendations' => $this->recommendations,
            'metadata' => $this->metadata,
            'severity' => $this->getSeverity()
        ];
    }

    /**
     * Create a successful result
     *
     * @param string $name The diagnostic test name
     * @param string $message The success message
     * @param array $details Optional details
     * @param array $metadata Optional metadata
     * @return self
     */
    public static function success(
        string $name, 
        string $message, 
        array $details = [], 
        array $metadata = []
    ): self {
        return new self($name, true, $message, $details, [], $metadata);
    }

    /**
     * Create a failed result
     *
     * @param string $name The diagnostic test name
     * @param string $message The failure message
     * @param array $details Optional details
     * @param array $recommendations Optional recommendations
     * @param array $metadata Optional metadata
     * @return self
     */
    public static function failure(
        string $name, 
        string $message, 
        array $details = [], 
        array $recommendations = [], 
        array $metadata = []
    ): self {
        return new self($name, false, $message, $details, $recommendations, $metadata);
    }

    /**
     * Create a warning result (technically successful but with concerns)
     *
     * @param string $name The diagnostic test name
     * @param string $message The warning message
     * @param array $details Optional details
     * @param array $recommendations Optional recommendations
     * @param array $metadata Optional metadata
     * @return self
     */
    public static function warning(
        string $name, 
        string $message, 
        array $details = [], 
        array $recommendations = [], 
        array $metadata = []
    ): self {
        $result = new self($name, true, $message, $details, $recommendations, $metadata);
        $result->addMetadata('warning', true);
        return $result;
    }

    /**
     * Check if this is a warning result
     *
     * @return bool
     */
    public function isWarning(): bool
    {
        return isset($this->metadata['warning']) && $this->metadata['warning'] === true;
    }

    /**
     * Get execution time if available
     *
     * @return float|null Execution time in seconds or null if not available
     */
    public function getExecutionTime(): ?float
    {
        return $this->metadata['execution_time'] ?? null;
    }

    /**
     * Set execution time
     *
     * @param float $time Execution time in seconds
     * @return void
     */
    public function setExecutionTime(float $time): void
    {
        $this->metadata['execution_time'] = $time;
    }
}
