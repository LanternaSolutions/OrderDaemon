<?php
/**
 * Replace Diagnostic String Keys with English Values
 *
 * This script replaces all diagnostic string keys used in __() function calls
 * with their corresponding English values from the JSON file.
 *
 * Usage: php replace-diagnostic-strings.php [--dry-run] [--backup]
 */

class DiagnosticStringReplacer
{
    private array $stringMappings = [];
    private array $processedFiles = [];
    private array $replacements = [];
    private bool $dryRun = false;
    private bool $createBackup = true;

    /**
     * Constructor
     */
    public function __construct(bool $dryRun = false, bool $createBackup = true)
    {
        $this->dryRun = $dryRun;
        $this->createBackup = $createBackup;
    }

    /**
     * Run the replacement process
     */
    public function run(): void
    {
        echo "=== Diagnostic String Replacement Tool ===\n\n";
        
        if ($this->dryRun) {
            echo "🔍 DRY RUN MODE - No files will be modified\n\n";
        }

        // Step 1: Load string mappings from JSON
        if (!$this->loadStringMappings()) {
            echo "❌ Failed to load string mappings. Exiting.\n";
            return;
        }

        echo "✅ Loaded " . count($this->stringMappings) . " string mappings from JSON\n\n";

        // Step 2: Find and process PHP files
        $files = $this->findPhpFiles();
        echo "📁 Found " . count($files) . " PHP files to process\n\n";

        // Step 3: Process each file
        foreach ($files as $file) {
            $this->processFile($file);
        }

        // Step 4: Generate summary report
        $this->generateReport();
    }

    /**
     * Load string mappings from the JSON file
     */
    private function loadStringMappings(): bool
    {
        $jsonPath = 'src/Diagnostics/diagnostics-i18n-strings.json';
        
        if (!file_exists($jsonPath)) {
            echo "❌ JSON file not found: $jsonPath\n";
            return false;
        }

        $jsonContent = file_get_contents($jsonPath);
        if ($jsonContent === false) {
            echo "❌ Failed to read JSON file: $jsonPath\n";
            return false;
        }

        $data = json_decode($jsonContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "❌ Invalid JSON in file: " . json_last_error_msg() . "\n";
            return false;
        }

        if (!isset($data['diagnostics_i18n_strings']['strings'])) {
            echo "❌ Missing 'diagnostics_i18n_strings.strings' in JSON file\n";
            return false;
        }

        $this->stringMappings = $data['diagnostics_i18n_strings']['strings'];
        return true;
    }

    /**
     * Find all PHP files in target directories
     */
    private function findPhpFiles(): array
    {
        $files = [];
        $directories = [
            'src/Diagnostics',
            'src/Admin/DiagnosticDashboard.php' // Specific file
        ];

        foreach ($directories as $dir) {
            if (is_file($dir)) {
                $files[] = $dir;
            } elseif (is_dir($dir)) {
                $files = array_merge($files, $this->scanDirectory($dir));
            }
        }

        return array_unique($files);
    }

    /**
     * Recursively scan directory for PHP files
     */
    private function scanDirectory(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Process a single PHP file
     */
    private function processFile(string $filePath): void
    {
        echo "🔍 Processing: $filePath\n";

        $content = file_get_contents($filePath);
        if ($content === false) {
            echo "   ❌ Failed to read file\n";
            return;
        }

        $originalContent = $content;
        $fileReplacements = 0;

        // Pattern to match __('key', 'order-daemon') calls
        // This handles various formatting styles
        $pattern = '/(__\(\s*[\'"])(admin\.diagnostics\.[^\'\"]+)([\'\"]\s*,\s*[\'"]order-daemon[\'\"]\s*\))/';
        
        $content = preg_replace_callback($pattern, function($matches) use (&$fileReplacements, $filePath) {
            $prefix = $matches[1]; // __('
            $key = $matches[2];    // admin.diagnostics.somekey
            $suffix = $matches[3]; // ', 'order-daemon')

            if (isset($this->stringMappings[$key])) {
                $englishValue = $this->stringMappings[$key];
                
                // Escape quotes in the English string
                $escapedValue = addslashes($englishValue);
                
                $fileReplacements++;
                $this->replacements[] = [
                    'file' => $filePath,
                    'key' => $key,
                    'value' => $englishValue
                ];

                return $prefix . $escapedValue . $suffix;
            } else {
                echo "   ⚠️  Key not found in JSON: $key\n";
                return $matches[0]; // Return unchanged
            }
        }, $content);

        if ($fileReplacements > 0) {
            echo "   ✅ Found $fileReplacements replacements\n";
            
            if (!$this->dryRun) {
                // Create backup if requested
                if ($this->createBackup) {
                    $backupPath = $filePath . '.backup.' . date('Y-m-d-H-i-s');
                    if (copy($filePath, $backupPath)) {
                        echo "   💾 Backup created: $backupPath\n";
                    }
                }

                // Write the modified content
                if (file_put_contents($filePath, $content) === false) {
                    echo "   ❌ Failed to write file\n";
                } else {
                    echo "   💾 File updated successfully\n";
                    $this->processedFiles[] = $filePath;
                }
            } else {
                echo "   🔍 DRY RUN: Would replace $fileReplacements strings\n";
            }
        } else {
            echo "   ℹ️  No replacements needed\n";
        }

        echo "\n";
    }

    /**
     * Generate summary report
     */
    private function generateReport(): void
    {
        echo "=== SUMMARY REPORT ===\n\n";
        echo "📊 Total files processed: " . count($this->processedFiles) . "\n";
        echo "🔄 Total replacements made: " . count($this->replacements) . "\n\n";

        if (!empty($this->replacements)) {
            echo "📝 Detailed replacements:\n";
            
            $groupedByFile = [];
            foreach ($this->replacements as $replacement) {
                $groupedByFile[$replacement['file']][] = $replacement;
            }

            foreach ($groupedByFile as $file => $fileReplacements) {
                echo "\n📁 $file (" . count($fileReplacements) . " replacements):\n";
                foreach ($fileReplacements as $replacement) {
                    echo "   • {$replacement['key']} → \"{$replacement['value']}\"\n";
                }
            }
        }

        if ($this->dryRun) {
            echo "\n🔍 This was a DRY RUN - no files were actually modified.\n";
            echo "Run without --dry-run to apply changes.\n";
        } else {
            echo "\n✅ Replacement process completed successfully!\n";
            if ($this->createBackup && !empty($this->processedFiles)) {
                echo "💾 Backup files created for all modified files.\n";
            }
        }
    }
}

// Parse command line arguments
$dryRun = in_array('--dry-run', $argv);
$createBackup = !in_array('--no-backup', $argv);

if (in_array('--help', $argv)) {
    echo "Usage: php replace-diagnostic-strings.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --dry-run     Preview changes without modifying files\n";
    echo "  --no-backup   Don't create backup files\n";
    echo "  --help        Show this help message\n\n";
    echo "Examples:\n";
    echo "  php replace-diagnostic-strings.php --dry-run     # Preview changes\n";
    echo "  php replace-diagnostic-strings.php              # Apply changes with backups\n";
    echo "  php replace-diagnostic-strings.php --no-backup  # Apply changes without backups\n\n";
    exit(0);
}

// Run the replacer
$replacer = new DiagnosticStringReplacer($dryRun, $createBackup);
$replacer->run();
