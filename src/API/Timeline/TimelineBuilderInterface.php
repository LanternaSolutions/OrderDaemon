<?php
declare(strict_types=1);

namespace OrderDaemon\CompletionManager\API\Timeline;

/**
 * Interface for building timeline data from log entries
 * 
 * @package OrderDaemon\CompletionManager\API\Timeline
 * @since   1.0.0
 */
interface TimelineBuilderInterface
{
    /**
     * Build timeline data from a log entry request
     * 
     * @param TimelineRequest $request The timeline request
     * @return TimelineData The constructed timeline data
     * @throws \Exception If timeline cannot be built
     */
    public function buildTimeline(TimelineRequest $request): TimelineData;
}
