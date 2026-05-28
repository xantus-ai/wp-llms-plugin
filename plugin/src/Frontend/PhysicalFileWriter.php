<?php
declare(strict_types=1);

namespace WPLlms\Frontend;

use WPLlms\Generator\DescriptionResolver;
use WPLlms\Generator\Extractor;
use WPLlms\Generator\HeaderRenderer;
use WPLlms\Generator\LlmsFullTxtGenerator;
use WPLlms\Generator\LlmsTxtGenerator;
use WPLlms\Generator\SectionResolver;
use WPLlms\Generator\TitleResolver;
use WPLlms\Storage\Options;
use WPLlms\Storage\OverridesRepository;
use WPLlms\Storage\SectionsRepository;

/**
 * Writes llms.txt and llms-full.txt as physical files at the WordPress
 * document root, so hosts that block dynamic .txt serving (WP Engine,
 * Kinsta) can serve them directly via nginx.
 *
 * When enabled, the FileServer rewrite rules still register as a fallback
 * for hosts where the physical file isn't writable for some reason.
 */
final class PhysicalFileWriter {
    public const LLMS_TXT_FILENAME = 'llms.txt';
    public const LLMS_FULL_TXT_FILENAME = 'llms-full.txt';

    public function is_enabled(): bool {
        $settings = Options::get_settings();
        if (array_key_exists('serve_via_static_file', $settings)) {
            return (bool) $settings['serve_via_static_file'];
        }
        // No explicit setting yet - default to true on hosts that need it.
        return HostDetector::blocks_dynamic_txt();
    }

    public function write_all(): array {
        $results = [];
        $results['llms.txt'] = $this->write_llms_txt();
        $results['llms-full.txt'] = $this->write_llms_full_txt();
        return $results;
    }

    public function write_llms_txt(): array {
        try {
            $content = $this->build_llms_generator()->generate();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'generation_failed: ' . $e->getMessage()];
        }
        return $this->write(self::LLMS_TXT_FILENAME, $content);
    }

    public function write_llms_full_txt(): array {
        try {
            $content = $this->build_llms_full_generator()->generate();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'generation_failed: ' . $e->getMessage()];
        }
        return $this->write(self::LLMS_FULL_TXT_FILENAME, $content);
    }

    public function delete_all(): void {
        $this->delete_file(self::LLMS_TXT_FILENAME);
        $this->delete_file(self::LLMS_FULL_TXT_FILENAME);
    }

    public function exists(string $filename): bool {
        return file_exists(self::path($filename));
    }

    public function age_seconds(string $filename): ?int {
        $path = self::path($filename);
        if (!file_exists($path)) return null;
        $mtime = filemtime($path);
        return $mtime === false ? null : (time() - $mtime);
    }

    public function size_bytes(string $filename): ?int {
        $path = self::path($filename);
        if (!file_exists($path)) return null;
        $size = filesize($path);
        return $size === false ? null : $size;
    }

    public static function path(string $filename): string {
        return rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR . $filename;
    }

    public static function can_write(): bool {
        $root = rtrim(ABSPATH, '/\\');
        return is_writable($root);
    }

    private function write(string $filename, string $content): array {
        $path = self::path($filename);

        if (file_exists($path) && !is_writable($path)) {
            return ['ok' => false, 'error' => 'file_not_writable', 'path' => $path];
        }
        if (!file_exists($path) && !is_writable(dirname($path))) {
            return ['ok' => false, 'error' => 'directory_not_writable', 'path' => $path];
        }

        // Write atomically via temp file + rename to avoid partial reads.
        $temp = $path . '.tmp-' . bin2hex(random_bytes(8));
        $bytes = @file_put_contents($temp, $content, LOCK_EX);
        if ($bytes === false) {
            return ['ok' => false, 'error' => 'write_failed', 'path' => $path];
        }
        if (!@rename($temp, $path)) {
            @unlink($temp);
            return ['ok' => false, 'error' => 'rename_failed', 'path' => $path];
        }
        @chmod($path, 0644);

        return [
            'ok' => true,
            'path' => $path,
            'bytes' => $bytes,
        ];
    }

    private function delete_file(string $filename): void {
        $path = self::path($filename);
        if (file_exists($path) && is_writable($path)) {
            @unlink($path);
        }
    }

    private function build_llms_generator(): LlmsTxtGenerator {
        $sections = new SectionsRepository();
        $overrides = new OverridesRepository();
        $extractor = new Extractor();

        return new LlmsTxtGenerator(
            $sections,
            new SectionResolver($overrides),
            new TitleResolver($overrides),
            new DescriptionResolver($extractor, $overrides),
            new HeaderRenderer()
        );
    }

    private function build_llms_full_generator(): LlmsFullTxtGenerator {
        $sections = new SectionsRepository();
        $overrides = new OverridesRepository();
        $extractor = new Extractor();

        return new LlmsFullTxtGenerator(
            $sections,
            new SectionResolver($overrides),
            new TitleResolver($overrides),
            new DescriptionResolver($extractor, $overrides),
            $extractor,
            new HeaderRenderer()
        );
    }
}
