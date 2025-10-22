<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Interface for rendering timeline data to HTML
 * 
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.0.0
 */
interface TimelineRendererInterface
{
    /**
     * Render timeline data to HTML
     * 
     * @param TimelineData $timeline The timeline data to render
     * @return string Generated HTML content
     * @throws \Exception If rendering fails
     */
    public function renderTimeline(TimelineData $timeline): string;
}
