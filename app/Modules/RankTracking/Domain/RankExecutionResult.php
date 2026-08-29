<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Domain;

use InvalidArgumentException;

final class RankExecutionResult
{
    public function __construct(
        public readonly string $type,
        public readonly ?int $position,
        public readonly ?string $rankingUrl,
        public readonly int $checkedDepth,
        public readonly string $executionDevice,
        public readonly string $userAgentProfile,
        public readonly string $observedAt,
    ) {
        if (!in_array($type, ['ranked', 'not_found'], true) || $checkedDepth < 1 || $checkedDepth > 1000) {
            throw new InvalidArgumentException('Invalid rank result classification.');
        }
        if (($type === 'ranked') !== ($position !== null && $position >= 1 && $position <= $checkedDepth && $rankingUrl !== null)) {
            throw new InvalidArgumentException('Ranked results require a position and ranking URL; not-found results require neither.');
        }
        if ($rankingUrl !== null && (strlen($rankingUrl) > 2048 || filter_var($rankingUrl, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($rankingUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new InvalidArgumentException('Invalid ranking URL.');
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,49}$/', $executionDevice) || !preg_match('/^[a-zA-Z0-9_.:\/-]{1,100}$/', $userAgentProfile)) {
            throw new InvalidArgumentException('Invalid execution provenance.');
        }
        $timestamp = strtotime($observedAt);
        if ($timestamp === false || $timestamp > time() + 300) throw new InvalidArgumentException('Invalid observation timestamp.');
    }
}
